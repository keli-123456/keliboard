<?php

require_once __DIR__ . '/../Controllers/ProxyController.php';

use Illuminate\Support\Facades\Route;
use Plugin\RemoteCheckProxy\Controllers\ProxyController;

// Final routes: /api/plugin/remote-check/*
Route::prefix('api/plugin/remote-check')
    ->middleware(['api'])
    ->group(function () {
        Route::get('/fetch', [ProxyController::class, 'fetch']);
        Route::get('/random-ip', [ProxyController::class, 'randomIp']);
    });
