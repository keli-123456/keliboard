<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineOperationNoticeController;
use App\Jobs\SendMachineOperationNoticeJob;
use App\Jobs\SendTelegramJob;
use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineCloudTask;
use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use App\Models\MachineOperationNotice as Notice;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\MachineDnsService;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineOperationNoticeException;
use App\Services\ServerMachine\MachineOperationNoticeText;
use App\Services\ServerMachine\MachineOperationNotifier;
use App\Services\TelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineOperationNotifierTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineOperationNotifier $notifier;
    private ServerMachine $machine;
    private MachineDnsBinding $binding;
    private MachineCloudResource $resource;
    private User $admin;
    private string $instance = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('o', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        foreach (['2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_05_200000_create_machine_operation_notices.php', '2026_11_06_010000_add_machine_cloud_providers.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->bigInteger('telegram_id')->nullable(); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        $this->admin = User::query()->create(['is_admin' => true, 'telegram_id' => 123]);
        $this->machine = ServerMachine::query()->create(['name' => 'Synthetic', 'token' => 'private-machine-token', 'is_active' => true]);
        $credential = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'credentials' => ['api_token' => 'private-provider-token'], 'revision' => 1]);
        $this->binding = MachineDnsBinding::query()->create(['credential_id' => $credential->id, 'machine_id' => $this->machine->id,
            'installation_id' => $this->instance, 'machine_authority' => hash('sha256', $this->machine->token), 'record_scope' => str_repeat('b', 64),
            'zone' => str_repeat('b', 32), 'record_id' => str_repeat('c', 32), 'hostname' => 'node.example.com', 'type' => 'A',
            'enabled' => false, 'revision' => 1, 'last_ip' => '8.8.8.8', 'status' => 'disabled', 'last_verified_at' => time()]);
        $account = MachineCloudAccount::query()->create(['name' => 'Synthetic Linode', 'provider' => 'linode', 'credentials' => ['api_token' => 'private-cloud-token'], 'revision' => 1]);
        $this->resource = MachineCloudResource::query()->create(['account_id' => $account->id, 'external_id' => '123', 'label' => 'Synthetic', 'region' => 'sg-sin-2',
            'size' => 'g6-standard-1', 'provider_created_at' => '2026-01-01T00:00:00', 'status' => 'running', 'public_ips' => ['8.8.8.8'], 'observed_at' => time(),
            'revision' => 1, 'machine_id' => $this->machine->id, 'installation_id' => $this->instance, 'machine_authority' => hash('sha256', $this->machine->token)]);
        $this->notifier = new class extends MachineOperationNotifier { public bool $ready = true; protected function channelReady(): bool { return $this->ready; } };
        app()->instance(MachineOperationNotifier::class, $this->notifier);
    }
    private function enabled(string $scope): void
    {
        $policy = $this->notifier->overview($scope)['policy'];
        $this->notifier->save($scope, true, $policy['revision'], $this->admin->id);
    }
    private function failedDns(string $reason = 'provider_unavailable'): Notice
    {
        $this->binding->refresh()->fill(['status' => $reason])->save();
        $this->notifier->dns($this->binding->id);
        return Notice::query()->latest('id')->firstOrFail();
    }
    private function task(string $status, string $reason = 'provider_unavailable'): MachineCloudTask
    {
        return MachineCloudTask::query()->create(['resource_id' => $this->resource->id, 'admin_id' => $this->admin->id,
            'request_key' => bin2hex(random_bytes(32)), 'resource_revision' => 1, 'account_revision' => 1,
            'external_id' => '123', 'resource_label' => 'Synthetic', 'action' => 'reboot', 'status' => $status, 'reason' => $reason,
            'deadline_at' => time() + 900, 'dispatched_at' => time() - 60]);
    }
    private function transport(int $times = 1): void
    {
        $mock = \Mockery::mock(TelegramService::class);
        $mock->shouldReceive('sendMessageWithActiveAdmins')->times($times)->withArgs(fn($message) => is_string($message))->andReturnNull();
        app()->instance(TelegramService::class, $mock);
    }

    public function test_notification_policies_default_off_and_never_enable_machine_or_dns_actions(): void
    {
        $this->assertFalse($this->notifier->overview('cloud')['policy']['enabled']);
        $this->assertFalse($this->notifier->overview('dns')['policy']['enabled']);
        $this->enabled('cloud'); $this->enabled('dns');
        $this->assertFalse($this->resource->fresh()->auto_boot);
        $this->assertFalse($this->binding->fresh()->enabled);
        $this->assertSame(0, MachineCloudTask::query()->count()); Http::assertNothingSent();
    }
    public function test_old_disabled_events_are_not_replayed_when_notifications_are_enabled(): void
    {
        $notice = $this->failedDns(); $this->assertSame('disabled', $notice->status);
        $this->enabled('dns'); $this->notifier->dns($this->binding->id); $this->notifier->deliver($notice->id); $this->notifier->advance();
        $this->assertSame(1, Notice::query()->count()); $this->assertSame('disabled', $notice->fresh()->status); Bus::assertNothingDispatched();
    }
    public function test_one_failure_episode_and_duplicate_jobs_submit_only_once(): void
    {
        $this->enabled('dns'); $notice = $this->failedDns();
        $this->failedDns(); $this->failedDns('provider_rate_limited');
        $this->assertSame(1, Notice::query()->count());
        Bus::assertDispatchedTimes(SendMachineOperationNoticeJob::class, 1);
        Bus::assertDispatched(SendMachineOperationNoticeJob::class, fn($job) => $job->noticeId === $notice->id && $job->queue === 'admin_operations' && $job->tries === 1);
        $this->transport(); $job = new SendMachineOperationNoticeJob($notice->id); $job->handle($this->notifier); $job->handle($this->notifier);
        $this->assertSame('submitted', $notice->fresh()->status);
    }
    public function test_recovery_follows_a_submitted_failure_and_has_a_ten_minute_recurrence_cooldown(): void
    {
        $this->enabled('dns'); $failure = $this->failedDns(); $this->transport(3); $this->notifier->deliver($failure->id);
        $this->binding->refresh()->fill(['status' => 'synced'])->save(); $this->notifier->dns($this->binding->id);
        $recovery = Notice::query()->latest('id')->first(); $this->assertSame('recovered', $recovery->phase);
        $this->notifier->deliver($recovery->id); $this->notifier->dns($this->binding->id);
        $this->assertSame(2, Notice::query()->count());
        $again = $this->failedDns(); $this->assertSame('suppressed', $again->status); $this->assertSame('cooldown', $again->delivery_reason);
        $this->binding->refresh()->fill(['status' => 'synced'])->save(); $this->notifier->dns($this->binding->id);
        $this->assertSame('no_prior_notice', Notice::query()->latest('id')->first()->delivery_reason);
        $this->binding->refresh()->fill(['notice_last_alert_at' => time() - 601])->save();
        $next = $this->failedDns(); $this->assertSame('pending', $next->status); $this->notifier->deliver($next->id);
    }
    public function test_recovery_without_a_submitted_failure_does_not_send_an_orphan_message(): void
    {
        $this->enabled('dns'); $failure = $this->failedDns();
        $this->binding->refresh()->fill(['status' => 'synced'])->save(); $this->notifier->dns($this->binding->id);
        $this->notifier->deliver($failure->id);
        $this->assertSame('suppressed', $failure->fresh()->status);
        $this->assertSame('no_prior_notice', Notice::query()->latest('id')->first()->delivery_reason);
    }
    public function test_policy_disable_and_reenable_revokes_pending_delivery_without_changing_actions(): void
    {
        $this->enabled('dns'); $notice = $this->failedDns();
        $this->notifier->save('dns', false, 2, $this->admin->id); $this->enabled('dns'); $this->notifier->deliver($notice->id);
        $this->assertSame('suppressed', $notice->fresh()->status);
        $this->assertSame('policy_changed', $notice->fresh()->delivery_reason);
        $this->assertFalse($this->binding->fresh()->enabled); $this->assertFalse($this->resource->fresh()->auto_boot);
    }
    public function test_banned_authorizing_admin_or_changed_instance_cancels_pending_delivery(): void
    {
        $this->enabled('dns'); $notice = $this->failedDns();
        $this->admin->banned = true; $this->admin->save(); $this->notifier->deliver($notice->id);
        $this->assertSame('policy_changed', $notice->fresh()->delivery_reason);
        $this->admin->banned = false; $this->admin->save();
        $this->notifier->resetDns($this->binding->id); $this->binding->refresh()->fill(['notice_last_alert_at' => 0])->save();
        $next = $this->failedDns(); $this->binding->installation_id = str_repeat('c', 32); $this->binding->save();
        $this->notifier->deliver($next->id); $this->assertSame('source_changed', $next->fresh()->delivery_reason);
    }
    public function test_transport_exception_and_crashed_claim_are_unknown_and_never_retried(): void
    {
        $this->enabled('dns'); $notice = $this->failedDns();
        $mock = \Mockery::mock(TelegramService::class); $mock->shouldReceive('sendMessageWithActiveAdmins')->once()->andThrow(new \RuntimeException('PRIVATE_TRANSPORT_URL'));
        app()->instance(TelegramService::class, $mock);
        $this->notifier->deliver($notice->id); $this->notifier->deliver($notice->id);
        $this->assertSame('unknown', $notice->fresh()->status);
        $notice->forceFill(['status' => 'sending', 'claimed_at' => time() - 301])->save();
        $this->assertSame(1, $this->notifier->advance()['unknown']); $this->notifier->deliver($notice->id);
        $this->assertStringNotContainsString('PRIVATE_TRANSPORT_URL', json_encode($this->notifier->overview('dns')));
    }
    public function test_missing_channel_expires_to_panel_only_without_later_replay(): void
    {
        $this->enabled('dns'); $this->notifier->ready = false; $notice = $this->failedDns();
        $this->assertSame('panel_only', $notice->status);
        $this->notifier->ready = true; $this->notifier->deliver($notice->id); $this->notifier->advance(); Bus::assertNothingDispatched();
    }
    public function test_expired_pending_notice_is_not_submitted(): void
    {
        $this->enabled('dns'); $notice = $this->failedDns();
        $notice->forceFill(['expires_at' => time() - 1])->save(); $this->notifier->deliver($notice->id);
        $this->assertSame('expired', $notice->fresh()->delivery_reason);
    }
    public function test_cloud_unknown_completion_failure_and_verified_recovery_have_separate_events(): void
    {
        $this->enabled('cloud'); $task = $this->task('uncertain', 'outcome_uncertain');
        $this->notifier->cloud($task->id); $this->notifier->cloud($task->id);
        $uncertain = Notice::query()->latest('id')->first(); $this->assertSame('uncertain', $uncertain->phase);
        $this->transport(3); $this->notifier->deliver($uncertain->id);
        $task->status = 'completed'; $task->save(); $this->notifier->cloud($task->id);
        $complete = Notice::query()->latest('id')->first(); $this->notifier->deliver($complete->id); $this->assertSame('completed', $complete->phase);
        $failed = $this->task('expired', 'deadline'); $this->notifier->cloud($failed->id);
        $alert = Notice::query()->latest('id')->first(); $this->notifier->deliver($alert->id);
        $this->notifier->cloud($failed->id, true);
        $recovery = Notice::query()->latest('id')->first(); $this->assertSame('recovered', $recovery->phase); $this->assertSame('pending', $recovery->status);
        $this->assertSame('expired', $failed->fresh()->status); $this->assertFalse($this->resource->fresh()->auto_boot); Http::assertNothingSent();
    }
    public function test_old_cloud_uncertainty_is_suppressed_after_completion(): void
    {
        $this->enabled('cloud'); $task = $this->task('uncertain'); $this->notifier->cloud($task->id);
        $notice = Notice::query()->first(); $task->status = 'completed'; $task->save(); $this->notifier->deliver($notice->id);
        $this->assertSame('source_changed', $notice->fresh()->delivery_reason);
        $task->status = 'cancelled'; $task->save(); $this->notifier->cloud($task->id); $this->assertSame(1, Notice::query()->count());
    }
    public function test_overview_and_messages_exclude_credentials_full_installation_and_provider_details(): void
    {
        $this->enabled('cloud'); $task = $this->task('failed', 'PRIVATE_PROVIDER_TOKEN'); $this->notifier->cloud($task->id);
        $notice = Notice::query()->first(); $json = json_encode($this->notifier->overview('cloud')); $message = MachineOperationNoticeText::message($notice);
        foreach (['private-provider-token', 'PRIVATE_PROVIDER_TOKEN', 'private-machine-token', $this->instance, 'context_hash', 'event_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json); $this->assertStringNotContainsString($secret, $message);
        }
        $this->assertStringContainsString('不代表客户端转发成功', $message);
    }
    public function test_dns_recovery_requires_provider_readback_not_a_cached_success(): void
    {
        $this->enabled('dns'); $failure = $this->failedDns(); $this->transport(); $this->notifier->deliver($failure->id);
        $this->binding->refresh()->fill(['enabled' => true, 'last_verified_at' => time()])->save();
        $inventory = new MachineAddressInventory(); $inventory->record($this->machine, $this->instance, ['public_ipv4' => '8.8.8.8'], time());
        $dns = new MachineDnsService(new DnsProviderClient(), $inventory);
        Http::fake(['api.cloudflare.com/*' => Http::response([], 403)]);
        $dns->sync($this->binding->id, 1);
        $this->assertSame('provider_forbidden', $this->binding->fresh()->status);
        $this->assertSame(1, Notice::query()->count()); Http::assertSentCount(1);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['name' => 'node.example.com', 'type' => 'A', 'content' => '8.8.8.8', 'ttl' => 300]])]);
        $dns->sync($this->binding->id, 1);
        $this->assertSame('synced', $this->binding->fresh()->status); Http::assertSentCount(2);
        $this->assertSame('recovered', Notice::query()->latest('id')->first()->phase);
        Http::assertNotSent(fn($request) => $request->method() !== 'GET');
    }
    public function test_non_admin_routes_and_stale_policy_updates_are_rejected(): void
    {
        $request = Request::create('/notices', 'GET'); $request->setUserResolver(fn() => (object)['is_admin' => false]);
        foreach (['fetch', 'save'] as $action) {
            try { (new MachineOperationNoticeController())->$action($request, $this->notifier); $this->fail('Admin only'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->enabled('dns');
        $this->expectException(MachineOperationNoticeException::class); $this->expectExceptionMessage('policy_changed');
        $this->notifier->save('dns', true, 1, $this->admin->id);
    }
    public function test_missing_migration_is_inert_and_reset_is_not_a_recovery(): void
    {
        $this->enabled('dns'); $notice = $this->failedDns(); $this->notifier->resetDns($this->binding->id);
        $this->notifier->deliver($notice->id); $this->assertSame('source_changed', $notice->fresh()->delivery_reason);
        $this->assertSame(1, Notice::query()->count());
        Schema::drop('v2_machine_operation_notice');
        $this->assertFalse($this->notifier->overview('dns')['available']); $this->notifier->dns($this->binding->id); $this->notifier->cloud(1);
        $this->assertSame(['queued' => 0, 'unknown' => 0], $this->notifier->advance());
    }
    public function test_telegram_transport_only_enqueues_active_admin_recipients(): void
    {
        User::query()->create(['is_admin' => true, 'banned' => true, 'telegram_id' => 456]);
        User::query()->create(['is_admin' => false, 'telegram_id' => 789]);
        User::query()->create(['is_admin' => true]);
        $telegram = new class extends TelegramService { public function __construct() {} };
        $telegram->sendMessageWithActiveAdmins('Synthetic operation notice');
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
        Bus::assertDispatched(SendTelegramJob::class, function ($job) {
            return (new \ReflectionProperty($job, 'telegramId'))->getValue($job) === 123;
        });
        Http::assertNothingSent();
    }
    public function test_cloud_process_failure_and_manual_recovery_capture_notices_without_enabling_auto_boot(): void
    {
        $this->enabled('cloud'); $task = $this->task('queued');
        $this->resource->fill(['active_task_id' => $task->id, 'last_task_id' => $task->id, 'dns_hold' => true])->save();
        $provider = \Mockery::mock(\App\Services\ServerMachine\Cloud\LinodeClient::class);
        $provider->shouldReceive('instance')->andReturn($this->resource->only(['external_id', 'label', 'region', 'size', 'status', 'public_ips', 'provider_created_at', 'observed_at']));
        $provider->shouldReceive('safety')->andReturn([]);
        $provider->shouldReceive('power')->once()->andThrow(new \App\Services\ServerMachine\Cloud\CloudException('provider_forbidden'));
        $health = \Mockery::mock(\App\Services\ServerMachine\Cloud\MachineCloudHealth::class);
        $health->shouldReceive('snapshot')->andReturn(['agent' => 'fresh', 'nodes' => 'fresh', 'ready' => true]);
        $cloud = new \App\Services\ServerMachine\Cloud\MachineCloudService($provider, $health);
        $cloud->process($task->id);
        $this->assertSame('failed', $task->fresh()->status);
        $notice = Notice::query()->latest('id')->first(); $this->assertSame('failed', $notice->phase);
        $this->transport(); $this->notifier->deliver($notice->id);
        $cloud->verifyRecovery($this->resource->id, $this->resource->fresh()->revision);
        $this->assertSame('recovered', Notice::query()->latest('id')->first()->phase);
        $this->assertFalse($this->resource->fresh()->auto_boot); $this->assertSame('failed', $task->fresh()->status);
    }
}
