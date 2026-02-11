<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\Cargo;
use App\Models\Empresa;
use App\Models\Regional;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccessRequestController extends Controller
{
    public function options()
    {
        return response()->json([
            'empresas' => Empresa::query()->select('id', 'nome')->orderBy('nome')->get(),
            'cargos' => Cargo::query()->select('id', 'nome')->orderBy('nome')->get(),
            'regionais' => Regional::query()->select('id', 'nome')->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(
            [
                'nome' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'password' => 'required|string|min:8',
                'numero' => 'nullable|string|max:255',
                'cpf' => 'nullable|string|max:14',
                'empresa_id' => 'required|exists:empresas,id',
                'cargo_id' => 'required|exists:cargos,id',
                'regional_id' => 'required|exists:regionais,id',
                'observacao' => 'nullable|string|max:1000',
            ],
            [
                'nome.required' => 'Informe o nome completo.',
                'nome.max' => 'O nome pode ter no maximo :max caracteres.',
                'email.required' => 'Informe o e-mail.',
                'email.email' => 'Informe um e-mail valido.',
                'email.max' => 'O e-mail pode ter no maximo :max caracteres.',
                'password.required' => 'Informe uma senha.',
                'password.min' => 'A senha deve ter pelo menos :min caracteres.',
                'numero.max' => 'O numero pode ter no maximo :max caracteres.',
                'cpf.max' => 'O CPF pode ter no maximo :max caracteres.',
                'empresa_id.required' => 'Selecione a empresa.',
                'empresa_id.exists' => 'Empresa informada nao e valida.',
                'cargo_id.required' => 'Selecione o cargo.',
                'cargo_id.exists' => 'Cargo informado nao e valido.',
                'regional_id.required' => 'Selecione a regional.',
                'regional_id.exists' => 'Regional informada nao e valida.',
                'observacao.max' => 'A observacao pode ter no maximo :max caracteres.',
            ]
        );

        if (User::where('email', $validated['email'])->exists()) {
            return response()->json(['message' => 'Ja existe um usuario com este email.'], 422);
        }

        $hasPending = AccessRequest::where('email', $validated['email'])
            ->where('status', 'pendente')
            ->exists();

        if ($hasPending) {
            return response()->json(['message' => 'Ja existe uma solicitacao pendente para este email.'], 422);
        }

        $validated['password'] = Hash::make($validated['password']);
        $accessRequest = AccessRequest::create($validated);

        ActivityLogService::log(
            'access_request.created',
            "Nova solicitacao de acesso criada para {$accessRequest->email}",
            null,
            ['email' => $accessRequest->email, 'nome' => $accessRequest->nome],
            AccessRequest::class,
            $accessRequest->id
        );

        return response()->json([
            'message' => 'Solicitacao enviada com sucesso. Aguarde aprovacao do administrador.',
            'request_id' => $accessRequest->id,
        ], 201);
    }

    public function index()
    {
        return AccessRequest::with(['empresa:id,nome', 'cargo:id,nome', 'regional:id,nome', 'reviewer:id,nome'])
            ->latest()
            ->get();
    }

    public function show(AccessRequest $accessRequest)
    {
        return $accessRequest->load(['empresa:id,nome', 'cargo:id,nome', 'regional:id,nome', 'reviewer:id,nome']);
    }

    public function approve(Request $request, AccessRequest $accessRequest)
    {
        if ($accessRequest->status !== 'pendente') {
            return response()->json(['message' => 'Solicitacao ja processada.'], 422);
        }

        if (User::where('email', $accessRequest->email)->exists()) {
            return response()->json(['message' => 'Ja existe um usuario com este email.'], 422);
        }

        $reviewNotes = $request->input('review_notes');
        $admin = $request->user();

        DB::transaction(function () use ($accessRequest, $reviewNotes, $admin) {
            $newUser = User::create([
                'nome' => $accessRequest->nome,
                'email' => $accessRequest->email,
                'password' => $accessRequest->password,
                'numero' => $accessRequest->numero,
                'cpf' => $accessRequest->cpf,
                'empresa_id' => $accessRequest->empresa_id,
                'cargo_id' => $accessRequest->cargo_id,
                'regional_id' => $accessRequest->regional_id,
                'status' => 'ativo',
                'cadastro_completo' => true,
            ]);

            $accessRequest->update([
                'status' => 'aprovado',
                'reviewed_by' => $admin?->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            ActivityLogService::log(
                'access_request.approved',
                "Solicitacao de {$accessRequest->email} aprovada",
                $admin?->id,
                ['access_request_id' => $accessRequest->id, 'new_user_id' => $newUser->id],
                AccessRequest::class,
                $accessRequest->id
            );
        });

        return response()->json(['message' => 'Solicitacao aprovada e usuario criado com sucesso.']);
    }

    public function reject(Request $request, AccessRequest $accessRequest)
    {
        if ($accessRequest->status !== 'pendente') {
            return response()->json(['message' => 'Solicitacao ja processada.'], 422);
        }

        $request->validate([
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $accessRequest->update([
            'status' => 'rejeitado',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('review_notes'),
        ]);

        ActivityLogService::log(
            'access_request.rejected',
            "Solicitacao de {$accessRequest->email} rejeitada",
            $request->user()?->id,
            ['access_request_id' => $accessRequest->id],
            AccessRequest::class,
            $accessRequest->id
        );

        return response()->json(['message' => 'Solicitacao rejeitada com sucesso.']);
    }
}
