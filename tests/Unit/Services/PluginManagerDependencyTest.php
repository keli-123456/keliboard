<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Plugin\PluginManager;
use Tests\TestCase;

final class PluginManagerDependencyTest extends TestCase
{
    public function test_xboard_dependency_passes_when_constraint_is_satisfied(): void
    {
        config(['app.version' => '1.2.3']);

        $manager = new PluginManager();
        $reason = null;

        $result = $this->invokeCheckDependencies($manager, [
            'xboard' => '>=1.0.0 <2.0.0',
        ], $reason);

        $this->assertTrue($result);
        $this->assertNull($reason);
    }

    public function test_xboard_dependency_rejects_when_constraint_is_not_satisfied(): void
    {
        config(['app.version' => '1.2.3']);

        $manager = new PluginManager();
        $reason = null;

        $result = $this->invokeCheckDependencies($manager, [
            'xboard' => '>=2.0.0',
        ], $reason);

        $this->assertFalse($result);
        $this->assertStringContainsString('xboard version constraint not satisfied', (string) $reason);
    }

    public function test_xboard_dependency_supports_or_expression(): void
    {
        config(['app.version' => '1.2.3']);

        $manager = new PluginManager();
        $reason = null;

        $result = $this->invokeCheckDependencies($manager, [
            'xboard' => '>=2.0.0 || >=1.2.0 <1.3.0',
        ], $reason);

        $this->assertTrue($result);
        $this->assertNull($reason);
    }

    public function test_xboard_dependency_supports_tilde_and_wildcard_constraints(): void
    {
        config(['app.version' => '1.2.3']);

        $manager = new PluginManager();
        $reason = null;

        $tildeResult = $this->invokeCheckDependencies($manager, [
            'xboard' => '~1.2.0',
        ], $reason);

        $this->assertTrue($tildeResult);
        $this->assertNull($reason);

        $wildcardResult = $this->invokeCheckDependencies($manager, [
            'xboard' => '1.2.*',
        ], $reason);

        $this->assertTrue($wildcardResult);
        $this->assertNull($reason);
    }

    public function test_xboard_dependency_supports_caret_constraints(): void
    {
        config(['app.version' => '1.2.3']);

        $manager = new PluginManager();
        $reason = null;

        $result = $this->invokeCheckDependencies($manager, [
            'xboard' => '^1.2.0',
        ], $reason);

        $this->assertTrue($result);
        $this->assertNull($reason);
    }

    public function test_invalid_xboard_version_configuration_is_rejected(): void
    {
        config(['app.version' => '20260418-abcdef0']);

        $manager = new PluginManager();
        $reason = null;

        $result = $this->invokeCheckDependencies($manager, [
            'xboard' => '>=1.0.0',
        ], $reason);

        $this->assertFalse($result);
        $this->assertStringContainsString('Unable to determine current xboard version', (string) $reason);
    }

    public function test_unsupported_dependency_package_is_rejected(): void
    {
        config(['app.version' => '1.2.3']);

        $manager = new PluginManager();
        $reason = null;

        $result = $this->invokeCheckDependencies($manager, [
            'vendor/package' => '^1.0.0',
        ], $reason);

        $this->assertFalse($result);
        $this->assertStringContainsString('Unsupported dependency package', (string) $reason);
    }

    private function invokeCheckDependencies(PluginManager $manager, array $requires, ?string &$reason = null): bool
    {
        $runner = \Closure::bind(
            function (array $requires, ?string &$reason = null): bool {
                return $this->checkDependencies($requires, $reason);
            },
            $manager,
            PluginManager::class
        );

        return $runner($requires, $reason);
    }
}

