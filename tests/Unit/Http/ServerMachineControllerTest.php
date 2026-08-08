<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Server\MachineController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\ServerTlsCertificate;
use App\Models\Site;
use App\Models\SiteDomain;
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
        $this->assertSame('sub/panel-a', $proxy['subscribe_path']);
        $this->assertSame('0.0.0.0:8443', $proxy['https_listen']);
        $this->assertSame('0.0.0.0:8080', $proxy['http_listen']);
        $this->assertSame('203.0.113.10', $proxy['certificate_domain']);
        $this->assertSame('/etc/v2node/subproxy/challenges', $proxy['challenge_dir']);
        $this->assertSame('/etc/v2node/subproxy/fullchain.pem', $proxy['cert_file']);
        $this->assertSame('/etc/v2node/subproxy/key.pem', $proxy['key_file']);
    }

    public function test_nodes_response_includes_website_proxy_profile_without_subscription_profile(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => false,
            'website_proxy_enable' => false,
            'website_proxy_path_prefix' => '/shop',
            'subscription_proxy_https_port' => 443,
            'subscription_proxy_http_port' => 80,
            'subscription_proxy_cert_file' => '/etc/v2node/subproxy/fullchain.pem',
            'subscription_proxy_key_file' => '/etc/v2node/subproxy/key.pem',
            'website_proxy_max_request_body_bytes' => 67108864,
            'website_proxy_max_response_bytes' => 134217728,
            'subscription_proxy_challenge_dir' => '/etc/v2node/subproxy/challenges',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => false,
            'webproxy_enabled' => true,
            'webproxy_path_prefix' => '/checkout',
            'subproxy_cert_domain' => '203.0.113.10',
        ])->save();
        $machine = $machine->fresh();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $payload = $response->getData(true);
        $proxy = $payload['agent']['subscription_proxy'];

        $this->assertTrue($proxy['enabled']);
        $this->assertSame([], $proxy['profiles']);
        $this->assertArrayNotHasKey('site_id', $proxy);
        $this->assertSame('0.0.0.0:443', $proxy['https_listen']);
        $this->assertSame('203.0.113.10', $proxy['certificate_domain']);
        $this->assertSame('/etc/v2node/subproxy/fullchain.pem', $proxy['cert_file']);
        $this->assertSame('/etc/v2node/subproxy/key.pem', $proxy['key_file']);
        $this->assertSame(67108864, $proxy['website_max_request_body_bytes']);
        $this->assertSame(134217728, $proxy['website_max_response_bytes']);
        $this->assertSame([
            [
                'site_id' => 'panel.example.test',
                'upstream_base_url' => 'https://panel.example.test',
                'path_prefix' => '/',
            ],
        ], $proxy['website_profiles']);
    }

    public function test_nodes_response_automatically_targets_active_site_primary_domain(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => false,
            'website_proxy_enable' => true,
        ]);

        $site = Site::create([
            'code' => 'branch-a',
            'name' => 'Branch A',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $fallbackDomain = SiteDomain::create([
            'site_id' => $site->id,
            'domain' => 'fallback.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => false,
        ]);
        SiteDomain::create([
            'site_id' => $site->id,
            'domain' => 'branch-a.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'webproxy_enabled' => true,
            'webproxy_site_domain_id' => $fallbackDomain->id,
            'webproxy_bindings' => [
                ['site_domain_id' => $fallbackDomain->id, 'https_port' => 9443],
            ],
        ])->save();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $proxy = $response->getData(true)['agent']['subscription_proxy'];

        $this->assertSame('panel.example.test', $proxy['website_profiles'][0]['site_id']);
        $this->assertSame([], $proxy['profiles']);
        $this->assertSame([
            [
                'https_listen' => '0.0.0.0:8443',
                'website_profiles' => [[
                    'site_id' => 'branch-a',
                    'upstream_base_url' => 'https://branch-a.example.test',
                    'path_prefix' => '/',
                ]],
            ],
        ], $proxy['website_listeners']);
    }

    public function test_nodes_response_includes_all_active_sites_and_one_domain_per_site(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => true,
            'website_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
        ]);

        $siteA = Site::create([
            'code' => 'branch-a',
            'name' => 'Branch A',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::create([
            'site_id' => $siteA->id,
            'domain' => 'branch-a.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        SiteDomain::create([
            'site_id' => $siteA->id,
            'domain' => 'branch-a-alt.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => false,
        ]);
        $siteB = Site::create([
            'code' => 'branch-b',
            'name' => 'Branch B',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::create([
            'site_id' => $siteB->id,
            'domain' => 'branch-b-disabled.example.test',
            'status' => SiteDomain::STATUS_DISABLED,
            'is_primary' => true,
        ]);
        SiteDomain::create([
            'site_id' => $siteB->id,
            'domain' => 'branch-b.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => false,
        ]);
        $inactiveSite = Site::create([
            'code' => 'branch-off',
            'name' => 'Branch Off',
            'status' => Site::STATUS_DISABLED,
            'is_default' => false,
        ]);
        SiteDomain::create([
            'site_id' => $inactiveSite->id,
            'domain' => 'branch-off.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'webproxy_enabled' => true,
            'subproxy_https_port' => 443,
            'webproxy_bindings' => [],
        ])->save();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $proxy = $response->getData(true)['agent']['subscription_proxy'];

        $this->assertSame('panel-a', $proxy['profiles'][0]['site_id']);
        $this->assertSame('panel-a', $proxy['website_profiles'][0]['site_id']);
        $this->assertSame([
            [
                'https_listen' => '0.0.0.0:8443',
                'website_profiles' => [[
                    'site_id' => 'branch-a',
                    'upstream_base_url' => 'https://branch-a.example.test',
                    'path_prefix' => '/',
                ]],
            ],
            [
                'https_listen' => '0.0.0.0:8444',
                'website_profiles' => [[
                    'site_id' => 'branch-b',
                    'upstream_base_url' => 'https://branch-b.example.test',
                    'path_prefix' => '/',
                ]],
            ],
        ], $proxy['website_listeners']);
    }

    public function test_nodes_response_skips_sites_without_active_domain_but_keeps_main_website(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => false,
            'website_proxy_enable' => true,
        ]);

        $site = Site::create([
            'code' => 'branch-a',
            'name' => 'Branch A',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::create([
            'site_id' => $site->id,
            'domain' => 'branch-a.example.test',
            'status' => SiteDomain::STATUS_DISABLED,
            'is_primary' => true,
        ]);
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill(['webproxy_enabled' => true])->save();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $proxy = $response->getData(true)['agent']['subscription_proxy'];

        $this->assertTrue($proxy['enabled']);
        $this->assertSame('panel.example.test', $proxy['website_profiles'][0]['site_id']);
        $this->assertSame([], $proxy['website_listeners']);
    }
    public function test_nodes_response_reuses_subscription_proxy_certificate_for_website_proxy(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscribe_path' => 'answer/land',
            'subscription_proxy_enable' => true,
            'website_proxy_enable' => true,
            'website_proxy_path_prefix' => '/',
            'subscription_proxy_site_id' => 'panel-a',
            'subscription_proxy_https_port' => 443,
            'subscription_proxy_http_port' => 80,
            'subscription_proxy_cert_file' => '/etc/v2node/subproxy/fullchain.pem',
            'subscription_proxy_key_file' => '/etc/v2node/subproxy/key.pem',
            'subscription_proxy_challenge_dir' => '/etc/v2node/subproxy/challenges',
        ]);

        $site = Site::create([
            'code' => 'branch-a',
            'name' => 'Branch A',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::create([
            'site_id' => $site->id,
            'domain' => 'branch-a.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'subproxy_enabled' => true,
            'webproxy_enabled' => true,
            'subproxy_https_port' => 8443,
            'subproxy_http_port' => 8080,
            'subproxy_cert_domain' => '203.0.113.10',
        ])->save();
        $machine = $machine->fresh();

        $response = (new MachineController())->nodes(Request::create(
            'https://panel.example.test/api/v2/server/machine/nodes',
            'POST',
            ['machine_id' => $machine->id, 'token' => 'machine-token']
        ));
        $payload = $response->getData(true);
        $proxy = $payload['agent']['subscription_proxy'];

        $this->assertTrue($proxy['enabled']);
        $this->assertSame('panel-a', $proxy['profiles'][0]['site_id']);
        $this->assertSame('panel-a', $proxy['website_profiles'][0]['site_id']);
        $this->assertSame('/', $proxy['website_profiles'][0]['path_prefix']);
        $this->assertSame('0.0.0.0:8443', $proxy['https_listen']);
        $this->assertSame('/etc/v2node/subproxy/fullchain.pem', $proxy['cert_file']);
        $this->assertSame('/etc/v2node/subproxy/key.pem', $proxy['key_file']);
        $this->assertSame('0.0.0.0:8444', $proxy['website_listeners'][0]['https_listen']);
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

    public function test_nodes_response_keeps_active_auto_certificate_domain_across_multiple_public_ips(): void
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
                'domain' => '139.28.232.249',
                'domain_source' => 'auto',
                'status' => 'pending_validation',
            ],
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
            ['REMOTE_ADDR' => '198.51.100.20']
        ));
        $payload = $response->getData(true);

        $proxy = $payload['agent']['subscription_proxy'];
        $this->assertTrue($proxy['enabled']);
        $this->assertSame('139.28.232.249', $proxy['certificate_domain']);
    }

    public function test_nodes_response_prefers_reported_public_ipv4_over_transient_request_ipv4(): void
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
            ['REMOTE_ADDR' => '198.51.100.20']
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

    public function test_status_persists_hy2_tls_certificate_fingerprint(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $server = $this->createServer([
            'type' => Server::TYPE_HYSTERIA,
            'machine_id' => $machine->id,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'hy-sni.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);
        $hex = str_repeat('d', 64);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'tls_certificates' => [
                        [
                            'node_id' => $server->id,
                            'machine_id' => $machine->id,
                            'tag' => 'hysteria2:node-' . $server->id,
                            'protocol' => 'hysteria2',
                            'sni' => 'HY-SNI.EXAMPLE.COM',
                            'status' => 'valid',
                            'sha256_hex' => strtoupper($hex),
                        ],
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']);
        $record = ServerTlsCertificate::query()->first();
        $this->assertSame($server->id, $record?->server_id);
        $this->assertSame($machine->id, $record?->machine_id);
        $this->assertSame('hysteria2', $record?->protocol);
        $this->assertSame('hy-sni.example.com', $record?->sni);
        $this->assertSame('valid', $record?->status);
        $this->assertSame($hex, $record?->sha256_hex);
        $this->assertSame(base64_encode(hex2bin($hex)), $record?->sha256_base64);
        $this->assertSame($server->id, ServerMachine::find($machine->id)?->load_status['tls_certificates'][0]['node_id'] ?? null);
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

    public function test_status_response_requests_one_reload_when_subscription_proxy_desired_config_changes(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
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
        ])->save();

        $requestPayload = [
            'machine_id' => $machine->id,
            'token' => 'machine-token',
            'status' => [
                'runtime' => [
                    'nodes' => 0,
                ],
                'agent' => [
                    'subscription_proxy' => [
                        'enabled' => true,
                        'profiles' => 1,
                        'website_profiles' => 0,
                        'certificate_domain' => '198.51.100.20',
                    ],
                ],
            ],
        ];
        $server = ['REMOTE_ADDR' => '198.51.100.20'];

        $first = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            $requestPayload,
            [],
            [],
            $server
        ));
        $firstPayload = $first->getData(true);
        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;

        $this->assertTrue($firstPayload['reload']);
        $this->assertNotEmpty($state['config_signature'] ?? null);

        $second = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            $requestPayload,
            [],
            [],
            $server
        ));
        $secondPayload = $second->getData(true);
        $nextState = ServerMachine::find($machine->id)?->subproxy_cert_state;

        $this->assertFalse($secondPayload['reload']);
        $this->assertSame($state['config_signature'], $nextState['config_signature'] ?? null);
    }

    public function test_status_multi_site_website_proxy_stops_reloading_after_report_matches(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => false,
            'website_proxy_enable' => true,
        ]);

        $siteA = Site::create([
            'code' => 'branch-a',
            'name' => 'Branch A',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $domainA = SiteDomain::create([
            'site_id' => $siteA->id,
            'domain' => 'branch-a.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $siteB = Site::create([
            'code' => 'branch-b',
            'name' => 'Branch B',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::create([
            'site_id' => $siteB->id,
            'domain' => 'branch-b.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill(['webproxy_enabled' => true])->save();

        $requestPayload = [
            'machine_id' => $machine->id,
            'token' => 'machine-token',
            'status' => [
                'runtime' => ['nodes' => 0],
                'agent' => [
                    'subscription_proxy' => [
                        'enabled' => true,
                        'running' => true,
                        'profiles' => 0,
                        'website_profiles' => 3,
                        'website_listeners' => 2,
                        'website_listens' => ['0.0.0.0:8443', '0.0.0.0:8444'],
                        'certificate_domain' => '198.51.100.20',
                    ],
                ],
            ],
        ];
        $server = ['REMOTE_ADDR' => '198.51.100.20'];

        $first = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            $requestPayload,
            [],
            [],
            $server
        ));
        $second = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            $requestPayload,
            [],
            [],
            $server
        ));

        $this->assertTrue($first->getData(true)['reload']);
        $this->assertFalse($second->getData(true)['reload']);

        $domainA->forceFill(['domain' => 'branch-a-new.example.test'])->save();
        $changed = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            $requestPayload,
            [],
            [],
            $server
        ));

        $this->assertTrue($changed->getData(true)['reload']);
    }
    public function test_status_response_requests_reload_when_website_proxy_profile_is_missing(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => false,
            'website_proxy_enable' => true,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $machine->forceFill([
            'webproxy_enabled' => true,
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
                            'profiles' => 0,
                            'website_profiles' => 0,
                            'certificate_domain' => '198.51.100.20',
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

    public function test_status_response_requests_reload_when_agent_reports_stale_certificate_domain(): void
    {
        $this->bindSettings([
            'app_url' => 'https://panel.example.test',
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'panel-a',
            'zerossl_access_key' => 'test-key',
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
                            'certificate_domain' => '2607:f358:1a:e::d4d9:5831',
                            'certificate_id' => 'cert-1',
                            'validation_ready' => true,
                            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => '2607:f358:1a:e::d4d9:5831']
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

    public function test_nodes_response_does_not_send_issued_zero_ssl_certificate_without_ca_chain(): void
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
                'status' => 'issued',
                'certificate_pem' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----",
                'ca_bundle_pem' => '',
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

        $this->assertSame('issued', $zeroSsl['status']);
        $this->assertSame('cert-1', $zeroSsl['certificate_id']);
        $this->assertArrayNotHasKey('certificate_pem', $zeroSsl);
        $this->assertArrayNotHasKey('ca_bundle_pem', $zeroSsl);
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
        $this->assertSame(1, $state['dispatch_attempts']);
        $this->assertGreaterThan(0, (int) $state['last_dispatched_at']);
    }

    public function test_status_redispatches_upgrade_until_agent_acknowledges_command(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $firstDispatchedAt = now()->timestamp - 30;
        $machine = ServerMachine::create([
            'name' => 'edge-upgrade-retry',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-retry',
                'status' => 'dispatched',
                'component' => 'kelinode-rs',
                'target_version' => 'v0.1.344',
                'requested_at' => now()->timestamp - 60,
                'dispatched_at' => $firstDispatchedAt,
                'dispatch_attempts' => 1,
            ],
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.343',
                    'runtime' => ['agent' => 'kelinode-rs'],
                    'upgrade' => [
                        'id' => 'previous-upgrade',
                        'status' => 'succeeded',
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);
        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertSame('upgrade-node-retry', $payload['upgrade']['id']);
        $this->assertSame('kelinode-rs', $payload['upgrade']['component']);
        $this->assertSame('v0.1.344', $payload['upgrade']['target_version']);
        $this->assertSame('dispatched', $state['status']);
        $this->assertSame($firstDispatchedAt, $state['dispatched_at']);
        $this->assertSame(2, $state['dispatch_attempts']);
        $this->assertGreaterThan($firstDispatchedAt, (int) $state['last_dispatched_at']);
    }

    public function test_status_does_not_immediately_redispatch_unacknowledged_upgrade(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $lastDispatchedAt = now()->timestamp - 5;
        $machine = ServerMachine::create([
            'name' => 'edge-upgrade-dispatch-cooldown',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-cooldown',
                'status' => 'dispatched',
                'component' => 'kelinode-rs',
                'target_version' => 'v0.1.344',
                'requested_at' => now()->timestamp - 60,
                'dispatched_at' => now()->timestamp - 30,
                'last_dispatched_at' => $lastDispatchedAt,
                'dispatch_attempts' => 1,
            ],
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.343',
                    'runtime' => ['agent' => 'kelinode-rs'],
                ],
            ]
        ));
        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertNull($response->getData(true)['upgrade']);
        $this->assertSame('dispatched', $state['status']);
        $this->assertSame(1, $state['dispatch_attempts']);
        $this->assertSame($lastDispatchedAt, $state['last_dispatched_at']);
    }

    public function test_status_stops_redispatch_after_agent_acknowledges_command(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-upgrade-running',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-running',
                'status' => 'dispatched',
                'component' => 'kelinode-rs',
                'target_version' => 'v0.1.344',
                'requested_at' => now()->timestamp - 60,
                'dispatch_attempts' => 1,
            ],
        ]);

        $response = (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.343',
                    'runtime' => ['agent' => 'kelinode-rs'],
                    'upgrade' => [
                        'id' => 'upgrade-node-running',
                        'status' => 'running',
                        'phase' => 'downloading_manifest',
                    ],
                ],
            ]
        ));
        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertNull($response->getData(true)['upgrade']);
        $this->assertSame('running', $state['status']);
        $this->assertSame('downloading_manifest', $state['phase']);
        $this->assertSame(1, $state['dispatch_attempts']);
    }

    public function test_status_dispatches_panel_release_source_with_upgrade_command(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
            'server_machine_distribution_source' => 'panel',
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-panel-upgrade',
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

        $this->assertSame('panel', $payload['upgrade']['release_source']);
        $this->assertSame('https://panel.example.test/api/v2/server/machine/releases', $payload['upgrade']['release_base_url']);
        $this->assertSame([
            'machine_id' => (string) $machine->id,
            'machine_token' => 'machine-token',
        ], $payload['upgrade']['release_auth']);
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
                'expected_binary_sha256' => str_repeat('a', 64),
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
                    'runtime' => ['binary_sha256' => str_repeat('a', 64)],
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

    public function test_status_marks_node_upgrade_succeeded_when_current_version_is_newer(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-1',
                'status' => 'running',
                'component' => 'kelinode-rs',
                'target_version' => 'v0.1.330',
                'expected_binary_sha256' => str_repeat('a', 64),
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
                    'version' => 'v0.1.331',
                    'runtime' => [
                        'binary_sha256' => str_repeat('a', 64),
                        'agent' => 'kelinode-rs',
                    ],
                ],
            ]
        ));
        $payload = $response->getData(true);
        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertNull($payload['upgrade']);
        $this->assertSame('succeeded', $state['status']);
        $this->assertSame('v0.1.331', $state['current_version']);
    }

    public function test_status_does_not_mark_upgrade_succeeded_when_runtime_hash_differs(): void
    {
        $this->bindSettings(['subscription_proxy_enable' => false]);

        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-hash-mismatch',
                'status' => 'running',
                'component' => 'node',
                'target_version' => 'v0.1.343',
                'expected_binary_sha256' => str_repeat('a', 64),
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
                    'version' => 'v0.1.343',
                    'runtime' => ['binary_sha256' => str_repeat('b', 64)],
                    'upgrade' => [
                        'id' => 'upgrade-node-hash-mismatch',
                        'status' => 'succeeded',
                        'phase' => 'completed',
                        'expected_binary_sha256' => str_repeat('a', 64),
                    ],
                ],
            ]
        ));

        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertNull($response->getData(true)['upgrade']);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('runtime_binary_sha256_mismatch', $state['error']);
    }

    public function test_status_marks_upgrade_succeeded_when_runtime_hash_matches_target(): void
    {
        $this->bindSettings(['subscription_proxy_enable' => false]);

        $hash = str_repeat('a', 64);
        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-hash-match',
                'status' => 'running',
                'component' => 'node',
                'target_version' => 'v0.1.343',
                'expected_binary_sha256' => $hash,
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
                    'version' => 'v0.1.343',
                    'runtime' => ['binary_sha256' => $hash],
                    'upgrade' => [
                        'id' => 'upgrade-node-hash-match',
                        'status' => 'succeeded',
                        'phase' => 'completed',
                        'expected_binary_sha256' => $hash,
                    ],
                ],
            ]
        ));

        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertNull($response->getData(true)['upgrade']);
        $this->assertSame('succeeded', $state['status']);
        $this->assertSame($hash, $state['expected_binary_sha256']);
        $this->assertSame($hash, $state['running_binary_sha256']);
        $this->assertGreaterThan(0, (int) ($state['finished_at'] ?? 0));
    }

    public function test_status_recovers_timed_out_upgrade_when_runtime_identity_arrives_late(): void
    {
        $this->bindSettings(['subscription_proxy_enable' => false]);

        $hash = str_repeat('a', 64);
        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-late-identity',
                'status' => 'failed',
                'component' => 'kelinode-rs',
                'target_version' => 'v0.1.344',
                'expected_binary_sha256' => $hash,
                'requested_at' => now()->subMinutes(30)->timestamp,
                'finished_at' => now()->subMinutes(10)->timestamp,
                'error' => 'upgrade_identity_unavailable',
            ],
        ]);

        (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.344',
                    'runtime' => [
                        'binary_sha256' => $hash,
                        'agent' => 'kelinode-rs',
                    ],
                ],
            ]
        ));

        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertSame('succeeded', $state['status']);
        $this->assertSame('v0.1.344', $state['current_version']);
        $this->assertSame($hash, $state['running_binary_sha256']);
        $this->assertArrayNotHasKey('error', $state);
    }

    public function test_status_copies_only_bounded_upgrade_report_fields(): void
    {
        $this->bindSettings(['subscription_proxy_enable' => false]);

        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'id' => 'upgrade-node-report',
                'status' => 'running',
                'component' => 'node',
                'target_version' => 'v0.1.343',
                'expected_binary_sha256' => str_repeat('a', 64),
                'requested_at' => now()->timestamp,
            ],
        ]);

        (new MachineController())->status(Request::create(
            'https://panel.example.test/api/v2/server/machine/status',
            'POST',
            [
                'machine_id' => $machine->id,
                'token' => 'machine-token',
                'status' => [
                    'version' => 'v0.1.342',
                    'runtime' => ['binary_sha256' => str_repeat('a', 64)],
                    'upgrade' => [
                        'id' => 'upgrade-node-report',
                        'status' => 'failed',
                        'phase' => 'rollback_failed',
                        'expected_archive_sha256' => str_repeat('b', 64),
                        'expected_binary_sha256' => str_repeat('a', 64),
                        'previous_binary_sha256' => str_repeat('c', 64),
                        'installed_binary_sha256' => str_repeat('d', 64),
                        'running_binary_sha256' => str_repeat('e', 64),
                        'previous_pid' => 100,
                        'running_pid' => 200,
                        'rollback_performed' => true,
                        'rollback_succeeded' => false,
                        'rollback_error' => 'rollback_restart_failed',
                        'unknown_secret' => 'must-not-persist',
                    ],
                ],
            ]
        ));

        $state = ServerMachine::find($machine->id)?->upgrade_state ?? [];

        $this->assertSame('failed', $state['status']);
        $this->assertSame('rollback_failed', $state['phase']);
        $this->assertSame(str_repeat('b', 64), $state['expected_archive_sha256']);
        $this->assertSame(100, $state['previous_pid']);
        $this->assertFalse($state['rollback_succeeded']);
        $this->assertArrayNotHasKey('unknown_secret', $state);
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
            $table->unsignedBigInteger('webproxy_site_domain_id')->nullable();
            $table->json('webproxy_bindings')->nullable();
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

        Schema::create('v2_site', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default(Site::STATUS_ACTIVE);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('v2_site_domain', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('domain')->unique();
            $table->string('status')->default(SiteDomain::STATUS_PENDING);
            $table->boolean('is_primary')->default(false);
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

        Schema::create('v2_server_tls_certificate', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('machine_id');
            $table->string('protocol', 32);
            $table->string('sni', 255)->default('');
            $table->string('status', 32)->default('valid');
            $table->string('sha256_hex', 64)->nullable();
            $table->string('sha256_base64', 128)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('reported_at')->nullable();
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
