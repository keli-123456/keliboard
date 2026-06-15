<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminUserControllerRegressionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentUserTable();
        $this->createPersonalAccessTokenTable();
    }

    public function test_update_without_invite_user_email_preserves_existing_inviter(): void
    {
        $inviter = User::create([
            'email' => 'inviter@example.com',
            'password' => 'secret',
            'token' => 'inviter-token',
            'uuid' => 'inviter-uuid',
        ]);

        $user = User::create([
            'email' => 'customer@example.com',
            'password' => 'secret',
            'token' => 'customer-token',
            'uuid' => 'customer-uuid',
            'invite_user_id' => $inviter->id,
            'balance' => 500,
        ]);

        $response = (new UserController())->update($this->userUpdateRequest([
            'id' => $user->id,
            'email' => 'customer@example.com',
            'balance' => 5,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($inviter->id, (int) $user->fresh()->invite_user_id);
        $this->assertSame(500, (int) $user->fresh()->balance);
    }

    public function test_batch_ban_without_filters_or_confirmation_is_rejected(): void
    {
        $response = (new UserController())->ban(Request::create('/admin/user/ban', 'POST'));
        $payload = $response->getData(true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('批量封禁全部用户需要确认', $payload['message']);
    }

    public function test_batch_ban_by_ids_skips_admins_and_clears_tokens(): void
    {
        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => 'secret',
            'token' => 'admin-token',
            'uuid' => 'admin-uuid',
            'is_admin' => true,
        ]);
        $user = User::create([
            'email' => 'customer@example.com',
            'password' => 'secret',
            'token' => 'customer-token',
            'uuid' => 'customer-uuid',
            'is_admin' => false,
        ]);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test',
            'token' => str_repeat('a', 64),
        ]);

        $request = Request::create('/admin/user/ban', 'POST', [
            'ids' => [$admin->id, $user->id],
        ]);
        $request->setUserResolver(fn () => $admin);

        $response = (new UserController())->ban($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $payload['data']['affected_count']);
        $this->assertFalse((bool) $admin->fresh()->banned);
        $this->assertTrue((bool) $user->fresh()->banned);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_send_mail_excludes_agent_subordinate_users(): void
    {
        $dispatcher = new class implements Dispatcher {
            public array $dispatched = [];

            public function dispatch($command)
            {
                $this->dispatched[] = $command;
                return $command;
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return null;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        };
        app()->instance(Dispatcher::class, $dispatcher);

        $normal = User::create([
            'email' => 'normal@example.com',
            'password' => 'secret',
            'token' => 'normal-token',
            'uuid' => 'normal-uuid',
        ]);
        $agent = User::create([
            'email' => 'agent@example.com',
            'password' => 'secret',
            'token' => 'agent-token',
            'uuid' => 'agent-uuid',
        ]);
        $subordinate = User::create([
            'email' => 'fake-customer@example.com',
            'password' => 'secret',
            'token' => 'subordinate-token',
            'uuid' => 'subordinate-uuid',
        ]);
        DB::table('v2_agent_user')->insert([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $response = (new UserController())->sendMail($this->userSendMailRequest([
            'subject' => 'Hello',
            'content' => 'Message',
        ]));
        $payload = $response->getData(true);
        $jobs = array_values(array_filter(
            $dispatcher->dispatched,
            fn ($job) => $job instanceof SendEmailJob
        ));
        $emails = array_map(fn (SendEmailJob $job) => $this->emailJobParam($job, 'email'), $jobs);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $payload['data']['queued_count']);
        $this->assertSame(1, $payload['data']['skipped_agent_users_count']);
        $this->assertContains($normal->email, $emails);
        $this->assertContains($agent->email, $emails);
        $this->assertNotContains($subordinate->email, $emails);
    }

    private function createAgentUserTable(): void
    {
        $this->database->schema()->create('v2_agent_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('sub_user_id')->unique();
            $table->string('remark')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function emailJobParam(SendEmailJob $job, string $key): mixed
    {
        $property = new \ReflectionProperty($job, 'params');
        $property->setAccessible(true);
        $params = $property->getValue($job);
        return is_array($params) ? ($params[$key] ?? null) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function userSendMailRequest(array $payload): UserSendMail
    {
        return new class($payload) extends UserSendMail {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(array $payload)
            {
                parent::__construct([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']);
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function userUpdateRequest(array $payload): UserUpdate
    {
        return new class($payload) extends UserUpdate {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(private array $payload)
            {
                parent::__construct([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']);
            }

            public function validated($key = null, $default = null)
            {
                return $key === null ? $this->payload : ($this->payload[$key] ?? $default);
            }
        };
    }
}
