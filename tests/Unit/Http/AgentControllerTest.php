<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\AgentController;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindJsonResponseFactory();
    }

    public function test_overview_returns_agent_service_payload_for_current_user(): void
    {
        $service = new class extends AgentCenterService {
            public ?int $userId = null;

            public function overview(User $agent): array
            {
                $this->userId = (int) $agent->id;
                return ['profile' => ['status' => 'active']];
            }
        };
        app()->instance(AgentCenterService::class, $service);

        $response = (new AgentController())->overview($this->requestForUser(42));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(42, $service->userId);
        $this->assertSame('success', $payload['status']);
        $this->assertSame('active', $payload['data']['profile']['status']);
    }

    public function test_users_returns_owned_subordinate_list(): void
    {
        $service = new class extends AgentCenterService {
            public ?int $userId = null;

            public function listUsers(User $agent): array
            {
                $this->userId = (int) $agent->id;
                return [['id' => 7, 'email' => 'buyer@example.test']];
            }
        };
        app()->instance(AgentCenterService::class, $service);

        $response = (new AgentController())->users($this->requestForUser(9));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(9, $service->userId);
        $this->assertSame('buyer@example.test', $payload['data'][0]['email']);
    }

    private function requestForUser(int $id): Request
    {
        $request = Request::create('/user/agent/overview', 'GET');
        $user = new User();
        $user->id = $id;
        $request->setUserResolver(fn () => $user);
        return $request;
    }
}
