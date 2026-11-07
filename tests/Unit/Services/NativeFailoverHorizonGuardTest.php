<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;
use Tests\Support\NativeFailoverHorizonFixture as HorizonFixture;

final class NativeFailoverHorizonGuardTest extends TestCase
{
    private function environment(): array
    {
        return ['GITHUB_ACTIONS' => 'true', 'GITHUB_WORKFLOW' => HorizonFixture::WORKFLOW,
            'KELI_NATIVE_ACCEPTANCE' => '1', 'KELI_NATIVE_HORIZON_ACCEPTANCE' => '1', 'KELI_NATIVE_HOST' => '127.0.0.1',
            'KELI_NATIVE_DATABASE' => Fixture::DATABASE, 'KELI_NATIVE_USER' => Fixture::USER, 'KELI_NATIVE_PASSWORD' => 'synthetic',
            'KELI_NATIVE_MYSQL_PORT' => '33201', 'KELI_NATIVE_REDIS_PORT' => '33202', 'KELI_NATIVE_ISOLATION' => 'REPEATABLE READ'];
    }
    public function test_only_fixture_prefix_and_queue_are_used_by_console_supervisors(): void
    {
        $owner = str_repeat('a', 32); $other = str_repeat('b', 32);
        $config = HorizonFixture::configuration($this->environment(), $owner);
        $this->assertSame('native-fixture:' . $owner . ':horizon:', $config['prefix']);
        $this->assertNotSame($config['prefix'], HorizonFixture::configuration($this->environment(), $other)['prefix']);
        $this->assertSame([HorizonFixture::ENVIRONMENT], array_keys($config['environments']));
        $supervisor = $config['environments'][HorizonFixture::ENVIRONMENT]['native'];
        $this->assertSame(['admin_operations'], $supervisor['queue']);
        $this->assertSame(2, $supervisor['minProcesses']); $this->assertSame(2, $supervisor['maxProcesses']);
        $this->assertSame(1, $supervisor['tries']); $this->assertFalse($config['fast_termination']);
        $this->assertSame([], $config['defaults']);
    }
    #[DataProvider('invalidConfiguration')]
    public function test_guard_rejects_non_fixture_daemons_before_io(string $key, string $value): void
    {
        $env = $this->environment(); $env[$key] = $value;
        $this->expectException(\RuntimeException::class); HorizonFixture::configuration($env, str_repeat('a', 32));
    }
    public static function invalidConfiguration(): array
    {
        return [['GITHUB_ACTIONS', 'false'], ['GITHUB_WORKFLOW', 'CI'], ['KELI_NATIVE_HORIZON_ACCEPTANCE', '0'],
            ['KELI_NATIVE_ACCEPTANCE', '0'], ['KELI_NATIVE_HOST', 'database'], ['KELI_NATIVE_HOST', '203.0.113.1'],
            ['KELI_NATIVE_DATABASE', 'production'], ['KELI_NATIVE_USER', 'root'], ['KELI_NATIVE_PASSWORD', ''],
            ['KELI_NATIVE_MYSQL_PORT', '3306'], ['KELI_NATIVE_REDIS_PORT', '6379'], ['KELI_NATIVE_REDIS_PORT', '65536']];
    }
    public function test_missing_installation_identity_is_rejected_before_io(): void
    {
        $this->expectException(\RuntimeException::class); HorizonFixture::configuration($this->environment(), '');
    }
    public function test_native_job_keeps_its_own_timeout_below_visibility_and_runtime_lease(): void
    {
        $job = new \App\Jobs\MachineFailoverRunJob('synthetic', 1, 'synthetic');
        $this->assertSame(HorizonFixture::JOB_TIMEOUT, $job->timeout); $this->assertSame(1, $job->tries);
        $this->assertSame('redis', $job->connection); $this->assertSame('admin_operations', $job->queue);
        $this->assertLessThan(90, $job->timeout); $this->assertGreaterThan(90, HorizonFixture::RUN_LEASE);
    }
    public function test_workflow_has_only_manual_fixture_services_without_production_inputs(): void
    {
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 3) . '/.github/workflows/native-horizon-acceptance.yml');
        $this->assertSame(HorizonFixture::WORKFLOW, $workflow['name']);
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);
        $job = $workflow['jobs']['daemon']; $this->assertSame('ubuntu-latest', $job['runs-on']);
        $this->assertSame('mysql:8.4', $job['services']['mysql']['image']); $this->assertSame('redis:7-alpine', $job['services']['redis']['image']);
        $this->assertSame(['127.0.0.1::3306'], $job['services']['mysql']['ports']);
        $this->assertSame(['127.0.0.1::6379'], $job['services']['redis']['ports']);
        $this->assertSame(['REPEATABLE READ', 'READ COMMITTED'], $job['strategy']['matrix']['isolation']);
        $this->assertStringNotContainsString('secrets.', json_encode($workflow));
        $this->assertStringNotContainsString('deploy', json_encode($job['steps']));
    }
    public function test_daemon_cases_load_without_io_and_do_not_accelerate_expiry(): void
    {
        $this->assertTrue(class_exists(\Tests\Integration\NativeHorizon\NativeFailoverHorizonTest::class));
        $source = file_get_contents(dirname(__DIR__, 2) . '/Integration/NativeHorizon/NativeFailoverHorizonTest.php');
        foreach (['->due(', '->expire(', 'setTestNow(', 'travel(', 'travelTo('] as $bypass)
            $this->assertStringNotContainsString($bypass, $source);
        $this->assertSame(75, HorizonFixture::JOB_TIMEOUT); $this->assertSame(120, HorizonFixture::RUN_LEASE);
        $this->assertSame(300, HorizonFixture::MEMBER_LEASE);
    }
}
