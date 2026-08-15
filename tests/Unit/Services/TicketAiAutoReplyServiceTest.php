<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TicketAiAssistantService;
use App\Services\TicketAiAutoReplyService;
use App\Services\TicketService;
use App\Support\Setting;
use Tests\TestCase;

final class TicketAiAutoReplyServiceTest extends TestCase
{
    public function test_allows_only_low_risk_high_confidence_knowledge_backed_reply(): void
    {
        $this->bindSettings();

        $this->assertNull($this->service()->rejectionReason($this->safeResult()));
    }

    public function test_rejects_unstructured_risky_or_human_review_results(): void
    {
        $this->bindSettings();
        $service = $this->service();

        $this->assertSame('unstructured_output', $service->rejectionReason($this->safeResult([
            'structured_output' => false,
        ])));
        $this->assertSame('risk_not_low', $service->rejectionReason($this->safeResult([
            'risk' => 'medium',
        ])));
        $this->assertSame('needs_human', $service->rejectionReason($this->safeResult([
            'needs_human' => true,
        ])));
    }

    public function test_rejects_low_confidence_missing_knowledge_and_disallowed_category(): void
    {
        $this->bindSettings();
        $service = $this->service();

        $this->assertSame('low_confidence', $service->rejectionReason($this->safeResult([
            'confidence' => 0.89,
        ])));
        $this->assertSame('knowledge_not_matched', $service->rejectionReason($this->safeResult([
            'matched_knowledge' => [],
        ])));
        $this->assertSame('category_not_allowed', $service->rejectionReason($this->safeResult([
            'category' => '套餐订单',
        ])));
    }

    public function test_empty_category_selection_disables_all_automatic_sends(): void
    {
        $this->bindSettings([
            'ticket_ai_auto_reply_allowed_categories' => [],
        ]);

        $this->assertSame('category_not_allowed', $this->service()->rejectionReason($this->safeResult()));
    }

    public function test_ticket_ai_queue_is_consumed_in_local_and_production(): void
    {
        $horizon = require dirname(__DIR__, 3) . '/config/horizon.php';

        $this->assertContains('ticket_ai', $horizon['environments']['production']['Xboard']['queue']);
        $this->assertContains('ticket_ai', $horizon['environments']['local']['Xboard']['queue']);
    }

    private function service(): TicketAiAutoReplyService
    {
        return new TicketAiAutoReplyService(new TicketAiAssistantService(), new TicketService());
    }

    /** @return array<string, mixed> */
    private function safeResult(array $overrides = []): array
    {
        return array_merge([
            'structured_output' => true,
            'risk' => 'low',
            'needs_human' => false,
            'confidence' => 0.95,
            'category' => '订阅与节点',
            'draft' => '请重新导入订阅后再试。',
            'matched_knowledge' => [['id' => 1, 'title' => '订阅导入']],
        ], $overrides);
    }

    private function bindSettings(array $overrides = []): void
    {
        $values = array_merge([
            'ticket_ai_auto_reply_min_confidence' => 0.9,
            'ticket_ai_auto_reply_require_knowledge' => true,
            'ticket_ai_auto_reply_allowed_categories' => ['客户端连接', '订阅与节点'],
        ], $overrides);

        app()->instance(Setting::class, new class($values) extends Setting {
            public function __construct(private array $values)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
            }
        });
    }
}
