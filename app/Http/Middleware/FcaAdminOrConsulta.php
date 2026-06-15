<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FcaAdminOrConsulta
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->attributes->get('fca_user');

        if (!$user || !in_array($user->role, ['admin', 'consulta'], true)) {
            return response()->json(['error' => 'Acesso nao autorizado.'], 403);
        }

        return $next($request);
    }
}
