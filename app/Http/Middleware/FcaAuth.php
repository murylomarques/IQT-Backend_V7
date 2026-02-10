<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\FcaUser;

class FcaAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('Authorization');
        if (!$token) {
            return response()->json(['error' => 'Token ausente.'], 401);
        }

        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        $user = FcaUser::where('token', $token)->first();
        if (!$user) {
            return response()->json(['error' => 'Token inv??lido.'], 401);
        }

        $request->attributes->set('fca_user', $user);
        return $next($request);
    }
}
