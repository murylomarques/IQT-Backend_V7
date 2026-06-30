<?php

namespace App\Http\Controllers;

use App\Models\AgendaManutencao;
use App\Models\BaseManutencao;
use App\Models\Regional;
use App\Models\User;
use App\Models\VistoriaManutencao;
use App\Models\VistoriaManutencaoChecklistItem;
use App\Services\EvidenceFileService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ManutencaoController extends Controller
{
    private function getNaoConformeValues(): array
    {
        return ['Não Conforme', 'Nao Conforme'];
    }

    private function getNaoValues(): array
    {
        return ['Não', 'Nao'];
    }

    private function normalizeAnswer(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(Str::ascii($value ?? ''))));
    }

    private function applyIssueFilter($query)
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('status', $this->getNaoConformeValues())
                ->orWhere(function (Builder $sub) {
                    $sub->whereIn('item_key', ['riscos_qualidade_interrupcao', 'emenda_cabo_drop'])
                        ->where('status', 'Sim');
                })
                ->orWhere(function (Builder $sub) {
                    $sub->where('item_key', 'cliente_satisfeito_atendimento')
                        ->whereIn('status', $this->getNaoValues());
                })
                ->orWhere(function (Builder $sub) {
                    $sub->where('item_key', 'retorno_tecnico')
                        ->where('status', 'Sim');
                });
        });
    }

    private function isIssueItem(string $key, ?string $status): bool
    {
        $normalizedStatus = $this->normalizeAnswer($status);

        if ($normalizedStatus === 'nao conforme') {
            return true;
        }

        if (in_array($key, ['riscos_qualidade_interrupcao', 'emenda_cabo_drop', 'retorno_tecnico'], true)
            && $normalizedStatus === 'sim') {
            return true;
        }

        return $key === 'cliente_satisfeito_atendimento'
            && $normalizedStatus === 'nao';
    }

    private function isRetornoTecnicoSolicitado(?string $value): bool
    {
        return $this->normalizeAnswer($value) === 'sim';
    }

    private function markOverdueBacklogItems(): void
    {
        VistoriaManutencao::query()
            ->where('status_laudo', '!=', 'Finalizado')
            ->where('created_at', '<', now()->subHours(72))
            ->update(['status_laudo' => 'Vencido']);
    }

    public function atendimentos(Request $request)
    {
        $today = Carbon::today()->toDateString();

        $globalStats = [
            'total_agendamentos' => AgendaManutencao::count(),
            'pendentes_hoje' => AgendaManutencao::whereDate('data_agendamento', $today)
                ->where('statusAgendamento', 'Pendente')
                ->count(),
            'total_concluidos' => AgendaManutencao::where('statusAgendamento', 'Concluído')->count(),
        ];

        $fiscais = User::where('cargo_id', 3)->select('id', 'nome')->get();
        $agendaCounts = AgendaManutencao::query()
            ->select(
                'fiscal_id',
                DB::raw("SUM(CASE WHEN DATE(data_agendamento) = '{$today}' THEN 1 ELSE 0 END) as agendados_hoje"),
                DB::raw("SUM(CASE WHEN DATE(data_agendamento) > '{$today}' THEN 1 ELSE 0 END) as agendados_futuro")
            )
            ->groupBy('fiscal_id')
            ->get()
            ->keyBy('fiscal_id');

        $fiscaisStats = $fiscais->map(function ($fiscal) use ($agendaCounts) {
            $item = $agendaCounts->get($fiscal->id);

            return [
                'nome' => $fiscal->nome,
                'agendados_hoje' => (int) ($item->agendados_hoje ?? 0),
                'agendados_futuro' => (int) ($item->agendados_futuro ?? 0),
            ];
        });

        $query = BaseManutencao::query();

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
            $query->where(function ($q) use ($request) {
                $sa = '%' . $request->query('sa') . '%';
                $q->where('numero_compromisso', 'like', $sa)
                    ->orWhere('caso', 'like', $sa);
            });
        }
        if ($request->filled('endereco')) {
            $query->where('endereco', 'like', '%' . $request->query('endereco') . '%');
        }

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $atendimentos = $query->select(
            'id as ID',
            'regional as Regional',
            'city as Cidade',
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
            'porta as Porta',
            'territorio as Territorio',
            DB::raw("COALESCE(motivo_vistoria, tipo_trabalho, tipo_servico) as Motivo")
        )
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $atendimentos->items(),
            'current_page' => $atendimentos->currentPage(),
            'last_page' => $atendimentos->lastPage(),
            'per_page' => $atendimentos->perPage(),
            'total' => $atendimentos->total(),
            'stats' => [
                'global' => $globalStats,
                'fiscais' => $fiscaisStats,
            ],
        ]);
    }

    public function showAtendimento($id)
    {
        $appointment = BaseManutencao::query()
            ->select(
                'id as ID',
                'regional as Regional',
                'city as Cidade',
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
                'porta as Porta',
                'territorio as Territorio',
                DB::raw("COALESCE(motivo_vistoria, tipo_trabalho, tipo_servico) as Motivo")
            )
            ->where('id', $id)
            ->first();

        if (!$appointment) {
            return response()->json(['message' => 'Atendimento de manutenção não encontrado'], 404);
        }

        return response()->json($appointment);
    }

    public function storeAgenda(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'atendimentoId' => 'required|exists:base_manutencao,id',
            'fiscalId' => 'required|exists:users,id',
            'data' => 'required|date_format:Y-m-d',
            'hora' => 'nullable|date_format:H:i',
            'periodo' => 'required|string|max:50',
            'observacoes' => 'nullable|string',
            'agendado' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        $tipo = $validatedData['agendado'] ? 'Agendado' : 'Não Agendado';
        $agendamento = null;

        try {
            DB::transaction(function () use ($validatedData, $tipo, &$agendamento) {
                $atendimentoOriginal = BaseManutencao::findOrFail($validatedData['atendimentoId']);

                $agendamento = AgendaManutencao::create([
                    'fiscal_id' => $validatedData['fiscalId'],
                    'data_agendamento' => $validatedData['data'],
                    'hora_agendamento' => $validatedData['hora'] ?? null,
                    'periodo' => $validatedData['periodo'],
                    'observacoes' => $validatedData['observacoes'] ?? null,
                    'tipo' => $tipo,
                    'original_atendimento_id' => $atendimentoOriginal->id,
                    'caso' => $atendimentoOriginal->caso,
                    'numero_compromisso' => $atendimentoOriginal->numero_compromisso,
                    'regional' => $atendimentoOriginal->regional,
                    'city' => $atendimentoOriginal->city,
                    'data_sa_concluida' => $atendimentoOriginal->data_sa_concluida,
                    'nome_tecnico' => $atendimentoOriginal->nome_tecnico,
                    'empresa_tecnico' => $atendimentoOriginal->empresa_tecnico,
                    'tipo_servico' => $atendimentoOriginal->tipo_servico ?: 'Manutencao',
                    'motivo_vistoria' => $atendimentoOriginal->motivo_vistoria,
                    'tipo_trabalho' => $atendimentoOriginal->tipo_trabalho,
                    'status_caso' => $atendimentoOriginal->status_caso,
                    'cto' => $atendimentoOriginal->cto,
                    'porta' => $atendimentoOriginal->porta,
                    'nome_conta' => $atendimentoOriginal->nome_conta,
                    'endereco' => $atendimentoOriginal->endereco,
                    'telefone' => $atendimentoOriginal->telefone,
                    'territorio' => $atendimentoOriginal->territorio,
                ]);

                $atendimentoOriginal->delete();
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Ocorreu um erro ao processar o agendamento de manutenção.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Agendamento de manutenção criado com sucesso.',
            'data' => $agendamento,
        ], 201);
    }

    public function showAgenda(AgendaManutencao $agenda)
    {
        return response()->json($agenda);
    }

    public function minhasVistoriasHoje()
    {
        $vistorias = AgendaManutencao::where('fiscal_id', Auth::id())
            ->whereDate('data_agendamento', Carbon::today())
            ->orderBy('hora_agendamento', 'asc')
            ->get();

        return response()->json($vistorias);
    }

    public function agendaFiscal($id)
    {
        $agenda = AgendaManutencao::where('fiscal_id', $id)
            ->orderBy('data_agendamento')
            ->orderBy('hora_agendamento')
            ->get();

        return response()->json($agenda);
    }

    public function storeVistoria(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'agenda_manutencao_id' => 'required|exists:agenda_manutencao,id',
            'tipo' => 'required|string|in:completa,externa',
            'metros_drop' => 'required|integer|min:0',
            'retorno_tecnico' => 'required|string|in:Sim,Não,Nao',
            'resultado_final' => 'nullable|string|in:Aprovado,Aprovado com Ressalvas,Reprovado',
            'observacoes_gerais' => 'nullable|string',
            'checklist' => 'required|array',
            'checklist.*.status' => 'required|string|in:Conforme,Não Conforme,Nao Conforme,Não se Aplica,Nao se Aplica,Sim,Não,Nao',
            'checklist.*.observacao' => 'nullable|string|max:1000',
            'checklist.*.foto' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        $agenda = AgendaManutencao::findOrFail($validatedData['agenda_manutencao_id']);
        $storedPaths = [];

        $missingEvidence = [];
        foreach ($validatedData['checklist'] as $key => $itemData) {
            if ($this->isIssueItem($key, $itemData['status'] ?? null)
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

        DB::beginTransaction();

        try {
            $temPendencia = false;
            foreach ($validatedData['checklist'] as $key => $itemData) {
                if ($this->isIssueItem($key, $itemData['status'] ?? null)) {
                    $temPendencia = true;
                    break;
                }
            }

            $retornoSolicitado = $this->isRetornoTecnicoSolicitado($validatedData['retorno_tecnico']);
            $resultadoFinal = $temPendencia
                ? 'Reprovado'
                : ($retornoSolicitado ? 'Aprovado com Ressalvas' : 'Aprovado');
            $statusLaudo = ($temPendencia || $retornoSolicitado) ? 'Em Correção' : 'Finalizado';

            $vistoria = VistoriaManutencao::create([
                'agenda_manutencao_id' => $agenda->id,
                'fiscal_id' => Auth::id(),
                'tipo' => $validatedData['tipo'],
                'metros_drop' => $validatedData['metros_drop'],
                'retorno_tecnico' => $validatedData['retorno_tecnico'],
                'resultado_final' => $resultadoFinal,
                'observacoes_gerais' => $validatedData['observacoes_gerais'] ?? null,
                'status_laudo' => $statusLaudo,
            ]);

            foreach ($validatedData['checklist'] as $key => $itemData) {
                $fotoPath = null;
                if ($request->hasFile("checklist.{$key}.foto")) {
                    $fotoPath = EvidenceFileService::storeUploaded($request->file("checklist.{$key}.foto"), 'vistorias-manutencao');
                    $storedPaths[] = $fotoPath;
                }

                $vistoria->checklistItens()->create([
                    'item_key' => $key,
                    'status' => $itemData['status'],
                    'observacao' => $itemData['observacao'] ?? null,
                    'foto_path' => $fotoPath,
                ]);
            }

            if ($retornoSolicitado) {
                $vistoria->checklistItens()->create([
                    'item_key' => 'retorno_tecnico',
                    'status' => 'Sim',
                    'observacao' => 'Retorno do tecnico solicitado pelo fiscal.',
                    'foto_path' => null,
                ]);
            }

            $agenda->update([
                'status' => 'Concluído',
                'statusAgendamento' => 'Concluído',
                'statusLaudo' => ($temPendencia || $retornoSolicitado) ? 'Reprovado' : 'Concluído',
            ]);

            DB::commit();

            return response()->json(['message' => 'Vistoria de manutenção registrada com sucesso.'], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                EvidenceFileService::delete($path);
            }

            report($e);

            return response()->json(['message' => 'Erro ao registrar vistoria de manutenção.'], 500);
        }
    }

    public function backlog()
    {
        $this->markOverdueBacklogItems();

        $user = Auth::user();
        $empresaNome = $user->empresa?->nome ?? null;

        $query = VistoriaManutencao::query()
            ->select(['id', 'agenda_manutencao_id', 'fiscal_id', 'retorno_tecnico', 'resultado_final', 'status_laudo', 'created_at'])
            ->where('status_laudo', '!=', 'Finalizado')
            ->withCount([
                'checklistItens as itens_nao_conformes' => function (Builder $q) {
                    $this->applyIssueFilter($q);
                },
                'checklistItens as itens_reprovados' => function (Builder $q) {
                    $this->applyIssueFilter($q)->where('status_correcao', 'Reprovado');
                },
                'checklistItens as itens_em_analise' => function (Builder $q) {
                    $this->applyIssueFilter($q)->where('status_correcao', 'Em Análise');
                },
                'checklistItens as itens_pendentes_resposta' => function (Builder $q) {
                    $this->applyIssueFilter($q)->where('status_correcao', 'Pendente');
                },
            ]);

        $concluidosQuery = VistoriaManutencao::query()->where('status_laudo', 'Finalizado');

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

            // Segmentação por regional: se o usuário tem regional vinculada, filtra por ela
            if ($user->regional_id) {
                $regional = Regional::find($user->regional_id);
                if ($regional) {
                    $regionalNome = $regional->nome;
                    $query->whereHas('agenda', function ($q) use ($regionalNome) {
                        $q->where('regional', $regionalNome);
                    });
                    $concluidosQuery->whereHas('agenda', function ($q) use ($regionalNome) {
                        $q->where('regional', $regionalNome);
                    });
                }
            }
        }

        $vistorias = $query->with([
            'fiscal:id,nome,empresa_id',
            'fiscal.empresa:id,nome',
            'agenda:id,numero_compromisso,caso,empresa_tecnico,nome_tecnico,territorio,regional,city,created_at',
        ])->latest()->get();

        $formattedData = $vistorias->map(function ($vistoria) {
            $dataLaudo = $vistoria->created_at?->format('Y-m-d H:i');
            $deadline = $vistoria->created_at?->copy()->addHours(72);
            $slaStatus = ($vistoria->status_laudo === 'Vencido' || ($deadline && now()->gt($deadline))) ? 'Vencido' : 'No Prazo';

            $correcaoStatus = 'Sem pendencia';
            if (($vistoria->itens_em_analise ?? 0) > 0) {
                $correcaoStatus = 'Respondido (em analise)';
            } elseif (($vistoria->itens_reprovados ?? 0) > 0 || ($vistoria->itens_pendentes_resposta ?? 0) > 0) {
                $correcaoStatus = 'Aguardando resposta';
            } elseif (($vistoria->itens_nao_conformes ?? 0) > 0) {
                $correcaoStatus = 'Aguardando resposta';
            } elseif ($this->isRetornoTecnicoSolicitado($vistoria->retorno_tecnico)) {
                $correcaoStatus = 'Retorno tecnico solicitado';
            }

            return [
                'id' => $vistoria->id,
                'regional' => $vistoria->agenda?->regional ?? 'N/A',
                'empresa' => $vistoria->agenda?->empresa_tecnico ?? 'N/A',
                'tecnico' => $vistoria->agenda?->nome_tecnico ?? 'N/A',
                'fiscal' => $vistoria->fiscal?->nome ?? 'N/A',
                'supervisor' => 'N/A',
                'protocolo' => $vistoria->agenda?->numero_compromisso ?? $vistoria->agenda?->caso ?? 'N/A',
                'territorio' => $vistoria->agenda?->territorio ?? $vistoria->agenda?->regional ?? 'N/A',
                'cidade' => $vistoria->agenda?->city ?? 'N/A',
                'data' => $dataLaudo,
                'dataSla' => $deadline?->format('Y-m-d H:i'),
                'sla' => $slaStatus,
                'statusLaudo' => $vistoria->status_laudo,
                'resultadoFinal' => $vistoria->resultado_final,
                'retornoTecnico' => $vistoria->retorno_tecnico,
                'reprovada' => ($vistoria->itens_reprovados ?? 0) > 0,
                'correcaoStatus' => $correcaoStatus,
            ];
        });

        return response()->json([
            'tableData' => $formattedData,
            'kpiData' => [
                'totalBacklog' => $formattedData->count(),
                'slaVencido' => $formattedData->where('sla', 'Vencido')->count(),
                'concluidos' => $concluidosQuery->count(),
            ],
        ]);
    }

    public function showVistoria(VistoriaManutencao $vistoria)
    {
        $vistoria->load([
            'fiscal:id,nome',
            'agenda:id,numero_compromisso,caso,nome_conta,endereco,nome_tecnico,empresa_tecnico,regional,city,motivo_vistoria,tipo_trabalho',
            'checklistItens',
        ]);

        return response()->json($vistoria);
    }

    public function resolverItem(Request $request, VistoriaManutencaoChecklistItem $item)
    {
        $request->validate([
            'foto_correcao' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'observacao_correcao' => 'nullable|string|max:1000',
        ]);

        $oldPath = $item->foto_correcao_path;
        $path = EvidenceFileService::storeUploaded($request->file('foto_correcao'), 'correcoes-manutencao');

        try {
            $item->update([
                'foto_correcao_path' => $path,
                'observacao_correcao' => $request->observacao_correcao,
                'status_correcao' => 'Em Análise',
            ]);
        } catch (\Throwable $e) {
            EvidenceFileService::delete($path);
            throw $e;
        }

        if ($oldPath && $oldPath !== $path) {
            EvidenceFileService::delete($oldPath);
        }

        return response()->json($item);
    }

    public function avaliarItem(Request $request, VistoriaManutencaoChecklistItem $item)
    {
        if (Auth::user()->cargo_id != 1) {
            return response()->json(['message' => 'Nao autorizado'], 403);
        }

        $request->validate(['status' => 'required|in:Aprovado,Reprovado']);

        $item->update(['status_correcao' => $request->status]);

        if ($request->status === 'Aprovado') {
            $vistoria = $item->vistoria;

            $pendenciasRestantes = $this->applyIssueFilter($vistoria->checklistItens())
                ->where('status_correcao', '!=', 'Aprovado')
                ->count();

            if ($pendenciasRestantes === 0) {
                $vistoria->update(['status_laudo' => 'Finalizado']);
            }
        }

        return response()->json($item);
    }

    public function dataForPdf(VistoriaManutencao $vistoria)
    {
        $vistoria->load(['agenda', 'fiscal', 'checklistItens']);
        return response()->json($vistoria);
    }

    public function getIdsByDateRange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $ids = VistoriaManutencao::query()
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59',
            ])
            ->pluck('id');

        return response()->json($ids);
    }

    public function updateGantt(Request $request, AgendaManutencao $agenda)
    {
        $validated = $request->validate([
            'data_agendamento' => 'sometimes|required|date_format:Y-m-d',
            'fiscal_id' => 'sometimes|required|exists:users,id',
            'hora_agendamento' => 'sometimes|nullable|date_format:H:i',
        ]);

        DB::transaction(function () use ($agenda, $validated) {
            $agenda->vistorias()->get()->each->delete();

            $agenda->update(array_merge($validated, [
                'status' => 'Agendado',
                'statusAgendamento' => 'Pendente',
                'statusLaudo' => 'Pendente',
            ]));
        });

        $agenda->load('fiscal:id,nome');

        return response()->json($agenda);
    }

    public function destroyAgenda(AgendaManutencao $agenda)
    {
        $agenda->delete();

        return response()->json(['message' => 'Agendamento de manutencao removido com sucesso.']);
    }

    public function ganttManutencao(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        $fiscais = User::where('cargo_id', 3)->select('id', 'nome')->get();

        $agendamentos = AgendaManutencao::whereDate('data_agendamento', $date)
            ->with('fiscal:id,nome')
            ->get();

        return response()->json([
            'resources' => $fiscais,
            'tasks' => $agendamentos,
        ]);
    }
}
