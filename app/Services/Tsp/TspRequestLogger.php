<?php

namespace App\Services\Tsp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TspRequestLogger
{
    public function log(
        Request $request,
        string $requestId,
        int $statusCode,
        float $startedAt,
        ?int $clientId = null,
        ?string $errorCode = null,
        array $metadata = []
    ): void {
        try {
            DB::connection(config('tsp.connection'))->table('api_request_logs')->insert([
                'request_id' => $requestId,
                'api_client_id' => $clientId,
                'endpoint' => substr($request->path(), 0, 128),
                'method' => $request->method(),
                'status_code' => $statusCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'error_code' => $errorCode,
                'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao gravar log TSP.', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
