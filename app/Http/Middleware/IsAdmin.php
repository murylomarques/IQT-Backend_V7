<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        // Supondo que o cargo_id de Admin seja 1
        if (Auth::check() && Auth::user()->cargo_id == 1) {
            return $next($request);
        }
        return response()->json(['message' => 'Acesso não autorizado.'], 403);
    }
}