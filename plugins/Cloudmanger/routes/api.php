<?php

require_once __DIR__ . '/../Controllers/TokenController.php';
require_once __DIR__ . '/../Controllers/WorkerConfigController.php';
require_once __DIR__ . '/../Controllers/WorkerScriptController.php';
require_once __DIR__ . '/../Controllers/MeController.php';

use Illuminate\Support\Facades\Route;
use Plugin\Cloudmanger\Controllers\MeController;
use Plugin\Cloudmanger\Controllers\TokenController;
use Plugin\Cloudmanger\Controllers\WorkerConfigController;
use Plugin\Cloudmanger\Controllers\WorkerScriptController;

// Final routes: /api/cm/v1/*
Route::prefix('api/cm/v1')
    ->middleware(['api'])
    ->group(function () {
        Route::prefix('admin')
            ->middleware(['admin'])
            ->group(function () {
                Route::get('/me', [MeController::class, 'me']);

                Route::get('/users/{userId}/worker-configs', [WorkerConfigController::class, 'listForUser']);
                Route::get('/users/{userId}/worker-configs/{worker}', [WorkerConfigController::class, 'getForUser']);
                Route::put('/users/{userId}/worker-configs/{worker}', [WorkerConfigController::class, 'upsertForUser']);
                Route::delete('/users/{userId}/worker-configs/{worker}', [WorkerConfigController::class, 'deleteForUser']);

                Route::get('/users/{userId}/worker-scripts/{worker}', [WorkerScriptController::class, 'listForUser']);
                Route::get('/users/{userId}/worker-scripts/{worker}/{scriptId}', [WorkerScriptController::class, 'getForUser']);
                Route::put('/users/{userId}/worker-scripts/{worker}/{scriptId}', [WorkerScriptController::class, 'upsertForUser']);
                Route::delete('/users/{userId}/worker-scripts/{worker}/{scriptId}', [WorkerScriptController::class, 'deleteForUser']);

                Route::get('/users/{userId}/tokens', [TokenController::class, 'listForUser']);
                Route::post('/users/{userId}/tokens', [TokenController::class, 'createForUser']);
                Route::delete('/users/{userId}/tokens/{tokenId}', [TokenController::class, 'revokeForUser']);
            });

        Route::get('/worker-configs/{worker}/rendered', [WorkerConfigController::class, 'rendered'])
            ->middleware(['user', 'ability:cm-worker']);

        Route::get('/worker-scripts/{worker}', [WorkerScriptController::class, 'bundle'])
            ->middleware(['user', 'ability:cm-worker']);
        Route::get('/worker-scripts/{worker}/{scriptId}', [WorkerScriptController::class, 'fetch'])
            ->middleware(['user', 'ability:cm-worker']);
    });
