<?php

require_once __DIR__ . '/../Controllers/RunController.php';

use Illuminate\Support\Facades\Route;
use Plugin\NodeAutoRename\Controllers\RunController;

Route::prefix('api/v1/node-auto-rename')
    ->middleware(['api', 'admin'])
    ->group(function () {
        Route::post('/run', [RunController::class, 'run']);
    });
