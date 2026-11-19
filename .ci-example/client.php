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

ini_set('display_errors', '0');
$root = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !preg_match('#\A/run/keli-example-linux-[0-9]+-[0-9]+/(candidate|rollback)\z#D', $root)
    || file_exists($root . '/.env')) exit(2);
try {
    require $root . '/vendor/autoload.php';
    $source = (new ReflectionClass(AdminRoute::class))->getFileName();
    if (!str_starts_with($source, $root . '/app/')) throw new RuntimeException('wrong_route_source');
    $requestAction = $argv[3] ?? '';
    if (!in_array($requestAction, ['status', 'v2', 'dns', 'stop', 'bad_input'], true)) throw new RuntimeException('unknown_fixture_action');
    $app = new Application($root);
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
    $subject = match ($argv[2] ?? '') {
        'granted' => ['id' => 42, 'is_admin' => true, 'banned' => false],
        'other' => ['id' => 43, 'is_admin' => true, 'banned' => false],
        'banned' => ['id' => 42, 'is_admin' => true, 'banned' => true],
        'staff' => ['id' => 42, 'is_admin' => false, 'is_staff' => true, 'banned' => false],
        default => null,
    };
    $user = $subject ? (new User())->forceFill($subject) : null;
    $uri = '/api/v2/fixture-admin/server/machine/cloud/failover/example';
    $body = $requestAction === 'bad_input' ? ['action' => 'v2', 'service_id' => 5] : ['action' => $requestAction];
    $request = $requestAction === 'status' ? Request::create($uri)
        : Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
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
    $record = ['status' => $response->getStatusCode(),
        'no_store' => str_contains((string) $response->headers->get('Cache-Control'), 'no-store')];
    if ($record['status'] === 200) $record['report'] = $response->getData(true)['data'];
    echo json_encode($record, JSON_THROW_ON_ERROR) . "\n";
} catch (ApiException|HttpExceptionInterface $error) {
    echo json_encode(['status' => $error instanceof HttpExceptionInterface ? $error->getStatusCode() : $error->getCode()], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['status' => 500, 'class' => get_class($error), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}

final class CiNoProductionWrite
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->isMethodSafe()) throw new RuntimeException('unexpected_audit_write');
        return $next($request);
    }
}
