<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimeAuthenticator;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerMachineServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
        $this->resetServerServiceSchemaCache();
    }

    public function test_machine_nodes_only_include_enabled_bound_servers(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);

        $second = $this->createServer(['machine_id' => $machine->id, 'enabled' => true, 'sort' => 20]);
        $first = $this->createServer(['machine_id' => $machine->id, 'enabled' => true, 'sort' => 10]);
        $this->createServer(['machine_id' => $machine->id, 'enabled' => false, 'sort' => 1]);
        $this->createServer(['machine_id' => null, 'enabled' => true, 'sort' => 5]);

        $nodes = ServerService::getMachineNodes($machine);

        $this->assertSame([$first->id, $second->id], $nodes->pluck('id')->all());
    }

    public function test_machine_authenticator_requires_bound_enabled_node(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);
        $server = $this->createServer([
            'machine_id' => $machine->id,
            'enabled' => true,
            'type' => Server::TYPE_VLESS,
        ]);
        $disabled = $this->createServer([
            'machine_id' => $machine->id,
            'enabled' => false,
            'type' => Server::TYPE_VLESS,
        ]);

        $authenticator = new NodeRealtimeAuthenticator();
        $auth = $authenticator->authenticate([
            'machine_id' => $machine->id,
            'token' => 'machine-token',
            'node_id' => $server->id,
            'node_type' => 'v2node',
        ]);

        $this->assertNotNull($auth);
        $this->assertSame($server->id, $auth['server']->id);
        $this->assertSame($machine->id, $auth['machine']->id);

        $this->assertNull($authenticator->authenticate([
            'machine_id' => $machine->id,
            'token' => 'machine-token',
            'node_id' => $disabled->id,
            'node_type' => 'v2node',
        ]));
    }

    public function test_machine_authenticator_allows_v2node_machine_connection_without_node(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $auth = (new NodeRealtimeAuthenticator())->authenticate([
            'machine_id' => $machine->id,
            'token' => 'machine-token',
            'node_id' => 0,
            'node_type' => 'v2node',
        ]);

        $this->assertNotNull($auth);
        $this->assertNull($auth['server']);
        $this->assertSame($machine->id, $auth['machine']->id);
        $this->assertSame('0', $auth['input_node_id']);
        $this->assertSame('v2node:machine:' . $machine->id, $auth['connection_key']);
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->unsignedInteger('last_seen_at')->nullable();
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

    private function resetServerServiceSchemaCache(): void
    {
        $property = new \ReflectionProperty(ServerService::class, 'hasServerEnabledColumn');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
