<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\McpController;

Route::middleware('api')->group(function () {

    Route::prefix('mcp')->group(function () {
        Route::get('/entrantes-hoje',    [McpController::class, 'entrantes']);
        Route::get('/entrantes-por-hora',[McpController::class, 'entrantesPorHora']);
        Route::get('/anomalias-olt',     [McpController::class, 'anomaliasOlt']);
        Route::get('/backlog-aging',     [McpController::class, 'backlogAging']);
        Route::get('/radar-entrantes-planejamento', [McpController::class, 'radarEntrantesPlanejamento']);
        Route::get('/historico-diario',  [McpController::class, 'historicoDiario']);
        Route::get('/resumo-operacional',[McpController::class, 'resumoOperacional']);
    });

});
