<?php

namespace App\Http\Middleware;

use App\Services\Tsp\TspTokenService;
use Closure;
use Illuminate\Http\Request;

class TspBearerAuth
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes)
    {
        $plainToken = $request->bearerToken();

        if (!$plainToken) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Token Bearer ausente.',
                ],
            ], 401);
        }

        $auth = app(TspTokenService::class)->authenticateBearer($plainToken);

        if (!$auth) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_token',
                    'message' => 'Token invalido ou expirado.',
                ],
            ], 401);
        }

        $scopes = $auth['scopes'] ?? [];
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $scopes, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'forbidden',
                        'message' => 'Escopo insuficiente.',
                    ],
                ], 403);
            }
        }

        $request->attributes->set('tsp_client', $auth['client']);
        $request->attributes->set('tsp_token', $auth['token']);
        $request->attributes->set('tsp_scopes', $scopes);

        return $next($request);
    }
}
