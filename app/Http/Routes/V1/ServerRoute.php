<?php
namespace App\Http\Routes\V1;

use App\Contracts\NodeApiContract;
use App\Http\Controllers\V1\Server\DeepbworkController;
use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server',
        ], function ($router) {
            $router->group([
                'prefix' => NodeApiContract::V1_UNIPROXY_SEGMENT,
                'middleware' => 'server'
            ], function ($route) {
                $route->get(NodeApiContract::ENDPOINT_CONFIG, [UniProxyController::class, 'config']);
                $route->get(NodeApiContract::ENDPOINT_USER, [UniProxyController::class, 'user']);
                $route->get(NodeApiContract::ENDPOINT_USER_DELTA, [UniProxyController::class, 'userDelta']);
                $route->post(NodeApiContract::ENDPOINT_PUSH, [UniProxyController::class, 'push']);
                $route->post(NodeApiContract::ENDPOINT_ALIVE, [UniProxyController::class, 'alive']);
                $route->get(NodeApiContract::ENDPOINT_ALIVE_LIST, [UniProxyController::class, 'alivelist']);
                $route->post(NodeApiContract::ENDPOINT_STATUS, [UniProxyController::class, 'status']);
            });
            $router->group([
                'prefix' => 'ShadowsocksTidalab',
                'middleware' => 'server:shadowsocks'
            ], function ($route) {
                $route->get('user', [ShadowsocksTidalabController::class, 'user']);
                $route->post('submit', [ShadowsocksTidalabController::class, 'submit']);
            });
            $router->group([
                'prefix' => 'TrojanTidalab',
                'middleware' => 'server:trojan'
            ], function ($route) {
                $route->get('config', [TrojanTidalabController::class, 'config']);
                $route->get('user', [TrojanTidalabController::class, 'user']);
                $route->post('submit', [TrojanTidalabController::class, 'submit']);
            });
        });
    }
}
