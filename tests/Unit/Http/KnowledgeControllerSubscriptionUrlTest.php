<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\KnowledgeController;
use App\Models\User;
use App\Services\KnowledgeContextService;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Services\UserService;
use ReflectionMethod;
use Tests\TestCase;

final class KnowledgeControllerSubscriptionUrlTest extends TestCase
{
    public function test_placeholders_prefer_accelerated_subscription_proxy_url(): void
    {
        $user = new User();
        $user->token = 'knowledge-user-token';
        $userService = $this->createMock(UserService::class);
        $userService->method('isAvailable')->with($user)->willReturn(true);
        $subscriptionProxy = $this->createMock(SubscriptionProxyProbeService::class);
        $subscriptionProxy->expects($this->once())
            ->method('userPayload')
            ->with('knowledge-user-token')
            ->willReturn([
                'available' => true,
                'subscribe_url' => 'https://edge.example.test/sub/panel/knowledge-user-token',
            ]);
        $controller = new KnowledgeController(
            $userService,
            $this->createMock(KnowledgeContextService::class),
            $subscriptionProxy
        );
        $process = new ReflectionMethod($controller, 'processKnowledgeContent');

        $result = $process->invoke($controller, [
            'body' => '{{subscribeUrl}}|{{urlEncodeSubscribeUrl}}',
        ], $user, []);

        $this->assertSame(
            'https://edge.example.test/sub/panel/knowledge-user-token|'
                . urlencode('https://edge.example.test/sub/panel/knowledge-user-token'),
            $result['body']
        );
    }
}