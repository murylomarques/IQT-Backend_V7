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
            ->with('supervisor:id,name')
            ->orderByDesc('created_at')
            ->get();

        if ($checklists->isEmpty()) return response()->json(null);

        $latest = $checklists->first();

        return response()->json([
            'id' => $latest->id,
            'answers' => $latest->answers,
            'created_at' => $latest->created_at,
            'performed_at' => $latest->created_at,
            'checklist_count' => $checklists->count(),
            'checklists' => $checklists->map(fn($item) => [
                'id' => $item->id,
                'supervisor_id' => $item->supervisor_id,
                'supervisor_name' => $item->supervisor?->name,
                'answers' => $item->answers,
                'created_at' => $item->created_at,
                'performed_at' => $item->created_at,
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

        $supervisorId = $req->attributes->get('fca_user')->id;

        $existingCount = FcaChecklist::where('fca_period_id', $period->id)
            ->where('tecnico_id', $req->tecnico_id)
            ->count();

        if ($existingCount >= $tec->requiredPos()) {
            return response()->json(['error' => 'Quantidade maxima de checklists ja realizada para este tecnico neste periodo.'], 422);
        }

        $lastChecklist = FcaChecklist::where('fca_period_id', $period->id)
            ->where('tecnico_id', $req->tecnico_id)
            ->orderByDesc('created_at')
            ->first();

        if ($lastChecklist) {
            $nextAvailableAt = $lastChecklist->created_at->copy()->addHours(FcaPeriodTecnico::MIN_HOURS_BETWEEN_SAME_TYPE);

            if (now()->lt($nextAvailableAt)) {
                return response()->json([
                    'error' => 'Aguarde 72 horas desde o ultimo Checklist deste tecnico para registrar o proximo.',
                    'last_performed_at' => $lastChecklist->created_at,
                    'next_available_at' => $nextAvailableAt,
                ], 422);
            }
        }

        $checklist = FcaChecklist::create([
            'fca_period_id' => $period->id,
            'supervisor_id' => $supervisorId,
            'tecnico_id' => $req->tecnico_id,
            'answers' => $req->answers,
        ]);

        return response()->json(['id' => $checklist->id, 'message' => 'Checklist salvo com sucesso.'], 201);
    }
}
