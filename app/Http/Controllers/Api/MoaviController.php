<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Moavi\MoaviRequestLogger;
use App\Services\Moavi\MoaviSyncService;
use App\Services\Moavi\MoaviTokenService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MoaviController extends Controller
{
    public function token(Request $request, MoaviTokenService $tokens, MoaviRequestLogger $logger): JsonResponse
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

    public function period(Request $request, MoaviSyncService $sync, MoaviRequestLogger $logger): JsonResponse
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $client = $request->attributes->get('moavi_client');

        $validator = Validator::make($request->query(), [
            'inicio' => 'required|date_format:Y-m-d',
            'fim' => 'required|date_format:Y-m-d',
            'page_size' => 'nullable|integer|min:1|max:' . (int) config('moavi.page_size_max'),
            'page_token' => 'nullable|string|max:4096',
        ]);

        if ($validator->fails()) {
            $logger->log($request, $requestId, 422, $startedAt, $client->id ?? null, 'validation_error');
            return $this->error($requestId, 'validation_error', 'Dados invalidos.', 422, $validator->errors()->toArray());
        }

        try {
            [$start, $end] = $this->validatedPeriod(
                (string) $request->query('inicio'),
                (string) $request->query('fim')
            );

            $this->allowLongRun();

            $payload = $sync->listAppointments(
                $client,
                $start,
                $end,
                'periodo',
                $this->pageSize($request),
                $request->query('page_token')
            );

            $logger->log($request, $requestId, 200, $startedAt, $client->id, null, [
                'items' => count($payload['items']),
                'source_count' => $payload['snapshot']['source_count'] ?? null,
            ]);

            return response()->json(array_merge(['request_id' => $requestId], $payload));
        } catch (RuntimeException $e) {
            $logger->log($request, $requestId, 400, $startedAt, $client->id ?? null, 'bad_request');
            return $this->error($requestId, 'bad_request', $e->getMessage(), 400);
        } catch (Throwable $e) {
            $logger->log($request, $requestId, 500, $startedAt, $client->id ?? null, 'period_error');
            return $this->error($requestId, 'period_error', 'Falha ao consultar agendamentos.', 500);
        }
    }

    public function next15Days(Request $request, MoaviSyncService $sync, MoaviRequestLogger $logger): JsonResponse
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $client = $request->attributes->get('moavi_client');

        $validator = Validator::make($request->query(), [
            'page_size' => 'nullable|integer|min:1|max:' . (int) config('moavi.page_size_max'),
            'page_token' => 'nullable|string|max:4096',
        ]);

        if ($validator->fails()) {
            $logger->log($request, $requestId, 422, $startedAt, $client->id ?? null, 'validation_error');
            return $this->error($requestId, 'validation_error', 'Dados invalidos.', 422, $validator->errors()->toArray());
        }

        try {
            $today = Carbon::now(config('moavi.timezone'))->startOfDay();
            $end = $today->copy()->addDays(15);

            $this->allowLongRun();

            $payload = $sync->listAppointments(
                $client,
                $today,
                $end,
                'proximos_15_dias',
                $this->pageSize($request),
                $request->query('page_token')
            );

            $logger->log($request, $requestId, 200, $startedAt, $client->id, null, [
                'items' => count($payload['items']),
                'source_count' => $payload['snapshot']['source_count'] ?? null,
            ]);

            return response()->json(array_merge(['request_id' => $requestId], $payload));
        } catch (RuntimeException $e) {
            $logger->log($request, $requestId, 400, $startedAt, $client->id ?? null, 'bad_request');
            return $this->error($requestId, 'bad_request', $e->getMessage(), 400);
        } catch (Throwable $e) {
            $logger->log($request, $requestId, 500, $startedAt, $client->id ?? null, 'next15_error');
            return $this->error($requestId, 'next15_error', 'Falha ao consultar proximos 15 dias.', 500);
        }
    }

    public function changes(Request $request, MoaviSyncService $sync, MoaviRequestLogger $logger): JsonResponse
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $client = $request->attributes->get('moavi_client');

        $validator = Validator::make($request->all(), [
            'sync_key' => 'required|string|max:255',
            'page_size' => 'nullable|integer|min:1|max:' . (int) config('moavi.page_size_max'),
            'page_token' => 'nullable|string|max:4096',
        ]);

        if ($validator->fails()) {
            $logger->log($request, $requestId, 422, $startedAt, $client->id ?? null, 'validation_error');
            return $this->error($requestId, 'validation_error', 'Dados invalidos.', 422, $validator->errors()->toArray());
        }

        try {
            $this->allowLongRun();

            $payload = $sync->changes(
                $client,
                (string) $request->input('sync_key'),
                $this->pageSize($request),
                $request->input('page_token')
            );

            $logger->log($request, $requestId, 200, $startedAt, $client->id, null, [
                'changes' => count($payload['changes']),
                'has_more' => $payload['pagination']['has_more'] ?? null,
            ]);

            return response()->json(array_merge(['request_id' => $requestId], $payload));
        } catch (RuntimeException $e) {
            $logger->log($request, $requestId, 400, $startedAt, $client->id ?? null, 'bad_request');
            return $this->error($requestId, 'bad_request', $e->getMessage(), 400);
        } catch (Throwable $e) {
            $logger->log($request, $requestId, 500, $startedAt, $client->id ?? null, 'changes_error');
            return $this->error($requestId, 'changes_error', 'Falha ao consultar alteracoes.', 500);
        }
    }

    private function validatedPeriod(string $startInput, string $endInput): array
    {
        $timezone = (string) config('moavi.timezone');
        $start = Carbon::createFromFormat('Y-m-d', $startInput, $timezone)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $endInput, $timezone)->startOfDay();
        $min = Carbon::createFromFormat('Y-m-d', (string) config('moavi.min_schedule_date'), $timezone)->startOfDay();

        if ($start->lt($min)) {
            throw new RuntimeException('inicio deve ser maior ou igual a ' . $min->toDateString() . '.');
        }

        if ($end->lt($start)) {
            throw new RuntimeException('fim deve ser maior ou igual a inicio.');
        }

        if ($start->diffInDays($end) > 29) {
            throw new RuntimeException('Periodo maximo permitido: 30 dias corridos.');
        }

        return [$start, $end];
    }

    private function pageSize(Request $request): int
    {
        $default = (int) config('moavi.page_size_default', 500);
        $max = (int) config('moavi.page_size_max', 2000);

        return min(max((int) $request->input('page_size', $default), 1), $max);
    }

    private function error(string $requestId, string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'request_id' => $requestId,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ],
        ], $status);
    }

    private function allowLongRun(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
