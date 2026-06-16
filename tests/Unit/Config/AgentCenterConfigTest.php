<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Http\Requests\Admin\ConfigSave;
use Tests\TestCase;

final class AgentCenterConfigTest extends TestCase
{
    public function test_config_save_accepts_agent_center_settings(): void
    {
        foreach ([
            'agent_center_enable',
            'agent_center_unlock_mode',
            'agent_center_unlock_balance',
            'agent_center_auto_activate',
            'agent_center_allowed_plan_ids',
            'agent_center_discount_percent',
            'agent_center_user_limit',
            'agent_center_daily_create_limit',
            'agent_center_allow_traffic_reset',
            'agent_center_reset_price_mode',
            'agent_center_bonus_day_price',
        ] as $key) {
            $this->assertArrayHasKey($key, ConfigSave::RULES);
        }
    }
}
