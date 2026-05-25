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
            'period' => [
                'id'          => $period->id,
                'mes'         => $period->mes,
                'created_at'  => $period->created_at,
                'expires_at'  => $period->expires_at,
                'is_expired'  => $period->isExpired(),
                'days_left'   => $period->daysLeft(),
                'total_tecnicos' => $period->tecnicos()->count(),
            ],
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
            'period'   => ['id' => $period->id, 'mes' => $period->mes, 'expires_at' => $period->expires_at],
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
        $ghNames = FcaUser::where('manager_id', $user->id)
            ->whereIn('role', ['tecnico', 'consulta'])
            ->pluck('name')
            ->map(fn($n) => mb_strtoupper(trim($n)))
            ->toArray();

        $tecnicos = FcaPeriodTecnico::where('fca_period_id', $period->id)
            ->whereIn(\DB::raw('UPPER(TRIM(nome))'), $ghNames)
            ->get();

        return response()->json(
            $tecnicos->map(fn($t) => $this->formatTecnico($t, $user->id, $period))
        );
    }

    // ── GET /fcaf/tecnico/{id} ────────────────────────────────────────────────
    public function tecnicoDetail(Request $req, $id)
    {
        $user   = $req->attributes->get('fca_user');
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        $tec    = FcaPeriodTecnico::findOrFail($id);

        return response()->json($this->formatTecnico($tec, $user->id, $period));
    }

    // ── GET /fcaf/analytics ──────────────────────────────────────────────────
    public function analytics(Request $req)
    {
        $user   = $req->attributes->get('fca_user');
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) {
            return response()->json(['period' => null, 'metrics' => null, 'tecnicos' => []]);
        }

        $ghNames = FcaUser::where('manager_id', $user->id)
            ->whereIn('role', ['tecnico', 'consulta'])
            ->pluck('name')
            ->map(fn($n) => mb_strtoupper(trim($n)))
            ->toArray();

        $tecnicos = FcaPeriodTecnico::where('fca_period_id', $period->id)
            ->whereIn(\DB::raw('UPPER(TRIM(nome))'), $ghNames)
            ->get()
            ->map(fn($t) => $this->formatTecnico($t, $user->id, $period));

        $metrics = [
            'total'     => $tecnicos->count(),
            'realizado' => $tecnicos->where('status', 'realizado')->count(),
            'pendente'  => $tecnicos->where('status', 'pendente')->count(),
            'vencido'   => $tecnicos->where('status', 'vencido')->count(),
        ];

        return response()->json([
            'period'   => [
                'id'         => $period->id,
                'mes'        => $period->mes,
                'expires_at' => $period->expires_at,
                'is_expired' => $period->isExpired(),
                'days_left'  => $period->daysLeft(),
            ],
            'metrics'  => $metrics,
            'tecnicos' => $tecnicos->values(),
        ]);
    }

    // ── GET /fcaf/analytics/all (admin) ──────────────────────────────────────
    public function analyticsAll(Request $req)
    {
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(['period' => null, 'supervisors' => []]);

        $supervisors = FcaUser::where('role', 'supervisao')->get();

        $result = $supervisors->map(function ($sup) use ($period) {
            $ghNames = FcaUser::where('manager_id', $sup->id)
                ->whereIn('role', ['tecnico', 'consulta'])
                ->pluck('name')
                ->map(fn($n) => mb_strtoupper(trim($n)))
                ->toArray();

            $tecs = FcaPeriodTecnico::where('fca_period_id', $period->id)
                ->whereIn(\DB::raw('UPPER(TRIM(nome))'), $ghNames)
                ->get()
                ->map(fn($t) => $this->formatTecnico($t, $sup->id, $period));

            return [
                'id'        => $sup->id,
                'name'      => $sup->name,
                'territory' => $sup->territory,
                'total'     => $tecs->count(),
                'realizado' => $tecs->where('status', 'realizado')->count(),
                'pendente'  => $tecs->where('status', 'pendente')->count(),
                'vencido'   => $tecs->where('status', 'vencido')->count(),
            ];
        });

        return response()->json([
            'period' => [
                'id'         => $period->id,
                'mes'        => $period->mes,
                'expires_at' => $period->expires_at,
                'is_expired' => $period->isExpired(),
                'days_left'  => $period->daysLeft(),
            ],
            'supervisors' => $result->values(),
        ]);
    }

    // ── GET /fcaf/periods (admin) ─────────────────────────────────────────────
    public function periodHistory()
    {
        return response()->json(
            FcaPeriod::latest()->take(12)->get()->map(fn($p) => [
                'id'         => $p->id,
                'mes'        => $p->mes,
                'is_active'  => $p->is_active,
                'is_expired' => $p->isExpired(),
                'expires_at' => $p->expires_at,
                'created_at' => $p->created_at,
                'total'      => $p->tecnicos()->count(),
            ])
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function formatTecnico(FcaPeriodTecnico $t, int $supId, FcaPeriod $period): array
    {
        $checklist    = FcaChecklist::where('tecnico_id', $t->id)->where('supervisor_id', $supId)->first();
        $pos          = FcaPo::where('tecnico_id', $t->id)->where('supervisor_id', $supId)->get();
        $distinctDays = $pos->pluck('po_date')->map(fn($d) => $d->toDateString())->unique()->count();
        $requiredPos  = $t->requiredPos();

        $poMet = $t->isCertificado()
            ? $pos->count() >= 1
            : $distinctDays >= 5;

        $isComplete = $checklist && $poMet;
        $isExpired  = $period->isExpired();

        if ($isComplete) {
            $status = 'realizado';
        } elseif ($isExpired) {
            $status = 'vencido';
        } else {
            $status = 'pendente';
        }

        return [
            'id'            => $t->id,
            'nome'          => $t->nome,
            'certificado'   => $t->certificado,
            'prod_bruta'    => $t->prod_bruta,
            'revisita'      => $t->revisita,
            'tec1'          => $t->tec1,
            'has_checklist' => (bool) $checklist,
            'po_count'      => $pos->count(),
            'po_days'       => $distinctDays,
            'required_pos'  => $requiredPos,
            'status'        => $status,
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
