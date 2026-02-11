<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\VistoriaChecklistItem;
use Illuminate\Support\Facades\Cache;

class AdminOverviewController extends Controller
{
    public function index()
    {
        return Cache::remember('admin_overview_v1', now()->addSeconds(15), function () {
            $pendingAccessRequests = AccessRequest::where('status', 'pendente')->count();

            $reprovados = VistoriaChecklistItem::where('status_correcao', 'Reprovado')->count();
            $emAnalise = VistoriaChecklistItem::where('status_correcao', 'Em Análise')->count();

            $recentActivities = ActivityLog::with('user:id,nome')
                ->latest()
                ->limit(20)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'description' => $log->description,
                        'user' => $log->user?->nome,
                        'created_at' => $log->created_at,
                    ];
                });

            $pendingApprovals = AccessRequest::with(['empresa:id,nome', 'cargo:id,nome'])
                ->where('status', 'pendente')
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'nome' => $request->nome,
                        'email' => $request->email,
                        'empresa' => $request->empresa?->nome,
                        'cargo' => $request->cargo?->nome,
                        'created_at' => $request->created_at,
                    ];
                });

            return response()->json([
                'stats' => [
                    'pending_access_requests' => $pendingAccessRequests,
                    'reprovados' => $reprovados,
                    'em_analise' => $emAnalise,
                    'recent_activity_count' => $recentActivities->count(),
                ],
                'recent_activities' => $recentActivities,
                'pending_approvals' => $pendingApprovals,
            ]);
        });
    }
}

