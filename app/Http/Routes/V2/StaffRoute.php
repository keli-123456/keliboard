<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Staff\SiteController;
use App\Http\Controllers\V2\Staff\TicketController;
use App\Http\Controllers\V2\Staff\UserController;
use Illuminate\Contracts\Routing\Registrar;

class StaffRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/staff',
            'middleware' => ['staff', 'log'],
        ], function ($router) {
            // Ticket
            $router->group([
                'prefix' => 'ticket'
            ], function ($router) {
                $router->get('/overview', [TicketController::class, 'overview']);
                $router->any('/fetch', [TicketController::class, 'fetch']);
                $router->post('/reply', [TicketController::class, 'reply']);
                $router->post('/close', [TicketController::class, 'close']);
                $router->get('/attachment/{id}', [TicketController::class, 'attachment']);
            });

            // User
            $router->group([
                'prefix' => 'user'
            ], function ($router) {
                $router->get('/getUserInfoById', [UserController::class, 'getUserInfoById']);
                $router->get('/getUserInfoByEmail', [UserController::class, 'getUserInfoByEmail']);
            });

            // Sites (read-only config for staff desk)
            $router->group([
                'prefix' => 'site'
            ], function ($router) {
                $router->get('/fetch', [SiteController::class, 'fetch']);
            });
        });
    }
}
