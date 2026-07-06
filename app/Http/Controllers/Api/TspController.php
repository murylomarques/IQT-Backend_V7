<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tsp\TspAppointmentService;
use App\Services\Tsp\TspRequestLogger;
use App\Services\Tsp\TspTokenService;
use App\Services\Tsp\TspWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TspController extends Controller
{
    public function token(Request $request, TspTokenService $tokens, TspRequestLogger $logger): JsonResponse
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();

        $validator = Validator::make($request->all(), [
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string|max:1024',
        ]);

        if ($validator->fails()) {
            $logger->log($request, $requestId, 422, $startedAt, null, 'validation_error');
            return $this->error($requestId, 'validation_error', 'Dados invalidos.', 422, $validator->errors()->toArray());
        }

        try {
            $issued = $tokens->issueToken(
                (string) $request->input('client_id'),
                (string) $request->input('client_secret')
            );

            if (!$issued) {
                $logger->log($request, $requestId, 401, $startedAt, null, 'invalid_client');
                return $this->error($requestId, 'invalid_client', 'client_id ou client_secret invalido.', 401);
            }

            $client = $issued['client'];
            $logger->log($request, $requestId, 200, $startedAt, $client->id);

            return response()->json([
                'request_id' => $requestId,
                'access_token' => $issued['access_token'],
                'token_type' => $issued['token_type'],
                'expires_in' => $issued['expires_in'],
                'expires_at' => $issued['expires_at'],
                'scope' => $issued['scope'],
            ]);
        } catch (Throwable $e) {
            $logger->log($request, $requestId, 500, $startedAt, null, 'token_error');
            return $this->error($requestId, 'token_error', 'Falha ao emitir token.', 500);
        }
    }

    public function next15Days(Request $request, TspAppointmentService $appointments, TspRequestLogger $logger): JsonResponse
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $client = $request->attributes->get('tsp_client');

        $validator = Validator::make($request->query(), [
            'page_size' => 'nullable|integer|min:1|max:' . (int) config('tsp.page_size_max'),
            'page_token' => 'nullable|string|max:4096',
        ]);

        if ($validator->fails()) {
            $logger->log($request, $requestId, 422, $startedAt, $client->id ?? null, 'validation_error');
            return $this->error($requestId, 'validation_error', 'Dados invalidos.', 422, $validator->errors()->toArray());
        }

        try {
            $payload = $appointments->next15Days($this->pageSize($request), $request->query('page_token'));

            $logger->log($request, $requestId, 200, $startedAt, $client->id ?? null, null, [
                'items' => count($payload['items']),
            ]);

            return response()->json(array_merge(['request_id' => $requestId], $payload));
        } catch (RuntimeException $e) {
            $logger->log($request, $requestId, 400, $startedAt, $client->id ?? null, 'bad_request');
            return $this->error($requestId, 'bad_request', $e->getMessage(), 400);
        } catch (Throwable $e) {
            $logger->log($request, $requestId, 500, $startedAt, $client->id ?? null, 'next15_error');
            return $this->error($requestId, 'next15_error', 'Falha ao consultar agenda TSP.', 500);
        }
    }

    public function fixar(Request $request, string $id, TspAppointmentService $appointments, TspWriteService $write, TspRequestLogger $logger): JsonResponse
    {
        return $this->writeOperation('fixar', $id, $request, $appointments, $write, $logger, [
            'fixado' => 'required|boolean',
            'motivo' => 'nullable|string|max:1000',
        ], fn() => $write->fixar($id, (bool) $request->boolean('fixado')));
    }

    public function mudarTecnico(Request $request, string $id, TspAppointmentService $appointments, TspWriteService $write, TspRequestLogger $logger): JsonResponse
    {
        return $this->writeOperation('mudar_tecnico', $id, $request, $appointments, $write, $logger, [
            'novo_tecnico_id' => 'required|string|max:18',
            'novo_tecnico_nome' => 'nullable|string|max:255',
            'novo_inicio' => 'nullable|required_with:novo_fim|date_format:Y-m-d H:i:s',
            'novo_fim' => 'nullable|required_with:novo_inicio|date_format:Y-m-d H:i:s',
            'fixado' => 'nullable|boolean',
            'motivo' => 'nullable|string|max:1000',
        ], fn() => $write->mudarTecnico(
            $id,
            (string) $request->input('novo_tecnico_id'),
            $request->input('novo_inicio'),
            $request->input('novo_fim'),
            $request->has('fixado') ? (bool) $request->boolean('fixado') : null
        ));
    }

    public function mudarHorario(Request $request, string $id, TspAppointmentService $appointments, TspWriteService $write, TspRequestLogger $logger): JsonResponse
    {
        return $this->writeOperation('mudar_horario', $id, $request, $appointments, $write, $logger, [
            'novo_inicio' => 'required|date_format:Y-m-d H:i:s',
            'novo_fim' => 'required|date_format:Y-m-d H:i:s',
            'fixado' => 'nullable|boolean',
            'motivo' => 'nullable|string|max:1000',
        ], fn() => $write->mudarHorario(
            $id,
            (string) $request->input('novo_inicio'),
            (string) $request->input('novo_fim'),
            $request->has('fixado') ? (bool) $request->boolean('fixado') : null
        ));
    }

    public function suspender(Request $request, string $id, TspAppointmentService $appointments, TspWriteService $write, TspRequestLogger $logger): JsonResponse
    {
        return $this->writeOperation('suspender', $id, $request, $appointments, $write, $logger, [
            'descricao' => 'required|string|max:5000',
            'imagem' => 'required|file|image|max:' . (int) config('tsp.max_image_kb'),
            'motivo' => 'nullable|string|max:1000',
        ], fn() => $write->suspender(
            $id,
            (string) $request->input('descricao'),
            $request->file('imagem')
        ));
    }

    private function writeOperation(
        string $operation,
        string $id,
        Request $request,
        TspAppointmentService $appointments,
        TspWriteService $write,
        TspRequestLogger $logger,
        array $rules,
        callable $callback
    ): JsonResponse {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $client = $request->attributes->get('tsp_client');

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $logger->log($request, $requestId, 422, $startedAt, $client->id ?? null, 'validation_error');
            return $this->error($requestId, 'validation_error', 'Dados invalidos.', 422, $validator->errors()->toArray());
        }

        $source = null;

        try {
            $source = $appointments->eligibleAppointment($id);
            $payload = $callback();

            $write->logWrite(
                $client->id ?? null,
                $requestId,
                $operation,
                $source,
                'success',
                $this->safeRequestPayload($request),
                $payload
            );

            $logger->log($request, $requestId, 200, $startedAt, $client->id ?? null, null, [
                'operation' => $operation,
                'source_id' => $source->id,
            ]);

            return response()->json(array_merge([
                'request_id' => $requestId,
                'success' => true,
            ], $payload));
        } catch (RuntimeException $e) {
            if ($source) {
                $this->logFailedWrite($write, $client->id ?? null, $requestId, $operation, $source, $request, 'bad_request', $e->getMessage());
            }

            $logger->log($request, $requestId, 400, $startedAt, $client->id ?? null, 'bad_request', [
                'operation' => $operation,
            ]);

            return $this->error($requestId, 'bad_request', $e->getMessage(), 400);
        } catch (Throwable $e) {
            if ($source) {
                $this->logFailedWrite($write, $client->id ?? null, $requestId, $operation, $source, $request, 'write_error', 'Falha ao executar operacao TSP.');
            }

            $logger->log($request, $requestId, 500, $startedAt, $client->id ?? null, 'write_error', [
                'operation' => $operation,
            ]);

            return $this->error($requestId, 'write_error', 'Falha ao executar operacao TSP.', 500);
        }
    }

    private function pageSize(Request $request): int
    {
        $default = (int) config('tsp.page_size_default', 500);
        $max = (int) config('tsp.page_size_max', 2000);

        return min(max((int) $request->input('page_size', $default), 1), $max);
    }

    private function logFailedWrite(
        TspWriteService $write,
        ?int $clientId,
        string $requestId,
        string $operation,
        object $source,
        Request $request,
        string $errorCode,
        string $message
    ): void {
        try {
            $write->logWrite($clientId, $requestId, $operation, $source, 'failed', $this->safeRequestPayload($request), [
                'error' => $message,
            ], $errorCode);
        } catch (Throwable) {
        }
    }

    private function safeRequestPayload(Request $request): array
    {
        $payload = $request->except(['imagem']);
        if ($request->hasFile('imagem')) {
            $payload['imagem'] = [
                'name' => $request->file('imagem')->getClientOriginalName(),
                'size' => $request->file('imagem')->getSize(),
                'mime' => $request->file('imagem')->getMimeType(),
            ];
        }

        return $payload;
    }

    private function error(string $requestId, string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'request_id' => $requestId,
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ],
        ], $status);
    }
}
