<?php
namespace Tests\Support;

use App\Models\MachineFailoverAttempt;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineProvisionLimits;

trait TestsProvisionLimits
{
    private function limitsSchema(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('v2_machine_cloud_account', 'provision_limits'));
    }

    public function test_capacity_native_reservation_waits_on_the_same_lock_as_direct_creation(): void
    {
        $run = $this->start();
        $lock = \Illuminate\Support\Facades\Cache::lock('machine-cloud:quota:linode', 120);
        $this->assertTrue($lock->get());
        try {
            $waiting = $this->advance($run);
            $this->assertSame('running', $waiting['status']);
            $this->assertSame(0, MachineFailoverAttempt::count()); $this->assertCount(0, $this->posts);
        } finally { $lock->release(); }
        $running = $this->advance($run);
        $this->assertSame('waiting_initialization', $running['members'][0]['phase'], json_encode($running));
        $this->assertSame(1, MachineFailoverAttempt::count()); $this->assertCount(1, $this->posts);
        $this->advance($run); $this->assertCount(1, $this->posts);
    }

    public function test_capacity_daily_fuse_off_keeps_two_recent_sources_without_exhausting_credentials(): void
    {
        $this->limitsSchema(); $first = $this->fixtures[0]; $second = $this->fixture(2);
        $first['source']->fill(['created_at' => time() - 600])->save();
        $second['source']->fill(['created_at' => time() - 600, 'account_id' => $first['accounts'][0]->id])->save();
        $first['policy']->fill(['daily_limit' => 0])->save();
        $first['accounts'][1]->fill(['group_id' => null, 'group_revision' => 2])->save();
        $limits = app(MachineProvisionLimits::class); $account = $first['accounts'][0];
        $state = $limits->assertAvailable($account);
        $this->assertSame(2, $state['attempts_24h']); $this->assertSame(2, $state['used']);
        $this->assertSame(0, $state['active']); $this->assertSame(0, $state['daily_limit']);
        $this->assertSame($account->id, app(MachineFailoverCredentials::class)->selection($first['policy'])['account_id']);
        $account->fill(['provision_limits' => ['max_instances' => 2, 'max_parallel' => 1, 'daily_limit' => 0]])->save();
        $this->assertSame(2, $account->fresh()->provision_limits['max_instances']);
        $this->assertSame(2, $limits->settings($account)['max_instances'], json_encode($limits->settings($account)));
        $this->expectExceptionMessage('credential_capacity_full'); $limits->assertAvailable($account);
    }

    public function test_capacity_cleanup_releases_slot_but_not_attempt_history_then_allows_third_daily_task(): void
    {
        $this->limitsSchema(); $f = $this->fixtures[0];
        $f['policy']->fill(['daily_limit' => 0])->save();
        $f['source']->fill(['created_at' => time() - 600])->save();
        \Illuminate\Support\Facades\DB::table('v2_machine_cloud_identity')->where('account_id', $f['accounts'][1]->id)->update([
            'identity_key' => \Illuminate\Support\Facades\DB::table('v2_machine_cloud_identity')->where('account_id', $f['accounts'][0]->id)->value('identity_key')]);
        $f['accounts'][0]->fill(['provision_limits' => ['max_instances' => 2, 'max_parallel' => 1, 'daily_limit' => 0]])->save();
        $client = \Mockery::mock(\App\Services\ServerMachine\Cloud\LinodeClient::class)->makePartial();
        $client->shouldReceive('inventory')->andReturn(['resources' => [], 'pages' => 0, 'total' => 0]);
        app()->instance(\App\Services\ServerMachine\Cloud\LinodeClient::class, $client);
        $run = $this->cleanupStarted(); $account = $f['accounts'][0]; $limits = app(MachineProvisionLimits::class);
        $before = $limits->usage($account);
        $this->assertSame(2, $before['used']); $this->assertSame(1, $before['active']);
        $run = $this->cleanupConfirmed($run);
        $after = $limits->assertAvailable($account);
        $this->assertSame(1, $after['used']); $this->assertSame(0, $after['active']); $this->assertSame(2, $after['attempts_24h']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame('candidate_retry_cooldown', $this->advance($run)['members'][0]['phase']);
        $this->finishCandidateCooldown(); $run = $this->advance($run);
        $this->assertSame('waiting_initialization', $run['members'][0]['phase'], json_encode($run));
        $this->assertCount(2, $this->posts); $this->assertSame(3, MachineProvisionTask::query()->count());
        $this->assertSame(3, $limits->usage($account)['attempts_24h']);
        $this->assertSame(2, MachineFailoverAttempt::query()->count());
    }

    public function test_capacity_configured_daily_fuse_over_two_and_aliases_share_the_strictest_limit(): void
    {
        $this->limitsSchema(); $account = $this->fixtures[0]['accounts'][0]; $alias = $this->fixtures[0]['accounts'][1];
        $alias->fill(['credentials' => $account->credentials, 'provision_limits' => ['daily_limit' => 3, 'max_instances' => 4, 'max_parallel' => 2]])->save();
        $account->fill(['provision_limits' => ['daily_limit' => 8, 'max_instances' => 9, 'max_parallel' => 5]])->save();
        $settings = app(MachineProvisionLimits::class)->settings($account);
        $this->assertSame(3, $settings['daily_limit'], json_encode(['settings' => $settings, 'ids' => app(\App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth::class)->sameAccountIds($account), 'stored' => $account->fresh()->provision_limits])); $this->assertSame(4, $settings['max_instances']);
        $this->assertSame(1, $settings['max_parallel']);
        config(['machine_provision.daily_limit_per_account' => 7]);
        $this->assertSame(3, app(MachineProvisionLimits::class)->settings($alias)['daily_limit']);
        $alias->fill(['provision_limits' => ['daily_limit' => 0, 'max_instances' => 0, 'max_parallel' => 1]])->save();
        $this->assertSame(7, app(MachineProvisionLimits::class)->settings($account)['daily_limit']);
    }

    public function test_capacity_unconfirmed_cleanup_cannot_release_a_reservation(): void
    {
        $run = $this->cleanupStarted(); $run = $this->cleanupConfirmed($run);
        $attempt = MachineFailoverAttempt::query()->firstOrFail(); $payload = $attempt->payload;
        $payload['candidate_retry']['seal'] = str_repeat('0', 64); $attempt->fill(['payload' => $payload])->save();
        $this->expectExceptionMessage('candidate_cleanup_unconfirmed');
        app(MachineProvisionLimits::class)->usage(\App\Models\MachineCloudAccount::query()->findOrFail($this->task()->account_id));
    }

    public function test_capacity_member_limit_is_pinned_and_exhaustion_does_not_erase_sibling_allowance(): void
    {
        $this->limitsSchema(); $this->memberAttemptLimit = 1;
        $this->fixtures[0]['policy']->fill(['daily_limit' => 0])->save();
        $this->fixtures[] = $this->fixture(2);
        $run = $this->cleanupStarted(attempts: 4); $run = $this->cleanupConfirmed($run);
        $this->finishCandidateCooldown(); $run = $this->advance($run);
        $this->assertSame('member_attempt_limit', $run['members'][0]['reason']);
        $this->assertSame('waiting_initialization', $run['members'][1]['phase']);
        $this->assertCount(2, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $journal = \App\Models\MachineFailoverExecution::query()->findOrFail($run['id']);
        $this->assertSame(4, $journal->payload['snapshot']['service']['attempt_limit']);
        $this->assertSame(1, $journal->payload['snapshot']['members'][0]['attempt_limit']);
        $this->assertSame(2, MachineFailoverAttempt::query()->count());
    }

    public function test_capacity_digitalocean_inventory_deduplicates_local_ids_and_counts_reservations(): void
    {
        $this->limitsSchema(); $f = $this->fixture(2, 'digitalocean'); $account = $f['accounts'][0];
        $f['source']->fill(['external_id' => '2001'])->save();
        $client = \Mockery::mock(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class);
        $client->shouldReceive('instanceLimit')->andReturn(2);
        $client->shouldReceive('inventory')->andReturn(['pages' => 1, 'total' => 1, 'resources' => [['external_id' => '2001']]]);
        app()->instance(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class, $client);
        $task = new MachineProvisionTask(['id' => 'synthetic-unpersisted', 'payload' => []]);
        $limits = app(MachineProvisionLimits::class); $limits->assertDispatch($account, $task);
        $this->assertSame(1, $limits->usage($account)['used']);
        $copy = $f['source']->replicate();
        $copy->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'request_key' => hash('sha256', 'extra-create'),
            'installation_id' => str_repeat('f', 32), 'marker' => 'synthetic-extra', 'status' => 'queued', 'external_id' => null])->save();
        $this->assertSame(2, $limits->usage($account)['used']);
        $this->expectExceptionMessage('task_active'); $limits->assertDispatch($account, $task);
    }

    public function test_capacity_digitalocean_known_quota_and_unknown_response_stop_before_post(): void
    {
        $f = $this->fixture(2, 'digitalocean'); $account = $f['accounts'][0];
        $f['source']->fill(['external_id' => '2001'])->save();
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://api.digitalocean.com/v2/account' => \Illuminate\Support\Facades\Http::response(['account' => ['droplet_limit' => 0]])]);
        try { app(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class)->instanceLimit($account); $this->fail('Unknown quota must stop'); }
        catch (CloudException $error) { $this->assertSame('capacity_unknown', $error->getMessage()); }
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $client = \Mockery::mock(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class);
        $client->shouldReceive('instanceLimit')->andReturn(1);
        $client->shouldReceive('inventory')->andReturn(['pages' => 1, 'total' => 1, 'resources' => [['external_id' => '2001']]]);
        app()->instance(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class, $client);
        $this->expectExceptionMessage('credential_capacity_full');
        app(MachineProvisionLimits::class)->assertDispatch($account, new MachineProvisionTask(['id' => 'synthetic-unpersisted', 'payload' => []]));
    }

    public function test_capacity_unknown_create_outcome_holds_parallel_slot_even_if_marked_failed(): void
    {
        $f = $this->fixtures[0];
        $f['source']->fill(['status' => 'failed', 'dispatched_at' => time(), 'external_id' => null])->save();
        $usage = app(MachineProvisionLimits::class)->usage($f['accounts'][0]);
        $this->assertSame(1, $usage['active']); $this->assertSame(1, $usage['reserved']);
        $this->expectExceptionMessage('task_active'); app(MachineProvisionLimits::class)->assertAvailable($f['accounts'][0]);
    }

    public function test_capacity_digitalocean_parallel_limit_is_configurable_not_a_daily_limit(): void
    {
        $this->limitsSchema(); $f = $this->fixture(2, 'digitalocean'); $account = $f['accounts'][0];
        $account->fill(['provision_limits' => ['max_instances' => 0, 'max_parallel' => 2, 'daily_limit' => 0]])->save();
        $f['source']->fill(['status' => 'queued'])->save();
        $limits = app(MachineProvisionLimits::class); $state = $limits->assertAvailable($account);
        $this->assertSame(2, $state['max_parallel']); $this->assertSame(1, $state['active']);
        $copy = $f['source']->replicate();
        $copy->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'request_key' => hash('sha256', 'parallel-create'),
            'installation_id' => str_repeat('f', 32), 'marker' => 'synthetic-parallel'])->save();
        $this->expectExceptionMessage('task_active'); $limits->assertAvailable($account);
    }

    public function test_capacity_limits_reject_invalid_numbers_and_zero_does_not_disable_parallel_guard(): void
    {
        $this->assertSame(7, MachineProvisionLimits::minimum(0, 9, 7));
        $this->assertFalse(MachineProvisionLimits::reached(999, 0));
        foreach ([['max_instances' => -1], ['max_parallel' => 0], ['daily_limit' => 1001], ['daily_limit' => '3'], ['unknown' => 1]] as $invalid) {
            try { MachineProvisionLimits::normalize($invalid); $this->fail('Invalid limits accepted'); }
            catch (CloudException $error) { $this->assertSame('invalid_capacity', $error->getMessage()); }
        }
        foreach (['disabled', '', null, false, 1.5, -1, 1001] as $invalid) {
            config(['machine_provision.daily_limit_per_account' => $invalid]);
            try { app(MachineProvisionLimits::class)->settings($this->fixtures[0]['accounts'][0]); $this->fail('Invalid global fuse accepted'); }
            catch (CloudException $error) { $this->assertSame('invalid_capacity', $error->getMessage()); }
        }
        config(['machine_provision.daily_limit_per_account' => '8']);
        $this->assertSame(8, app(MachineProvisionLimits::class)->settings($this->fixtures[0]['accounts'][0])['daily_limit']);
    }

    public function test_capacity_definite_rejection_releases_slot_without_erasing_daily_history(): void
    {
        $f = $this->fixtures[0];
        $f['source']->fill(['status' => 'failed', 'created_at' => time(), 'dispatched_at' => time(), 'external_id' => null, 'bootstrap_state' => null,
            'evidence' => ['credential_failure_operation' => 'create', 'credential_failure' => ['outcome' => 'rejected', 'requires_review' => false]]])->save();
        $state = app(MachineProvisionLimits::class)->assertAvailable($f['accounts'][0]);
        $this->assertSame(0, $state['active']); $this->assertSame(0, $state['reserved']); $this->assertSame(1, $state['attempts_24h']);
        $f['source']->fill(['bootstrap_state' => 'pending'])->save();
        $this->expectExceptionMessage('task_active'); app(MachineProvisionLimits::class)->assertAvailable($f['accounts'][0]);
    }

    public function test_capacity_partial_duplicate_or_changing_inventory_never_grants_a_slot(): void
    {
        $f = $this->fixture(2, 'digitalocean'); $account = $f['accounts'][0];
        $f['source']->fill(['external_id' => '2001'])->save();
        $rows = array_map(fn ($id) => ['external_id' => (string) $id], range(1, 100));
        foreach ([
            [['pages' => 1, 'total' => 2, 'resources' => [['external_id' => '2001']]]],
            [['pages' => 1, 'total' => 2, 'resources' => [['external_id' => '2001'], ['external_id' => '2001']]]],
            [['pages' => 2, 'total' => 101, 'resources' => $rows], ['pages' => 2, 'total' => 102, 'resources' => [['external_id' => '101'], ['external_id' => '102']]]],
        ] as $pages) {
            $client = \Mockery::mock(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class);
            $client->shouldReceive('instanceLimit')->andReturn(200);
            foreach ($pages as $index => $page) $client->shouldReceive('inventory')->withArgs(fn ($a, $number) => $a->id === $account->id && $number === $index + 1)->once()->andReturn($page);
            app()->instance(\App\Services\ServerMachine\Cloud\DigitalOceanClient::class, $client);
            try {
                app(MachineProvisionLimits::class)->assertDispatch($account, new MachineProvisionTask(['id' => 'synthetic-unpersisted', 'payload' => []]));
                $this->fail('Incomplete provider inventory accepted');
            } catch (CloudException $error) { $this->assertSame('capacity_unknown', $error->getMessage()); }
        }
        $this->assertCount(0, $this->posts);
    }

    public function test_capacity_account_edit_preserves_token_and_revision_and_blocks_unresolved_create(): void
    {
        $f = $this->fixtures[0]; $account = $f['accounts'][0]; $before = $account->credentials;
        $service = app(\App\Services\ServerMachine\Cloud\MachineCloudService::class);
        $limits = ['max_instances' => 7, 'max_parallel' => 1, 'daily_limit' => 6];
        $saved = $service->saveAccount(['id' => $account->id, 'expected_revision' => $account->revision,
            'name' => $account->name, 'provision_limits' => $limits]);
        $this->assertSame($account->revision + 1, $saved->revision);
        $this->assertSame($before, $saved->credentials); $this->assertSame($limits, $saved->provision_limits);
        $f['source']->fill(['status' => 'failed', 'dispatched_at' => time(), 'external_id' => null])->save();
        try {
            $service->saveAccount(['id' => $account->id, 'expected_revision' => $saved->revision,
                'name' => $account->name, 'provision_limits' => MachineProvisionLimits::DEFAULTS]);
            $this->fail('Unresolved create allowed a capacity edit');
        } catch (CloudException $error) { $this->assertSame('task_active', $error->getMessage()); }
        $this->assertSame($limits, $saved->fresh()->provision_limits);
    }
}
