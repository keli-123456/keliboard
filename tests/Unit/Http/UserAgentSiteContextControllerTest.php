<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\AgentSiteContextController;
use App\Http\Routes\V1\UserRoute;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserAgentSiteContextControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
    }

    public function test_normal_user_gets_null_site_context(): void
    {
        $user = $this->createUser('user@example.test');
        $request = $this->userRequest($user);

        $payload = $this->responsePayload(app(AgentSiteContextController::class)->show($request));

        $this->assertSame('success', $payload['status']);
        $this->assertNull($payload['data']['site']);
    }

    public function test_bound_subordinate_gets_agent_site_context(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'site_name' => 'Agent Storefront',
            'announcement' => 'Welcome buyers',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = $this->userRequest($buyer);

        $payload = $this->responsePayload(app(AgentSiteContextController::class)->show($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('Agent Storefront', $payload['data']['site']['site_name']);
        $this->assertSame('Welcome buyers', $payload['data']['site']['announcement']);
        $this->assertSame($agent->id, $payload['data']['site']['agent_user_id']);
    }

    public function test_user_route_registers_agent_site_context_endpoint(): void
    {
        $registrar = new UserAgentSiteContextRouteRegistrar();

        (new UserRoute())->map($registrar);

        $this->assertContains([
            'method' => 'GET',
            'uri' => '/user/agent/site-context',
            'action' => [AgentSiteContextController::class, 'show'],
        ], $registrar->routes);
    }

    private function userRequest(User $user): Request
    {
        $request = Request::create('/api/v1/user/agent/site-context', 'GET');
        $request->headers->set('host', 'panel.example.test');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}

final class UserAgentSiteContextRouteRegistrar implements Registrar
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

    private function record(string $method, string $uri, $action): void
    {
        $this->routes[] = [
            'method' => $method,
            'uri' => '/' . trim(implode('/', array_filter($this->prefixes)) . '/' . ltrim($uri, '/'), '/'),
            'action' => $action,
        ];
    }
}
