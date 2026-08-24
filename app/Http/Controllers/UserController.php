<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(\App\Http\Middleware\IsAdmin::class);
    }
    /**
     * Exibe uma lista de todos os usuários.
     * Rota: GET /api/admin/users
     */
    public function index()
    {
        // Carrega todos os usuários, incluindo as informações de sua empresa e cargo,
        // ordenados pelos mais recentes.
        return User::with(['empresa:id,nome', 'cargo:id,nome'])->latest()->get();
    }

    /**
     * Armazena um novo usuário no banco de dados.
     * Rota: POST /api/admin/users
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'empresa_id' => 'nullable|exists:empresas,id',
            'cargo_id' => 'nullable|exists:cargos,id',
            'regional_id' => 'nullable|exists:regionais,id',
            // Adicione outras validações para 'numero', 'cpf', etc., se necessário
        ]);

        // Criptografa a senha antes de salvar
        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        ActivityLogService::log(
            'user.created',
            "Usuario {$user->email} criado",
            auth()->id(),
            ['target_user_id' => $user->id],
            User::class,
            $user->id
        );

        // Retorna o usuário recém-criado com as relações carregadas
        return response()->json($user->load(['empresa', 'cargo']), 201);
    }

    /**
     * Exibe os detalhes de um usuário específico.
     * Rota: GET /api/admin/users/{user}
     */
    public function show(User $user)
    {
        // Carrega as relações para garantir que os dados de empresa e cargo sejam incluídos
        return $user->load(['empresa', 'cargo']);
    }

    /**
     * Atualiza um usuário existente no banco de dados.
     * Rota: PUT/PATCH /api/admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'nome' => 'sometimes|required|string|max:255',
            // Ao atualizar, a regra 'unique' deve ignorar o ID do usuário atual
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'empresa_id' => 'nullable|exists:empresas,id',
            'cargo_id' => 'nullable|exists:cargos,id',
            'regional_id' => 'nullable|exists:regionais,id',
        ]);

        // Se uma nova senha for fornecida, valida e criptografa
        if ($request->filled('password')) {
            $request->validate(['password' => 'string|min:8']);
            $validated['password'] = Hash::make($request->password);
        }

        $user->update($validated);

        ActivityLogService::log(
            'user.updated',
            "Usuario {$user->email} atualizado",
            auth()->id(),
            ['target_user_id' => $user->id],
            User::class,
            $user->id
        );

        // Retorna o usuário atualizado com as relações carregadas
        return response()->json($user->load(['empresa', 'cargo']));
    }

    /**
     * Remove um usuário do banco de dados.
     * Rota: DELETE /api/admin/users/{user}
     */
    public function destroy(User $user)
    {
        // Opcional: Adicionar lógica para impedir que um admin delete a si mesmo
        if (auth()->id() === $user->id) {
             return response()->json(['message' => 'Você não pode deletar a si mesmo.'], 403);
        }
        
        $user->delete();

        ActivityLogService::log(
            'user.deleted',
            "Usuario {$user->email} removido",
            auth()->id(),
            ['target_user_id' => $user->id]
        );

        return response()->json(null, 204); // 204: No Content
    }
}
