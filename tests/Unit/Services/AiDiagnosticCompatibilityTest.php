<?php

namespace Tests\Unit\Services;

use App\Services\AiDiagnosticService;
use Tests\TestCase;

class AiDiagnosticCompatibilityTest extends TestCase
{
    public function test_feature_is_disabled_read_only_and_local_by_default(): void
    {
        $settings = app(AiDiagnosticService::class)->settings();

        $this->assertFalse($settings['enabled']);
        $this->assertFalse($settings['schedule_enabled']);
        $this->assertTrue($settings['shadow_mode']);
        $this->assertTrue($settings['read_only']);
        $this->assertFalse($settings['external_data_sharing']);
    }
}
