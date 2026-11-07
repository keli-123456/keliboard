<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;

final class NativeFailoverFixtureGuardTest extends TestCase
{
    private function environment(): array
    {
        return ['GITHUB_ACTIONS' => 'true', 'KELI_NATIVE_ACCEPTANCE' => '1', 'KELI_NATIVE_HOST' => '127.0.0.1',
            'KELI_NATIVE_DATABASE' => Fixture::DATABASE, 'KELI_NATIVE_USER' => Fixture::USER, 'KELI_NATIVE_PASSWORD' => 'synthetic',
            'KELI_NATIVE_MYSQL_PORT' => '33201', 'KELI_NATIVE_REDIS_PORT' => '33202', 'KELI_NATIVE_ISOLATION' => 'REPEATABLE READ'];
    }
    public function test_only_the_explicit_loopback_disposable_identity_can_be_configured(): void
    {
        $config = Fixture::configuration($this->environment());
        $this->assertSame('127.0.0.1', $config['host']); $this->assertSame(Fixture::DATABASE, $config['database']);
        $this->assertSame(Fixture::USER, $config['username']); $this->assertSame('InnoDB', $config['engine']);
    }
    #[DataProvider('invalidConfiguration')]
    public function test_production_or_implicit_connections_are_rejected_before_io(string $key, mixed $value): void
    {
        $env = $this->environment(); $env[$key] = $value;
        $this->expectException(\RuntimeException::class); Fixture::configuration($env);
    }
    public static function invalidConfiguration(): array
    {
        return [['GITHUB_ACTIONS', 'false'], ['KELI_NATIVE_ACCEPTANCE', '0'], ['KELI_NATIVE_HOST', 'database'],
            ['KELI_NATIVE_HOST', '203.0.113.1'], ['KELI_NATIVE_DATABASE', 'production'], ['KELI_NATIVE_USER', 'root'],
            ['KELI_NATIVE_PASSWORD', ''], ['KELI_NATIVE_MYSQL_PORT', '3306'], ['KELI_NATIVE_REDIS_PORT', '6379'],
            ['KELI_NATIVE_MYSQL_PORT', '0'], ['KELI_NATIVE_REDIS_PORT', '65536'], ['KELI_NATIVE_REDIS_PORT', 'other'], ['KELI_NATIVE_ISOLATION', 'SERIALIZABLE']];
    }
    public function test_acceptance_workflow_is_manual_only_and_has_no_release_or_secret_inputs(): void
    {
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 3) . '/.github/workflows/native-failover-acceptance.yml');
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        $job = $workflow['jobs']['concurrency']; $this->assertSame('ubuntu-latest', $job['runs-on']);
        $this->assertSame('mysql:8.4', $job['services']['mysql']['image']); $this->assertSame('redis:7-alpine', $job['services']['redis']['image']);
        $this->assertSame(['REPEATABLE READ', 'READ COMMITTED'], $job['strategy']['matrix']['isolation']);
        $this->assertStringNotContainsString('secrets.', json_encode($workflow));
        $this->assertStringNotContainsString('deploy', json_encode($job['steps']));
    }
}
