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
    private const ADMIN_CARGO_ID = 1;
    private const TERCEIRIZADO_CARGO_ID = 2;
    private const USUARIO_PROPRIO_CARGO_ID = 3;
    private const PROPRIO_MANUTENCAO_CARGO_ID = 4;
    private const MAINTENANCE_SLA_HOURS = 72;
    private const GENERIC_MAINTENANCE_REASON_VALUES = [
        'manutencao',
        'manutencao corretiva',
        'manutencao preventiva',
        'ativacao',
        'instalacao',
        'instalacao fibra',
        'reparo',
        'reparo prev',
        'mudanca de endereco',
        'mud end',
        'retirada',
        'outros servicos',
        'servicos adicionais',
    ];

    private function getNaoConformeValues(): array
    {
        return ['Não Conforme', 'Nao Conforme'];
    }

    private function getNaoValues(): array
    {
        return ['Não', 'Nao'];
    }

    private function normalizeReasonValue(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(Str::ascii($value ?? ''))));
    }

    private function specificReasonFromValue(?string $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        foreach (self::GENERIC_MAINTENANCE_REASON_VALUES as $generic) {
            $normalized = $this->normalizeReasonValue($text);

            if ($normalized === $generic) {
                return null;
            }

            if (str_starts_with($normalized, $generic . ' - ') || str_starts_with($normalized, $generic . ': ')) {
                return trim(preg_replace('/^[^-:]+[-:]\s*/', '', $text)) ?: null;
            }
        }

        return $text;
    }

    private function resolveMotivoVistoria($source): string
    {
        foreach ([$source?->motivo_caso, $source?->motivo_vistoria, $source?->tipo_trabalho] as $value) {
            $reason = $this->specificReasonFromValue($value);
            if ($reason !== null) {
                return $reason;
            }
        }

        return 'N/A';
    }

    private function formatMaintenanceAppointment($appointment)
    {
        $appointment->Motivo = $this->resolveMotivoVistoria($appointment);
        unset($appointment->motivo_caso, $appointment->motivo_vistoria, $appointment->tipo_trabalho);

        return $appointment;
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
                });
        });
    }

    private function isIssueItem(string $key, ?string $status): bool
    {
        $normalizedStatus = $this->normalizeAnswer($status);

        if ($normalizedStatus === 'nao conforme') {
            return true;
        }

        if (in_array($key, ['riscos_qualidade_interrupcao', 'emenda_cabo_drop'], true)
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

    private function isAdmin(User $user): bool
    {
        return (int) $user->cargo_id === self::ADMIN_CARGO_ID;
    }

    private function isTerceirizado(User $user): bool
    {
        return (int) $user->cargo_id === self::TERCEIRIZADO_CARGO_ID;
    }

    private function isUsuarioProprioManutencao(User $user): bool
    {
        return (int) $user->cargo_id === self::USUARIO_PROPRIO_CARGO_ID;
    }

    private function isProprioManutencao(User $user): bool
    {
        return (int) $user->cargo_id === self::PROPRIO_MANUTENCAO_CARGO_ID;
    }

    /**
     * "Fiscal" (USUARIO_PROPRIO_CARGO_ID) e "Usuario Proprio" (PROPRIO_MANUTENCAO_CARGO_ID)
     * compartilham o mesmo escopo de visibilidade: apenas pelo territorio do usuario.
     * A diferenca entre os dois esta em userCanSubmitMaintenanceCorrection() — apenas o
     * segundo pode enviar correcao/laudo, igual ao Terceirizado.
     */
    private function isTerritoryOnlyScopedManutencao(User $user): bool
    {
        return $this->isUsuarioProprioManutencao($user) || $this->isProprioManutencao($user);
    }

    private function userMaintenanceTerritoryName(User $user): ?string
    {
        if (!$user->regional_id) {
            return null;
        }

        return Regional::find($user->regional_id)?->nome;
    }

    /**
     * Valor livre de "territorio" (ex: "TERRITORIO CAMPINAS"), independente da Regional.
     * Regional e Territorio sao dimensoes distintas nos dados de manutencao (agenda_manutencao
     * tem colunas "regional" e "territorio" separadas), entao um usuario pode ser escopado
     * por um, pelo outro, ou pelos dois ao mesmo tempo (E logico, para maior precisao).
     */
    private function userMaintenanceTerritorioValue(User $user): ?string
    {
        $value = trim((string) ($user->territorio_manutencao ?? ''));

        return $value !== '' ? $value : null;
    }

    private function normalizeScopeValue(?string $value): string
    {
        return preg_replace('/[\s_-]+/', '', strtolower(Str::ascii(trim((string) $value)))) ?? '';
    }

    private function scopeComparisonValues(string $value): array
    {
        $trimmed = trim($value);
        $ascii = Str::ascii($trimmed);
        $territorioAccentVariant = str_replace('TERRITORIO', 'TERRITÓRIO', strtoupper($ascii));

        return array_values(array_unique(array_filter([
            $trimmed,
            $ascii,
            $territorioAccentVariant,
        ], fn ($candidate) => trim((string) $candidate) !== '')));
    }

    private function applyAgendaScopeColumnFilter(Builder $query, array $columns, string $value): Builder
    {
        $normalized = $this->normalizeScopeValue($value);
        $exactValues = $this->scopeComparisonValues($value);

        return $query->where(function (Builder $q) use ($columns, $normalized, $exactValues) {
            foreach ($columns as $column) {
                $q->orWhereRaw(
                    "LOWER(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '_', ''), '-', '')) = ?",
                    [$normalized]
                );

                $q->orWhereIn($column, $exactValues);
            }
        });
    }

    private function applyAgendaTerritoryFilter(Builder $query, string $territoryName): Builder
    {
        return $this->applyAgendaScopeColumnFilter($query, ['regional', 'territorio'], $territoryName);
    }

    private function agendaMatchesMaintenanceTerritory($agenda, string $territoryName): bool
    {
        $allowed = $this->normalizeScopeValue($territoryName);

        return in_array($allowed, [
            $this->normalizeScopeValue($agenda?->regional),
            $this->normalizeScopeValue($agenda?->territorio),
        ], true);
    }

    private function applyAgendaTerritorioColumnFilter(Builder $query, string $territorioValue): Builder
    {
        return $this->applyAgendaScopeColumnFilter($query, ['territorio'], $territorioValue);
    }

    private function agendaMatchesMaintenanceTerritorio($agenda, string $territorioValue): bool
    {
        return $this->normalizeScopeValue($agenda?->territorio) === $this->normalizeScopeValue($territorioValue);
    }

    /**
     * Territorio (territorio_manutencao) tem prioridade: se estiver preenchido, e o UNICO
     * criterio usado (Regional e ignorada). Regional so entra em jogo quando o usuario nao
     * tem territorio_manutencao definido — mantendo compatibilidade com quem so usa Regional.
     */
    private function applyMaintenanceTerritoryScope(Builder $query, ?string $territoryName, ?string $territorioValue): void
    {
        if ($territorioValue !== null) {
            $this->applyAgendaTerritorioColumnFilter($query, $territorioValue);
            return;
        }

        if ($territoryName !== null) {
            $this->applyAgendaTerritoryFilter($query, $territoryName);
        }
    }

    private function agendaMatchesMaintenanceScope($agenda, ?string $territoryName, ?string $territorioValue): bool
    {
        if ($territorioValue !== null) {
            return $this->agendaMatchesMaintenanceTerritorio($agenda, $territorioValue);
        }

        if ($territoryName !== null) {
            return $this->agendaMatchesMaintenanceTerritory($agenda, $territoryName);
        }

        return false;
    }

    private function userCanAccessMaintenanceAgenda(AgendaManutencao $agenda, User $user, bool $requireAssignment = false): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($requireAssignment) {
            // Atribuicao direta (fiscal_id) ja e suficiente: quem foi designado para
            // a agenda pode abrir/realizar a vistoria dela, independente de territorio
            // ou empresa — essas regras servem para visibilidade em listas (Backlog,
            // agenda geral), nao para bloquear quem já foi explicitamente atribuido.
            return (int) $agenda->fiscal_id === (int) $user->id;
        }

        if ($this->isTerritoryOnlyScopedManutencao($user)) {
            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);

            if ($territoryName === null && $territorioValue === null) {
                return false;
            }

            return $this->agendaMatchesMaintenanceScope($agenda, $territoryName, $territorioValue);
        }

        if ($this->isTerceirizado($user)) {
            $empresaNome = $user->empresa?->nome ?? null;
            if (!$empresaNome || ($agenda->empresa_tecnico ?? null) !== $empresaNome) {
                return false;
            }

            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);

            if ($territoryName === null && $territorioValue === null) {
                return true;
            }

            return $this->agendaMatchesMaintenanceScope($agenda, $territoryName, $territorioValue);
        }

        return false;
    }

    private function applyMaintenanceVisibilityScope(Builder $query, User $user): ?string
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        if ($this->isTerritoryOnlyScopedManutencao($user)) {
            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);
            if ($territoryName === null && $territorioValue === null) {
                return 'Usuario proprio sem territorio de manutencao vinculado';
            }

            $query->whereHas('agenda', function (Builder $q) use ($territoryName, $territorioValue) {
                $this->applyMaintenanceTerritoryScope($q, $territoryName, $territorioValue);
            });

            return null;
        }

        if ($this->isTerceirizado($user)) {
            $empresaNome = $user->empresa?->nome ?? null;
            if (!$empresaNome) {
                return 'Usuario sem empresa vinculada';
            }

            $query->whereHas('agenda', function (Builder $q) use ($empresaNome) {
                $q->where('empresa_tecnico', $empresaNome);
            });

            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);
            if ($territoryName !== null || $territorioValue !== null) {
                $query->whereHas('agenda', function (Builder $q) use ($territoryName, $territorioValue) {
                    $this->applyMaintenanceTerritoryScope($q, $territoryName, $territorioValue);
                });
            }

            return null;
        }

        return 'Perfil sem permissao para manutencao';
    }

    private function applyMaintenanceSourceVisibilityScope(Builder $query, User $user): ?string
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        if ($this->isTerritoryOnlyScopedManutencao($user)) {
            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);
            if ($territoryName === null && $territorioValue === null) {
                return 'Usuario proprio sem territorio de manutencao vinculado';
            }

            $this->applyMaintenanceTerritoryScope($query, $territoryName, $territorioValue);

            return null;
        }

        if ($this->isTerceirizado($user)) {
            $empresaNome = $user->empresa?->nome ?? null;
            if (!$empresaNome) {
                return 'Usuario sem empresa vinculada';
            }

            $query->where('empresa_tecnico', $empresaNome);

            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);
            if ($territoryName !== null || $territorioValue !== null) {
                $this->applyMaintenanceTerritoryScope($query, $territoryName, $territorioValue);
            }

            return null;
        }

        return 'Perfil sem permissao para manutencao';
    }

    private function applyMaintenanceAgendaVisibilityScope(Builder $query, User $user): ?string
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        if ($this->isTerritoryOnlyScopedManutencao($user)) {
            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);
            if ($territoryName === null && $territorioValue === null) {
                return 'Usuario proprio sem territorio de manutencao vinculado';
            }

            $this->applyMaintenanceTerritoryScope($query, $territoryName, $territorioValue);

            return null;
        }

        if ($this->isTerceirizado($user)) {
            $empresaNome = $user->empresa?->nome ?? null;
            if (!$empresaNome) {
                return 'Usuario sem empresa vinculada';
            }

            $query->where('empresa_tecnico', $empresaNome);

            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);
            if ($territoryName !== null || $territorioValue !== null) {
                $this->applyMaintenanceTerritoryScope($query, $territoryName, $territorioValue);
            }

            return null;
        }

        return 'Perfil sem permissao para manutencao';
    }

    private function userCanAccessMaintenanceVistoria(VistoriaManutencao $vistoria, User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $vistoria->loadMissing('agenda');
        $agenda = $vistoria->agenda;

        if ($this->isTerritoryOnlyScopedManutencao($user)) {
            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);

            if ($territoryName === null && $territorioValue === null) {
                return false;
            }

            return $this->agendaMatchesMaintenanceScope($agenda, $territoryName, $territorioValue);
        }

        if ($this->isTerceirizado($user)) {
            $empresaNome = $user->empresa?->nome ?? null;
            if (!$empresaNome || ($agenda?->empresa_tecnico ?? null) !== $empresaNome) {
                return false;
            }

            $territoryName = $this->userMaintenanceTerritoryName($user);
            $territorioValue = $this->userMaintenanceTerritorioValue($user);

            if ($territoryName === null && $territorioValue === null) {
                return true;
            }

            return $this->agendaMatchesMaintenanceScope($agenda, $territoryName, $territorioValue);
        }

        return false;
    }

    private function userCanSubmitMaintenanceCorrection(VistoriaManutencaoChecklistItem $item, User $user): bool
    {
        $item->loadMissing('vistoria.agenda');

        return ($this->isAdmin($user) || $this->isTerceirizado($user) || $this->isProprioManutencao($user))
            && $this->userCanAccessMaintenanceVistoria($item->vistoria, $user);
    }

    private function denyMaintenanceAccessResponse()
    {
        return response()->json(['message' => 'Nao autorizado para esta vistoria de manutencao'], 403);
    }

    private function markOverdueBacklogItems(): void
    {
        VistoriaManutencao::query()
            ->where('status_laudo', '!=', 'Finalizado')
            ->where('created_at', '<=', now()->subHours(self::MAINTENANCE_SLA_HOURS))
            ->update(['status_laudo' => 'Vencido']);
    }

    public function atendimentos(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $user = Auth::user();
        $globalStatsQuery = AgendaManutencao::query();
        $agendaCountsQuery = AgendaManutencao::query();
        $agendaScopeError = $this->applyMaintenanceAgendaVisibilityScope($globalStatsQuery, $user)
            ?? $this->applyMaintenanceAgendaVisibilityScope($agendaCountsQuery, $user);

        $globalStats = $agendaScopeError ? [
            'total_agendamentos' => 0,
            'pendentes_hoje' => 0,
            'total_concluidos' => 0,
        ] : [
            'total_agendamentos' => (clone $globalStatsQuery)->count(),
            'pendentes_hoje' => (clone $globalStatsQuery)
                ->whereDate('data_agendamento', $today)
                ->where('statusAgendamento', 'Pendente')
                ->count(),
            'total_concluidos' => (clone $globalStatsQuery)->where('statusAgendamento', 'Concluído')->count(),
        ];

        $agendaCounts = $agendaScopeError ? collect() : $agendaCountsQuery
            ->select(
                'fiscal_id',
                DB::raw("SUM(CASE WHEN DATE(data_agendamento) = '{$today}' THEN 1 ELSE 0 END) as agendados_hoje"),
                DB::raw("SUM(CASE WHEN DATE(data_agendamento) > '{$today}' THEN 1 ELSE 0 END) as agendados_futuro")
            )
            ->groupBy('fiscal_id')
            ->get()
            ->keyBy('fiscal_id');

        $fiscaisQuery = User::where('cargo_id', 3)->select('id', 'nome');
        if (!$this->isAdmin($user)) {
            $fiscaisQuery->whereIn('id', $agendaCounts->keys()->all());
        }

        $fiscaisStats = $fiscaisQuery->get()->map(function ($fiscal) use ($agendaCounts) {
            $item = $agendaCounts->get($fiscal->id);

            return [
                'nome' => $fiscal->nome,
                'agendados_hoje' => (int) ($item->agendados_hoje ?? 0),
                'agendados_futuro' => (int) ($item->agendados_futuro ?? 0),
            ];
        });

        $query = BaseManutencao::query();
        $scopeError = $this->applyMaintenanceSourceVisibilityScope($query, $user);
        if ($scopeError) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $request->query('per_page', 50),
                'total' => 0,
                'stats' => [
                    'global' => $globalStats,
                    'fiscais' => $fiscaisStats,
                ],
                'message' => $scopeError,
            ]);
        }

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
            'motivo_caso',
            'motivo_vistoria',
            'tipo_trabalho'
        )
            ->orderBy('id')
            ->paginate($perPage);

        $data = collect($atendimentos->items())
            ->map(fn ($appointment) => $this->formatMaintenanceAppointment($appointment))
            ->values();

        return response()->json([
            'data' => $data,
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
        $query = BaseManutencao::query()
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
                'motivo_caso',
                'motivo_vistoria',
                'tipo_trabalho'
            )
            ->where('id', $id);

        $scopeError = $this->applyMaintenanceSourceVisibilityScope($query, Auth::user());
        if ($scopeError) {
            return response()->json(['message' => $scopeError], 403);
        }

        $appointment = $query->first();

        if (!$appointment) {
            return response()->json(['message' => 'Atendimento de manutenção não encontrado'], 404);
        }

        $this->formatMaintenanceAppointment($appointment);

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
        $atendimentoQuery = BaseManutencao::whereKey($validatedData['atendimentoId']);
        $scopeError = $this->applyMaintenanceSourceVisibilityScope($atendimentoQuery, Auth::user());
        if ($scopeError) {
            return response()->json(['message' => $scopeError], 403);
        }

        $atendimentoOriginal = $atendimentoQuery->first();
        if (!$atendimentoOriginal) {
            return response()->json(['message' => 'Atendimento de manutencao nao encontrado ou fora do territorio permitido'], 404);
        }

        try {
            DB::transaction(function () use ($validatedData, $tipo, &$agendamento, $atendimentoOriginal) {
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
                    'motivo_caso' => $atendimentoOriginal->motivo_caso,
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
        if (!$this->userCanAccessMaintenanceAgenda($agenda, Auth::user(), true)) {
            return response()->json(['message' => 'Nao autorizado para esta agenda de manutencao'], 403);
        }

        $agenda->setAttribute('motivo_vistoria_resolvido', $this->resolveMotivoVistoria($agenda));

        return response()->json($agenda);
    }

    public function minhasVistoriasHoje()
    {
        // Filtra direto por fiscal_id = usuario logado — atribuicao direta ja e a
        // autorizacao (mesma regra de userCanAccessMaintenanceAgenda com requireAssignment).
        // Nao aplica escopo de territorio/empresa aqui: quem foi atribuido a uma agenda
        // deve conseguir realizar a vistoria dela, mesmo que o territorio/empresa dele
        // nao bata (isso so importa para visibilidade ampla, como o Backlog).
        $vistorias = AgendaManutencao::where('fiscal_id', Auth::id())
            ->whereDate('data_agendamento', Carbon::today())
            ->orderBy('hora_agendamento', 'asc')
            ->get();

        return response()->json($vistorias);
    }

    public function agendaFiscal($id)
    {
        $query = AgendaManutencao::where('fiscal_id', $id)
            ->orderBy('data_agendamento')
            ->orderBy('hora_agendamento');

        $scopeError = $this->applyMaintenanceAgendaVisibilityScope($query, Auth::user());
        if ($scopeError) {
            return response()->json(['message' => $scopeError], 403);
        }

        $agenda = $query->get();

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
        $user = Auth::user();

        if (!$this->userCanAccessMaintenanceAgenda($agenda, $user, true)) {
            return response()->json(['message' => 'Nao autorizado para esta agenda de manutencao'], 403);
        }

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

        $query = VistoriaManutencao::query()
            ->select(['id', 'agenda_manutencao_id', 'fiscal_id', 'resultado_final', 'status_laudo', 'created_at'])
            ->whereNotIn('status_laudo', ['Finalizado', 'Vencido'])
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
        $vencidosQuery = VistoriaManutencao::query()->where('status_laudo', 'Vencido');

        $scopeError = $this->applyMaintenanceVisibilityScope($query, $user)
            ?? $this->applyMaintenanceVisibilityScope($concluidosQuery, $user)
            ?? $this->applyMaintenanceVisibilityScope($vencidosQuery, $user);

        if ($scopeError) {
            return response()->json([
                'tableData' => collect(),
                'kpiData' => [
                    'totalBacklog' => 0,
                    'slaVencido' => 0,
                    'concluidos' => 0,
                ],
                'message' => $scopeError,
            ]);
        }

        $vistorias = $query->with([
            'fiscal:id,nome,empresa_id',
            'fiscal.empresa:id,nome',
            'agenda:id,numero_compromisso,caso,empresa_tecnico,nome_tecnico,territorio,regional,city,created_at',
        ])->latest()->get();

        $formattedData = $vistorias->map(function ($vistoria) {
            $dataLaudo = $vistoria->created_at?->format('Y-m-d H:i');
            $deadline = $vistoria->created_at?->copy()->addHours(self::MAINTENANCE_SLA_HOURS);
            $slaStatus = ($vistoria->status_laudo === 'Vencido' || ($deadline && now()->gte($deadline))) ? 'Vencido' : 'No Prazo';

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
                'reprovada' => ($vistoria->itens_reprovados ?? 0) > 0,
                'correcaoStatus' => $correcaoStatus,
            ];
        });

        return response()->json([
            'tableData' => $formattedData,
            'kpiData' => [
                'totalBacklog' => $formattedData->count(),
                'slaVencido' => $vencidosQuery->count(),
                'concluidos' => $concluidosQuery->count(),
            ],
        ]);
    }

    public function showVistoria(VistoriaManutencao $vistoria)
    {
        if (!$this->userCanAccessMaintenanceVistoria($vistoria, Auth::user())) {
            return $this->denyMaintenanceAccessResponse();
        }

        $vistoria->load([
            'fiscal:id,nome',
            'agenda:id,numero_compromisso,caso,nome_conta,endereco,nome_tecnico,empresa_tecnico,regional,city,motivo_caso,motivo_vistoria,tipo_trabalho,tipo_servico',
            'checklistItens',
        ]);
        $vistoria->agenda?->setAttribute('motivo_vistoria_resolvido', $this->resolveMotivoVistoria($vistoria->agenda));

        return response()->json($vistoria);
    }

    public function resolverItem(Request $request, VistoriaManutencaoChecklistItem $item)
    {
        if (!$this->userCanSubmitMaintenanceCorrection($item, Auth::user())) {
            return $this->denyMaintenanceAccessResponse();
        }

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
        if (!$this->userCanAccessMaintenanceVistoria($vistoria, Auth::user())) {
            return $this->denyMaintenanceAccessResponse();
        }

        $vistoria->load(['agenda', 'fiscal', 'checklistItens']);
        $vistoria->agenda?->setAttribute('motivo_vistoria_resolvido', $this->resolveMotivoVistoria($vistoria->agenda));

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

        $query = VistoriaManutencao::query()
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59',
            ]);

        $scopeError = $this->applyMaintenanceVisibilityScope($query, Auth::user());
        if ($scopeError) {
            return response()->json(['message' => $scopeError], 403);
        }

        $ids = $query->pluck('id');

        return response()->json($ids);
    }

    public function updateGantt(Request $request, AgendaManutencao $agenda)
    {
        if (!$this->userCanAccessMaintenanceAgenda($agenda, Auth::user())) {
            return response()->json(['message' => 'Nao autorizado para esta agenda de manutencao'], 403);
        }

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
        if (!$this->userCanAccessMaintenanceAgenda($agenda, Auth::user())) {
            return response()->json(['message' => 'Nao autorizado para esta agenda de manutencao'], 403);
        }

        $agenda->delete();

        return response()->json(['message' => 'Agendamento de manutencao removido com sucesso.']);
    }

    public function ganttManutencao(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today();
        $user = Auth::user();

        $fiscaisQuery = User::where('cargo_id', 3)->select('id', 'nome');
        if (!$this->isAdmin($user)) {
            if ($user->regional_id) {
                $fiscaisQuery->where('regional_id', $user->regional_id);
            } else {
                $fiscaisQuery->where('id', $user->id);
            }
        }

        $fiscais = $fiscaisQuery->get();

        $query = AgendaManutencao::whereDate('data_agendamento', $date)
            ->with('fiscal:id,nome');

        $scopeError = $this->applyMaintenanceAgendaVisibilityScope($query, $user);
        if ($scopeError) {
            return response()->json([
                'resources' => [],
                'tasks' => [],
                'message' => $scopeError,
            ], 403);
        }

        $agendamentos = $query->get();

        return response()->json([
            'resources' => $fiscais,
            'tasks' => $agendamentos,
        ]);
    }
}
