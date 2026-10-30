<?php

use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\ProjectWriteApiController;
use Illuminate\Support\Facades\Route;

// API JSON para n8n / integrações. Header: X-Api-Key: <VIX_API_KEY>
Route::middleware('api.key')->group(function () {
    Route::get('/projetos', [ProjectApiController::class, 'index']);
    Route::get('/projetos/{project}', [ProjectApiController::class, 'show']);
});

// Escrita (AlfaMatriz e quem lança demandas). Header: X-Api-Key: <VIX_API_WRITE_KEY>.
// Opcionais: X-Vix-Autor (e-mail do usuário que fez) e X-Vix-Origem (aparece no histórico).
Route::middleware(['api.key:escrita', 'throttle:60,1'])->group(function () {
    Route::post('/projetos', [ProjectWriteApiController::class, 'storeProject']);
    Route::post('/projetos/{project}/status', [ProjectWriteApiController::class, 'status']);
    Route::post('/projetos/{project}/passos', [ProjectWriteApiController::class, 'storeStep']);
    Route::post('/projetos/{project}/passos/{step}/check', [ProjectWriteApiController::class, 'check']);
    Route::post('/projetos/{project}/passos/{step}/registros', [ProjectWriteApiController::class, 'comment']);
});
