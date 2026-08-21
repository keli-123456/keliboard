<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionProxyProbeServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
        $this->bindSettings([
            'subscription_proxy_enable' => true,
            'app_url' => 'https://sp.huhu.icu',
        ]);
        config(['app.key' => 'base64:test-key']);
    }

    public function test_probe_success_stores_state_and_exposes_user_subscribe_url(): void
    {
        Http::fake([
            'https://edge.example.com/*' => Http::response('xboard-subproxy-health-ok', 200),
        ]);
        $service = new SubscriptionProxyProbeService();
        $machine = $this->createHealthyMachine();

        $probe = $service->probeMachine($machine);

        $this->assertSame('ok', $probe['status']);
        $this->assertSame('sp.huhu.icu', $probe['site_id']);
        $this->assertSame(200, $probe['http_code']);
        $this->assertSame(
            'https://edge.example.com/sub/sp.huhu.icu/[health-token]',
            $probe['url']
        );

        $fresh = ServerMachine::find($machine->id);
        $this->assertSame('issued', $fresh?->subproxy_cert_state['status'] ?? null);
        $this->assertSame('ok', $fresh?->subproxy_cert_state['probe']['status'] ?? null);

        $payload = $service->userPayload('user-token');
        $this->assertTrue($payload['available']);
        $this->assertSame('https://edge.example.com/sub/sp.huhu.icu/user-token', $payload['subscribe_url']);
        Http::assertSentCount(1);
    }

    public function test_failed_network_probe_prevents_proxy_url_from_being_issued(): void
    {
        Http::fake([
            'https://edge.example.com/*' => Http::response('upstream unavailable', 503),
        ]);
        $service = new SubscriptionProxyProbeService();
        $this->createHealthyMachine();

        $results = $service->probeAll();

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(503, $results[0]['http_code']);
        $this->assertFalse($service->userPayload('user-token')['available']);
    }

    public function test_stale_machine_is_rejected_without_sending_probe(): void
    {
        Http::fake();
        $service = new SubscriptionProxyProbeService();
        $machine = $this->createHealthyMachine();
        $machine->forceFill(['last_seen_at' => time() - 600])->save();

        $probe = $service->probeMachine($machine->fresh());

        $this->assertSame('error', $probe['status']);
        $this->assertStringContainsString('offline or stale', $probe['last_error']);
        $this->assertFalse($service->userPayload('user-token')['available']);
        Http::assertNothingSent();
    }

    public function test_health_token_is_stable_and_verifiable(): void
    {
        $service = new SubscriptionProxyProbeService();
        $token = $service->healthToken();

        $this->assertStringStartsWith('__xboard_subproxy_probe_', $token);
        $this->assertTrue($service->isHealthToken($token));
        $this->assertFalse($service->isHealthToken($token . 'x'));
    }

    private function createHealthyMachine(): ServerMachine
    {
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
            'sort' => 10,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'subproxy_cert_domain' => 'edge.example.com',
            'subproxy_cert_state' => ['status' => 'issued'],
            'last_seen_at' => time(),
            'load_status' => [
                'agent' => [
                    'subscription_proxy' => [
                        'status' => 'running',
                        'running' => true,
                        'mode' => 'https',
                        'https_listen' => '0.0.0.0:443',
                    ],
                ],
            ],
        ])->save();

        return $machine->fresh();
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->boolean('subproxy_enabled')->default(false);
            $table->boolean('webproxy_enabled')->default(false);
            $table->string('webproxy_path_prefix')->nullable();
            $table->unsignedSmallInteger('subproxy_https_port')->nullable();
            $table->unsignedSmallInteger('subproxy_http_port')->nullable();
            $table->string('subproxy_cert_domain')->nullable();
            $table->json('subproxy_cert_state')->nullable();
            $table->integer('sort')->default(0);
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->json('load_status')->nullable();
            $table->timestamps();
        });
    }

    private function bindSettings(array $values): void
    {
        app()->instance(Setting::class, new class($values) extends Setting {
            private array $values;

            public function __construct(array $values)
            {
                $this->values = array_change_key_case($values, CASE_LOWER);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[strtolower($key)] ?? $default;
            }
        });
    }
}
