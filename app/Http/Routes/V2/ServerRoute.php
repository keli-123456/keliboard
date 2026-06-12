<?php
namespace App\Http\Routes\V2;

use App\Contracts\NodeApiContract;
use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Server\MachineController;
use App\Http\Controllers\V2\Server\MachineReleaseController;
use App\Http\Controllers\V2\Server\ServerController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {

        $router->group([
            'prefix' => NodeApiContract::V2_SERVER_PREFIX,
            'middleware' => 'server'
        ], function ($route) {
            $route->match(['GET', 'POST'], NodeApiContract::ENDPOINT_HANDSHAKE, [ServerController::class, 'handshake']);
            $route->post(NodeApiContract::ENDPOINT_REPORT, [ServerController::class, 'report']);
            $route->get(NodeApiContract::ENDPOINT_CONFIG, [UniProxyController::class, 'config']);
            $route->get(NodeApiContract::ENDPOINT_USER, [UniProxyController::class, 'user']);
            $route->get(NodeApiContract::ENDPOINT_USER_DELTA, [UniProxyController::class, 'userDelta']);
            $route->post(NodeApiContract::ENDPOINT_PUSH, [UniProxyController::class, 'push']);
            $route->post(NodeApiContract::ENDPOINT_ALIVE, [UniProxyController::class, 'alive']);
            $route->get(NodeApiContract::ENDPOINT_ALIVE_LIST, [UniProxyController::class, 'alivelist']);
            $route->post(NodeApiContract::ENDPOINT_STATUS, [UniProxyController::class, 'status']);
        });

        $router->group([
            'prefix' => NodeApiContract::V2_SERVER_MACHINE_PREFIX,
        ], function ($route) {
            $route->get('kelinode-rs/install.sh', [MachineReleaseController::class, 'installScript']);
            $route->get('releases/{component}/{platform}/latest', [MachineReleaseController::class, 'latest']);
            $route->get('releases/{component}/{version}/{platform}/manifest.json', [MachineReleaseController::class, 'manifest']);
            $route->get('releases/{component}/{version}/{platform}/archive.tar.gz', [MachineReleaseController::class, 'archive']);
            $route->post(NodeApiContract::ENDPOINT_MACHINE_NODES, [MachineController::class, 'nodes']);
            $route->post(NodeApiContract::ENDPOINT_MACHINE_STATUS, [MachineController::class, 'status']);
        });
    }
}
