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

    public function test_save_persists_website_site_domain_binding(): void
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
        $this->assertSame($domainId, $payload['data']['webproxy_site_domain_id']);
        $this->assertSame($domainId, $machine?->webproxy_site_domain_id);
        $this->assertTrue((bool) $machine?->webproxy_enabled);
        $this->assertSame('/checkout', $machine?->webproxy_path_prefix);
        $this->assertSame('admin.server_machine.saved', $this->publisher->reason);
        $this->assertSame($machine?->id, $this->publisher->payload['machine_id'] ?? null);
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
            $table->unsignedSmallInteger('subproxy_https_port')->nullable();
            $table->unsignedSmallInteger('subproxy_http_port')->nullable();
            $table->string('subproxy_cert_domain')->nullable();
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