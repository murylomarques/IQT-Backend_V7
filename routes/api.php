<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\FiscalController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Api\AtendimentoController; 

Route::middleware('api')->group(function () {

    // Login
    Route::post('/login', function(Request $request) {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    });
    Route::middleware('auth:sanctum')->group(function () {
    Route::get('/atendimentos', [AtendimentoController::class, 'index']);
    Route::get('/atendimentos/{id}', [AtendimentoController::class, 'show']);
    // Rota para listar todos os usuários que são Fiscais
    
    // Rota para buscar a agenda de um fiscal específico
    Route::get('/fiscais/{id}/agenda', [FiscalController::class, 'showAgenda']);
    
    // Rota para salvar um novo agendamento (o POST do seu formulário)
    // ...outras rotas protegidas
    Route::get('/fiscais', [FiscalController::class, 'index']);
});
    Route::post('/agenda', [AgendaController::class, 'store']);
});
