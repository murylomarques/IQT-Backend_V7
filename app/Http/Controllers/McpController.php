<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class McpController extends Controller
{
    private function db()
    {
        return DB::connection('melhoria_continua');
    }

    // GET /api/mcp/entrantes-hoje
    public function entrantes(Request $request)
    {
        $query = $this->db()->table('vw_mcp_entrantes_hoje');

        if ($request->filled('regional')) {
            $query->where('regional', $request->regional);
        }
        if ($request->filled('tipo_os')) {
            $query->where('tipo_os', $request->tipo_os);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    // GET /api/mcp/entrantes-por-hora
    public function entrantesPorHora(Request $request)
    {
        $query = $this->db()->table('vw_mcp_entrantes_por_hora');

        if ($request->filled('regional')) {
            $query->where('regional', $request->regional);
        }

        return response()->json($query->orderBy('hora')->get());
    }

    // GET /api/mcp/anomalias-olt
    // ?nivel_alerta=CRITICO  (opcional — omitir retorna tudo exceto NORMAL)
    public function anomaliasOlt(Request $request)
    {
        $query = $this->db()->table('vw_mcp_anomalias_olt');

        if ($request->filled('nivel_alerta')) {
            $query->where('nivel_alerta', strtoupper($request->nivel_alerta));
        } else {
            $query->where('nivel_alerta', '!=', 'NORMAL');
        }

        return response()->json($query->orderByRaw("FIELD(nivel_alerta,'CRITICO','ELEVADO','ATENCAO','NORMAL')")->get());
    }

    // GET /api/mcp/backlog-aging
    // ?faixa=  (opcional)
    public function backlogAging(Request $request)
    {
        $query = $this->db()->table('vw_mcp_backlog_aging');

        if ($request->filled('faixa')) {
            $query->where('faixa_aging', $request->faixa);
        }
        if ($request->filled('area_pendente')) {
            $query->where('area_pendente', $request->area_pendente);
        }

        return response()->json($query->get());
    }

    // GET /api/mcp/historico-diario
    // ?regional=  &tipo_os=  &data_inicio=  &data_fim=
    public function historicoDiario(Request $request)
    {
        $query = $this->db()->table('vw_mcp_historico_diario');

        if ($request->filled('regional')) {
            $query->where('regional', $request->regional);
        }
        if ($request->filled('tipo_os')) {
            $query->where('tipo_os', $request->tipo_os);
        }
        if ($request->filled('data_inicio')) {
            $query->where('data', '>=', $request->data_inicio);
        }
        if ($request->filled('data_fim')) {
            $query->where('data', '<=', $request->data_fim);
        }

        return response()->json($query->orderBy('data', 'desc')->get());
    }

    // GET /api/mcp/resumo-operacional
    // ?regional=  &data=
    public function resumoOperacional(Request $request)
    {
        $query = $this->db()->table('vw_mcp_resumo_operacional_diario');

        if ($request->filled('regional')) {
            $query->where('regional', $request->regional);
        }
        if ($request->filled('data')) {
            $query->where('data', $request->data);
        }

        return response()->json($query->get());
    }
}
