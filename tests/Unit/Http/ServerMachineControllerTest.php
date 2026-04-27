<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Server\MachineController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerMachineControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->bindJsonResponseFactory();
        $this->bindValidatorFactory();
        $this->createTables();
        $this->resetServerServiceSchemaCache();
    }

    public function test_nodes_response_includes_subscription_proxy_config_for_enabled_machine(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 'answer/land',
            'server_pull_interval' => 60,
            'server_push_interval' => 60,
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
            'subscription_proxy_https_port' => 443,
            'subscription_proxy_http_port' => 80,
            'subscription_proxy_cert_file' => '/etc/v2node/subproxy/cert.pem',
            'subscription_proxy_key_file' => '/etc/v2node/subproxy/key.pem',
            'subscription_proxy_challenge_dir' => '/etc/v2node/subproxy/challenges',
            'subscription_proxy_allow_http_fallback' => false,
            'subscription_proxy_max_response_bytes' => 10485760,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'subproxy_https_port' => 8443,
            'subproxy_http_port' => 8080,
            'subproxy_cert_domain' => '203.0.113.10',
        ])->save();
        $machine = $machine->fresh();
        $server = $this->createServer(['machine_id' => $machine->id]);
        $this->assertTrue((bool) admin_setting('subscription_proxy_enable', false));
        $this->assertTrue((bool) ServerMachine::find($machine->id)?->getAttribute('subproxy_enabled'));

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $payload = $response->getData(true);

        $this->assertSame($server->id, $payload['nodes'][0]['id']);
        $proxy = $payload['agent']['subscription_proxy'];
        $this->assertTrue($proxy['enabled']);
        $this->assertSame('panel-a', $proxy['site_id']);
        $this->assertSame('https://panel.example.test', $proxy['upstream_base_url']);
        $this->assertSame('answer/land', $proxy['subscribe_path']);
        $this->assertSame('0.0.0.0:8443', $proxy['https_listen']);
        $this->assertSame('0.0.0.0:8080', $proxy['http_listen']);
        $this->assertSame('203.0.113.10', $proxy['certificate_domain']);
        $this->assertSame('/etc/v2node/subproxy/challenges', $proxy['challenge_dir']);
        $this->assertSame('/etc/v2node/subproxy/cert.pem', $proxy['cert_file']);
        $this->assertSame('/etc/v2node/subproxy/key.pem', $proxy['key_file']);
    }

    public function test_nodes_response_disables_subscription_proxy_when_machine_is_not_bound(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => true,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => false,
        ])->save();
        $machine = $machine->fresh();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $payload = $response->getData(true);

        $this->assertSame(['enabled' => false], $payload['agent']['subscription_proxy']);
    }

    public function test_status_persists_machine_ip_and_network_metrics(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'cpu' => 12.5,
                    'mem' => ['total' => 1024, 'used' => 512],
                    'swap' => ['total' => 2048, 'used' => 256],
                    'disk' => ['total' => 4096, 'used' => 1024],
                    'net' => [
                        'rx_bytes' => 1000,
                        'tx_bytes' => 2000,
                        'rx_rate' => 12.3,
                        'tx_rate' => 45.6,
                    ],
                    'ip' => [
                        'public_ipv4' => '172.104.189.93',
                        'local' => ['172.104.189.93'],
                    ],
                    'system' => [
                        'hostname' => 'edge-a',
                        'os' => 'linux',
                        'arch' => 'amd64',
                    ],
                    'version' => 'v0.3.8',
                    'uptime' => 12345,
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']);

        $fresh = ServerMachine::find($machine->id);
        $status = $fresh?->load_status ?? [];
        $this->assertSame('172.104.189.93', $status['ip']['public_ipv4'] ?? null);
        $this->assertSame('198.51.100.20', $status['ip']['panel_seen'] ?? null);
        $this->assertSame(12.3, $status['net']['rx_rate'] ?? null);
        $this->assertSame('edge-a', $status['system']['hostname'] ?? null);

        $history = ServerMachineLoadHistory::query()->where('machine_id', $machine->id)->first();
        $this->assertSame('172.104.189.93', $history?->load_status['ip']['public_ipv4'] ?? null);
        $this->assertSame(45.6, $history?->load_status['net']['tx_rate'] ?? null);
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->boolean('subproxy_enabled')->default(false);
            $table->unsignedSmallInteger('subproxy_https_port')->nullable();
            $table->unsignedSmallInteger('subproxy_http_port')->nullable();
            $table->string('subproxy_cert_domain')->nullable();
            $table->json('subproxy_cert_state')->nullable();
            $table->integer('sort')->default(0);
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->json('load_status')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_server_machine_load_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id')->index();
            $table->float('cpu')->nullable();
            $table->unsignedBigInteger('mem_total')->default(0);
            $table->unsignedBigInteger('mem_used')->default(0);
            $table->unsignedBigInteger('swap_total')->default(0);
            $table->unsignedBigInteger('swap_used')->default(0);
            $table->unsignedBigInteger('disk_total')->default(0);
            $table->unsignedBigInteger('disk_used')->default(0);
            $table->json('load_status')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('runtime')->default(Server::RUNTIME_GENERIC);
            $table->string('code')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->json('group_ids')->nullable();
            $table->json('route_ids')->nullable();
            $table->string('name');
            $table->decimal('rate', 8, 2)->default(1);
            $table->json('tags')->nullable();
            $table->string('host');
            $table->string('port');
            $table->integer('server_port');
            $table->json('protocol_settings')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->nullable();
            $table->timestamps();
        });
    }

    private function createServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'type' => Server::TYPE_VLESS,
            'runtime' => Server::RUNTIME_V2NODE,
            'code' => null,
            'machine_id' => null,
            'group_ids' => [],
            'route_ids' => [],
            'name' => 'node',
            'rate' => 1,
            'tags' => [],
            'host' => '127.0.0.1',
            'port' => '443',
            'server_port' => 443,
            'protocol_settings' => [],
            'show' => true,
            'enabled' => true,
            'sort' => 0,
        ], $overrides));
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

    private function bindValidatorFactory(): void
    {
        app()->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), app()));
    }

    private function resetServerServiceSchemaCache(): void
    {
        $property = new \ReflectionProperty(ServerService::class, 'hasServerEnabledColumn');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
