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
use App\Http\Controllers\Api\MoaviController;
use App\Http\Controllers\VistoriaController;

use App\Http\Controllers\UserController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\RegionalController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\VistoriaSegurancaController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FcaController;
use App\Http\Controllers\FcaHierarchyController;
use App\Http\Controllers\FcaFormController;
use App\Http\Controllers\FcaChecklistController;
use App\Http\Controllers\FcaPoController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\ManutencaoController;
use App\Http\Middleware\IsAdmin;
use App\Services\ActivityLogService;

Route::middleware('api')->group(function () {

    Route::prefix('v1/moavi')->group(function () {
        Route::post('/auth/token', [MoaviController::class, 'token'])->middleware('throttle:moavi-token');

        Route::middleware(['moavi.auth:moavi:read', 'throttle:moavi-api'])->group(function () {
            Route::get('/agendamentos', [MoaviController::class, 'period']);
            Route::get('/agendamentos/proximos-15-dias', [MoaviController::class, 'next15Days']);
        });

        Route::post('/agendamentos/alteracoes', [MoaviController::class, 'changes'])
            ->middleware(['moavi.auth:moavi:changes', 'throttle:moavi-api']);
    });

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

        // Rotas de Vistoria de Manutencao
        Route::prefix('manutencao')->group(function () {
            Route::get('/atendimentos', [ManutencaoController::class, 'atendimentos']);
            Route::get('/atendimentos/{id}', [ManutencaoController::class, 'showAtendimento']);
            Route::get('/fiscais/{id}/agenda', [ManutencaoController::class, 'agendaFiscal']);
            Route::get('/agenda/minhas-vistorias-hoje', [ManutencaoController::class, 'minhasVistoriasHoje']);
            Route::get('/agenda-gantt', [ManutencaoController::class, 'ganttManutencao']);
            Route::patch('/agenda-gantt/{agenda}', [ManutencaoController::class, 'updateGantt']);
            Route::get('/agenda/{agenda}', [ManutencaoController::class, 'showAgenda']);
            Route::post('/agenda', [ManutencaoController::class, 'storeAgenda']);
            Route::delete('/agenda/{agenda}', [ManutencaoController::class, 'destroyAgenda']);
            Route::get('/vistorias/backlog', [ManutencaoController::class, 'backlog']);
            Route::get('/vistorias/ids-por-periodo', [ManutencaoController::class, 'getIdsByDateRange']);
            Route::get('/vistorias/{vistoria}/data-pdf', [ManutencaoController::class, 'dataForPdf']);
            Route::get('/vistorias/{vistoria}', [ManutencaoController::class, 'showVistoria']);
            Route::post('/vistorias', [ManutencaoController::class, 'storeVistoria']);
            Route::post('/checklist-itens/{item}/resolver', [ManutencaoController::class, 'resolverItem']);
            Route::post('/checklist-itens/{item}/avaliar', [ManutencaoController::class, 'avaliarItem']);
        });

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
        Route::get('/export/manutencao', [ExportController::class, 'exportManutencao']);
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
        Route::delete('/agenda/{agenda}', [AgendaController::class, 'destroy']);

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
        // Current user
        Route::get('/fca/me', [FcaController::class, 'me']);

        // Dashboard metrics
        Route::get('/fca/dashboard', [FcaController::class, 'dashboard']);

        // Monthly window
        Route::get('/fca/window', [FcaController::class, 'getWindow']);
        Route::put('/fca/window', [FcaController::class, 'updateWindow'])->middleware('fca.admin');

        // User management (admin only)
        Route::get('/fca/users', [FcaController::class, 'indexUsers'])->middleware('fca.admin_or_consulta');
        Route::post('/fca/users', [FcaController::class, 'createUser'])->middleware('fca.admin');
        // Rotas fixas devem vir ANTES das rotas com {id}
        Route::post('/fca/users/import-csv', [FcaController::class, 'importCsv'])->middleware('fca.admin');
        Route::get('/fca/users/imports', [FcaController::class, 'importHistory'])->middleware('fca.admin');
        Route::get('/fca/users/export-csv', [FcaController::class, 'exportCsv'])->middleware('fca.admin');
        Route::delete('/fca/users/clear-imported', [FcaController::class, 'clearImported'])->middleware('fca.admin');
        Route::put('/fca/users/{id}', [FcaController::class, 'updateUser'])->middleware('fca.admin');
        Route::delete('/fca/users/{id}', [FcaController::class, 'deleteUser'])->middleware('fca.admin');

        // Hierarchy
        Route::get('/fca/hierarchy', [FcaHierarchyController::class, 'getSubordinates']);
        Route::get('/fca/hierarchy/all', [FcaHierarchyController::class, 'getAllHierarchy'])->middleware('fca.admin_or_consulta');
        Route::get('/fca/hierarchy/full-tree', [FcaHierarchyController::class, 'fullTree'])->middleware('fca.admin_or_consulta');
        Route::post('/fca/hierarchy/link', [FcaHierarchyController::class, 'link']);
        Route::post('/fca/hierarchy/bulk-link', [FcaHierarchyController::class, 'bulkLink']);
        Route::delete('/fca/hierarchy/unlink/{childId}', [FcaHierarchyController::class, 'unlink']);

        // Link requests (admin)
        Route::get('/fca/link-requests', [FcaHierarchyController::class, 'getLinkRequests'])->middleware('fca.admin_or_consulta');
        Route::put('/fca/link-requests/{id}/approve', [FcaHierarchyController::class, 'approveLinkRequest'])->middleware('fca.admin');
        Route::put('/fca/link-requests/{id}/reject', [FcaHierarchyController::class, 'rejectLinkRequest'])->middleware('fca.admin');

        // ── FCA Form (checklist / PO) ──────────────────────────────────────
        Route::get('/fcaf/period/active',    [FcaFormController::class, 'activePeriod']);
        Route::get('/fcaf/periods',          [FcaFormController::class, 'periodHistory'])->middleware('fca.admin_or_consulta');
        Route::post('/fcaf/period/upload',   [FcaFormController::class, 'uploadBase'])->middleware('fca.admin');
        Route::get('/fcaf/tecnicos',         [FcaFormController::class, 'myTecnicos']);
        Route::get('/fcaf/analytics',        [FcaFormController::class, 'analytics']);
        Route::get('/fcaf/analytics/all',    [FcaFormController::class, 'analyticsAll'])->middleware('fca.admin_or_consulta');
        Route::get('/fcaf/analytics/all/export', [FcaFormController::class, 'exportAnalyticsAll'])->middleware('fca.admin_or_consulta');
        Route::get('/fcaf/tecnico/{id}',     [FcaFormController::class, 'tecnicoDetail']);

        Route::get('/fcaf/tecnico/{id}/checklist', [FcaChecklistController::class, 'getForTecnico']);
        Route::post('/fcaf/checklist',             [FcaChecklistController::class, 'store'])->middleware('fca.supervisor');

        Route::get('/fcaf/tecnico/{id}/pos',  [FcaPoController::class, 'getForTecnico']);
        Route::post('/fcaf/po',               [FcaPoController::class, 'store'])->middleware('fca.supervisor');
    });

});
