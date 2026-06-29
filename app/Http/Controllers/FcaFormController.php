<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaPeriod;
use App\Models\FcaPeriodTecnico;
use App\Models\FcaUser;
use App\Models\FcaChecklist;
use App\Models\FcaPo;

class FcaFormController extends Controller
{
    // ── GET /fcaf/period/active ───────────────────────────────────────────────
    public function activePeriod(Request $req)
    {
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(['period' => null]);

        return response()->json([
            'period' => $this->periodPayload($period),
        ]);
    }

    // ── POST /fcaf/period/upload (admin) ─────────────────────────────────────
    public function uploadBase(Request $req)
    {
        $req->validate(['file' => 'required|file|mimes:xlsx,xls']);

        $file = $req->file('file');
        $rows = $this->parseXlsx($file->getRealPath());

        // Detect header row (row with 'Nome' as first cell)
        $headerIdx = null;
        foreach ($rows as $i => $row) {
            if (isset($row[0]) && strtolower(trim((string)$row[0])) === 'nome') {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            return response()->json(['error' => 'Coluna "Nome" não encontrada na planilha.'], 422);
        }

        // Find column indices
        $header    = $rows[$headerIdx];
        $colNome   = 0;
        $colProd   = null; $colRev = null; $colTec1 = null; $colCert = null;
        foreach ($header as $i => $h) {
            $key = strtolower(trim((string)$h));
            if ($key === 'prod_bruta') $colProd = $i;
            if ($key === 'revisita')   $colRev  = $i;
            if ($key === 'tec1')       $colTec1 = $i;
            if ($key === 'certificado') $colCert = $i;
        }

        // Deactivate old periods
        FcaPeriod::where('is_active', true)->update(['is_active' => false]);

        // Detect mes from row[0] (first row, second column)
        $mes = 'N/A';
        foreach ($rows as $i => $row) {
            if ($i < $headerIdx && isset($row[1]) && !empty(trim((string)$row[1]))) {
                $mes = trim((string)$row[1]);
                break;
            }
        }

        $period = FcaPeriod::create([
            'mes'         => $mes,
            'uploaded_by' => $req->attributes->get('fca_user')->id,
            'expires_at'  => now()->addDays(25),
            'is_active'   => true,
        ]);

        $inserted = 0;
        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            $nome = isset($row[$colNome]) ? trim((string)$row[$colNome]) : '';
            if (empty($nome)) continue;

            $cert = '-';
            if ($colCert !== null && isset($row[$colCert])) {
                $cv = trim((string)$row[$colCert]);
                if (in_array($cv, ['Sim', 'Não', 'Nao'])) {
                    $cert = ($cv === 'Nao') ? 'Não' : $cv;
                }
            }

            FcaPeriodTecnico::create([
                'fca_period_id' => $period->id,
                'nome'          => $nome,
                'prod_bruta'    => ($colProd !== null && isset($row[$colProd])) ? (float)$row[$colProd] : null,
                'revisita'      => ($colRev  !== null && isset($row[$colRev]))  ? (float)$row[$colRev]  : null,
                'tec1'          => ($colTec1 !== null && isset($row[$colTec1])) ? (float)$row[$colTec1] : null,
                'certificado'   => $cert,
            ]);
            $inserted++;
        }

        return response()->json([
            'message'  => "Base importada: $inserted técnicos no período {$period->mes}.",
            'period'   => $this->periodPayload($period),
            'inserted' => $inserted,
        ]);
    }

    // ── GET /fcaf/tecnicos ────────────────────────────────────────────────────
    public function myTecnicos(Request $req)
    {
        $user   = $req->attributes->get('fca_user');
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json([]);

        // Technicians linked in GH under this supervisor
        $linkedUsers = FcaUser::where('manager_id', $user->id)
            ->whereIn('role', ['tecnico', 'consulta'])
            ->get()
            ->keyBy(fn($u) => $this->normalizeName($u->name));

        $tecnicos = FcaPeriodTecnico::where('fca_period_id', $period->id)
            ->whereIn(\DB::raw('UPPER(TRIM(nome))'), $linkedUsers->keys()->toArray())
            ->get();

        return response()->json(
            $tecnicos->map(fn($t) => $this->formatTecnico($t, $user, $period, $linkedUsers->get($this->normalizeName($t->nome))))
        );
    }

    // ── GET /fcaf/tecnico/{id} ────────────────────────────────────────────────
    public function tecnicoDetail(Request $req, $id)
    {
        $user   = $req->attributes->get('fca_user');
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(['error' => 'Nenhum periodo ativo.'], 422);
        $tec    = FcaPeriodTecnico::findOrFail($id);
        $tecnicoUser = FcaUser::where('manager_id', $user->id)
            ->whereIn('role', ['tecnico', 'consulta'])
            ->get()
            ->first(fn($u) => $this->normalizeName($u->name) === $this->normalizeName($tec->nome));

        return response()->json($this->formatTecnico($tec, $user, $period, $tecnicoUser));
    }

    // ── GET /fcaf/analytics ──────────────────────────────────────────────────
    public function analytics(Request $req)
    {
        $user   = $req->attributes->get('fca_user');
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) {
            return response()->json(['period' => null, 'metrics' => null, 'tecnicos' => []]);
        }

        $linkedUsers = FcaUser::where('manager_id', $user->id)
            ->whereIn('role', ['tecnico', 'consulta'])
            ->get()
            ->keyBy(fn($u) => $this->normalizeName($u->name));

        $tecnicos = FcaPeriodTecnico::where('fca_period_id', $period->id)
            ->whereIn(\DB::raw('UPPER(TRIM(nome))'), $linkedUsers->keys()->toArray())
            ->get()
            ->map(fn($t) => $this->formatTecnico($t, $user, $period, $linkedUsers->get($this->normalizeName($t->nome))));

        $metrics = $this->buildMetrics($tecnicos);

        return response()->json([
            'period'   => $this->periodPayload($period),
            'metrics'  => $metrics,
            'tecnicos' => $tecnicos->values(),
        ]);
    }

    // ── GET /fcaf/analytics/all (admin) ──────────────────────────────────────
    public function analyticsAll(Request $req)
    {
        return response()->json($this->analyticsAllPayload($req));
    }

    public function exportAnalyticsAll(Request $req)
    {
        $payload = $this->analyticsAllPayload($req);

        $lines = [];
        $lines[] = $this->csvLine([
            'id',
            'name',
            'email',
            'role',
            'manager_id',
            'supervisor_id',
            'supervisor_name',
            'supervisor_email',
            'supervisor_role',
        ]);

        foreach (($payload['supervisors'] ?? []) as $supervisor) {
            foreach (($supervisor['tecnicos'] ?? []) as $tecnico) {
                $tecnicoUser = $tecnico['tecnico_user'] ?? null;
                $supervisorData = $tecnico['supervisor'] ?? null;

                $lines[] = $this->csvLine([
                    $tecnicoUser['id'] ?? $tecnico['tecnico_user_id'] ?? $tecnico['id'] ?? '',
                    $tecnicoUser['name'] ?? $tecnico['nome'] ?? '',
                    $tecnicoUser['email'] ?? '',
                    $tecnicoUser['role'] ?? 'tecnico',
                    $tecnicoUser['manager_id'] ?? ($supervisorData['id'] ?? ''),
                    $supervisorData['id'] ?? '',
                    $supervisorData['name'] ?? '',
                    $supervisorData['email'] ?? '',
                    $supervisorData['role'] ?? '',
                ]);
            }
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="fca_extracao_' . now()->format('Ymd') . '.csv"',
        ]);
    }

    private function analyticsAllPayload(Request $req): array
    {
        $period = $this->resolvePeriod($req);
        if (!$period) {
            return [
                'period' => null,
                'metrics' => $this->buildMetrics(collect()),
                'supervisors' => [],
                'filters' => $this->filterPayload($req),
            ];
        }

        $selectedRegional = trim((string) $req->query('regional', ''));
        $selectedStatus = trim((string) $req->query('status', ''));

        $periodTecnicos = FcaPeriodTecnico::where('fca_period_id', $period->id)
            ->get()
            ->keyBy(fn($t) => $this->normalizeName($t->nome));

        $supervisors = FcaUser::where('role', 'supervisao')
            ->with('manager:id,name,role,regional,territory')
            ->orderBy('name')
            ->get();

        $result = $supervisors->map(function ($sup) use ($period, $periodTecnicos, $selectedRegional, $selectedStatus) {
            $children = FcaUser::where('manager_id', $sup->id)
                ->whereIn('role', ['tecnico', 'consulta'])
                ->orderBy('name')
                ->get();

            $tecs = $children->map(function ($child) use ($periodTecnicos, $sup, $period) {
                $periodTec = $periodTecnicos->get($this->normalizeName($child->name));
                if (!$periodTec) return null;

                return $this->formatTecnico($periodTec, $sup, $period, $child);
            })->filter()->values();

            if ($selectedRegional !== '') {
                $supervisorMatchesRegional = $this->sameValue($sup->regional, $selectedRegional);
                $tecs = $tecs->filter(fn($tec) =>
                    $supervisorMatchesRegional
                    || $this->sameValue($tec['regional'] ?? null, $selectedRegional)
                    || $this->sameValue($tec['coordenador']['regional'] ?? null, $selectedRegional)
                )->values();
            }

            if ($selectedStatus !== '') {
                $tecs = $tecs->filter(fn($tec) => ($tec['status'] ?? null) === $selectedStatus)->values();
            }

            $metrics = $this->buildMetrics($tecs);

            return [
                'id'        => $sup->id,
                'name'      => $sup->name,
                'territory' => $sup->territory,
                'regional'  => $sup->regional,
                'coordenador' => $this->formatCoordinator($sup),
                'total'     => $metrics['total'],
                'realizado' => $metrics['realizado'],
                'pendente'  => $metrics['pendente'],
                'nao_iniciado' => $metrics['nao_iniciado'],
                'em_andamento' => $metrics['em_andamento'],
                'vencido'   => $metrics['vencido'],
                'tecnicos'  => $tecs,
            ];
        })->filter(function ($sup) use ($selectedRegional, $selectedStatus) {
            return ($selectedRegional === '' && $selectedStatus === '') || $sup['total'] > 0;
        })->values();

        $allTecnicos = $result->flatMap(fn($sup) => $sup['tecnicos']);

        return [
            'period' => $this->periodPayload($period),
            'metrics' => $this->buildMetrics($allTecnicos),
            'filters' => $this->filterPayload($req),
            'supervisors' => $result,
        ];
    }

    // ── GET /fcaf/periods (admin) ─────────────────────────────────────────────
    public function periodHistory()
    {
        return response()->json(
            FcaPeriod::latest()->take(24)->get()->map(fn($p) => $this->periodPayload($p))
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function formatTecnico(FcaPeriodTecnico $t, ?FcaUser $supervisor, FcaPeriod $period, ?FcaUser $tecnicoUser = null): array
    {
        $checklists = FcaChecklist::where('fca_period_id', $period->id)
            ->where('tecnico_id', $t->id)
            ->with('supervisor:id,name,regional,territory,manager_id')
            ->orderBy('created_at')
            ->get();

        $pos = FcaPo::where('fca_period_id', $period->id)
            ->where('tecnico_id', $t->id)
            ->with('supervisor:id,name,regional,territory,manager_id')
            ->orderBy('created_at')
            ->get();

        $checklistCount = $checklists->count();
        $poCount = $pos->count();
        $distinctDays = $pos->pluck('po_date')->map(fn($d) => $d->toDateString())->unique()->count();
        $requiredPos = $t->requiredPos();
        $poProgress = $t->isCertificado() ? $poCount : $distinctDays;

        $lastChecklist = $checklists->last();
        $lastPo = $pos->last();
        $nextChecklistAt = $lastChecklist
            ? $lastChecklist->created_at->copy()->addHours(FcaPeriodTecnico::MIN_HOURS_BETWEEN_SAME_TYPE)
            : null;
        $nextPoAt = $lastPo
            ? $lastPo->created_at->copy()->addHours(FcaPeriodTecnico::MIN_HOURS_BETWEEN_SAME_TYPE)
            : null;

        $checklistMet = $checklistCount >= $requiredPos;
        $poMet = $poProgress >= $requiredPos && $poCount >= min($checklistCount, $requiredPos);
        $isComplete = $checklistMet && $poMet;
        $isExpired = $period->isExpired();
        $started = $checklistCount > 0 || $poCount > 0;

        if ($isComplete) {
            $status = 'realizado';
        } elseif ($isExpired) {
            $status = 'vencido';
        } elseif (!$started) {
            $status = 'nao_iniciado';
        } else {
            $status = 'em_andamento';
        }

        $canCreateChecklist = !$isExpired
            && $checklistCount < $requiredPos
            && (!$nextChecklistAt || now()->greaterThanOrEqualTo($nextChecklistAt));

        $canCreatePo = !$isExpired
            && $checklistCount > 0
            && $poCount < $requiredPos
            && $poCount < $checklistCount
            && (!$nextPoAt || now()->greaterThanOrEqualTo($nextPoAt));

        return [
            'id'            => $t->id,
            'nome'          => $t->nome,
            'tecnico_user_id' => $tecnicoUser?->id,
            'tecnico_user'  => $tecnicoUser ? [
                'id' => $tecnicoUser->id,
                'name' => $tecnicoUser->name,
                'email' => $tecnicoUser->email,
                'role' => $tecnicoUser->role,
                'manager_id' => $tecnicoUser->manager_id,
            ] : null,
            'regional'      => $tecnicoUser?->regional ?? $supervisor?->regional,
            'territory'     => $tecnicoUser?->territory ?? $supervisor?->territory,
            'certificado'   => $t->certificado,
            'prod_bruta'    => $t->prod_bruta,
            'revisita'      => $t->revisita,
            'tec1'          => $t->tec1,
            'isCertificado' => $t->isCertificado(),
            'has_checklist' => $checklistCount > 0,
            'checklist_count' => $checklistCount,
            'required_checklists' => $requiredPos,
            'po_count'      => $poCount,
            'po_days'       => $distinctDays,
            'po_progress'   => $poProgress,
            'required_pos'  => $requiredPos,
            'status'        => $status,
            'status_label'  => $this->statusLabel($status),
            'started'       => $started,
            'progress_text' => "{$checklistCount}/{$requiredPos} Checklists, {$poProgress}/{$requiredPos} POs",
            'period_started_at' => $period->created_at,
            'base_uploaded_at' => $period->created_at,
            'period_ends_at' => $period->expires_at,
            'last_checklist_at' => $lastChecklist?->created_at,
            'next_checklist_at' => $nextChecklistAt,
            'can_create_checklist' => $canCreateChecklist,
            'last_po_at' => $lastPo?->created_at,
            'next_po_at' => $nextPoAt,
            'can_create_po' => $canCreatePo,
            'supervisor' => $this->formatSupervisor($supervisor),
            'coordenador' => $this->formatCoordinator($supervisor),
            'checklist_dates' => $checklists->map(fn($item) => [
                'id' => $item->id,
                'created_at' => $item->created_at,
                'performed_at' => $item->created_at,
                'supervisor_id' => $item->supervisor_id,
                'supervisor_name' => $item->supervisor?->name,
            ])->values(),
            'po_dates' => $pos->map(fn($item) => [
                'id' => $item->id,
                'po_date' => $item->po_date->toDateString(),
                'created_at' => $item->created_at,
                'performed_at' => $item->po_date->toDateString(),
                'supervisor_id' => $item->supervisor_id,
                'supervisor_name' => $item->supervisor?->name,
            ])->values(),
        ];
    }

    private function resolvePeriod(Request $req): ?FcaPeriod
    {
        if ($req->filled('period_id')) {
            return FcaPeriod::find($req->query('period_id'));
        }

        return FcaPeriod::where('is_active', true)->latest()->first();
    }

    private function periodPayload(FcaPeriod $period): array
    {
        return [
            'id' => $period->id,
            'mes' => $period->mes,
            'is_active' => $period->is_active,
            'is_expired' => $period->isExpired(),
            'days_left' => $period->daysLeft(),
            'created_at' => $period->created_at,
            'starts_at' => $period->created_at,
            'base_uploaded_at' => $period->created_at,
            'expires_at' => $period->expires_at,
            'ends_at' => $period->expires_at,
            'total' => $period->tecnicos()->count(),
            'total_tecnicos' => $period->tecnicos()->count(),
        ];
    }

    private function buildMetrics($tecnicos): array
    {
        $collection = collect($tecnicos);
        $naoIniciado = $collection->where('status', 'nao_iniciado')->count();
        $emAndamento = $collection->where('status', 'em_andamento')->count();

        return [
            'total' => $collection->count(),
            'realizado' => $collection->where('status', 'realizado')->count(),
            'nao_iniciado' => $naoIniciado,
            'em_andamento' => $emAndamento,
            'pendente' => $naoIniciado + $emAndamento,
            'vencido' => $collection->where('status', 'vencido')->count(),
        ];
    }

    private function filterPayload(Request $req): array
    {
        return [
            'selected_period_id' => $req->query('period_id'),
            'selected_regional' => $req->query('regional'),
            'selected_status' => $req->query('status'),
            'regionals' => FcaUser::whereNotNull('regional')
                ->where('regional', '<>', '')
                ->distinct()
                ->orderBy('regional')
                ->pluck('regional')
                ->values(),
            'statuses' => [
                ['value' => 'nao_iniciado', 'label' => 'Nao iniciado'],
                ['value' => 'em_andamento', 'label' => 'Em andamento'],
                ['value' => 'realizado', 'label' => 'Realizado'],
                ['value' => 'vencido', 'label' => 'Vencido'],
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'nao_iniciado' => 'Nao iniciado',
            'em_andamento' => 'Em andamento',
            'realizado' => 'Realizado',
            'vencido' => 'Vencido',
            default => $status,
        };
    }

    private function normalizeName(?string $name): string
    {
        return mb_strtoupper(trim((string) $name));
    }

    private function sameValue(?string $left, string $right): bool
    {
        return mb_strtoupper(trim((string) $left)) === mb_strtoupper(trim($right));
    }

    private function csvLine(array $fields): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $fields, ',', '"', '');
        rewind($handle);
        $line = rtrim(stream_get_contents($handle), "\r\n");
        fclose($handle);

        return $line;
    }

    private function formatSupervisor(?FcaUser $supervisor): ?array
    {
        if (!$supervisor) return null;

        return [
            'id' => $supervisor->id,
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'role' => $supervisor->role,
            'manager_id' => $supervisor->manager_id,
            'regional' => $supervisor->regional,
            'territory' => $supervisor->territory,
        ];
    }

    private function formatCoordinator(?FcaUser $supervisor): ?array
    {
        if (!$supervisor) return null;

        $coordenador = $supervisor->manager;
        if (!$coordenador || $coordenador->role !== 'coordenacao') {
            return null;
        }

        return [
            'id' => $coordenador->id,
            'name' => $coordenador->name,
            'regional' => $coordenador->regional,
            'territory' => $coordenador->territory,
        ];
    }

    // ── Simple XLSX parser (no external dep) ─────────────────────────────────
    private function parseXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            abort(422, 'Arquivo xlsx inválido.');
        }

        // Shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ssDoc = new \SimpleXMLElement($ssXml);
            foreach ($ssDoc->si as $si) {
                $sharedStrings[] = (string)$si->t ?? implode('', array_map('strval', $si->r->t ?? []));
            }
        }

        // Sheet data
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) return [];

        $doc  = new \SimpleXMLElement($sheetXml);
        $rows = [];

        foreach ($doc->sheetData->row as $rowEl) {
            $row     = [];
            $lastCol = -1;

            foreach ($rowEl->c as $cell) {
                $ref   = (string)$cell['r'];
                $colLetter = preg_replace('/[0-9]/', '', $ref);
                $colIdx    = $this->colLetterToIndex($colLetter);

                // Fill gaps with null
                while (++$lastCol < $colIdx) {
                    $row[] = null;
                }

                $t = (string)($cell['t'] ?? '');
                $v = (string)($cell->v ?? '');

                if ($t === 's') {
                    $row[] = $sharedStrings[(int)$v] ?? null;
                } elseif ($t === 'b') {
                    $row[] = (bool)(int)$v;
                } elseif ($v !== '') {
                    $row[] = is_numeric($v) ? $v + 0 : $v;
                } else {
                    $row[] = null;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function colLetterToIndex(string $col): int
    {
        $col   = strtoupper($col);
        $index = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
}
