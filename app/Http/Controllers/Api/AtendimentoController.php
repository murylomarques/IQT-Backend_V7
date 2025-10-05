<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BaseSalesforceIntegrada;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Agenda; // Importe o model de Agenda
use App\Models\User;   // Importe o model de User
use Carbon\Carbon; // Essencial para lidar com datas

class AtendimentoController extends Controller
{
    /**
     * Exibe uma lista paginada e filtrada de atendimentos.
     */
    public function index(Request $request)
    {
        // ===================================================================
        // PASSO 1: CALCULAR AS ESTATÍSTICAS GLOBAIS (Sem alterações)
        // ===================================================================
        $today = Carbon::today();

        $totalAgendamentos = Agenda::count();
        $pendentesHoje = Agenda::whereDate('data_agendamento', $today)
                               ->where('statusAgendamento', 'pendente')
                               ->count();
        $totalConcluidos = Agenda::where('statusAgendamento', 'concluido')
                                 ->count();

        $globalStats = [
            'total_agendamentos' => $totalAgendamentos,
            'pendentes_hoje' => $pendentesHoje,
            'total_concluidos' => $totalConcluidos,
        ];

        // ===================================================================
        // PASSO 2: CALCULAR AS ESTATÍSTICAS POR FISCAL (Sem alterações)
        // ===================================================================
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
            $stats = $agendaCounts->get($fiscal->id);
            return [
                'nome' => $fiscal->nome, 
                'agendados_hoje' => $stats->agendados_hoje ?? 0,
                'agendados_futuro' => $stats->agendados_futuro ?? 0,
            ];
        });

        // ===================================================================
        // PASSO 3: BUSCAR OS ATENDIMENTOS PENDENTES (Sem alterações)
        // ===================================================================
        $perPage = 20;
        $query = BaseSalesforceIntegrada::query();

        if ($request->filled('tecnico')) {
            $query->where('nome_tecnico', 'like', '%' . $request->query('tecnico') . '%');
        }
        if ($request->filled('empresa')) {
            $query->where('empresa_tecnico', 'like', '%' . $request->query('empresa') . '%');
        }
        // ... (resto dos seus filtros)

        $atendimentosPaginados = $query->select(
            'id as ID',
            DB::raw("NULL as Regional"),
            'empresa_tecnico as Empresa',
            DB::raw("NULL as Supervisor"),
            'nome_tecnico as Tecnico',
            'nome_conta as Cliente',
            'telefone as Telefone',
            'caso as SA',
            'data_sa_concluida as Conclusao',
            'endereco as Endereco',
            'cto as CTO',
            'porta as Porta'
        )
        ->paginate($perPage)
        ->withQueryString();

        // ===================================================================
        // PASSO 4: COMBINAR TUDO EM UMA ÚNICA RESPOSTA (NOVA ABORDAGEM)
        // ===================================================================
        
        // 1. Converte o resultado da paginação em um array.
        //    Isso nos dá 'data', 'current_page', 'total', etc.
        $responseArray = $atendimentosPaginados->toArray();

        // 2. Adiciona a nossa chave 'stats' a este array.
        $responseArray['stats'] = [
            'global' => $globalStats,
            'fiscais' => $fiscaisStats,
        ];

        // 3. Retorna o array completo como uma resposta JSON.
        return response()->json($responseArray);
    }

    /**
     * ==========================================================
     * ==================== NOVO MÉTODO AQUI ====================
     * ==========================================================
     *
     * Exibe os detalhes de um único agendamento.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Busca o agendamento pelo ID e já renomeia as colunas
        $appointment = BaseSalesforceIntegrada::query()
            ->select(
                'id as ID',
                DB::raw("'SP-Interior' as Regional"), // Exemplo: Retornando um valor fixo
                'empresa_tecnico as Empresa',
                DB::raw("'Supervisor Exemplo' as Supervisor"), // Exemplo: Retornando um valor fixo
                'nome_tecnico as Tecnico',
                'nome_conta as Cliente',
                'telefone as Telefone',
                'caso as SA',
                'data_sa_concluida as Conclusao',
                'endereco as Endereco',
                'cto as CTO',
                'porta as Porta'
            )
            ->where('id', $id) // A condição para encontrar o registro específico
            ->first(); // Usa first() para obter apenas um resultado

        // Se o agendamento não for encontrado, retorna uma resposta 404
        if (!$appointment) {
            return response()->json(['message' => 'Agendamento não encontrado'], 404);
        }

        // Se encontrou, retorna o objeto JSON do agendamento
        return response()->json($appointment);
    }
}