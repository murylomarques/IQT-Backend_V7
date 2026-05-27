<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaUser;
use App\Models\FcaLinkRequest;
use App\Models\FcaMonthlyWindowConfig;

class FcaHierarchyController extends Controller
{
    // -------------------------------------------------------------------------
    // Subordinates
    // -------------------------------------------------------------------------

    public function getSubordinates(Request $request)
    {
        $user = $request->attributes->get('fca_user');

        $direct = FcaUser::where('manager_id', $user->id)
            ->with('subordinates')
            ->get();

        return response()->json([
            'direct' => $direct->map(fn($u) => $this->formatWithDepth($u, 1)),
        ]);
    }

    public function getAllHierarchy(Request $request)
    {
        $roots = FcaUser::whereNull('manager_id')->with('subordinates.subordinates')->get();
        return response()->json($roots->map(fn($u) => $this->formatWithDepth($u, 0)));
    }

    // -------------------------------------------------------------------------
    // Link / Unlink
    // -------------------------------------------------------------------------

    public function link(Request $request)
    {
        $actor = $request->attributes->get('fca_user');

        $request->validate([
            'parent_id' => 'required|integer|exists:fca_users,id',
            'child_id'  => 'required|integer|exists:fca_users,id|different:parent_id',
        ]);

        $parent = FcaUser::findOrFail($request->parent_id);
        $child  = FcaUser::findOrFail($request->child_id);

        // Role compatibility check
        $allowed = [
            'supervisao'  => ['tecnico'],
            'coordenacao' => ['supervisao'],
            'admin'       => ['tecnico', 'supervisao', 'coordenacao'],
        ];

        if (!isset($allowed[$parent->role]) || !in_array($child->role, $allowed[$parent->role])) {
            return response()->json(['error' => 'Combinação de papéis inválida para vínculo.'], 422);
        }

        // Territory compatibility (skip for admin actor)
        if ($actor->role !== 'admin' && $parent->territory && $child->territory && $parent->territory !== $child->territory) {
            return response()->json(['error' => 'Território incompatível.'], 422);
        }

        // Check if actor is authorized to make this link
        if ($actor->role !== 'admin' && (int) $actor->id !== (int) $parent->id) {
            return response()->json(['error' => 'Você só pode criar vínculos como superior direto.'], 403);
        }

        $windowOpen = $this->isWindowOpen();

        if ($actor->role === 'admin' || $windowOpen) {
            // Apply immediately
            $child->manager_id = $parent->id;
            $child->save();

            FcaLinkRequest::create([
                'requester_user_id' => $actor->id,
                'parent_user_id'    => $parent->id,
                'child_user_id'     => $child->id,
                'parent_role'       => $parent->role,
                'child_role'        => $child->role,
                'status'            => 'approved',
                'requested_at'      => now(),
                'decided_at'        => now(),
                'decided_by_user_id' => $actor->id,
                'decision_note'     => $actor->role === 'admin' ? 'Aplicado pelo admin.' : 'Aplicado automaticamente (janela aberta).',
            ]);

            return response()->json(['message' => 'Vínculo criado com sucesso.', 'applied' => true]);
        }

        // Outside window — create pending request
        $pending = FcaLinkRequest::create([
            'requester_user_id' => $actor->id,
            'parent_user_id'    => $parent->id,
            'child_user_id'     => $child->id,
            'parent_role'       => $parent->role,
            'child_role'        => $child->role,
            'status'            => 'pending',
            'requested_at'      => now(),
        ]);

        return response()->json([
            'message' => 'Solicitação enviada para aprovação do administrador.',
            'applied'  => false,
            'request'  => $pending,
        ], 202);
    }

    public function unlink(Request $request, $childId)
    {
        $actor = $request->attributes->get('fca_user');
        $child = FcaUser::findOrFail($childId);

        if ($actor->role !== 'admin' && (int) $actor->id !== (int) $child->manager_id) {
            return response()->json(['error' => 'Não autorizado para desvincular este usuário.'], 403);
        }

        $child->manager_id = null;
        $child->save();

        return response()->json(['message' => 'Vínculo removido.']);
    }

    // -------------------------------------------------------------------------
    // Link Requests (admin)
    // -------------------------------------------------------------------------

    public function getLinkRequests(Request $request)
    {
        $requests = FcaLinkRequest::with([
            'requester:id,name',
            'parent:id,name,role',
            'child:id,name,role',
            'decidedBy:id,name',
        ])->orderByDesc('requested_at')->get();

        return response()->json($requests);
    }

    public function approveLinkRequest(Request $request, $id)
    {
        $admin   = $request->attributes->get('fca_user');
        $linkReq = FcaLinkRequest::findOrFail($id);

        if ($linkReq->status !== 'pending') {
            return response()->json(['error' => 'Esta solicitação já foi processada.'], 422);
        }

        $child = FcaUser::findOrFail($linkReq->child_user_id);
        $child->manager_id = $linkReq->parent_user_id;
        $child->save();

        $linkReq->update([
            'status'            => 'approved',
            'decided_at'        => now(),
            'decided_by_user_id' => $admin->id,
            'decision_note'     => $request->input('note', 'Aprovado pelo admin.'),
        ]);

        return response()->json(['message' => 'Vínculo aprovado e aplicado.']);
    }

    public function rejectLinkRequest(Request $request, $id)
    {
        $admin   = $request->attributes->get('fca_user');
        $linkReq = FcaLinkRequest::findOrFail($id);

        if ($linkReq->status !== 'pending') {
            return response()->json(['error' => 'Esta solicitação já foi processada.'], 422);
        }

        $linkReq->update([
            'status'            => 'rejected',
            'decided_at'        => now(),
            'decided_by_user_id' => $admin->id,
            'decision_note'     => $request->input('note', 'Reprovado pelo admin.'),
        ]);

        return response()->json(['message' => 'Solicitação reprovada.']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isWindowOpen(): bool
    {
        $config  = FcaMonthlyWindowConfig::first();
        $start   = $config ? $config->start_day : 1;
        $end     = $config ? $config->end_day   : 7;
        $day     = (int) now()->format('j');
        return $day >= $start && $day <= $end;
    }

    public function fullTree(Request $request)
    {
        $coordenadores = FcaUser::where('role', 'coordenacao')
            ->with(['subordinates' => fn($q) => $q->with('subordinates')])
            ->orderBy('name')
            ->get();

        return response()->json($coordenadores->map(fn($u) => $this->formatWithDepth($u, 0))->values());
    }

    private function formatWithDepth(FcaUser $user, int $depth): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'role'        => $user->role,
            'employee_id' => $user->employee_id,
            'cpf'         => $user->cpf,
            'regional'    => $user->regional,
            'territory'   => $user->territory,
            'manager_id'  => $user->manager_id,
            'depth'       => $depth,
            'subordinates' => $user->relationLoaded('subordinates')
                ? $user->subordinates->map(fn($s) => $this->formatWithDepth($s, $depth + 1))->values()
                : [],
        ];
    }
}
