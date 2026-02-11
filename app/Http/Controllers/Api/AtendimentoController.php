<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agenda;
use App\Models\BaseSalesforceIntegrada;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AtendimentoController extends Controller
{
    /**
     * Exibe uma lista paginada e filtrada de atendimentos.
     */
    public function index(Request $request)
    {
        $today = Carbon::today();

        $stats = Cache::remember('atendimentos_stats_v1', now()->addSeconds(30), function () use ($today) {
            $globalStats = [
                'total_agendamentos' => Agenda::count(),
                'pendentes_hoje' => Agenda::whereDate('data_agendamento', $today)
                    ->where('statusAgendamento', 'pendente')
                    ->count(),
                'total_concluidos' => Agenda::where('statusAgendamento', 'concluido')->count(),
            ];

            $fiscais = User::where('cargo_id', 3)->select('id', 'nome')->get();

            $agendaCounts = Agenda::query()
                ->select(
                    'fiscal_id',
                    DB::raw('COUNT(CASE WHEN DATE(data_agendamento) = ? THEN 1 END) as agendados_hoje'),
                    DB::raw('COUNT(CASE WHEN DATE(data_agendamento) > ? THEN 1 END) as agendados_futuro')
                )
                ->setBindings([$today->toDateString(), $today->toDateString()])
                ->groupBy('fiscal_id')
                ->get()
                ->keyBy('fiscal_id');

            $fiscaisStats = $fiscais->map(function ($fiscal) use ($agendaCounts) {
                $item = $agendaCounts->get($fiscal->id);
                return [
                    'nome' => $fiscal->nome,
                    'agendados_hoje' => $item->agendados_hoje ?? 0,
                    'agendados_futuro' => $item->agendados_futuro ?? 0,
                ];
            });

            return [
                'global' => $globalStats,
                'fiscais' => $fiscaisStats,
            ];
        });

        $query = BaseSalesforceIntegrada::query();

        if ($request->filled('tecnico')) {
            $query->where('nome_tecnico', 'like', '%' . $request->query('tecnico') . '%');
        }
        if ($request->filled('empresa')) {
            $query->where('empresa_tecnico', 'like', '%' . $request->query('empresa') . '%');
        }
        if ($request->filled('cto')) {
            $query->where('cto', 'like', '%' . $request->query('cto') . '%');
        }
        if ($request->filled('sa')) {
            $query->where('numero_compromisso', 'like', '%' . $request->query('sa') . '%');
        }
        if ($request->filled('endereco')) {
            $query->where('endereco', 'like', '%' . $request->query('endereco') . '%');
        }

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $atendimentos = $query->select(
            'id as ID',
            DB::raw('NULL as Regional'),
            'empresa_tecnico as Empresa',
            DB::raw('NULL as Supervisor'),
            'nome_tecnico as Tecnico',
            'nome_conta as Cliente',
            'telefone as Telefone',
            'caso as SA',
            'numero_compromisso as NumeroCompromisso',
            'data_sa_concluida as Conclusao',
            'endereco as Endereco',
            'cto as CTO',
            'porta as Porta'
        )
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $atendimentos->items(),
            'current_page' => $atendimentos->currentPage(),
            'last_page' => $atendimentos->lastPage(),
            'per_page' => $atendimentos->perPage(),
            'total' => $atendimentos->total(),
            'stats' => $stats,
        ]);
    }

    /**
     * Exibe os detalhes de um unico agendamento.
     */
    public function show($id)
    {
        $appointment = BaseSalesforceIntegrada::query()
            ->select(
                'id as ID',
                DB::raw("'SP-Interior' as Regional"),
                'empresa_tecnico as Empresa',
                DB::raw("'Supervisor Exemplo' as Supervisor"),
                'nome_tecnico as Tecnico',
                'nome_conta as Cliente',
                'telefone as Telefone',
                'caso as SA',
                'data_sa_concluida as Conclusao',
                'endereco as Endereco',
                'cto as CTO',
                'porta as Porta'
            )
            ->where('id', $id)
            ->first();

        if (!$appointment) {
            return response()->json(['message' => 'Agendamento nao encontrado'], 404);
        }

        return response()->json($appointment);
    }
}

