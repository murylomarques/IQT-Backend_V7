<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaChecklist;
use App\Models\FcaPo;
use App\Models\FcaPeriod;
use App\Models\FcaPeriodTecnico;

class FcaPoController extends Controller
{
    // GET /fcaf/tecnico/{id}/pos
    public function getForTecnico(Request $req, $tecnicoId)
    {
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json([]);

        $pos = FcaPo::where('fca_period_id', $period->id)
            ->where('tecnico_id', $tecnicoId)
            ->with('supervisor:id,name')
            ->orderBy('po_date', 'desc')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($p) => [
                'id'      => $p->id,
                'supervisor_id' => $p->supervisor_id,
                'supervisor_name' => $p->supervisor?->name,
                'answers' => $p->answers,
                'po_date' => $p->po_date->toDateString(),
                'created_at' => $p->created_at,
                'performed_at' => $p->po_date->toDateString(),
            ]);

        return response()->json($pos);
    }

    // POST /fcaf/po
    public function store(Request $req)
    {
        $req->validate([
            'tecnico_id' => 'required|exists:fca_period_tecnicos,id',
            'answers'    => 'required|array|min:1',
            'po_date'    => 'required|date|before_or_equal:today',
        ]);

        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(['error' => 'Nenhum período ativo.'], 422);
        if ($period->isExpired()) return response()->json(['error' => 'Período expirado.'], 422);

        $tec = FcaPeriodTecnico::findOrFail($req->tecnico_id);
        if ($tec->fca_period_id !== $period->id) {
            return response()->json(['error' => 'Técnico não pertence ao período ativo.'], 422);
        }

        $supervisorId = $req->attributes->get('fca_user')->id;

        $checklistCount = FcaChecklist::where('fca_period_id', $period->id)
            ->where('tecnico_id', $req->tecnico_id)
            ->count();

        if ($checklistCount === 0) {
            return response()->json(['error' => 'Preencha o Checklist deste técnico antes de registrar um PO.'], 422);
        }

        $poCount = FcaPo::where('fca_period_id', $period->id)
            ->where('tecnico_id', $req->tecnico_id)
            ->count();

        if ($poCount >= $tec->requiredPos()) {
            return response()->json(['error' => 'Quantidade maxima de POs ja realizada para este tecnico neste periodo.'], 422);
        }

        if ($poCount >= $checklistCount) {
            return response()->json(['error' => 'Para registrar outro PO, realize mais um Checklist deste tecnico primeiro.'], 422);
        }

        $lastPo = FcaPo::where('fca_period_id', $period->id)
            ->where('tecnico_id', $req->tecnico_id)
            ->orderByDesc('created_at')
            ->first();

        if ($lastPo) {
            $nextAvailableAt = $lastPo->created_at->copy()->addHours(FcaPeriodTecnico::MIN_HOURS_BETWEEN_SAME_TYPE);

            if (now()->lt($nextAvailableAt)) {
                return response()->json([
                    'error' => 'Aguarde 72 horas desde o ultimo PO deste tecnico para registrar o proximo.',
                    'last_performed_at' => $lastPo->created_at,
                    'next_available_at' => $nextAvailableAt,
                ], 422);
            }
        }

        // Non-certified: block if already has a PO on the same date
        if (!$tec->isCertificado()) {
            $sameDay = FcaPo::where('fca_period_id', $period->id)
                ->where('tecnico_id', $req->tecnico_id)
                ->whereDate('po_date', $req->po_date)
                ->exists();

            if ($sameDay) {
                return response()->json([
                    'error' => 'Já existe um PO registrado nesta data para este técnico. Realize o próximo PO em outro dia.',
                ], 422);
            }
        }

        $po = FcaPo::create([
            'fca_period_id' => $period->id,
            'supervisor_id' => $supervisorId,
            'tecnico_id'    => $req->tecnico_id,
            'answers'       => $req->answers,
            'po_date'       => $req->po_date,
        ]);

        return response()->json(['id' => $po->id, 'message' => 'PO registrado com sucesso.'], 201);
    }
}
