<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TicketAiPolicyService;
use PHPUnit\Framework\TestCase;

final class TicketAiPolicyServiceTest extends TestCase
{
    public function test_agent_domain_policy_inherits_platform_and_agent_rules(): void
    {
        $service = new TicketAiPolicyService();
        $resolved = $service->resolve([
            'type' => 'agent',
            'agent_user_id' => 9,
            'agent_domain_id' => 21,
        ], [
            [
                'scope_type' => 'platform',
                'tone' => 'formal',
                'prohibited_promises' => ['承诺立即退款'],
            ],
            [
                'scope_type' => 'agent',
                'agent_user_id' => 9,
                'tone' => 'warm',
                'extra_instruction' => '使用代理品牌称呼。',
            ],
            [
                'scope_type' => 'agent',
                'agent_user_id' => 9,
                'agent_domain_id' => 21,
                'knowledge_enabled' => false,
                'prohibited_promises' => ['承诺节点永久可用'],
            ],
        ]);

        $this->assertTrue($resolved['enabled']);
        $this->assertFalse($resolved['knowledge_enabled']);
        $this->assertSame('warm', $resolved['tone']);
        $this->assertSame('使用代理品牌称呼。', $resolved['extra_instruction']);
        $this->assertSame(['承诺立即退款', '承诺节点永久可用'], $resolved['prohibited_promises']);
        $this->assertSame(['platform', 'agent:9:0', 'agent:9:21'], $resolved['sources']);
    }

    public function test_invalid_and_duplicate_policies_are_safely_normalized(): void
    {
        $policies = (new TicketAiPolicyService())->normalizePolicies([
            ['scope_type' => 'site', 'site_id' => 3, 'tone' => 'unknown'],
            ['scope_type' => 'site', 'site_id' => 3, 'tone' => 'concise'],
            ['scope_type' => 'site', 'site_id' => 0, 'enabled' => false],
        ]);

        $this->assertCount(1, $policies);
        $this->assertSame('site:3', $policies[0]['scope_key']);
        $this->assertSame('concise', $policies[0]['tone']);
    }
}
