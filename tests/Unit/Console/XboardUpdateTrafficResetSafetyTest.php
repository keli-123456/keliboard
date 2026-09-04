<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

final class XboardUpdateTrafficResetSafetyTest extends TestCase
{
    public function test_update_does_not_force_advance_all_reset_schedules(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Console/Commands/XboardUpdate.php'
        );

        $this->assertStringNotContainsString(
            "Artisan::call('reset:traffic', ['--force' => true])",
            $source
        );
        $this->assertStringContainsString("Artisan::call('reset:traffic');", $source);
        $this->assertStringContainsString(
            "Artisan::call('reset:traffic', ['--fix-null' => true]);",
            $source
        );
    }

    public function test_manual_force_mode_processes_due_resets_first(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Console/Commands/ResetTraffic.php'
        );
        $forceMethod = substr($source, (int) strpos($source, 'private function performForce'));

        $dueResetPosition = strpos($forceMethod, '$dueResult = $this->performReset();');
        $recalculatePosition = strpos($forceMethod, '$query = $this->getAllUsersQuery();');

        $this->assertNotFalse($dueResetPosition);
        $this->assertNotFalse($recalculatePosition);
        $this->assertLessThan($recalculatePosition, $dueResetPosition);
    }
}
