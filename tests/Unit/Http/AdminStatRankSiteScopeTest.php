<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminStatRankSiteScopeTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createStatUserTable();
    }

    public function test_user_traffic_rank_can_be_limited_to_a_site(): void
    {
        $now = time();
        $siteUser = User::create([
            'email' => 'site-user@example.test',
            'password' => 'secret',
            'token' => 'site-token',
            'uuid' => 'site-uuid',
            'site_id' => 7,
        ]);
        $otherUser = User::create([
            'email' => 'other-user@example.test',
            'password' => 'secret',
            'token' => 'other-token',
            'uuid' => 'other-uuid',
            'site_id' => 8,
        ]);
        DB::table('v2_stat_user')->insert([
            [
                'user_id' => $siteUser->id,
                'u' => 100,
                'd' => 200,
                'record_at' => $now - 60,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $otherUser->id,
                'u' => 5000,
                'd' => 5000,
                'record_at' => $now - 60,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $payload = $this->responsePayload($this->controller()->getTrafficRank($this->request('/stat/getTrafficRank', [
            'type' => 'user',
            'start_time' => $now - 3600,
            'end_time' => $now,
            'site_id' => 7,
        ])))['data'];

        $this->assertCount(1, $payload);
        $this->assertSame((string) $siteUser->id, $payload[0]['id']);
        $this->assertSame('site-user@example.test', $payload[0]['name']);
        $this->assertSame(300, (int) $payload[0]['value']);
    }

    public function test_invite_rank_can_be_limited_to_invited_users_site(): void
    {
        $now = time();
        $inviter = User::create([
            'email' => 'inviter@example.test',
            'password' => 'secret',
            'token' => 'inviter-token',
            'uuid' => 'inviter-uuid',
        ]);
        User::create([
            'email' => 'site-invited@example.test',
            'password' => 'secret',
            'token' => 'site-invited-token',
            'uuid' => 'site-invited-uuid',
            'invite_user_id' => $inviter->id,
            'site_id' => 7,
            'created_at' => $now - 30,
        ]);
        User::create([
            'email' => 'other-invited@example.test',
            'password' => 'secret',
            'token' => 'other-invited-token',
            'uuid' => 'other-invited-uuid',
            'invite_user_id' => $inviter->id,
            'site_id' => 8,
            'created_at' => $now - 30,
        ]);

        $payload = $this->responsePayload($this->controller()->getInviteRank($this->request('/stat/getInviteRank', [
            'start_time' => $now - 3600,
            'end_time' => $now,
            'site_id' => 7,
        ])))['data'];

        $this->assertCount(1, $payload);
        $this->assertSame((string) $inviter->id, $payload[0]['id']);
        $this->assertSame('inviter@example.test', $payload[0]['name']);
        $this->assertSame(1, (int) $payload[0]['value']);
    }

    private function createStatUserTable(): void
    {
        $this->database->schema()->create('v2_stat_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('record_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(\Illuminate\Http\JsonResponse $response): array
    {
        return $response->getData(true);
    }

    private function controller(): StatController
    {
        return new StatController(new StatisticalService());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $path, array $query): Request
    {
        return new class($path, $query) extends Request {
            /**
             * @param array<string, mixed> $query
             */
            public function __construct(string $path, array $query)
            {
                parent::__construct($query, [], [], [], [], [
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => $path,
                ]);
            }

            public function validate(array $rules, ...$params): array
            {
                return $this->query->all();
            }
        };
    }
}
