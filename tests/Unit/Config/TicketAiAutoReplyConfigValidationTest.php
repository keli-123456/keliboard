<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Arr;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Tests\TestCase;

final class TicketAiAutoReplyConfigValidationTest extends TestCase
{
    private const KEYS = [
        'ticket_ai_auto_reply_enable',
        'ticket_ai_auto_reply_mode',
        'ticket_ai_auto_reply_on_user_reply',
        'ticket_ai_auto_reply_min_confidence',
        'ticket_ai_auto_reply_require_knowledge',
        'ticket_ai_auto_reply_allowed_categories',
        'ticket_ai_auto_reply_allowed_categories.*',
        'ticket_ai_auto_reply_max_per_ticket',
    ];

    public function test_safe_auto_reply_settings_are_accepted(): void
    {
        $validator = $this->validator()->make([
            'ticket_ai_auto_reply_enable' => true,
            'ticket_ai_auto_reply_mode' => 'broad',
            'ticket_ai_auto_reply_on_user_reply' => true,
            'ticket_ai_auto_reply_min_confidence' => 0.9,
            'ticket_ai_auto_reply_require_knowledge' => true,
            'ticket_ai_auto_reply_allowed_categories' => ['客户端连接', '订阅与节点'],
            'ticket_ai_auto_reply_max_per_ticket' => 1,
        ], Arr::only(ConfigSave::RULES, self::KEYS));

        $this->assertTrue($validator->passes());
    }

    public function test_unsafe_auto_reply_settings_are_rejected(): void
    {
        $validator = $this->validator()->make([
            'ticket_ai_auto_reply_mode' => 'unsafe',
            'ticket_ai_auto_reply_min_confidence' => 0.2,
            'ticket_ai_auto_reply_allowed_categories' => ['支付退款'],
            'ticket_ai_auto_reply_max_per_ticket' => 11,
        ], Arr::only(ConfigSave::RULES, self::KEYS));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ticket_ai_auto_reply_mode', $validator->errors()->toArray());
        $this->assertArrayHasKey('ticket_ai_auto_reply_min_confidence', $validator->errors()->toArray());
        $this->assertArrayHasKey('ticket_ai_auto_reply_allowed_categories.0', $validator->errors()->toArray());
        $this->assertArrayHasKey('ticket_ai_auto_reply_max_per_ticket', $validator->errors()->toArray());
    }

    private function validator(): Factory
    {
        return new Factory(new Translator(new ArrayLoader(), 'en'));
    }
}
