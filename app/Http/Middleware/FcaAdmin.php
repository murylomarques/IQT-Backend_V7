<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FcaAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->attributes->get('fca_user');
        if (!$user || $user->role !== 'admin') {
            return response()->json(['error' => 'Acesso não autorizado.'], 403);
        }

        return $next($request);
    }
}
