<?php

namespace App\Http\Controllers;

use App\Models\Agenda;
use App\Models\Vistoria;
use App\Models\VistoriaChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class VistoriaController extends Controller
{
    /**
     * Armazena uma nova vistoria completa vinda do fiscal.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'agenda_id' => 'required|exists:agenda,id',
            'tipo' => 'required|string|in:completa,externa,interna',
            'observacoes_gerais' => 'nullable|string',
            'checklist' => 'required|array',
            'checklist.*.status' => 'required|string|in:Conforme,Não Conforme,Não se Aplica',
            'checklist.*.observacao' => 'nullable|string|max:1000',
            'checklist.*.foto' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        $agenda = Agenda::findOrFail($validatedData['agenda_id']);

        DB::beginTransaction();
        
            // Verifica se existe algum item "Não Conforme" para definir o status inicial do laudo
            $temNaoConforme = false;
            foreach ($validatedData['checklist'] as $itemData) {
                if ($itemData['status'] === 'Não Conforme') {
                    $temNaoConforme = true;
                    break;
                }
            }
            $statusInicialLaudo = $temNaoConforme ? 'Em Correção' : 'Finalizado';

            // 1. Cria o registro principal da Vistoria com o status correto
            $vistoria = Vistoria::create([
                'agenda_id' => $agenda->id,
                'fiscal_id' => Auth::id(),
                'tipo' => $validatedData['tipo'],
                'observacoes_gerais' => $validatedData['observacoes_gerais'] ?? null,
                'status_laudo' => $statusInicialLaudo, // Status inicial definido aqui
            ]);

            // 2. Itera e salva os itens do checklist
            foreach ($validatedData['checklist'] as $key => $itemData) {
                $fotoPath = null;
                if ($request->hasFile("checklist.{$key}.foto")) {
                    $fotoPath = $request->file("checklist.{$key}.foto")->store('vistorias', 'public');
                }
                $vistoria->checklistItens()->create([
                    'item_key' => $key,
                    'status' => $itemData['status'],
                    'observacao' => $itemData['observacao'] ?? null,
                    'foto_path' => $fotoPath,
                ]);
            }

            // 3. Atualiza o status do agendamento original
            $agenda->status = 'Concluído';
            $agenda->statusAgendamento = 'Concluído';
            $agenda->save();

            DB::commit();

            return response()->json(['message' => 'Vistoria registrada com sucesso!'], 201);

       
    }

    /**
     * Retorna a lista de vistorias com inconformidades para a tela de backlog.
     */
    public function backlog(Request $request)
    {
        $user = Auth::user();

        // Filtra vistorias que estão com o laudo "Em Correção"
         $query = Vistoria::where('status_laudo', '!=', 'Finalizado');

        if ($user->cargo_id != 1) { // Apenas Admins veem tudo
            $query->whereHas('fiscal', function ($q) use ($user) {
                $q->where('empresa_id', $user->empresa_id);
            });
        }

        $vistorias = $query->with(['fiscal:id,nome,empresa_id', 'fiscal.empresa:id,nome', 'agenda:id,numero_compromisso,empresa_tecnico,territorio,created_at'])->latest()->get();

        $formattedData = $vistorias->map(function ($vistoria) {
            $dataLaudo = $vistoria->created_at->toDateString();
            $dataSla = $vistoria->created_at->addDays(5)->toDateString();
            $slaStatus = now()->gt($dataSla) ? 'Vencido' : 'No Prazo';

            return [
                'id' => $vistoria->id,
                'regional' => $vistoria->fiscal?->empresa?->nome ?? 'N/A',
                'empresa' => $vistoria->fiscal?->empresa?->nome ?? 'N/A',
                'fiscal' => $vistoria->fiscal?->nome ?? 'N/A',
                'territorio' => $vistoria->agenda?->territorio ?? 'N/A',
                'supervisor' => 'N/A',
                'protocolo' => $vistoria->agenda?->numero_compromisso ?? 'N/A',
                'data' => $dataLaudo,
                'dataSla' => $dataSla,
                'sla' => $slaStatus,
                'statusLaudo' => $vistoria->status_laudo, // Usando o status real da vistoria
            ];
        });

        $kpiData = [
            'totalBacklog' => $formattedData->count(),
            'slaVencido' => $formattedData->where('sla', 'Vencido')->count(),
            'concluidos' => Vistoria::where('status_laudo', 'Finalizado')->count(), // Exemplo de KPI de concluídos
        ];

        return response()->json(['tableData' => $formattedData, 'kpiData' => $kpiData]);
    }

    /**
     * Exibe os detalhes de uma vistoria específica.
     */
    public function show(Vistoria $vistoria)
    {
        $vistoria->load(['fiscal:id,nome', 'agenda:id,numero_compromisso,nome_conta,endereco', 'checklistItens']);
        return response()->json($vistoria);
    }

    /**
     * Recebe a correção de um item do checklist (foto e observação).
     */
    public function resolverItem(Request $request, VistoriaChecklistItem $item)
    {
        $request->validate([
            'foto_correcao' => 'required|image|max:2048',
            'observacao_correcao' => 'nullable|string|max:1000',
        ]);

        $path = $request->file('foto_correcao')->store('correcoes', 'public');

        $item->update([
            'foto_correcao_path' => $path,
            'observacao_correcao' => $request->observacao_correcao,
            'status_correcao' => 'Em Análise',
        ]);

        return response()->json($item);
    }

    /**
     * Recebe a avaliação (Aprovado/Reprovado) de um administrador para um item corrigido.
     */
    public function avaliarItem(Request $request, VistoriaChecklistItem $item)
    {
        if (Auth::user()->cargo_id != 1) { // Apenas Admins
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $request->validate(['status' => 'required|in:Aprovado,Reprovado']);

        $item->update(['status_correcao' => $request->status]);

        // LÓGICA DE FINALIZAÇÃO AUTOMÁTICA DO LAUDO
        if ($request->status === 'Aprovado') {
            $vistoria = $item->vistoria;

            // Verifica se ainda existe algum item "Não Conforme" que não esteja "Aprovado"
            $pendenciasRestantes = $vistoria->checklistItens()
                ->where('status', 'Não Conforme')
                ->where('status_correcao', '!=', 'Aprovado')
                ->count();
            
            // Se não houver mais pendências, finaliza o laudo
            if ($pendenciasRestantes === 0) {
                $vistoria->update(['status_laudo' => 'Finalizado']);
            }
        }

        return response()->json($item);
    }
    /**
     * Fornece os dados completos de uma vistoria para geração de PDF no frontend.
     *
     * @param  \App\Models\Vistoria  $vistoria
     * @return \Illuminate\Http\JsonResponse
     */
    public function dataForPdf(Vistoria $vistoria)
    {
        // O Laravel já encontrou a vistoria pelo ID na URL.
        // Agora, vamos carregar todas as informações relacionadas que queremos no PDF.
        // Isso é chamado de "Eager Loading" e é muito mais performático.
        $vistoria->load(['agenda', 'fiscal', 'checklistItens']);

        // Retornamos a vistoria com todas as suas relações como um JSON.
        return response()->json($vistoria);
    }

    /**
     * Retorna uma lista de IDs de vistorias criadas dentro de um intervalo de datas.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIdsByDateRange(Request $request)
    {
        // 1. Validação: Garante que as datas foram enviadas e estão no formato correto.
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Adiciona a hora final do dia para garantir que o intervalo seja inclusivo.
        $startDate = $request->start_date . ' 00:00:00';
        $endDate = $request->end_date . ' 23:59:59';

        // 2. Consulta ao Banco de Dados:
        //    - Filtra as vistorias pela coluna 'created_at' usando o intervalo de datas.
        //    - O método pluck('id') é uma otimização que retorna apenas a coluna 'id'
        //      em vez de todos os dados de cada vistoria, tornando a consulta muito rápida.
        $ids = Vistoria::whereBetween('created_at', [$startDate, $endDate])
            ->pluck('id');

        // 3. Resposta: Retorna a lista de IDs encontrados em formato JSON.
        //    O frontend receberá um array como [10, 15, 22, 45].
        return response()->json($ids);
    }
}