<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Routes\V2\AdminRoute;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\InteractsWithInMemoryDatabase;

if (PHP_SAPI !== 'cli-server' || getenv('EXAMPLE_FIXTURE_WEB') !== 'isolated-ci' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || file_exists('/app/.env')) {
    http_response_code(404);
    return;
}
if (($_SERVER['REQUEST_URI'] ?? null) === '/fixture/identity') {
    header('Content-Type: application/json');
    $dir = '/run/keli-failover-example';
    $socket = $dir . '/worker.sock';
    $write = is_dir($dir) ? @fopen($dir . '/.write-test', 'xb') : false;
    if (is_resource($write)) { fclose($write); unlink($dir . '/.write-test'); }
    echo json_encode(['uid' => posix_geteuid(), 'groups' => posix_getgroups(), 'sapi' => PHP_SAPI,
        'network_namespace' => readlink('/proc/self/ns/net'), 'socket_visible' => @filetype($socket) === 'socket',
        'socket_mode' => @filetype($socket) === 'socket' ? fileperms($socket) & 0777 : null,
        'directory_write_rejected' => $write === false, 'state_mount_absent' => !file_exists('/var/lib/keli-failover-example')]);
    return;
}

try {
    require '/app/vendor/autoload.php';
    require '/app/tests/Support/InteractsWithInMemoryDatabase.php';
    if (!str_starts_with((new ReflectionClass(AdminRoute::class))->getFileName(), '/app/app/')) throw new RuntimeException('wrong_runtime');
    $app = new Application('/app');
    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    $app->instance('config', new \Illuminate\Config\Repository(['app' => ['key' => 'ci-example-key']]));
    if (getenv('FAILOVER_EXAMPLE_GRANT_ID')) {
        config(['failover_example' => ['id' => getenv('FAILOVER_EXAMPLE_GRANT_ID'), 'admin' => getenv('FAILOVER_EXAMPLE_ADMIN_ID'),
            'opens' => getenv('FAILOVER_EXAMPLE_OPENS_AT'), 'expires' => getenv('FAILOVER_EXAMPLE_EXPIRES_AT')]]);
    }
    (new class {
        use InteractsWithInMemoryDatabase;
        public function setup(): void
        {
            $this->bindTestSettings(['secure_path' => 'fixture-admin']);
            $this->bindJsonResponseFactory();
        }
    })->setup();
    $request = Request::capture();
    $subject = match ($request->header('X-Fixture-Identity')) {
        'granted' => ['id' => 42, 'is_admin' => true, 'banned' => false],
        'other' => ['id' => 43, 'is_admin' => true, 'banned' => false],
        'banned' => ['id' => 42, 'is_admin' => true, 'banned' => true],
        'staff' => ['id' => 42, 'is_admin' => false, 'is_staff' => true, 'banned' => false],
        default => null,
    };
    $user = $subject ? (new User())->forceFill($subject) : null;
    $request->setUserResolver(fn () => $user);
    $app->instance('request', $request);
    $app->instance('auth', new class($user) {
        public function __construct(private ?User $user) {}
        public function guard(): self { return $this; }
        public function user(): ?User { return $this->user; }
    });
    $router = new Router(new Dispatcher($app), $app);
    $router->aliasMiddleware('admin', \App\Http\Middleware\Admin::class);
    $router->aliasMiddleware('log', CiNoProductionWrite::class);
    $router->group(['prefix' => 'api/v2'], fn ($router) => (new AdminRoute())->map($router));
    $response = $router->dispatch($request);
    if ($request->header('X-Fixture-Drop-Reply') === '1' && $request->input('action') === 'dns' && $response->getStatusCode() === 200) {
        response()->json(['result_discarded' => true], 504)->send();
    } else {
        $response->send();
    }
} catch (ApiException|HttpExceptionInterface $error) {
    response()->json(['error' => 'denied'], $error instanceof HttpExceptionInterface ? $error->getStatusCode() : $error->getCode())->send();
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'fixture', 'class' => get_class($error), 'line' => $error->getLine()]);
}

final class CiNoProductionWrite
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->isMethodSafe()) throw new RuntimeException('unexpected_audit_write');
        return $next($request);
    }
}
