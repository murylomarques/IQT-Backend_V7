<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MonitorMagicLink
{
    /**
     * Valida acesso por link magico para rotas publicas do monitor.
     */
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) env('MONITOR_MAGIC_TOKEN', '');
        if ($expected === '') {
            return response()->json([
                'message' => 'Acesso por link magico nao configurado.',
            ], 503);
        }

        $provided = (string) ($request->query('ml') ?: $request->header('X-Monitor-Magic', ''));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Link magico invalido.',
            ], 401);
        }

        return $next($request);
    }
}

