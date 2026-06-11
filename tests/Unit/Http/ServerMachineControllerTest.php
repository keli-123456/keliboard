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
use Illuminate\Support\Facades\Http;
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
            'subscription_proxy_cert_file' => '/etc/v2node/subproxy/fullchain.pem',
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
        $this->assertSame('/etc/v2node/subproxy/fullchain.pem', $proxy['cert_file']);
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

    public function test_nodes_response_for_inactive_machine_returns_empty_shutdown_config(): void
    {
        $this->bindSettings([
            'server_pull_interval' => 60,
            'server_push_interval' => 60,
            'subscription_proxy_enable' => true,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-offline',
            'token' => 'machine-token',
            'is_active' => false,
        ]);
        $this->createServer(['machine_id' => $machine->id]);

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $payload['nodes']);
        $this->assertSame(['enabled' => false], $payload['agent']['subscription_proxy']);
        $this->assertFalse($payload['machine']['is_active']);
    }

    public function test_nodes_response_uses_current_request_ip_for_legacy_auto_certificate_domain(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 's',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
            'subscription_proxy_https_port' => 443,
            'subscription_proxy_http_port' => 80,
            'subscription_proxy_cert_file' => '/etc/v2node/subproxy/fullchain.pem',
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
            'subproxy_cert_domain' => '172.104.189.93',
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-old',
                'domain' => '172.104.189.93',
                'status' => 'draft',
            ],
        ])->save();
        $machine = $machine->fresh();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token'],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);

        $proxy = $payload['agent']['subscription_proxy'];
        $this->assertTrue($proxy['enabled']);
        $this->assertSame('198.51.100.20', $proxy['certificate_domain']);
    }

    public function test_nodes_response_prefers_existing_auto_ipv4_when_request_ip_is_ipv6(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 's',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '2.56.116.39',
                'domain_source' => 'auto',
                'status' => 'pending_validation',
            ],
        ])->save();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token'],
            [],
            [],
            ['REMOTE_ADDR' => '2607:f358:1a:e::d4d9:5831']
        ));
        $payload = $response->getData(true);

        $proxy = $payload['agent']['subscription_proxy'];
        $this->assertTrue($proxy['enabled']);
        $this->assertSame('2.56.116.39', $proxy['certificate_domain']);
    }

    public function test_nodes_response_prefers_last_reported_public_ipv4_when_request_ip_is_ipv6(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 's',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'load_status' => [
                'ip' => [
                    'public_ipv4' => '2.56.116.39',
                ],
            ],
        ])->save();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token'],
            [],
            [],
            ['REMOTE_ADDR' => '2607:f358:1a:e::d4d9:5831']
        ));
        $payload = $response->getData(true);

        $proxy = $payload['agent']['subscription_proxy'];
        $this->assertTrue($proxy['enabled']);
        $this->assertSame('2.56.116.39', $proxy['certificate_domain']);
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
                    'mieru_port_forward' => [
                        'enabled' => true,
                        'expected_rules' => [['spec' => 'udp dpt:11112 redirect']],
                    ],
                    'metrics' => [
                        'user_delta' => [
                            'kelinode_user_delta_native_apply_success_total' => 3,
                        ],
                        'keli_core_rs' => [
                            'keli_core_user_delta_apply_total' => 3,
                        ],
                        'native_core_gray_health' => [
                            'mode' => 'native_delta',
                            'metrics_available' => true,
                        ],
                    ],
                    'node_failures' => [
                        [
                            'api_host' => 'https://panel.example.test',
                            'node_id' => 51,
                            'machine_id' => $machine->id,
                            'node_type' => 'v2node',
                            'error' => 'user_delta request failed: 403 Forbidden',
                        ],
                    ],
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
        $this->assertSame('native_delta', $status['metrics']['native_core_gray_health']['mode'] ?? null);
        $this->assertSame(3, $status['metrics']['keli_core_rs']['keli_core_user_delta_apply_total'] ?? null);
        $this->assertSame(true, $status['mieru_port_forward']['enabled'] ?? null);
        $this->assertSame(51, $status['node_failures'][0]['node_id'] ?? null);
        $this->assertSame('user_delta request failed: 403 Forbidden', $status['node_failures'][0]['error'] ?? null);

        $history = ServerMachineLoadHistory::query()->where('machine_id', $machine->id)->first();
        $this->assertSame('172.104.189.93', $history?->load_status['ip']['public_ipv4'] ?? null);
        $this->assertSame(45.6, $history?->load_status['net']['tx_rate'] ?? null);
        $this->assertSame('native_delta', $history?->load_status['metrics']['native_core_gray_health']['mode'] ?? null);
        $this->assertSame(true, $history?->load_status['mieru_port_forward']['enabled'] ?? null);
        $this->assertSame(51, $history?->load_status['node_failures'][0]['node_id'] ?? null);
    }

    public function test_status_for_reactivated_machine_requests_reload_when_runtime_has_no_nodes(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-reactivated',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $this->createServer(['machine_id' => $machine->id]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'runtime' => [
                        'mode' => 'machine_binding',
                        'nodes' => 0,
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']);
        $this->assertTrue($payload['reload']);
    }

    public function test_status_requests_reload_when_runtime_still_has_unbound_node(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-unbound',
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
                    'runtime' => [
                        'nodes' => 1,
                        'node_statuses' => [
                            ['node_id' => 51, 'status' => 'configured'],
                        ],
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']);
        $this->assertTrue($payload['reload']);
    }

    public function test_status_for_inactive_machine_is_accepted_without_upgrade_dispatch(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-offline',
            'token' => 'machine-token',
            'is_active' => false,
            'upgrade_state' => [
                'id' => 'upgrade-node',
                'status' => 'queued',
                'target_version' => 'v0.1.31',
                'requested_at' => now()->timestamp,
            ],
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'runtime' => [
                        'nodes' => 0,
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']);
        $this->assertFalse($payload['reload']);
        $this->assertNull($payload['upgrade']);
        $this->assertSame('queued', ServerMachine::find($machine->id)?->upgrade_state['status'] ?? null);
    }

    public function test_status_response_requests_reload_when_subscription_proxy_cert_config_changes(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 's',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
            'subscription_proxy_https_port' => 443,
            'subscription_proxy_http_port' => 80,
            'subscription_proxy_cert_file' => '/etc/v2node/subproxy/fullchain.pem',
            'subscription_proxy_key_file' => '/etc/v2node/subproxy/key.pem',
            'subscription_proxy_challenge_dir' => '/etc/v2node/subproxy/challenges',
            'subscription_proxy_allow_http_fallback' => true,
            'subscription_proxy_max_response_bytes' => 10485760,
            'zerossl_access_key' => 'test-key',
            'subscription_proxy_renew_days' => 20,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/certificates?')) {
                return Http::response([
                    'id' => 'cert-1',
                    'status' => 'draft',
                    'expires' => '2026-07-01',
                    'validation' => [
                        'other_methods' => [
                            '198.51.100.20' => [
                                'file_validation_url_http' => 'http://198.51.100.20/.well-known/pki-validation/token.txt',
                                'file_validation_content' => ['line-a', 'line-b'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([
                'id' => 'cert-1',
                'status' => 'draft',
                'expires' => '2026-07-01',
            ]);
        });

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
        ])->save();

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'agent' => [
                        'subscription_proxy' => [
                            'certificate_domain' => '198.51.100.20',
                            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
                            'need_certificate' => true,
                            'validation_ready' => false,
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']);
        $this->assertTrue($payload['reload']);
        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('cert-1', $state['certificate_id'] ?? null);
        $this->assertSame('/.well-known/pki-validation/token.txt', $state['validation_path'] ?? null);
    }

    public function test_status_response_requests_reload_when_subscription_proxy_is_enabled_but_agent_has_no_proxy_status(): void
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
            'subproxy_enabled' => true,
        ])->save();
        $server = $this->createServer(['machine_id' => $machine->id]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'cpu' => 12.5,
                    'mem' => ['total' => 1024, 'used' => 512],
                    'swap' => ['total' => 0, 'used' => 0],
                    'disk' => ['total' => 4096, 'used' => 1024],
                    'runtime' => [
                        'node_statuses' => [
                            ['node_id' => $server->id],
                        ],
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['reload']);
    }

    public function test_status_response_requests_reload_until_agent_writes_current_validation_file(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '198.51.100.20',
                'status' => 'draft',
                'validation_path' => '/.well-known/pki-validation/token.txt',
                'validation_content' => ['line-a', 'line-b'],
            ],
        ])->save();

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'agent' => [
                        'subscription_proxy' => [
                            'enabled' => true,
                            'certificate_domain' => '198.51.100.20',
                            'certificate_id' => '',
                            'validation_ready' => false,
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['reload']);
    }

    public function test_status_response_requests_reload_until_agent_writes_issued_certificate(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '198.51.100.20',
                'status' => 'issued',
                'certificate_pem' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----",
                'ca_bundle_pem' => "-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----",
            ],
        ])->save();

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'agent' => [
                        'subscription_proxy' => [
                            'enabled' => true,
                            'running' => true,
                            'mode' => 'http_fallback',
                            'certificate_domain' => '198.51.100.20',
                            'certificate_id' => 'cert-1',
                            'cert_not_after' => '',
                            'need_certificate' => false,
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['reload']);
    }

    public function test_nodes_response_includes_zero_ssl_diagnostic_state(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 's',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '198.51.100.20',
                'domain_source' => 'auto',
                'status' => 'pending_validation',
                'validation_path' => '/.well-known/pki-validation/token.txt',
                'validation_content' => ['line-a', 'line-b'],
                'validation_requested_at' => '2026-06-11T10:00:00+00:00',
                'last_error' => 'waiting for ZeroSSL validation',
                'updated_at' => '2026-06-11T10:01:00+00:00',
            ],
        ])->save();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token'],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);
        $zeroSsl = $payload['agent']['subscription_proxy']['zerossl'];

        $this->assertSame('pending_validation', $zeroSsl['status']);
        $this->assertSame('cert-1', $zeroSsl['certificate_id']);
        $this->assertSame('198.51.100.20', $zeroSsl['domain']);
        $this->assertSame('auto', $zeroSsl['domain_source']);
        $this->assertSame('waiting for ZeroSSL validation', $zeroSsl['last_error']);
        $this->assertSame('2026-06-11T10:00:00+00:00', $zeroSsl['validation_requested_at']);
        $this->assertSame('2026-06-11T10:01:00+00:00', $zeroSsl['updated_at']);
    }

    public function test_status_dispatches_component_upgrade_command(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-core-1',
                'status' => 'queued',
                'component' => 'core',
                'target_version' => 'v0.1.1',
                'requested_at' => now()->timestamp,
            ],
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.4',
                    'core' => [
                        'version' => 'v0.1.0',
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);
        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertSame('upgrade-core-1', $payload['upgrade']['id']);
        $this->assertSame('core', $payload['upgrade']['component']);
        $this->assertSame('v0.1.1', $payload['upgrade']['target_version']);
        $this->assertSame('dispatched', $state['status']);
        $this->assertSame('core', $state['component']);
    }

    public function test_status_marks_core_upgrade_succeeded_from_core_version(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-core-1',
                'status' => 'running',
                'component' => 'core',
                'target_version' => 'v0.1.1',
                'requested_at' => now()->timestamp,
            ],
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.4',
                    'core' => [
                        'versions' => [
                            'keli-core-rs' => 'v0.1.1',
                        ],
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);
        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertNull($payload['upgrade']);
        $this->assertSame('succeeded', $state['status']);
        $this->assertSame('core', $state['component']);
        $this->assertSame('v0.1.1', $state['current_version']);
        $this->assertSame('v0.1.1', ServerMachine::find($machine->id)?->load_status['core']['versions']['keli-core-rs'] ?? null);
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
            $table->json('upgrade_state')->nullable();
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
