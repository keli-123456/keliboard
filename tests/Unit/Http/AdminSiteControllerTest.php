<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\SiteController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminSiteControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->bindTestSettings(['secure_path' => 'admin']);
        config(['app.key' => 'testing-site-key']);
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
    }

    public function test_admin_can_create_site_and_primary_domain(): void
    {
        $request = Request::create('/admin/site/save', 'POST', [
            'code' => 'cheap-main',
            'name' => 'Cheap Main',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
            'domains' => [
                [
                    'domain' => 'https://Cheap.Example.Test:443/path',
                    'status' => SiteDomain::STATUS_ACTIVE,
                    'is_primary' => true,
                ],
            ],
        ]);

        $payload = $this->responsePayload(app(SiteController::class)->save($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('cheap-main', $payload['data']['code']);
        $this->assertTrue($payload['data']['is_default']);
        $this->assertSame('cheap.example.test', $payload['data']['domains'][0]['domain']);
        $this->assertTrue($payload['data']['domains'][0]['is_primary']);
        $this->assertSame(1, Site::query()->where('is_default', true)->count());
    }

    public function test_duplicate_site_domain_is_rejected(): void
    {
        $site = Site::query()->create([
            'code' => 'first',
            'name' => 'First Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'taken.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = Request::create('/admin/site/save', 'POST', [
            'code' => 'second',
            'name' => 'Second Site',
            'domains' => [
                ['domain' => 'taken.example.test'],
            ],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        app(SiteController::class)->save($request);
    }

    public function test_admin_route_registers_site_endpoints(): void
    {
        $registrar = new AdminSiteRouteRegistrar();

        (new AdminRoute())->map($registrar);

        $this->assertContains([
            'method' => 'GET',
            'uri' => '/admin/site/fetch',
            'action' => [SiteController::class, 'fetch'],
        ], $registrar->routes);
        $this->assertContains([
            'method' => 'POST',
            'uri' => '/admin/site/save',
            'action' => [SiteController::class, 'save'],
        ], $registrar->routes);
    }

    private function bindRequestValidateMacro(): void
    {
        if (Request::hasMacro('validate')) {
            return;
        }

        Request::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}

final class AdminSiteRouteRegistrar implements Registrar
{
    /**
     * @var array<int, array{method: string, uri: string, action: mixed}>
     */
    public array $routes = [];

    /**
     * @var list<string>
     */
    private array $prefixes = [];

    public function get($uri, $action)
    {
        return $this->record('GET', $uri, $action);
    }

    public function post($uri, $action)
    {
        return $this->record('POST', $uri, $action);
    }

    public function put($uri, $action)
    {
        return $this->record('PUT', $uri, $action);
    }

    public function delete($uri, $action)
    {
        return $this->record('DELETE', $uri, $action);
    }

    public function patch($uri, $action)
    {
        return $this->record('PATCH', $uri, $action);
    }

    public function options($uri, $action)
    {
        return $this->record('OPTIONS', $uri, $action);
    }

    public function any($uri, $action)
    {
        return $this->record('ANY', $uri, $action);
    }

    public function match($methods, $uri, $action)
    {
        foreach ((array) $methods as $method) {
            $this->record(strtoupper((string) $method), $uri, $action);
        }
    }

    public function resource($name, $controller, array $options = [])
    {
        return null;
    }

    public function group(array $attributes, $routes)
    {
        $this->prefixes[] = (string) ($attributes['prefix'] ?? '');
        $routes($this);
        array_pop($this->prefixes);
    }

    public function substituteBindings($route)
    {
        return $route;
    }

    public function substituteImplicitBindings($route)
    {
        return null;
    }

    private function record(string $method, string $uri, $action): AdminSiteRecordedRoute
    {
        $this->routes[] = [
            'method' => $method,
            'uri' => '/' . trim(implode('/', array_filter($this->prefixes)) . '/' . ltrim($uri, '/'), '/'),
            'action' => $action,
        ];

        return new AdminSiteRecordedRoute();
    }
}

final class AdminSiteRecordedRoute
{
    public function whereNumber(string $parameter): self
    {
        return $this;
    }

    public function name(string $name): self
    {
        return $this;
    }
}
