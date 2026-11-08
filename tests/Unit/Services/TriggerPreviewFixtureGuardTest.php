<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TriggerPreviewFixture as Fixture;

final class TriggerPreviewFixtureGuardTest extends TestCase
{
    private function environment(): array
    {
        return ['GITHUB_ACTIONS' => 'true', 'KELI_NATIVE_ACCEPTANCE' => '1', 'KELI_TRIGGER_PREVIEW_ACCEPTANCE' => '1',
            'GITHUB_WORKFLOW' => Fixture::WORKFLOW, 'KELI_NATIVE_HOST' => '127.0.0.1',
            'KELI_NATIVE_DATABASE' => \Tests\Support\NativeFailoverConcurrentFixture::DATABASE,
            'KELI_NATIVE_USER' => \Tests\Support\NativeFailoverConcurrentFixture::USER, 'KELI_NATIVE_PASSWORD' => 'synthetic',
            'KELI_NATIVE_MYSQL_PORT' => '33201', 'KELI_NATIVE_REDIS_PORT' => '33202', 'KELI_NATIVE_ISOLATION' => 'REPEATABLE READ'];
    }
    public function test_only_explicit_dedicated_disposable_configuration_is_accepted(): void
    {
        $configuration = Fixture::configuration($this->environment(), str_repeat('a', 32));
        $this->assertSame('127.0.0.1', $configuration['host']);
        $this->assertTrue(class_exists(\Tests\Integration\TriggerPreview\TriggerPreviewIsolationTest::class));
    }
    #[DataProvider('invalidEnvironment')]
    public function test_other_hosts_databases_users_and_implicit_runs_are_refused_before_io(string $key, string $value): void
    {
        $env = $this->environment(); $env[$key] = $value;
        $this->expectException(\RuntimeException::class); Fixture::configuration($env, str_repeat('a', 32));
    }
    public static function invalidEnvironment(): array
    {
        return [['GITHUB_ACTIONS', 'false'], ['KELI_TRIGGER_PREVIEW_ACCEPTANCE', '0'], ['GITHUB_WORKFLOW', 'CI'],
            ['KELI_NATIVE_HOST', 'database'], ['KELI_NATIVE_DATABASE', 'production'], ['KELI_NATIVE_USER', 'root'],
            ['KELI_NATIVE_MYSQL_PORT', '3306'], ['KELI_NATIVE_REDIS_PORT', '6379']];
    }
    public function test_manual_workflow_has_no_production_release_or_credential_access(): void
    {
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 3) . '/.github/workflows/trigger-preview-acceptance.yml');
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on'])); $this->assertSame(Fixture::WORKFLOW, $workflow['name']);
        $this->assertSame(['contents' => 'read'], $workflow['permissions']); $job = $workflow['jobs']['preview'];
        $this->assertSame(['REPEATABLE READ', 'READ COMMITTED'], $job['strategy']['matrix']['isolation']);
        $this->assertSame('mysql:8.4', $job['services']['mysql']['image']); $this->assertSame('redis:7-alpine', $job['services']['redis']['image']);
        $json = json_encode($workflow); foreach (['secrets.', 'deploy', 'packages: write', 'schedule:'] as $forbidden) $this->assertStringNotContainsString($forbidden, $json);
    }
}
