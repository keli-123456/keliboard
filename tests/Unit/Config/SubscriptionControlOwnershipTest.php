<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Http\Requests\Admin\ConfigSave;
use Tests\TestCase;

final class SubscriptionControlOwnershipTest extends TestCase
{
    public function test_core_config_save_no_longer_accepts_subscription_control_settings(): void
    {
        $legacyKeys = array_filter(
            array_keys(ConfigSave::RULES),
            static fn(string $key): bool => str_starts_with($key, 'subscription_control_')
        );

        $this->assertSame([], array_values($legacyKeys));
    }

    public function test_core_scheduler_no_longer_runs_subscription_control_command(): void
    {
        $kernel = file_get_contents(dirname(__DIR__, 3) . '/app/Console/Kernel.php');

        $this->assertIsString($kernel);
        $this->assertStringNotContainsString('subscription-control:enforce', $kernel);
    }
}
