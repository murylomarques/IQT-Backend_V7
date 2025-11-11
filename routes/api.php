<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\FiscalController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Api\AtendimentoController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\VistoriaController;

use App\Http\Controllers\UserController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\RegionalController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\VistoriaSegurancaController;
use App\Http\Controllers\ExportController;



Route::middleware('api')->group(function () {

    // Login (público)
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

    // Rotas protegidas que exigem autenticação
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/vistorias/ids-por-periodo', [VistoriaController::class, 'getIdsByDateRange']);
        Route::get('/atendimentos', [AtendimentoController::class, 'index']);
        Route::get('/atendimentos/{id}', [AtendimentoController::class, 'show']);
        Route::get('/fiscais/{id}/agenda', [FiscalController::class, 'showAgenda']);
        Route::get('/fiscais', [FiscalController::class, 'index']);
        Route::post('/location', [LocationController::class, 'store']);
        Route::get('/home-data', [HomeController::class, 'index']);
        
        // --- Rotas de Agenda ---
        Route::get('/agenda/minhas-vistorias-hoje', [AgendaController::class, 'minhasVistoriasHoje']);
        
        // ==========================================================
        // ============= NOVAS ROTAS PARA A AGENDA GANTT ============
        // ==========================================================
        Route::get('/agenda-gantt', [AgendaController::class, 'gantt']);
        Route::patch('/agenda-gantt/{agenda}', [AgendaController::class, 'updateGantt']);
        // ==========================================================
        
        // --- Rotas de Vistoria ---
        Route::get('/vistorias/backlog', [VistoriaController::class, 'backlog']);
        Route::get('/vistorias/{vistoria}', [VistoriaController::class, 'show']);
        
        // --- Rotas de Correção ---
        Route::post('/checklist-itens/{item}/resolver', [VistoriaController::class, 'resolverItem']);
        Route::post('/checklist-itens/{item}/avaliar', [VistoriaController::class, 'avaliarItem']);
        
        
        

  
        Route::apiResource('cargos', CargoController::class);
        
        Route::apiResource('users', UserController::class);
        Route::apiResource('empresas', EmpresaController::class);
        Route::apiResource('regionais', RegionalController::class);
        Route::post('/vistorias-seguranca', [VistoriaSegurancaController::class, 'store']);
        
            Route::post('/vistorias', [VistoriaController::class, 'store']);

        Route::get('/vistorias/{vistoria}/data-pdf', [VistoriaController::class, 'dataForPdf']);
        Route::get('/vistorias-seguranca', [VistoriaSegurancaController::class, 'index']);
        Route::get('/export/seguranca', [ExportController::class, 'exportSeguranca']);
    });
    Route::get('/agenda/{agenda}', [AgendaController::class, 'show']);
    
    Route::post('/agenda', [AgendaController::class, 'store']);
    Route::get('/export/qualidade', [ExportController::class, 'exportQualidade']);
});