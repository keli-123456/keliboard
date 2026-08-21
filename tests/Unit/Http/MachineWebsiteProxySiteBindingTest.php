<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineWebsiteProxySiteBindingTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private object $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->bindJsonResponseFactory();
        $this->bindPublisher();
        $this->createTables();
    }

    public function test_save_clears_legacy_website_target_and_path(): void
    {
        $domainId = DB::table('v2_site_domain')->insertGetId([
            'site_id' => 10,
            'domain' => 'branch-a.example.test',
            'status' => 'active',
            'is_primary' => true,
        ]);

        $response = (new MachineController())->save($this->request([
            'name' => 'edge-site-a',
            'is_active' => true,
            'webproxy_enabled' => true,
            'webproxy_path_prefix' => '/checkout/',
            'webproxy_site_domain_id' => $domainId,
        ]));
        $payload = $response->getData(true);
        $machine = ServerMachine::find((int) $payload['data']['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($payload['data']['webproxy_site_domain_id']);
        $this->assertNull($machine?->webproxy_site_domain_id);
        $this->assertTrue((bool) $machine?->webproxy_enabled);
        $this->assertNull($machine?->webproxy_path_prefix);
        $this->assertSame('admin.server_machine.saved', $this->publisher->reason);
        $this->assertSame($machine?->id, $this->publisher->payload['machine_id'] ?? null);
    }

    public function test_save_clears_stale_subscription_proxy_probe_when_routing_changes(): void
    {
        $machine = ServerMachine::create([
            'name' => 'subscription-proxy',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
            'subproxy_enabled' => false,
            'subproxy_https_port' => 443,
            'subproxy_http_port' => 80,
            'subproxy_cert_domain' => '103.14.76.98',
            'subproxy_cert_state' => [
                'status' => 'issued',
                'probe' => [
                    'status' => 'ok',
                    'http_code' => 200,
                    'last_success_at' => time(),
                ],
            ],
        ]);

        $response = (new MachineController())->save($this->request([
            'id' => $machine->id,
            'name' => $machine->name,
            'is_active' => true,
            'subproxy_enabled' => true,
            'subproxy_https_port' => 443,
            'subproxy_http_port' => 80,
            'subproxy_cert_domain' => '103.14.76.98',
        ]));
        $state = ServerMachine::findOrFail($machine->id)->subproxy_cert_state;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('issued', $state['status'] ?? null);
        $this->assertArrayNotHasKey('probe', $state);
    }

    public function test_save_discards_manual_website_port_bindings(): void
    {
        $domainA = DB::table('v2_site_domain')->insertGetId([
            'site_id' => 10,
            'domain' => 'branch-a.example.test',
            'status' => 'active',
            'is_primary' => true,
        ]);
        $domainB = DB::table('v2_site_domain')->insertGetId([
            'site_id' => 11,
            'domain' => 'branch-b.example.test',
            'status' => 'active',
            'is_primary' => true,
        ]);

        $response = (new MachineController())->save($this->request([
            'name' => 'edge-sites',
            'webproxy_enabled' => true,
            'webproxy_bindings' => [
                ['site_domain_id' => null, 'https_port' => 443],
                ['site_domain_id' => $domainA, 'https_port' => 8443],
                ['site_domain_id' => $domainB, 'https_port' => 8444],
            ],
        ]));
        $machine = ServerMachine::find((int) $response->getData(true)['data']['id']);

        $this->assertNull($machine?->webproxy_bindings);
    }

    private function bindPublisher(): void
    {
        $this->publisher = new class {
            public string $reason = '';
            public array $payload = [];

            public function invalidateConfig(string $reason = 'config.updated', array $payload = []): void
            {
                $this->reason = $reason;
                $this->payload = $payload;
            }
        };
        app()->instance(NodeRealtimePublisher::class, $this->publisher);
    }

    private function request(array $payload): Request
    {
        $base = Request::create('/admin/server/machine/save', 'POST', $payload);
        $request = new class extends Request {
            public function validate(array $rules, ...$params): array
            {
                return $this->request->all();
            }
        };
        $request->initialize(
            $base->query->all(),
            $base->request->all(),
            $base->attributes->all(),
            $base->cookies->all(),
            $base->files->all(),
            $base->server->all(),
            $base->getContent()
        );

        return $request;
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->string('description')->nullable();
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
            $table->timestamps();
        });

        Schema::create('v2_site_domain', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('domain')->unique();
            $table->string('status');
            $table->boolean('is_primary')->default(false);
        });
    }
}