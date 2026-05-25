<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaPo;
use App\Models\FcaPeriod;
use App\Models\FcaPeriodTecnico;

class FcaPoController extends Controller
{
    // GET /fcaf/tecnico/{id}/pos
    public function getForTecnico(Request $req, $tecnicoId)
    {
        $pos = FcaPo::where('tecnico_id', $tecnicoId)
            ->where('supervisor_id', $req->attributes->get('fca_user')->id)
            ->orderBy('po_date', 'desc')
            ->get()
            ->map(fn($p) => [
                'id'      => $p->id,
                'answers' => $p->answers,
                'po_date' => $p->po_date->toDateString(),
                'created_at' => $p->created_at,
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

        // Non-certified: block if already has a PO on the same date
        if (!$tec->isCertificado()) {
            $sameDay = FcaPo::where('tecnico_id', $req->tecnico_id)
                ->where('supervisor_id', $req->attributes->get('fca_user')->id)
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
            'supervisor_id' => $req->attributes->get('fca_user')->id,
            'tecnico_id'    => $req->tecnico_id,
            'answers'       => $req->answers,
            'po_date'       => $req->po_date,
        ]);

        return response()->json(['id' => $po->id, 'message' => 'PO registrado com sucesso.'], 201);
    }
}
