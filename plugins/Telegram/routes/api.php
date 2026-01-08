<?php

require_once __DIR__ . '/../Controllers/LoginController.php';

use Illuminate\Support\Facades\Route;
use Plugin\Telegram\Controllers\LoginController;

// Final routes: /api/plugin/telegram/*
Route::prefix('api/plugin/telegram')
    ->middleware(['api'])
    ->group(function () {
        Route::post('/login', [LoginController::class, 'login']);
        Route::post('/login/start', [LoginController::class, 'start']);
        Route::post('/login/poll', [LoginController::class, 'poll']);
    });
