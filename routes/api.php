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
use App\Http\Controllers\FcaController;
use App\Http\Controllers\FcaRegistroController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MonitorController;
use App\Http\Middleware\IsAdmin;
use App\Services\ActivityLogService;

Route::middleware('api')->group(function () {

    // Login (publico)
    Route::post('/login', function(Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciais invalidas'], 401);
        }

        if ($user->status !== 'ativo') {
            return response()->json(['message' => 'Seu acesso esta inativo. Procure o administrador.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLogService::log(
            'auth.login',
            "Login efetuado por {$user->email}",
            $user->id,
            ['email' => $user->email]
        );

        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    })->middleware('throttle:login');

    Route::get('/access-requests/options', [AccessRequestController::class, 'options'])->middleware('throttle:login');
    Route::post('/access-requests', [AccessRequestController::class, 'store'])->middleware('throttle:login');

    // Monitor SP publico por link magico (somente leitura)
    Route::middleware('monitor.magic')->prefix('monitor-public')->group(function () {
        Route::get('/health', [MonitorController::class, 'health']);
        Route::get('/dashboard', [MonitorController::class, 'dashboard']);
        Route::get('/city/{nome}', [MonitorController::class, 'city']);
        Route::get('/cities-analytics', [MonitorController::class, 'citiesAnalytics']);
    });

    // Rotas protegidas que exigem autenticacao
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/vistorias/ids-por-periodo', [VistoriaController::class, 'getIdsByDateRange']);
        Route::get('/atendimentos', [AtendimentoController::class, 'index']);
        Route::get('/atendimentos/{id}', [AtendimentoController::class, 'show']);
        Route::get('/fiscais/{id}/agenda', [FiscalController::class, 'showAgenda']);
        Route::get('/fiscais', [FiscalController::class, 'index']);
        Route::post('/location', [LocationController::class, 'store']);
        Route::get('/home-data', [HomeController::class, 'index']);

        // Rotas de Agenda
        Route::get('/agenda/minhas-vistorias-hoje', [AgendaController::class, 'minhasVistoriasHoje']);
        Route::get('/agenda-gantt', [AgendaController::class, 'gantt']);
        Route::patch('/agenda-gantt/{agenda}', [AgendaController::class, 'updateGantt']);

        // Rotas de Vistoria
        Route::get('/vistorias/backlog', [VistoriaController::class, 'backlog']);
        Route::get('/vistorias/{vistoria}', [VistoriaController::class, 'show']);

        // Rotas de Correcao
        Route::post('/checklist-itens/{item}/resolver', [VistoriaController::class, 'resolverItem']);
        Route::post('/checklist-itens/{item}/avaliar', [VistoriaController::class, 'avaliarItem']);

        Route::apiResource('cargos', CargoController::class);
        Route::apiResource('users', UserController::class);
        Route::apiResource('empresas', EmpresaController::class);
        Route::apiResource('regionais', RegionalController::class);
        Route::post('/vistorias-seguranca', [VistoriaSegurancaController::class, 'store']);
        Route::post('/vistorias-seguranca/{vistoria}/upload', [VistoriaSegurancaController::class, 'upload']);
        Route::post('/vistorias', [VistoriaController::class, 'store']);

        Route::get('/vistorias/{vistoria}/data-pdf', [VistoriaController::class, 'dataForPdf']);
        Route::get('/vistorias-seguranca', [VistoriaSegurancaController::class, 'index']);
        Route::get('/export/seguranca', [ExportController::class, 'exportSeguranca']);
        Route::get('/export/qualidade', [ExportController::class, 'exportQualidade']);
        Route::patch('/vistorias-seguranca/{vistoria}/invalidar', [ExportController::class, 'invalidar']);

        // Chat e notificacoes
        Route::get('/chat/users', [ChatController::class, 'users']);
        Route::get('/chat/conversations/{user}', [ChatController::class, 'conversation']);
        Route::post('/chat/conversations/{user}/messages', [ChatController::class, 'sendMessage']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/send', [NotificationController::class, 'send']);

        // Monitor SP (proxy interno)
        Route::get('/monitor/health', [MonitorController::class, 'health']);
        Route::get('/monitor/dashboard', [MonitorController::class, 'dashboard']);
        Route::get('/monitor/city/{nome}', [MonitorController::class, 'city']);
        Route::get('/monitor/cities-analytics', [MonitorController::class, 'citiesAnalytics']);

        Route::get('/agenda/{agenda}', [AgendaController::class, 'show']);
        Route::post('/agenda', [AgendaController::class, 'store']);
        Route::delete('/agenda/{id}', [AgendaController::class, 'destroy']);

        Route::middleware(IsAdmin::class)->group(function () {
            Route::get('/admin/overview', [AdminOverviewController::class, 'index']);
            Route::get('/admin/access-requests', [AccessRequestController::class, 'index']);
            Route::get('/admin/access-requests/{accessRequest}', [AccessRequestController::class, 'show']);
            Route::post('/admin/access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve']);
            Route::post('/admin/access-requests/{accessRequest}/reject', [AccessRequestController::class, 'reject']);
        });
    });

    Route::post('/loginfca', [FcaController::class, 'login'])->middleware('throttle:loginfca');

    Route::middleware('fca.auth')->group(function () {
        Route::post('/fca/usuario', [FcaController::class, 'criarUsuario'])->middleware('fca.admin');
        Route::post('/fca/users', [FcaController::class, 'criarUsuario'])->middleware('fca.admin');
        Route::get('/fca/registros', [FcaRegistroController::class, 'index']);
        Route::post('/fca/registros', [FcaRegistroController::class, 'store']);
        Route::put('/fca/registros/{id}', [FcaRegistroController::class, 'update']);
        Route::delete('/fca/registros/{id}', [FcaRegistroController::class, 'destroy']);
        Route::get('/fca/registros/coordenador', [FcaRegistroController::class, 'indexCoordenador']);
        Route::get('/fca/registros/all', [FcaRegistroController::class, 'indexAll'])->middleware('fca.admin');
        Route::get('/fca/users', [FcaController::class, 'indexUsers'])->middleware('fca.admin');
        Route::put('fca/users/{userId}', [FcaController::class, 'updateUser'])->middleware('fca.admin');
    });

});
