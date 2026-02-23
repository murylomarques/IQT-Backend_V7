<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaUser;
use Illuminate\Support\Facades\Hash;

class FcaController extends Controller
{
    public function login(Request $request)
{
    $request->validate([
        'usuario' => 'required',
        'password' => 'required'
    ]);

    // 🔍 Busca o usuário pelo campo 'usuario' OU 'email'
    $user = FcaUser::where('usuario', $request->usuario)
                ->orWhere('email', $request->usuario)
                ->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'error' => 'Usuário ou senha inválidos.'
        ], 401);
    }

    // 🔑 Gera um token manual (sem Sanctum)
    $token = hash('sha256', uniqid(rand(), true));

    // 💾 Salva o token e define a expiração (opcional)
    $user->token  = $token;
 
    $user->save();

    return response()->json([
        'message' => 'Login realizado com sucesso.',
        'token' => $token,
        'nome' => $user->nome,
        'cargo' => $user->cargo ?? 'N/A',
        'nivel_hierarquia' => $user->nivel_hierarquia ?? null
    ]);
}


    // 🧩 Função para criar um usuário FCA
    public function criarUsuario(Request $request)
    {
        $admin = $this->getUserFromToken($request);
        if (!$admin || $admin->cargo !== 'Administrador') {
            return response()->json(['error' => 'Acesso n??o autorizado.'], 403);
        }


        $request->validate([
            'nome' => 'required|string|max:255',
            'email' => 'nullable|email|unique:fca_users,email',
            'usuario' => 'required|string|unique:fca_users,usuario',
            'password' => 'required|string|min:6',
            'cargo' => 'nullable|string|max:100',
            'nivel_hierarquia' => 'nullable|integer'
        ]);

        $user = FcaUser::create([
            'nome' => $request->nome,
            'email' => $request->email,
            'usuario' => $request->usuario,
            'password' => Hash::make($request->password),
            'cargo' => $request->cargo ?? 'Técnico',
            'nivel_hierarquia' => $request->nivel_hierarquia ?? 3
        ]);

        return response()->json([
            'message' => 'Usuário criado com sucesso!',
            'usuario' => $user
        ]);
    }
    // app/Http/Controllers/FcaController.php

// ... (dentro da classe FcaController)

    // =================================================================
    // ✅ NOVO MÉTODO PARA O ADMINISTRADOR (LISTAR USUÁRIOS)
    // =================================================================
    public function indexUsers(Request $request)
    {
        
        // Seleciona apenas os campos seguros para não expor senhas ou tokens
        $users = FcaUser::select('id', 'nome', 'email', 'usuario', 'cargo', 'nivel_hierarquia', 'created_at')
            ->orderBy('nome', 'asc')
            ->get();

        return response()->json($users);
    }

    private function getUserFromToken(Request $request)
    {
        $token = $request->header('Authorization');
        if (!$token) {
            return null;
        }

        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        return FcaUser::where('token', $token)->first();
    }

     public function updateUser(Request $request, $userId)
    {
        $admin = $this->getUserFromToken($request);
        if (!$admin || $admin->cargo !== 'Administrador') {
            return response()->json(['error' => 'Acesso não autorizado.'], 403);
        }

        $user = FcaUser::find($userId);
        if (!$user) {
            return response()->json(['error' => 'Usuário não encontrado.'], 404);
        }
        
        // ADICIONA A VALIDAÇÃO PARA O CAMPO 'cargo'
        $validated = $request->validate([
            'email' => 'sometimes|required|email|unique:fca_users,email,' . $user->id,
            'password' => 'sometimes|required|string|min:6',
            'cargo' => 'sometimes|required|string|max:100', // <-- ADICIONADO
            'nivel_hierarquia' => 'sometimes|nullable|integer|exists:fca_users,id',
        ]);

        $dataToUpdate = [];

        if ($request->has('email') && !empty($validated['email'])) {
            $dataToUpdate['email'] = $validated['email'];
        }

        if ($request->has('password') && !empty($validated['password'])) {
            $dataToUpdate['password'] = Hash::make($validated['password']);
        }

        // ADICIONA O CARGO AOS DADOS A SEREM ATUALIZADOS
        if ($request->has('cargo') && !empty($validated['cargo'])) {
            $dataToUpdate['cargo'] = $validated['cargo']; // <-- ADICIONADO
        }

        if ($request->has('nivel_hierarquia')) {
            $dataToUpdate['nivel_hierarquia'] = $request->nivel_hierarquia ?: null;
        }

        if (!empty($dataToUpdate)) {
            $user->update($dataToUpdate);
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso!',
            'user' => $user->only(['id', 'nome', 'email', 'usuario', 'cargo', 'nivel_hierarquia'])
        ]);
    }
}
