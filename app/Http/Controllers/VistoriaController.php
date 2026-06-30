<?php

namespace App\Http\Controllers;

use App\Models\Agenda;
use App\Models\Vistoria;
use App\Models\VistoriaChecklistItem;
use App\Services\ActivityLogService;
use App\Services\EvidenceFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VistoriaController extends Controller
{
    private function getNaoConformeValues(): array
    {
        return ['Não Conforme', 'Nao Conforme', 'NÃ£o Conforme'];
    }

    private function getEmAnaliseStatusValue(): string
    {
        try {
            $column = DB::selectOne("SHOW COLUMNS FROM vistoria_checklist_itens LIKE 'status_correcao'");
            $type = $column->Type ?? '';

            if (str_contains($type, 'Em AnÃ¡lise')) {
                return 'Em AnÃ¡lise';
            }

            if (str_contains($type, 'Em Análise')) {
                return 'Em Análise';
            }
        } catch (\Throwable $e) {
            // fallback
        }

        return 'Em Análise';
    }

    private function getEmAnaliseStatusCandidates(): array
    {
        return array_values(array_unique([
            'Em Análise',
            'Em AnÃ¡lise',
            $this->getEmAnaliseStatusValue(),
        ]));
    }

    /**
     * Armazena uma nova vistoria completa vinda do fiscal.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'agenda_id'        => 'required|exists:agenda,id',
            'tipo'             => 'required|string|in:completa,externa,interna',
            'metros_drop'      => 'nullable|integer|min:0',
            'retorno_tecnico'  => 'nullable|string|max:100',
            'observacoes_gerais' => 'nullable|string',
            'checklist'        => 'required|array',
            'checklist.*.status'    => 'required|string|in:Conforme,Não Conforme,Nao Conforme,Não se Aplica,Nao se Aplica,NÃ£o Conforme,NÃ£o se Aplica',
            'checklist.*.observacao' => 'nullable|string|max:1000',
            'checklist.*.foto'      => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        $agenda = Agenda::findOrFail($validatedData['agenda_id']);

        $missingEvidence = [];
        foreach ($validatedData['checklist'] as $key => $itemData) {
            if (in_array($itemData['status'], $this->getNaoConformeValues(), true)
                && !$request->hasFile("checklist.{$key}.foto")) {
                $missingEvidence["checklist.{$key}.foto"] = [
                    'A foto e obrigatoria para respostas nao conformes.',
                ];
            }
        }

        if (!empty($missingEvidence)) {
            return response()->json([
                'message' => 'Anexe fotos para todos os itens nao conformes.',
                'errors' => $missingEvidence,
            ], 422);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {

        $temNaoConforme = false;
        foreach ($validatedData['checklist'] as $itemData) {
            if (in_array($itemData['status'], $this->getNaoConformeValues(), true)) {
                $temNaoConforme = true;
                break;
            }
        }
        $retornoSolicitado = ($validatedData['retorno_tecnico'] ?? null) === 'Sim';
        $statusInicialLaudo = ($temNaoConforme || $retornoSolicitado) ? 'Em Correção' : 'Finalizado';

        $vistoria = Vistoria::create([
            'agenda_id'        => $agenda->id,
            'fiscal_id'        => Auth::id(),
            'tipo'             => $validatedData['tipo'],
            'metros_drop'      => $validatedData['metros_drop'] ?? null,
            'retorno_tecnico'  => $validatedData['retorno_tecnico'] ?? null,
            'observacoes_gerais' => $validatedData['observacoes_gerais'] ?? null,
            'status_laudo'     => $statusInicialLaudo,
        ]);

        foreach ($validatedData['checklist'] as $key => $itemData) {
            $fotoPath = null;
            if ($request->hasFile("checklist.{$key}.foto")) {
                $fotoPath = EvidenceFileService::storeUploaded($request->file("checklist.{$key}.foto"), 'vistorias');
                $storedPaths[] = $fotoPath;
            }

            $vistoria->checklistItens()->create([
                'item_key' => $key,
                'status' => $itemData['status'],
                'observacao' => $itemData['observacao'] ?? null,
                'foto_path' => $fotoPath,
            ]);
        }

        $agenda->status = 'Concluído';
        $agenda->statusAgendamento = 'Concluído';
        $agenda->save();

        DB::commit();

            return response()->json(['message' => 'Vistoria registrada com sucesso!'], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                EvidenceFileService::delete($path);
            }

            report($e);

            return response()->json(['message' => 'Erro ao registrar vistoria.'], 500);
        }
    }

    /**
     * Retorna a lista de vistorias com inconformidades para a tela de backlog.
     */
    public function backlog(Request $request)
    {
        $user = Auth::user();
        $empresaNome = $user->empresa?->nome ?? null;
        $naoConformeValues = $this->getNaoConformeValues();

        $query = Vistoria::query()
            ->select(['id', 'agenda_id', 'fiscal_id', 'status_laudo', 'created_at'])
            ->whereNotIn('status_laudo', ['Finalizado', 'Vencido'])
            ->withCount([
                'checklistItens as itens_nao_conformes' => function ($q) use ($naoConformeValues) {
                    $q->whereIn('status', $naoConformeValues);
                },
                'checklistItens as itens_reprovados' => function ($q) use ($naoConformeValues) {
                    $q->whereIn('status', $naoConformeValues)
                      ->where('status_correcao', 'Reprovado');
                },
                'checklistItens as itens_em_analise' => function ($q) use ($naoConformeValues) {
                    $q->whereIn('status', $naoConformeValues)
                      ->whereIn('status_correcao', $this->getEmAnaliseStatusCandidates());
                },
                'checklistItens as itens_pendentes_resposta' => function ($q) use ($naoConformeValues) {
                    $q->whereIn('status', $naoConformeValues)
                      ->where('status_correcao', 'Pendente');
                },
            ]);

        $concluidosQuery = Vistoria::query()->where('status_laudo', 'Finalizado');

        if ($user->cargo_id != 1) {
            if (!$empresaNome) {
                return response()->json([
                    'tableData' => collect(),
                    'kpiData' => [
                        'totalBacklog' => 0,
                        'slaVencido' => 0,
                        'concluidos' => 0,
                    ],
                    'message' => 'Usuario sem empresa vinculada',
                ]);
            }

            $query->whereHas('agenda', function ($q) use ($empresaNome) {
                $q->where('empresa_tecnico', $empresaNome);
            });

            $concluidosQuery->whereHas('agenda', function ($q) use ($empresaNome) {
                $q->where('empresa_tecnico', $empresaNome);
            });
        }

        $vistorias = $query->with([
            'fiscal:id,nome,empresa_id',
            'fiscal.empresa:id,nome',
            'agenda:id,numero_compromisso,empresa_tecnico,nome_tecnico,territorio,created_at',
        ])->latest()->get();

        $formattedData = $vistorias->map(function ($vistoria) {
            $dataLaudo = $vistoria->created_at?->toDateString();
            $dataSla = $vistoria->created_at?->copy()->addDays(5)?->toDateString();
            $slaStatus = ($vistoria->created_at && now()->gt($vistoria->created_at->copy()->addDays(5)))
                ? 'Vencido'
                : 'No Prazo';

            $correcaoStatus = 'Sem pendencia';
            if (($vistoria->itens_em_analise ?? 0) > 0) {
                $correcaoStatus = 'Respondido (em analise)';
            } elseif (($vistoria->itens_reprovados ?? 0) > 0 || ($vistoria->itens_pendentes_resposta ?? 0) > 0) {
                $correcaoStatus = 'Aguardando resposta';
            } elseif (($vistoria->itens_nao_conformes ?? 0) > 0) {
                $correcaoStatus = 'Aguardando resposta';
            }

            return [
                'id' => $vistoria->id,
                'regional' => $vistoria->agenda?->empresa_tecnico ?? 'N/A',
                'empresa' => $vistoria->agenda?->empresa_tecnico ?? 'N/A',
                'tecnico' => $vistoria->agenda?->nome_tecnico ?? 'N/A',
                'fiscal' => $vistoria->fiscal?->nome ?? 'N/A',
                'supervisor' => 'N/A',
                'protocolo' => $vistoria->agenda?->numero_compromisso ?? 'N/A',
                'territorio' => $vistoria->agenda?->territorio ?? 'N/A',
                'data' => $dataLaudo,
                'dataSla' => $dataSla,
                'sla' => $slaStatus,
                'statusLaudo' => $vistoria->status_laudo,
                'reprovada' => ($vistoria->itens_reprovados ?? 0) > 0,
                'correcaoStatus' => $correcaoStatus,
            ];
        });

        $kpiData = [
            'totalBacklog' => $formattedData->count(),
            'slaVencido' => $formattedData->where('sla', 'Vencido')->count(),
            'concluidos' => $concluidosQuery->count(),
        ];

        return response()->json([
            'tableData' => $formattedData,
            'kpiData' => $kpiData,
        ]);
    }

    /**
     * Exibe os detalhes de uma vistoria especifica.
     */
    public function show(Vistoria $vistoria)
    {
        $vistoria->load(['fiscal:id,nome', 'agenda:id,numero_compromisso,nome_conta,endereco', 'checklistItens']);
        return response()->json($vistoria);
    }

    /**
     * Recebe a correcao de um item do checklist (foto e observacao).
     */
    public function resolverItem(Request $request, VistoriaChecklistItem $item)
    {
        $request->validate([
            'foto_correcao' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'observacao_correcao' => 'nullable|string|max:1000',
        ]);

        $oldPath = $item->foto_correcao_path;
        $path = EvidenceFileService::storeUploaded($request->file('foto_correcao'), 'correcoes');

        try {
            $item->update([
                'foto_correcao_path' => $path,
                'observacao_correcao' => $request->observacao_correcao,
                'status_correcao' => $this->getEmAnaliseStatusValue(),
            ]);
        } catch (\Throwable $e) {
            EvidenceFileService::delete($path);
            throw $e;
        }

        if ($oldPath && $oldPath !== $path) {
            EvidenceFileService::delete($oldPath);
        }

        ActivityLogService::log(
            'checklist_item.response_submitted',
            "Resposta enviada para item {$item->id} da vistoria {$item->vistoria_id}",
            Auth::id(),
            [
                'vistoria_id' => $item->vistoria_id,
                'checklist_item_id' => $item->id,
                'status_correcao' => $this->getEmAnaliseStatusValue(),
            ],
            VistoriaChecklistItem::class,
            $item->id
        );

        return response()->json($item);
    }

    /**
     * Recebe a avaliacao (Aprovado/Reprovado) de um administrador para um item corrigido.
     */
    public function avaliarItem(Request $request, VistoriaChecklistItem $item)
    {
        if (Auth::user()->cargo_id != 1) {
            return response()->json(['message' => 'Nao autorizado'], 403);
        }

        $request->validate(['status' => 'required|in:Aprovado,Reprovado']);

        $item->update(['status_correcao' => $request->status]);

        ActivityLogService::log(
            'checklist_item.reviewed',
            "Item {$item->id} da vistoria {$item->vistoria_id} avaliado como {$request->status}",
            Auth::id(),
            [
                'vistoria_id' => $item->vistoria_id,
                'checklist_item_id' => $item->id,
                'status_correcao' => $request->status,
            ],
            VistoriaChecklistItem::class,
            $item->id
        );

        if ($request->status === 'Aprovado') {
            $vistoria = $item->vistoria;
            $naoConformeValues = $this->getNaoConformeValues();

            $pendenciasRestantes = $vistoria->checklistItens()
                ->whereIn('status', $naoConformeValues)
                ->where('status_correcao', '!=', 'Aprovado')
                ->count();

            if ($pendenciasRestantes === 0) {
                $vistoria->update(['status_laudo' => 'Finalizado']);
            }
        }

        return response()->json($item);
    }

    /**
     * Fornece os dados completos de uma vistoria para geracao de PDF no frontend.
     */
    public function dataForPdf(Vistoria $vistoria)
    {
        $vistoria->load(['agenda', 'fiscal', 'checklistItens']);
        return response()->json($vistoria);
    }

    /**
     * Retorna uma lista de IDs de vistorias criadas dentro de um intervalo de datas.
     */
    public function getIdsByDateRange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $startDate = $request->start_date . ' 00:00:00';
        $endDate = $request->end_date . ' 23:59:59';

        $ids = Vistoria::whereBetween('created_at', [$startDate, $endDate])->pluck('id');

        return response()->json($ids);
    }
}
