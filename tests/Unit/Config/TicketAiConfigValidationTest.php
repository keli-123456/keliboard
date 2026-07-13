<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Arr;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Tests\TestCase;

final class TicketAiConfigValidationTest extends TestCase
{
    private const KEYS = [
        'ticket_ai_max_tokens',
        'ticket_ai_timeout',
        'ticket_ai_json_mode',
        'ticket_ai_log_retention_days',
    ];

    public function test_operational_ai_settings_accept_supported_bounds(): void
    {
        $validator = $this->validator()->make([
            'ticket_ai_max_tokens' => 4096,
            'ticket_ai_timeout' => 120,
            'ticket_ai_json_mode' => true,
            'ticket_ai_log_retention_days' => 365,
        ], Arr::only(ConfigSave::RULES, self::KEYS));

        $this->assertTrue($validator->passes());
    }

    public function test_operational_ai_settings_reject_unsafe_values(): void
    {
        $validator = $this->validator()->make([
            'ticket_ai_max_tokens' => 127,
            'ticket_ai_timeout' => 4,
            'ticket_ai_json_mode' => 'not-a-boolean',
            'ticket_ai_log_retention_days' => 366,
        ], Arr::only(ConfigSave::RULES, self::KEYS));

        $this->assertTrue($validator->fails());
        $this->assertSame(self::KEYS, array_keys($validator->errors()->toArray()));
    }

    private function validator(): Factory
    {
        return new Factory(new Translator(new ArrayLoader(), 'en'));
    }
}
