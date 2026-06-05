<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_order_by_id_sequence_preserves_requested_route_order(): void
    {
        $records = new Collection([
            (object) ['id' => 3, 'action' => 'third'],
            (object) ['id' => 1, 'action' => 'first'],
            (object) ['id' => 2, 'action' => 'second'],
        ]);

        $ordered = ServerService::orderByIdSequence($records, [2, 3, 1]);

        $this->assertSame([2, 3, 1], $ordered->pluck('id')->all());
        $this->assertSame(['second', 'third', 'first'], $ordered->pluck('action')->all());
    }

    public function test_order_by_id_sequence_pushes_unrequested_records_to_tail(): void
    {
        $records = new Collection([
            (object) ['id' => 9],
            (object) ['id' => 5],
            (object) ['id' => 7],
        ]);

        $ordered = ServerService::orderByIdSequence($records, [7, 5]);

        $this->assertSame([7, 5, 9], $ordered->pluck('id')->all());
    }

    public function test_available_users_includes_admin_and_staff_from_state_table_path(): void
    {
        $this->setUpNodeUserDatabase();
        $this->createUserSyncStateTable();
        $this->insertNodeUserFixtureRows();

        foreach ([1, 2, 3, 4] as $id) {
            DB::table('user_sync_states')->insert([
                'user_id' => $id,
                'group_id' => 10,
                'uuid' => "uuid-{$id}",
                'speed_limit' => 0,
                'device_limit' => 0,
                'available' => 1,
            ]);
        }

        $users = ServerService::getAvailableUsers($this->nodeForGroup(10));

        $this->assertSame([1, 2, 3], $users->pluck('id')->all());
    }

    public function test_available_users_includes_admin_and_staff_from_fallback_path(): void
    {
        $this->setUpNodeUserDatabase();
        config(['user_sync.use_state_table_for_server_users' => false]);
        $this->insertNodeUserFixtureRows();

        $users = ServerService::getAvailableUsers($this->nodeForGroup(10));

        $this->assertSame([1, 2, 3], $users->pluck('id')->all());
    }

    private function setUpNodeUserDatabase(): void
    {
        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createUserTable();
        $this->resetServerServiceUserCache();
    }

    private function createUserSyncStateTable(): void
    {
        Schema::create('user_sync_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->integer('group_id')->nullable();
            $table->string('uuid')->default('');
            $table->integer('speed_limit')->default(0);
            $table->integer('device_limit')->default(0);
            $table->boolean('available')->default(false);
        });
    }

    private function insertNodeUserFixtureRows(): void
    {
        $rows = [
            ['id' => 1, 'email' => 'normal@example.test', 'plan_id' => 20, 'is_admin' => 0, 'is_staff' => 0],
            ['id' => 2, 'email' => 'admin@example.test', 'plan_id' => 20, 'is_admin' => 1, 'is_staff' => 0],
            ['id' => 3, 'email' => 'staff@example.test', 'plan_id' => 20, 'is_admin' => 0, 'is_staff' => 1],
            ['id' => 4, 'email' => 'system@example.test', 'plan_id' => null, 'is_admin' => 0, 'is_staff' => 0],
        ];

        foreach ($rows as $row) {
            DB::table('v2_user')->insert($row + [
                'password' => 'secret',
                'token' => 'token-' . $row['id'],
                'uuid' => 'uuid-' . $row['id'],
                'group_id' => 10,
                'transfer_enable' => 1024,
                'speed_limit' => 0,
                'device_limit' => 0,
                'u' => 100,
                'd' => 200,
                'banned' => 0,
                'expired_at' => time() + 3600,
            ]);
        }
    }

    private function nodeForGroup(int $groupId): Server
    {
        $node = new Server();
        $node->group_ids = [$groupId];

        return $node;
    }

    private function resetServerServiceUserCache(): void
    {
        foreach ([
            'hasUserSyncStatesTable' => null,
            'hasServerEnabledColumn' => null,
            'userSyncStatesReadDisabled' => false,
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(ServerService::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $value);
        }
    }
}
