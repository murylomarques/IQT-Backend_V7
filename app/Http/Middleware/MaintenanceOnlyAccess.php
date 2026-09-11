<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MaintenanceOnlyAccess
{
    private const PROPRIO_MANUTENCAO_CARGO_ID = 4;

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ((int) ($user?->cargo_id ?? 0) !== self::PROPRIO_MANUTENCAO_CARGO_ID) {
            return $next($request);
        }

        if ($request->is('api/manutencao') || $request->is('api/manutencao/*')) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Perfil autorizado apenas para vistorias de manutencao.',
        ], 403);
    }
}
