<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class McpController extends Controller
{
    private const HISTORICO_MAX_MESES = 3;
    private const HISTORICO_POR_PAGINA_PADRAO = 10000;
    private const HISTORICO_POR_PAGINA_MAX = 100000;

    private function db()
    {
        return DB::connection('melhoria_continua');
    }

    private function historicoPeriodo(Request $request): array
    {
        $hoje = Carbon::today();
        $limiteAplicado = false;

        if ($request->filled('mes') && $request->filled('ano')) {
            $inicio = Carbon::createFromDate((int) $request->ano, (int) $request->mes, 1)->startOfMonth();
            $fim = $inicio->copy()->endOfMonth();
        } else {
            $fim = $request->filled('data_fim')
                ? Carbon::parse($request->data_fim)->startOfDay()
                : $hoje->copy();

            $inicio = $request->filled('data_inicio')
                ? Carbon::parse($request->data_inicio)->startOfDay()
                : $fim->copy()->subMonthsNoOverflow(self::HISTORICO_MAX_MESES);
        }

        if ($inicio->gt($fim)) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        $inicioMinimo = $fim->copy()->subMonthsNoOverflow(self::HISTORICO_MAX_MESES);
        if ($inicio->lt($inicioMinimo)) {
            $inicio = $inicioMinimo;
            $limiteAplicado = true;
        }

        return [
            'inicio' => $inicio->toDateString(),
            'fim' => $fim->toDateString(),
            'limite_aplicado' => $limiteAplicado,
        ];
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
    // ?regional= &tipo_os= &data_inicio= &data_fim= &mes= &ano= &pagina= &por_pagina= &sem_total=
    public function historicoDiario(Request $request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $request->validate([
            'regional' => 'nullable|string|max:255',
            'tipo_os' => 'nullable|string|max:255',
            'data_inicio' => 'nullable|date',
            'data_fim' => 'nullable|date',
            'mes' => 'nullable|integer|between:1,12|required_with:ano',
            'ano' => 'nullable|integer|between:2000,2100|required_with:mes',
            'pagina' => 'nullable|integer|min:1',
            'por_pagina' => 'nullable|integer|min:1|max:' . self::HISTORICO_POR_PAGINA_MAX,
            'sem_total' => 'nullable|boolean',
        ]);

        $periodo = $this->historicoPeriodo($request);
        $query = $this->db()->table('vw_mcp_historico_entrantes_hora');

        if ($request->filled('regional')) {
            $query->where('regional', $request->regional);
        }
        if ($request->filled('tipo_os')) {
            $query->where('tipo_os', $request->tipo_os);
        }

        $query->whereBetween('data_referencia', [$periodo['inicio'], $periodo['fim']]);

        $porPagina = min(
            (int) $request->input('por_pagina', self::HISTORICO_POR_PAGINA_PADRAO),
            self::HISTORICO_POR_PAGINA_MAX
        );
        $pagina    = max((int) $request->input('pagina', 1), 1);

        $semTotal = $request->boolean('sem_total');
        $total = $semTotal ? null : (clone $query)->count();

        $dadosQuery = (clone $query)
            ->offset(($pagina - 1) * $porPagina)
            ->limit($semTotal ? $porPagina + 1 : $porPagina)
            ->orderBy('data_hora_abertura', 'desc')
            ->orderBy('data_referencia', 'desc')
            ->orderBy('regional')
            ->orderBy('tipo_os');

        $periodoResponse = [
            'data_inicio' => $periodo['inicio'],
            'data_fim' => $periodo['fim'],
            'maximo_meses' => self::HISTORICO_MAX_MESES,
            'limite_aplicado' => $periodo['limite_aplicado'],
        ];
        $paginas = $total === null ? null : (int) ceil($total / $porPagina);

        return response()->stream(function () use (
            $dadosQuery,
            $pagina,
            $porPagina,
            $semTotal,
            $total,
            $paginas,
            $periodoResponse
        ) {
            $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
            $temMais = false;
            $enviados = 0;
            $primeiro = true;

            echo '{';
            echo '"total":' . json_encode($total, $jsonFlags);
            echo ',"pagina":' . $pagina;
            echo ',"por_pagina":' . $porPagina;
            echo ',"paginas":' . json_encode($paginas, $jsonFlags);
            echo ',"periodo":' . json_encode($periodoResponse, $jsonFlags);
            echo ',"dados":[';

            foreach ($dadosQuery->cursor() as $row) {
                if ($semTotal && $enviados >= $porPagina) {
                    $temMais = true;
                    break;
                }

                if (!$primeiro) {
                    echo ',';
                }

                echo json_encode($row, $jsonFlags);
                $primeiro = false;
                $enviados++;

                if (($enviados % 500) === 0) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    @flush();
                }
            }

            if (!$semTotal) {
                $temMais = ($pagina * $porPagina) < $total;
            }

            echo '],"tem_mais":' . ($temMais ? 'true' : 'false');
            echo '}';
        }, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
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
