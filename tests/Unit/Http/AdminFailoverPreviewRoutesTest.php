<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineCloudController;
use App\Http\Routes\V2\AdminRoute;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class AdminFailoverPreviewRoutesTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_admin_route_table_exposes_only_cached_reads_and_synthetic_posts(): void
    {
        config(['app.key' => 'fixture-key']);
        $this->bindTestSettings(['secure_path' => 'preview-test-admin']);
        $router = new Router(new Dispatcher(app()), app());
        $router->group(['prefix' => 'api/v2', 'middleware' => 'api'], fn ($router) => (new AdminRoute())->map($router));
        $prefix = 'api/v2/preview-test-admin/server/machine/cloud/failover';
        $routes = [];
        foreach ($router->getRoutes() as $route) {
            if ($route->uri() !== $prefix && !str_starts_with($route->uri(), $prefix . '/')) continue;
            $this->assertContains('admin', $route->middleware());
            $this->assertContains('log', $route->middleware());
            $this->assertContains('api', $route->middleware());
            $routes[$route->methods()[0]][] = substr($route->uri(), strlen($prefix));
        }
        $this->assertEqualsCanonicalizing([
            'GET' => ['', '/history', '/replacements', '/services', '/services/run', '/services/shadow',
                '/services/dns', '/services/dns/run', '/services/native', '/services/native/run', '/services/native/history'],
            'POST' => ['/rehearse', '/services/simulate', '/services/advance', '/services/cancel',
                '/services/dns/simulate', '/services/dns/advance', '/services/dns/stop', '/services/dns/propagation'],
        ], $routes);
    }

    public function test_cloud_overview_advertises_preview_even_when_schema_is_missing(): void
    {
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $request = Request::create('/server/machine/cloud');
        $request->setUserResolver(fn () => (object) ['is_admin' => true]);
        $payload = (new MachineCloudController())->fetch($request, app(MachineCloudService::class))->getData(true)['data'];
        $this->assertTrue($payload['failover_preview_only']);
        $this->assertFalse($payload['available']);

        $request->setUserResolver(fn () => (object) ['is_admin' => false]);
        try {
            (new MachineCloudController())->fetch($request, app(MachineCloudService::class));
            $this->fail('Expected access denied');
        } catch (HttpException $denied) {
            $this->assertSame(403, $denied->getStatusCode());
        }
    }
}
