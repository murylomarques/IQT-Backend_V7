<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FcaSupervisor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->attributes->get('fca_user');

        if (!$user || $user->role !== 'supervisao') {
            return response()->json(['error' => 'Acesso nao autorizado.'], 403);
        }

        return $next($request);
    }
}
