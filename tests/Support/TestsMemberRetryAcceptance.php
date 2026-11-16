<?php
namespace Tests\Support;

use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineFailoverPlan;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\MachineCandidateRetry;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineProvisionLimits;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Support\Facades\Cache;

// Synthetic same-day setup only; no acceptance authority or provider credentials.
trait TestsMemberRetryAcceptance
{
    private function memberRetryAcceptanceFixture(int $sourceAge = 600, bool $aliSets = false, bool $start = true): array
    {
        $this->nodeReadiness = true;
        $this->fixtures[] = $this->fixture(2);
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5,
            'server_entry_probe_targets' => ['39.156.70.37', '111.33.167.71', '123.150.76.218', '157.255.219.143']]);
        [$a, $b] = $this->fixtures;
        $account = $a['accounts'][0];
        $account->fill(['provision_limits' => ['max_instances' => 5, 'max_parallel' => 1, 'daily_limit' => 5]])->save();
        $a['accounts'][1]->fill(['group_id' => null])->save();
        $pool = app(MachineFailoverCredentials::class)->pool($account->group_id, 'linode');
        $catalog = hash('sha256', 'r35-synthetic-catalog');
        Cache::put('machine-cloud:catalog:' . $pool['fingerprint'], ['checked_at' => time(), 'expires_at' => time() + 600,
            'fingerprint' => $catalog, 'regions' => [['id' => 'sg-sin-2']],
            'sizes' => [['id' => 'g6-standard-4', 'regions' => ['sg-sin-2'], 'disk_gib' => 80]],
            'images' => [['id' => 'linode/debian12', 'regions' => ['sg-sin-2'], 'min_disk_gib' => 4]]], 600);
        foreach ($this->fixtures as $index => $f) {
            $f['policy']->fill(['credential_group_id' => $account->group_id, 'daily_limit' => $index === 0 ? 3 : 1,
                'failure_threshold' => 3, 'revision' => 2, 'catalog_fingerprint' => $catalog, 'catalog_checked_at' => time()])->save();
            \App\Models\MachineOwnedInstance::query()->where('task_id', $f['source']->id)->update(['policy_revision' => 2]);
            $payload = $f['source']->payload;
            $payload['plan']['account_id'] = $account->id;
            $payload['plan']['account_revision'] = $account->revision;
            $payload['plan']['credential_pool'] = ['group_id' => $pool['id'], 'provider' => 'linode',
                'fingerprint' => $pool['fingerprint'], 'account_revision' => $account->revision];
            $f['source']->fill(['created_at' => time() - $sourceAge, 'account_id' => $account->id,
                'account_revision' => $account->revision, 'external_id' => (string) (901 + $index), 'payload' => $payload])->save();
            $this->remote[901 + $index] = $this->acceptanceInventoryRow(901 + $index, 'source-' . $index, $f['sourceIp']);
            Cache::forget('machine_addresses:' . $f['machine']->id . ':' . hash('sha256', $f['machine']->token));
            foreach ([-17, -12, -7] as $delta) $this->sourceReport($f, false, time() + $delta);
        }
        // One unrelated pre-existing instance and the new controller are inventory-only.
        $this->remote[700] = $this->acceptanceInventoryRow(700, 'unrelated', '8.8.8.7');
        $this->remote[800] = $this->acceptanceInventoryRow(800, 'controller', '8.8.8.6');
        $this->enable();
        config(['machine_provision.daily_limit_per_account' => 5, 'machine_failover.allow_rotation' => false,
            'machine_failover.allow_candidate_retry' => true,
            'machine_failover.allowed_candidate_retry_policy_ids' => [$a['policy']->id],
            'machine_failover.allowed_replacement_policy_ids' => [$a['policy']->id]]);
        $this->retryClock = new class extends MachineCandidateRetry {
            public int $at;
            protected function clock(): int { return $this->at; }
        };
        $this->retryClock->at = time();
        app()->instance(MachineCandidateRetry::class, $this->retryClock);
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'R35 synthetic two-member service',
            'max_parallel' => 1, 'attempt_limit' => 2, 'members' => array_map(fn ($f, $index) => [
                'policy_id' => $f['policy']->id, 'policy_revision' => $f['policy']->revision,
                'name' => $index === 0 ? 'A' : 'B', 'priority' => $index + 1, 'enabled' => true,
                'attempt_limit' => $index === 0 ? 2 : 1], $this->fixtures, [0, 1])], $this->admin->id);
        if ($aliSets) $service = $this->memberRetryAcceptanceDnsSets($service);
        $plan = MachineFailoverPlan::query()->where('policy_id', $a['policy']->id)->latest('id')->value('id');
        $run = $start ? app(MachineFailoverNativeBridge::class)->start($service['id'], $service['revision'], [$plan],
            $this->release->id, str_repeat('a', 32), $this->admin->id) : null;
        return compact('a', 'b', 'account', 'service', 'run', 'plan');
    }

    private function memberRetryAcceptanceDnsSets(array $service): array
    {
        foreach (['2026_11_09_180000_create_machine_failover_dns_sets.php', '2026_11_09_200000_create_machine_dns_set_runtime.php',
            '2026_11_10_010000_create_machine_native_address_proofs.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        $credential = \App\Models\MachineDnsCredential::query()->create(['name' => 'R35 synthetic Aliyun', 'provider' => 'aliyun',
            'revision' => 1, 'credentials' => ['access_key_id' => 'synthetic', 'access_key_secret' => 'synthetic']]);
        foreach ($this->fixtures as $index => $f) {
            $task = $f['source'];
            $task->fill(['provider_created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600), 'public_ips' => [$f['sourceIp']]])->save();
            $resource = \App\Models\MachineCloudResource::query()->create(['account_id' => $task->account_id, 'provider' => 'linode',
                'external_id' => $task->external_id, 'provider_created_at' => $task->provider_created_at, 'public_ips' => $task->public_ips,
                'status' => 'running', 'label' => $task->marker, 'region' => $f['policy']->region, 'size' => $f['policy']->size,
                'machine_id' => $task->machine_id, 'installation_id' => $task->installation_id,
                'machine_authority' => $task->machine_authority, 'observed_at' => time()]);
            \App\Models\MachineOwnedInstance::query()->where('task_id', $task->id)->update(['resource_id' => $resource->id]);
            $view = app(\App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry::class)->save([
                'service_id' => $service['id'], 'service_revision' => $service['revision'], 'credential_id' => $credential->id,
                'credential_revision' => 1, 'zone' => 'example.test', 'zone_name' => 'example.test',
                'hostname' => 'edge-' . ($index === 0 ? 'a' : 'b') . '.example.test', 'ttl' => 600,
                'members' => [['member_id' => $service['members'][$index]['id'], 'types' => ['A'], 'lines' => ['default']]]], $this->admin->id);
            $service['revision'] = $view['service_revision'];
        }
        foreach (\App\Models\MachineFailoverDnsSlot::query()->get() as $slot) {
            $set = \App\Models\MachineFailoverDnsSet::query()->findOrFail($slot->set_id);
            $index = array_search($slot->member_id, array_column($service['members'], 'id'), true);
            $id = (string) (5000 + $slot->id);
            $this->records[$id] = ['RecordId' => $id, 'Type' => 'A', 'Value' => $this->fixtures[$index]['sourceIp'],
                'RR' => $index === 0 ? 'edge-a' : 'edge-b', 'DomainName' => 'example.test', 'Line' => 'default',
                'TTL' => 600, 'Remark' => $slot->marker, 'Status' => 'ENABLE', 'Locked' => false];
        }
        return $service;
    }

    private function acceptanceInventoryRow(int $id, string $label, string $ip): array
    {
        return ['id' => $id, 'label' => $label, 'tags' => [], 'region' => 'sg-sin-2',
            'type' => 'g6-standard-4', 'image' => 'linode/debian12', 'status' => 'running',
            'created' => gmdate('Y-m-d\TH:i:s', time() - 120), 'ipv4' => [$ip], 'ipv6' => ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('memberRetryAcceptanceDnsModes')]
    public function test_member_retry_acceptance_positive_fuses_two_sources_cleanup_and_only_A_retries(bool $aliSets): void
    {
        $f = $this->memberRetryAcceptanceFixture(aliSets: $aliSets);
        $bTask = $f['b']['source']->fresh()->toArray();
        $bPolicy = $f['b']['policy']->fresh()->toArray();
        $bDns = $this->records[$f['b']['dns']->record_id];
        $bSet = $this->records['5002'] ?? null;
        $baseline = $this->remote[700];
        $limits = app(MachineProvisionLimits::class);
        $this->assertSame(2, $limits->usage($f['account'])['attempts_24h']);
        $this->assertCount(1, $f['run']['members']);
        $this->assertSame(1, MachineFailoverExecutionMember::query()->count());
        $run = $this->advance($f['run']);
        $this->assertSame('waiting_initialization', $run['members'][0]['phase'], json_encode($run));
        $task = $this->task();
        $firstIdentity = $task->only(['id', 'installation_id', 'external_id', 'marker', 'account_id']);
        $this->assertSame(5, count($this->remote));
        $this->assertSame(3, $limits->usage($f['account'])['attempts_24h']);
        app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 30, 'status' => 'waiting_ipv4_probe', 'next_check_at' => time() + 60])->save();
        foreach ([-20, -15] as $delta) {
            $run = $this->candidateSample($run, time() + $delta);
            $this->assertSame(0, $this->candidateDeletes);
        }
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->candidateDeletes);
        $this->assertSame(3, $limits->usage($f['account'])['used']);
        $this->assertSame(1, $limits->usage($f['account'])['active']);
        $run = $this->cleanupConfirmed($run);
        $this->assertSame(2, $limits->usage($f['account'])['used']);
        $this->assertSame(0, $limits->usage($f['account'])['active']);
        $this->assertSame(3, $limits->usage($f['account'])['attempts_24h']);
        $this->retryClock->at += 299;
        $this->assertSame('candidate_retry_cooldown', $this->advance($run)['members'][0]['phase']);
        $this->assertCount(1, $this->posts);
        // Existing fixture helper advances synthetic time only; daily history remains in the window.
        $this->finishCandidateCooldown();
        $run = $this->advance($run);
        $this->assertSame('waiting_initialization', $run['members'][0]['phase'], json_encode($run));
        $this->assertCount(2, $this->posts);
        $second = $this->task();
        $this->assertSame($firstIdentity, $task->fresh()->only(array_keys($firstIdentity)));
        $this->assertNotSame($task->id, $second->id);
        $this->assertSame($task->account_id, $second->account_id);
        $this->assertSame(4, MachineProvisionTask::query()->count());
        $this->assertSame(4, $limits->usage($f['account'])['attempts_24h']);
        $this->assertSame(2, MachineFailoverAttempt::query()->count());
        $this->assertSame(5, count($this->remote));
        $this->assertSame(0, $this->dnsWrites);
        $run = $this->ready($run); $run = $this->finishSets($run);
        $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites);
        $this->assertSame($bTask, $f['b']['source']->fresh()->toArray());
        $this->assertSame($bPolicy, $f['b']['policy']->fresh()->toArray());
        $this->assertSame($bDns, $this->records[$f['b']['dns']->record_id]);
        $this->assertSame($bSet, $this->records['5002'] ?? null);
        $this->assertSame($baseline, $this->remote[700]);
        $this->assertSame(0, $this->verifications);
        $this->assertSame($run, $this->advance($run));
        $this->assertCount(2, $this->posts);
    }

    public static function memberRetryAcceptanceDnsModes(): array
    {
        return ['legacy' => [false], 'aliyun_separate_member_A_records' => [true]];
    }

    public function test_member_retry_acceptance_recent_setup_must_wait_for_account_cooldown(): void
    {
        try {
            $this->memberRetryAcceptanceFixture(sourceAge: 120);
            $this->fail('New source tasks must not bypass the account cooldown.');
        } catch (\App\Services\ServerMachine\Cloud\CloudException $error) {
            $this->assertSame('credential_group_exhausted', $error->getMessage());
        }
        $this->assertSame(2, MachineProvisionTask::query()->count());
        $this->assertSame(0, MachineFailoverAttempt::query()->count());
        $this->assertCount(0, $this->posts);
        $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame(0, $this->dnsWrites);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('memberRetryLegacyIdentityChanges')]
    public function test_member_retry_acceptance_rejects_legacy_driver_request_identity_before_cloud_read(string $change): void
    {
        $f = $this->memberRetryAcceptanceFixture();
        $run = $this->advance($f['run']);
        $this->assertSame('waiting_initialization', $run['members'][0]['phase']);
        $task = $this->task(); $payload = $task->payload;
        if ($change === 'label') $payload['request']['label'] = 'keli-accept-synthetic-candidate';
        else $payload['request']['tags'][] = 'keli-accept-synthetic-owned-candidate';
        // Model the previous acceptance adapter, not a change to a live task.
        $task->fill(['payload' => $payload])->save();
        $before = \Illuminate\Support\Facades\Http::recorded()->count();
        try {
            app(\App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient::class)->inspect($task);
            $this->fail('A legacy adapter identity must not pass native cleanup.');
        } catch (\App\Services\ServerMachine\Cloud\CloudException $error) {
            $this->assertSame('ownership_mismatch', $error->getMessage());
        }
        $this->assertSame($before, \Illuminate\Support\Facades\Http::recorded()->count());
        $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame(0, $this->dnsWrites);
        $this->assertCount(1, $this->posts);
    }

    public static function memberRetryLegacyIdentityChanges(): array
    {
        return ['rewritten_provider_label' => ['label'], 'appended_test_tag' => ['tag']];
    }
}
