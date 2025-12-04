<?php

namespace App\Http\Controllers;

// Importações necessárias
use App\Models\Agenda;
use App\Models\BaseSalesforceIntegrada;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator; // Para validação mais robusta
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Facades\Auth; // Para pegar o usuário logado              // Seu model da tabela 'agenda'
use Carbon\Carbon;   
use App\Models\User;

class AgendaController extends Controller
{
    /**
     * Exibe uma lista de todos os agendamentos.
     * GET /api/agenda
     */
    public function index()
    {
        // Carrega os agendamentos e as informações do fiscal associado a cada um
        $agendamentos = Agenda::with('fiscal')->latest()->get();
        return response()->json($agendamentos);
    }

    /**
     * Cria e armazena um novo agendamento no banco de dados.
     * POST /api/agenda
     */
     public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'atendimentoId' => 'required|exists:base_salesforce_integrada,id',
            'fiscalId'      => 'required|exists:users,id',
            'data'          => 'required|date_format:Y-m-d',
            'hora'          => 'nullable|date_format:H:i',
            'periodo'       => 'required|string|max:50',
            'observacoes'   => 'nullable|string',
            'agendado'      => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        $tipo = $validatedData['agendado'] ? 'Agendado' : 'Não Agendado';
        $agendamento = null; // Variável para armazenar a resposta

        try {
            // A transação garante que as duas operações (criar e apagar)
            // ocorram com sucesso. Se uma falhar, a outra é desfeita.
            DB::transaction(function () use ($validatedData, $tipo, &$agendamento) {

                // PASSO 1: Encontra o registro original na tabela 'base_salesforce_integrada'.
                $atendimentoOriginal = BaseSalesforceIntegrada::findOrFail($validatedData['atendimentoId']);

                // PASSO 2: CRIA um novo registro na tabela 'agendas', copiando os dados necessários.
                // Nenhuma linha da tabela 'agendas' é apagada aqui.
                $agendamento = Agenda::create([
                    'fiscal_id'         => $validatedData['fiscalId'],
                    'data_agendamento'  => $validatedData['data'],
                    'hora_agendamento'  => $validatedData['hora'] ?? null,
                    'periodo'           => $validatedData['periodo'],
                    'observacoes'       => $validatedData['observacoes'],
                    'tipo'              => $tipo,
                    
                    // Copiando os dados do atendimento original
                    'original_atendimento_id' => $atendimentoOriginal->id,
                    'caso'                    => $atendimentoOriginal->caso,
                    'numero_compromisso'      => $atendimentoOriginal->numero_compromisso,
                    'city'                    => $atendimentoOriginal->city,
                    'data_sa_concluida'       => $atendimentoOriginal->data_sa_concluida,
                    'nome_tecnico'            => $atendimentoOriginal->nome_tecnico,
                    'empresa_tecnico'         => $atendimentoOriginal->empresa_tecnico,
                    'tipo_trabalho'           => $atendimentoOriginal->tipo_trabalho,
                    'status_caso'             => $atendimentoOriginal->status_caso,
                    'cto'                     => $atendimentoOriginal->cto,
                    'porta'                   => $atendimentoOriginal->porta,
                    'nome_conta'              => $atendimentoOriginal->nome_conta,
                    'endereco'                => $atendimentoOriginal->endereco,
                    'telefone'                => $atendimentoOriginal->telefone,
                    'territorio'              => $atendimentoOriginal->TERRITORIO_A,
                ]);

                // PASSO 3: APAGA o registro original da tabela 'base_salesforce_integrada'.
                // Esta ação só afeta a tabela 'base_salesforce_integrada' porque
                // a variável $atendimentoOriginal é uma instância desse modelo.
                $atendimentoOriginal->delete();

            }); // Fim da transação. Se chegou até aqui, tudo deu certo.

        } catch (\Exception $e) {
            // Se algo deu errado (ex: o banco de dados caiu no meio do processo),
            // a transação é revertida e este erro é retornado.
            return response()->json([
                'message' => 'Ocorreu um erro ao processar o agendamento. Nenhuma alteração foi feita.',
                'error' => $e->getMessage()
            ], 500);
        }
        
        // Se a transação foi bem-sucedida, retorna a resposta de sucesso.
        return response()->json([
            'message' => 'Agendamento criado com sucesso e o atendimento original foi removido da lista de pendentes!',
            'data' => $agendamento
        ], status: 201);
    }

     /**
     * Exibe os detalhes de um agendamento específico.
     *
     * @param  \App\Models\Agenda  $agenda O agendamento encontrado automaticamente pelo ID na URL.
     * @return \Illuminate\Http\Response
     */
    public function show(Agenda $agenda)
    {
        // O "Route Model Binding" do Laravel já encontra o agendamento pelo ID.
        // Se não encontrar, ele retorna um 404 automaticamente.
        // Nós apenas retornamos o resultado como JSON.
        return response()->json($agenda);
    }
    /**
     * Atualiza um agendamento existente no banco de dados.
     * PUT/PATCH /api/agenda/{agenda}
     */
    public function update(Request $request, Agenda $agenda)
    {
        // Exemplo de como você poderia atualizar os status
        $validator = Validator::make($request->all(), [
            'status'            => 'sometimes|in:Agendado,Concluído,Cancelado',
            'statusAgendamento' => 'sometimes|in:Pendente,Reparo,Caminho,Realizando,Concluído,Cancelado',
            'statusLaudo'       => 'sometimes|in:Pendente,Reprovado,Concluído,Cancelado',
            'observacoes'       => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Atualiza o agendamento apenas com os dados que foram enviados na requisição
        $agenda->update($validator->validated());

        return response()->json([
            'message' => 'Agendamento atualizado com sucesso!',
            'data' => $agenda
        ]);
    }

    /**
     * Remove um agendamento do banco de dados.
     * DELETE /api/agenda/{agenda}
     */
    public function destroy(Agenda $agenda)
    {
        $agenda->delete();
        return response()->json(['message' => 'Agendamento cancelado/removido com sucesso.'], 200);
    }


     /**
     * Retorna as vistorias agendadas para o fiscal autenticado para a data de hoje.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function minhasVistoriasHoje(Request $request)
    {
        // 1. Pega o ID do usuário (fiscal) que está autenticado
        $fiscalId = Auth::id();

        // 2. Busca na tabela 'agenda' as vistorias que:
        //    - Pertencem ao fiscal logado (fiscal_id)
        //    - A data_agendamento é a data de hoje
        $vistorias = Agenda::where('fiscal_id', $fiscalId)
                           ->whereDate('data_agendamento', Carbon::today())
                           ->orderBy('hora_agendamento', 'asc') // Opcional: ordena por hora
                           ->get();

        // 3. Retorna os resultados encontrados como uma resposta JSON
        return response()->json($vistorias);
    }

     public function gantt(Request $request)
    {
        // Valida se uma data foi enviada, se não, usa a data de hoje
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        // Busca todos os usuários que são Fiscais (ex: cargo_id = 3)
        $fiscais = User::where('cargo_id', 3)->select('id', 'nome')->get();

        // Busca todos os agendamentos para a data especificada
        $agendamentos = Agenda::whereDate('data_agendamento', $date)
            ->with('fiscal:id,nome') // Carrega a relação com o fiscal
            ->get();
            
        return response()->json([
            'resources' => $fiscais,
            'tasks' => $agendamentos,
        ]);
    }

    /**
     * Atualiza um agendamento a partir do Drag and Drop no Gantt.
     */
    public function updateGantt(Request $request, Agenda $agenda)
    {
        $validated = $request->validate([
            'fiscal_id' => 'sometimes|required|exists:users,id',
            'hora_agendamento' => 'sometimes|required|date_format:H:i',
        ]);

        $agenda->update($validated);

        return response()->json($agenda);
    }

}