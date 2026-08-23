<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\InviteClick;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\InviteTrackingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class InviteTrackingServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createInviteCodeTable();
        $this->createInviteClickTable();
        config(['app.key' => 'invite-monitor-test-key']);
    }

    public function test_it_records_an_anonymized_click_and_deduplicates_the_session(): void
    {
        $inviter = $this->createUser('inviter@example.test');
        $code = InviteCode::query()->forceCreate([
            'user_id' => $inviter->id,
            'code' => 'abc123',
            'status' => InviteCode::STATUS_UNUSED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $service = app(InviteTrackingService::class);
        $request = $this->trackingRequest('abc123');

        $this->assertTrue($service->track($request)['tracked']);
        $this->assertTrue($service->track($request)['tracked']);

        $this->assertSame(1, InviteClick::query()->count());
        $click = InviteClick::query()->firstOrFail();
        $this->assertSame($code->id, $click->invite_code_id);
        $this->assertSame($inviter->id, $click->inviter_user_id);
        $this->assertSame(2, $click->hit_count);
        $this->assertSame('wechat', $click->source);
        $this->assertSame('weixin.qq.com', $click->referrer_host);
        $this->assertSame('invite.example.test', $click->landing_host);
        $this->assertSame(64, strlen($click->visitor_hash));
        $this->assertStringNotContainsString('203.0.113.8', $click->visitor_hash);
    }

    public function test_it_attributes_registration_to_the_latest_matching_click(): void
    {
        $inviter = $this->createUser('inviter@example.test');
        $registered = $this->createUser('registered@example.test', ['invite_user_id' => $inviter->id]);
        InviteCode::query()->forceCreate([
            'user_id' => $inviter->id,
            'code' => 'convert-me',
            'status' => InviteCode::STATUS_UNUSED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $service = app(InviteTrackingService::class);
        $request = $this->trackingRequest('convert-me');

        $service->track($request);
        $service->attributeRegistration($request, 'convert-me', $registered);

        $click = InviteClick::query()->firstOrFail();
        $this->assertSame($registered->id, $click->registered_user_id);
        $this->assertNotNull($click->converted_at);
    }

    private function trackingRequest(string $code): Request
    {
        return Request::create('/api/v1/guest/invite/track', 'POST', [
            'code' => $code,
            'referrer' => 'https://weixin.qq.com/share/path?private=1',
        ], [], [], [
            'REMOTE_ADDR' => '203.0.113.8',
            'HTTP_HOST' => 'invite.example.test',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 MicroMessenger/8.0',
            'HTTP_ACCEPT_LANGUAGE' => 'zh-CN',
        ]);
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function createInviteCodeTable(): void
    {
        $this->database->schema()->create('v2_invite_code', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->string('code', 64)->unique();
            $table->boolean('status')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createInviteClickTable(): void
    {
        $this->database->schema()->create('v2_invite_click', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('invite_code_id');
            $table->string('invite_code', 64);
            $table->integer('inviter_user_id');
            $table->integer('site_id')->nullable();
            $table->char('visitor_hash', 64);
            $table->string('source', 50)->default('direct');
            $table->string('referrer_host', 191)->nullable();
            $table->string('landing_host', 191)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->unsignedInteger('hit_count')->default(1);
            $table->integer('clicked_at');
            $table->integer('last_clicked_at');
            $table->integer('registered_user_id')->nullable();
            $table->integer('converted_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}
