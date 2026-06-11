<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerMachineBatchBindNodesTest extends TestCase
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

    public function test_replace_mode_replaces_each_machine_nodes_and_reports_summary(): void
    {
        $firstMachine = $this->machine('edge-a');
        $secondMachine = $this->machine('edge-b');
        $oldNode = $this->node(['machine_id' => $firstMachine->id]);
        $firstNode = $this->node();
        $secondNode = $this->node();

        $response = (new MachineController())->batchBindNodes($this->request([
            'mode' => 'replace',
            'allow_transfer' => false,
            'items' => [
                ['machine_id' => $firstMachine->id, 'node_ids' => [$firstNode->id]],
                ['machine_id' => $secondMachine->id, 'node_ids' => [$secondNode->id]],
            ],
        ]));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('replace', $payload['data']['mode']);
        $this->assertSame([
            'machines' => 2,
            'bound' => 2,
            'unbound' => 1,
            'transferred' => 0,
            'skipped' => 0,
        ], $payload['data']['summary']);
        $this->assertSame($firstMachine->id, Server::find($firstNode->id)->machine_id);
        $this->assertSame($secondMachine->id, Server::find($secondNode->id)->machine_id);
        $this->assertNull(Server::find($oldNode->id)->machine_id);
        $this->assertSame([[[$firstMachine->id, $secondMachine->id], 'admin.server_machine.batch_bound']], $this->publisher->calls);
    }

    public function test_append_mode_keeps_existing_machine_nodes(): void
    {
        $machine = $this->machine('edge-a');
        $existingNode = $this->node(['machine_id' => $machine->id]);
        $newNode = $this->node();

        $response = (new MachineController())->batchBindNodes($this->request([
            'mode' => 'append',
            'items' => [
                ['machine_id' => $machine->id, 'node_ids' => [$newNode->id]],
            ],
        ]));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('append', $payload['data']['mode']);
        $this->assertSame($machine->id, Server::find($existingNode->id)->machine_id);
        $this->assertSame($machine->id, Server::find($newNode->id)->machine_id);
        $this->assertSame(0, $payload['data']['summary']['unbound']);
        $this->assertSame(1, $payload['data']['summary']['bound']);
    }

    public function test_conflicting_nodes_are_skipped_without_transfer_permission(): void
    {
        $targetMachine = $this->machine('edge-target');
        $otherMachine = $this->machine('edge-other');
        $conflictingNode = $this->node(['machine_id' => $otherMachine->id]);
        $freeNode = $this->node();

        $response = (new MachineController())->batchBindNodes($this->request([
            'mode' => 'replace',
            'allow_transfer' => false,
            'items' => [
                ['machine_id' => $targetMachine->id, 'node_ids' => [$conflictingNode->id, $freeNode->id]],
            ],
        ]));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($otherMachine->id, Server::find($conflictingNode->id)->machine_id);
        $this->assertSame($targetMachine->id, Server::find($freeNode->id)->machine_id);
        $this->assertSame([$conflictingNode->id], $payload['data']['items'][0]['skipped_node_ids']);
        $this->assertSame(1, $payload['data']['summary']['skipped']);
    }

    public function test_conflicting_nodes_can_be_transferred_when_allowed(): void
    {
        $targetMachine = $this->machine('edge-target');
        $otherMachine = $this->machine('edge-other');
        $conflictingNode = $this->node(['machine_id' => $otherMachine->id]);

        $response = (new MachineController())->batchBindNodes($this->request([
            'mode' => 'replace',
            'allow_transfer' => true,
            'items' => [
                ['machine_id' => $targetMachine->id, 'node_ids' => [$conflictingNode->id]],
            ],
        ]));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($targetMachine->id, Server::find($conflictingNode->id)->machine_id);
        $this->assertSame([$conflictingNode->id], $payload['data']['items'][0]['transferred_node_ids']);
        $this->assertSame(1, $payload['data']['summary']['transferred']);
    }

    public function test_same_node_cannot_be_submitted_for_multiple_machines(): void
    {
        $firstMachine = $this->machine('edge-a');
        $secondMachine = $this->machine('edge-b');
        $node = $this->node();

        $response = (new MachineController())->batchBindNodes($this->request([
            'items' => [
                ['machine_id' => $firstMachine->id, 'node_ids' => [$node->id]],
                ['machine_id' => $secondMachine->id, 'node_ids' => [$node->id]],
            ],
        ]));
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('同一个节点不能在一次批量关联中分配给多台机器', $payload['message']);
        $this->assertNull(Server::find($node->id)->machine_id);
    }

    private function bindPublisher(): void
    {
        $this->publisher = new class {
            /** @var array<int, array{0: array<int>, 1: string}> */
            public array $calls = [];

            public function invalidateConfigForMachines(array $machineIds, string $reason = 'config.updated', array $payload = []): void
            {
                sort($machineIds);
                $this->calls[] = [$machineIds, $reason];
            }
        };

        app()->instance(NodeRealtimePublisher::class, $this->publisher);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        $base = Request::create('/admin/server/machine/batchBindNodes', 'POST', $payload);
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
        $request->headers->replace($base->headers->all());

        return $request;
    }

    private function machine(string $name): ServerMachine
    {
        return ServerMachine::create([
            'name' => $name,
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function node(array $attributes = []): Server
    {
        return Server::create(array_merge([
            'type' => Server::TYPE_VLESS,
            'name' => 'node-' . uniqid(),
            'machine_id' => null,
            'sort' => 0,
            'enabled' => true,
        ], $attributes));
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }
}
