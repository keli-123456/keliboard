<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DomainHealth;
use App\Services\DomainHealthMonitorService;
use App\Services\DomainHealthProbeService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class DomainHealthMonitorServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindTestSettings([
            'domain_monitor_enabled' => 1,
            'domain_monitor_failure_threshold' => 2,
            'domain_monitor_timeout_seconds' => 8,
            'domain_monitor_certificate_warning_days' => 14,
            'domain_monitor_telegram_notify' => 1,
        ]);
        $this->createDomainHealthTable();
    }

    public function test_alerts_once_after_threshold_and_sends_recovery(): void
    {
        $probeResult = [
            'tls_valid' => false,
            'http_status' => null,
            'error' => 'connection refused',
        ];
        $events = [];
        $probe = new DomainHealthProbeService(
            fn (string $domain): array => ['8.8.8.8'],
            function () use (&$probeResult): array {
                return $probeResult;
            },
        );
        $monitor = new DomainHealthMonitorService(
            $probe,
            function (string $event, DomainHealth $domain) use (&$events): void {
                $events[] = [$event, $domain->domain];
            },
        );
        $domain = DomainHealth::query()->create([
            'domain' => 'down.example.com',
            'source_type' => DomainHealth::SOURCE_SITE,
            'source_name' => 'Test site',
            'configured_status' => 'active',
            'monitored' => true,
            'status' => DomainHealth::STATUS_UNKNOWN,
        ]);

        $first = $monitor->scanOne($domain);
        $this->assertSame(1, $first->consecutive_failures);
        $this->assertFalse($first->alert_active);
        $this->assertSame([], $events);

        $second = $monitor->scanOne($first);
        $this->assertSame(2, $second->consecutive_failures);
        $this->assertTrue($second->alert_active);
        $this->assertSame([['down', 'down.example.com']], $events);

        $third = $monitor->scanOne($second);
        $this->assertSame(3, $third->consecutive_failures);
        $this->assertCount(1, $events);

        $probeResult = [
            'tls_valid' => true,
            'http_status' => 200,
            'response_ms' => 30,
            'certificate_expires_at' => time() + (90 * 86400),
        ];
        $recovered = $monitor->scanOne($third);

        $this->assertSame(DomainHealth::STATUS_HEALTHY, $recovered->status);
        $this->assertSame(0, $recovered->consecutive_failures);
        $this->assertFalse($recovered->alert_active);
        $this->assertSame([
            ['down', 'down.example.com'],
            ['recovered', 'down.example.com'],
        ], $events);
    }

    private function createDomainHealthTable(): void
    {
        $this->database->schema()->create('v2_domain_health', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain')->unique();
            $table->string('source_type');
            $table->integer('source_id')->nullable();
            $table->integer('owner_id')->nullable();
            $table->string('source_name')->nullable();
            $table->string('configured_status')->nullable();
            $table->boolean('monitored')->default(true);
            $table->string('status')->default('unknown');
            $table->string('reason')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('response_ms')->nullable();
            $table->json('dns_addresses')->nullable();
            $table->integer('certificate_expires_at')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('certificate_sha256')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->boolean('alert_active')->default(false);
            $table->integer('last_checked_at')->nullable();
            $table->integer('last_success_at')->nullable();
            $table->integer('last_failure_at')->nullable();
            $table->integer('alerted_at')->nullable();
            $table->integer('recovered_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}
