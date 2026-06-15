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
        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(null);

        $checklists = FcaChecklist::where('fca_period_id', $period->id)
            ->where('tecnico_id', $tecnicoId)
            ->where('supervisor_id', $req->attributes->get('fca_user')->id)
            ->orderByDesc('created_at')
            ->get();

        if ($checklists->isEmpty()) return response()->json(null);

        $latest = $checklists->first();

        return response()->json([
            'id' => $latest->id,
            'answers' => $latest->answers,
            'created_at' => $latest->created_at,
            'checklist_count' => $checklists->count(),
            'checklists' => $checklists->map(fn($item) => [
                'id' => $item->id,
                'answers' => $item->answers,
                'created_at' => $item->created_at,
            ])->values(),
        ]);
    }

    // POST /fcaf/checklist
    public function store(Request $req)
    {
        $req->validate([
            'tecnico_id' => 'required|exists:fca_period_tecnicos,id',
            'answers' => 'required|array|min:1',
        ]);

        $period = FcaPeriod::where('is_active', true)->latest()->first();
        if (!$period) return response()->json(['error' => 'Nenhum periodo ativo.'], 422);
        if ($period->isExpired()) return response()->json(['error' => 'Periodo expirado.'], 422);

        $tec = FcaPeriodTecnico::findOrFail($req->tecnico_id);
        if ($tec->fca_period_id !== $period->id) {
            return response()->json(['error' => 'Tecnico nao pertence ao periodo ativo.'], 422);
        }

        $existingCount = FcaChecklist::where('fca_period_id', $period->id)
            ->where('tecnico_id', $req->tecnico_id)
            ->where('supervisor_id', $req->attributes->get('fca_user')->id)
            ->count();

        if ($existingCount >= $tec->requiredPos()) {
            return response()->json(['error' => 'Quantidade maxima de checklists ja realizada para este tecnico neste periodo.'], 422);
        }

        $checklist = FcaChecklist::create([
            'fca_period_id' => $period->id,
            'supervisor_id' => $req->attributes->get('fca_user')->id,
            'tecnico_id' => $req->tecnico_id,
            'answers' => $req->answers,
        ]);

        return response()->json(['id' => $checklist->id, 'message' => 'Checklist salvo com sucesso.'], 201);
    }
}
