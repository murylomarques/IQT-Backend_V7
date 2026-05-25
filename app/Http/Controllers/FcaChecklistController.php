<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaChecklist;
use App\Models\FcaPeriod;
use App\Models\FcaPeriodTecnico;

class FcaChecklistController extends Controller
{
    // GET /fcaf/tecnico/{id}/checklist
    public function getForTecnico(Request $req, $tecnicoId)
    {
        $checklist = FcaChecklist::where('tecnico_id', $tecnicoId)
            ->where('supervisor_id', $req->user()->id)
            ->first();

        if (!$checklist) return response()->json(null);

        return response()->json([
            'id'         => $checklist->id,
            'answers'    => $checklist->answers,
            'created_at' => $checklist->created_at,
        ]);
    }

    // POST /fcaf/checklist
    public function store(Request $req)
    {
        $req->validate([
            'tecnico_id' => 'required|exists:fca_period_tecnicos,id',
            'answers'    => 'required|array|min:1',
        ]);

        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(['error' => 'Nenhum período ativo.'], 422);
        if ($period->isExpired()) return response()->json(['error' => 'Período expirado.'], 422);

        $tec = FcaPeriodTecnico::findOrFail($req->tecnico_id);
        if ($tec->fca_period_id !== $period->id) {
            return response()->json(['error' => 'Técnico não pertence ao período ativo.'], 422);
        }

        $existing = FcaChecklist::where('tecnico_id', $req->tecnico_id)
            ->where('supervisor_id', $req->user()->id)
            ->first();

        if ($existing) return response()->json(['error' => 'Checklist já preenchido para este técnico neste período.'], 422);

        $checklist = FcaChecklist::create([
            'fca_period_id' => $period->id,
            'supervisor_id' => $req->user()->id,
            'tecnico_id'    => $req->tecnico_id,
            'answers'       => $req->answers,
        ]);

        return response()->json(['id' => $checklist->id, 'message' => 'Checklist salvo com sucesso.'], 201);
    }
}
