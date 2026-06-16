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
            public ?string $keyword = null;

            public function listUsers(User $agent, ?string $keyword = null): array
            {
                $this->userId = (int) $agent->id;
                $this->keyword = $keyword;
                return [['id' => 7, 'email' => 'buyer@example.test']];
            }
        };
        app()->instance(AgentCenterService::class, $service);

        $request = $this->requestForUser(9);
        $request->query->set('keyword', ' buyer-token ');

        $response = (new AgentController())->users($request);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(9, $service->userId);
        $this->assertSame('buyer-token', $service->keyword);
        $this->assertSame('buyer@example.test', $payload['data'][0]['email']);
    }

    public function test_subscribe_link_returns_service_payload_for_current_user_and_target(): void
    {
        $service = new class extends AgentCenterService {
            public ?int $userId = null;
            public ?int $targetUserId = null;

            public function subscribeLink(User $agent, int $targetUserId): array
            {
                $this->userId = (int) $agent->id;
                $this->targetUserId = $targetUserId;
                return ['subscribe_url' => 'https://example.test/s/buyer-token'];
            }
        };
        app()->instance(AgentCenterService::class, $service);

        $response = (new AgentController())->subscribeLink($this->requestForUser(9), 7);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(9, $service->userId);
        $this->assertSame(7, $service->targetUserId);
        $this->assertSame('https://example.test/s/buyer-token', $payload['data']['subscribe_url']);
    }

    public function test_reset_subscription_returns_service_payload_for_current_user_and_target(): void
    {
        $service = new class extends AgentCenterService {
            public ?int $userId = null;
            public ?int $targetUserId = null;

            public function resetSubscription(User $agent, int $targetUserId): array
            {
                $this->userId = (int) $agent->id;
                $this->targetUserId = $targetUserId;
                return ['subscribe_url' => 'https://example.test/s/new-token'];
            }
        };
        app()->instance(AgentCenterService::class, $service);

        $response = (new AgentController())->resetSubscription($this->requestForUser(9), 7);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(9, $service->userId);
        $this->assertSame(7, $service->targetUserId);
        $this->assertSame('https://example.test/s/new-token', $payload['data']['subscribe_url']);
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
