<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\App\BootstrapController;
use App\Http\Controllers\V1\App\ConfigController;
use Illuminate\Contracts\Routing\Registrar;

class AppRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'app',
            'middleware' => 'user'
        ], function ($router) {
            $router->get('/bootstrap', [BootstrapController::class, 'bootstrap']);
            $router->get('/config', [ConfigController::class, 'config']);
        });
    }
}
