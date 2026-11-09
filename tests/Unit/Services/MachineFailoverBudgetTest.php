<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverBudgetController;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverBudgetRelease as Release;
use App\Models\MachineFailoverCostSnapshot as Snapshot;
use App\Models\MachineFailoverReviewAction as ReviewAction;
use App\Models\MachineFailoverReviewCheck as ReviewCheck;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as ExecutionMember;
use App\Models\MachineFailoverMember;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverService as Service;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\CloudFailurePolicy;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use App\Services\ServerMachine\Cloud\MachineFailoverBudget;
use App\Services\ServerMachine\Cloud\MachineFailoverReview;
use App\Services\ServerMachine\Cloud\MachineFailoverHistory;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverBudgetTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private User $admin;
    private ServerMachine $machine;
    private Account $account;
    private Policy $policy;
    private Service $service;
    private Task $source;
    private array $remote = [];
    private string $mode = '';
    private int $grants = 0;
    private int $price = 48;
    private $duringRead = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('b', 32), 'AES-256-CBC'));
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $name) config([$name => require base_path('config/' . $name . '.php')]);
        foreach (['2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_170000_create_machine_cloud_tables.php',
            '2026_11_05_200000_create_machine_operation_notices.php',
            '2026_11_05_220000_create_machine_provision_tasks.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_06_030000_create_machine_failover_policies.php',
            '2026_11_06_040000_create_machine_failover_observations.php', '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php',
            '2026_11_07_090000_create_machine_replacement_tasks.php', '2026_11_07_120000_add_provider_groups_to_machine_failover.php',
            '2026_11_07_140000_create_machine_failover_services.php', '2026_11_07_160000_create_machine_credential_health.php',
            '2026_11_07_180000_create_machine_failover_native_journal.php', '2026_11_07_190000_create_machine_failover_finance.php',
            '2026_11_07_200000_create_machine_failover_review.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); });
        $this->bindTestSettings(['app_url' => 'https://panel.example.test']);
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Isolated budget fixture', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        $this->account = Account::query()->create(['name' => 'Synthetic account', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-token']]);
        $health = app(MachineCloudCredentialHealth::class);
        DB::table('v2_machine_cloud_identity')->insert(['account_id' => $this->account->id, 'provider' => 'linode', 'credential_key' => $health->credentialKey($this->account),
            'identity_key' => hash_hmac('sha256', 'linode:account:fixture-account-000001', app('encrypter')->getKey()), 'verified_at' => time(), 'checked_at' => time()]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns']]);
        $this->policy = Policy::query()->create(['name' => 'Budget fixture', 'revision' => 1, 'updated_by' => $this->admin->id, 'machine_id' => $this->machine->id,
            'machine_authority' => hash('sha256', $this->machine->token), 'account_id' => $this->account->id, 'account_revision' => 1, 'slot' => 'budget-fixture',
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'failure_threshold' => 2, 'stale_after_seconds' => 300,
            'cooldown_seconds' => 300, 'monthly_limit_usd' => 100, 'daily_limit' => 2, 'dns_credential_id' => $dns->id, 'dns_credential_revision' => 1,
            'dns_hostname' => 'fixture.example.test', 'isolation_confirmed' => true]);
        $this->service = Service::query()->create(['name' => 'Independent fixture', 'revision' => 1, 'max_parallel' => 1, 'attempt_limit' => 4, 'monthly_cap_usd' => 300, 'updated_by' => $this->admin->id]);
        MachineFailoverMember::query()->create(['service_id' => $this->service->id, 'policy_id' => $this->policy->id, 'policy_revision' => 1,
            'machine_id' => $this->machine->id, 'name' => 'Fixture member', 'priority' => 1, 'enabled' => true]);
        $this->source = $this->task('accepted', '9001'); $this->owned($this->source, true); $this->fake();
    }

    private function task(string $status, ?string $external = null, ?string $attempt = null, bool $dispatched = true): Task
    {
        $id = (string) Str::uuid(); $marker = 'keli-create-' . bin2hex(random_bytes(12));
        $plan = ProvisionPlan::normalize(['account_id' => $this->account->id, 'machine_id' => $this->machine->id, 'slot' => $this->policy->slot,
            'panel_origin' => 'https://panel.example.test', 'region' => $this->policy->region, 'size' => $this->policy->size, 'image' => $this->policy->image, 'monthly_limit_usd' => 100]);
        $request = ['label' => 'keli-' . str_replace('-', '', $id), 'tags' => [$marker], 'region' => $plan['region'], 'type' => $plan['size'], 'image' => $plan['image']];
        $task = Task::query()->create(['id' => $id, 'request_key' => hash('sha256', $id), 'admin_id' => $this->admin->id, 'account_id' => $this->account->id,
            'account_revision' => 1, 'machine_id' => $this->machine->id, 'machine_authority' => hash('sha256', $this->machine->token), 'plan_hash' => hash('sha256', json_encode($plan)),
            'slot' => $plan['slot'], 'installation_id' => bin2hex(random_bytes(16)), 'callback_hash' => str_repeat('c', 64), 'marker' => $marker,
            'payload' => ['plan' => $plan, 'request' => $request] + ($attempt ? ['coordinated_attempt' => $attempt] : []), 'status' => $status,
            'external_id' => $external, 'provider_created_at' => $external ? gmdate('Y-m-d\TH:i:s\Z', time() - 600) : null,
            'dispatched_at' => $dispatched ? time() - 600 : null, 'deadline_at' => time() + 600]);
        if ($external) $this->remote[$external] = ['id' => (int) $external, 'label' => $request['label'], 'tags' => [$marker], 'type' => $plan['size'], 'region' => $plan['region'],
            'status' => 'running', 'created' => $task->provider_created_at, 'ipv4' => ['8.8.4.4'], 'ipv6' => '', 'backups' => ['enabled' => false]];
        return $task;
    }
    private function owned(Task $task, bool $active = false): MachineOwnedInstance
    {
        return MachineOwnedInstance::query()->create(['policy_id' => $this->policy->id, 'policy_revision' => 1, 'task_id' => $task->id,
            'installation_id' => $task->installation_id, 'release_id' => 1, 'binary_sha256' => str_repeat('b', 64), 'version' => 'synthetic', 'ip' => '8.8.4.4',
            'status' => $active ? 'active' : 'retained', 'active_policy_id' => $active ? $this->policy->id : null]);
    }
    private function attempt(string $state = 'rejected', ?string $external = null): Attempt
    {
        $run = Execution::query()->create(['id' => (string) Str::uuid(), 'service_id' => $this->service->id, 'service_revision' => 1, 'admin_id' => $this->admin->id,
            'request_key' => bin2hex(random_bytes(32)), 'context_hash' => str_repeat('c', 64), 'intent_hash' => str_repeat('d', 64), 'payload' => [], 'status' => 'needs_review', 'deadline_at' => time() + 600]);
        $member = ExecutionMember::query()->create(['execution_id' => $run->id, 'member_id' => MachineFailoverMember::query()->value('id'), 'policy_id' => $this->policy->id,
            'policy_revision' => 1, 'plan_id' => 1, 'episode_key' => bin2hex(random_bytes(32)), 'phase' => 'needs_review', 'payload' => []]);
        $id = (string) Str::uuid(); $task = $this->task($state === 'rejected' ? 'failed' : ($state === 'queued' ? 'queued' : 'needs_review'), $external, $id, $state !== 'queued');
        if ($state === 'rejected') $task->fill(['evidence' => ['credential_failure' => app(CloudFailurePolicy::class)->response('linode', 'POST', 401), 'credential_failure_operation' => 'create']])->save();
        $attempt = Attempt::query()->create(['id' => $id, 'execution_member_id' => $member->id, 'ordinal' => 1, 'request_id' => bin2hex(random_bytes(16)),
            'account_id' => $this->account->id, 'account_revision' => 1, 'reserved_cents' => 10000, 'status' => $state, 'payload' => ['plan' => $task->payload['plan']]]);
        $member->fill(['attempt_id' => $id])->save();
        $fixtureBinder = new class(app(MachineFailoverServiceRegistry::class), new MachineFailoverExecutionGate()) extends MachineFailoverNativeBridge {
            public function assertAttempt(Attempt $attempt, ?Task $task = null, bool $requireLease = true): void {}
        };
        DB::transaction(fn () => $fixtureBinder->bind($attempt, $task)); return $attempt->fresh();
    }
    private function finance(): MachineFailoverBudget { return app(MachineFailoverBudget::class); }
    private function read(): array { return $this->finance()->workspace($this->service->id, $this->service->revision, $this->admin->id); }
    private function capture(): array { return $this->finance()->capture($this->service->id, $this->service->revision, $this->admin->id); }
    private function code(string $reason, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $reason); } catch (CloudException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    private function fake(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method()); $url = $request->url();
            if ($this->duringRead) ($this->duringRead)($url);
            if (str_ends_with($url, '/account')) return Http::response(['euuid' => $this->mode === 'identity' ? 'fixture-other-000001' : 'fixture-account-000001']);
            if (str_ends_with($url, '/profile/grants')) { $this->grants++; return $this->mode === 'restricted' || $this->mode === 'scope_changes' && $this->grants > 1 ? Http::response(['linode' => []]) : Http::response('', 204); }
            if (str_contains($url, '/linode/instances?')) {
                if ($this->mode === 'failed') return Http::response([], 503);
                return Http::response(['page' => 1, 'pages' => 1, 'results' => count($this->remote) + ($this->mode === 'incomplete' ? 1 : 0), 'data' => array_values($this->remote)]);
            }
            if (str_contains($url, '/volumes?')) return Http::response(['page' => 1, 'pages' => 1, 'results' => $this->mode === 'volume' ? 1 : 0, 'data' => $this->mode === 'volume' ? [['id' => 123]] : []]);
            if (str_contains($url, '/linode/types/')) return Http::response(['id' => 'g6-standard-4', 'class' => $this->mode === 'unsupported' ? 'dedicated' : 'standard',
                'price' => ['monthly' => $this->price], 'region_prices' => $this->mode === 'regional' ? [['id' => 'sg-sin-2', 'monthly' => 52.3]] : []]);
            throw new \RuntimeException('Unmocked cost fixture');
        });
    }

    public static function jsonProviders(): array { return [['digitalocean'], ['vultr']]; }
    #[DataProvider('jsonProviders')]
    public function test_json_provider_cost_capture_uses_native_identity_and_never_treats_missing_as_zero(string $provider): void
    {
        $this->account->fill(['provider' => $provider])->save();
        [$region, $size, $image] = $provider === 'digitalocean' ? ['sgp1', 's-1vcpu-1gb', 'debian-12-x64'] : ['40', 'vc2-1c-1gb', '2136'];
        $payload = $this->source->payload; $plan = $payload['plan'];
        $plan = array_replace($plan, compact('provider', 'region', 'size', 'image'));
        $factory = new \App\Services\ServerMachine\Cloud\MachineProvisionProviders();
        $request = $factory->client($this->account, $plan)->buildRequest($plan, $this->source->id, $this->source->marker, "#cloud-config\nruncmd: []\n");
        $this->source->fill(['payload' => ['plan' => $plan, 'request' => $request]])->save();
        $row = \App\Services\ServerMachine\Cloud\MachineProvisionProviders::requestIdentity($provider, $request)
            + ['external_id' => '9001', 'provider_created_at' => $this->source->provider_created_at, 'tags' => [$this->source->marker]];
        $client = new class($row) implements \App\Services\ServerMachine\Cloud\FailoverCostClient {
            public array $rows;
            public function __construct(array $row) { $this->rows = ['9001' => $row]; }
            public function authority(Account $account): bool { return false; }
            public function completeInventory(Account $account): array { return $this->rows; }
            public function quote(Account $account, array $row): int { return 600; }
        };
        app()->instance(\App\Services\ServerMachine\Cloud\MachineProvisionProviders::class,
            new class($client) extends \App\Services\ServerMachine\Cloud\MachineProvisionProviders {
                public function __construct(private \App\Services\ServerMachine\Cloud\FailoverCostClient $cost) {}
                public function cost(Account $account): \App\Services\ServerMachine\Cloud\FailoverCostClient { return $this->cost; }
            });
        $cost = $this->capture()['cost']; $this->assertSame('verified', $cost['state'], json_encode($cost));
        $this->assertSame(600, $cost['baseline_cents']);
        $client->rows['9001']['image'] = 'other-image';
        $this->assertSame('incomplete', $this->capture()['cost']['state']);
        $client->rows = []; $cost = $this->capture()['cost'];
        $this->assertSame('incomplete', $cost['state']); $this->assertNull($cost['baseline_cents']); $this->assertNull($cost['available_cents']);
        $this->assertSame(0, Release::count());
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
        Http::assertNothingSent();
    }

    public function test_opening_workspace_and_run_reports_never_calls_cloud_or_releases_budget(): void
    {
        $attempt = $this->attempt(); $read = $this->read();
        $this->assertSame('unread', $read['cost']['state']); $this->assertNull($read['cost']['baseline_cents']);
        $this->assertSame(10000, $read['cost']['reserved_cents']); $this->assertFalse($read['execution_enabled']);
        $run = Execution::query()->first(); $report = $this->finance()->report($run->id, $this->admin->id);
        $this->assertSame($attempt->id, $report['members'][0]['attempts'][0]['id']);
        Http::assertNothingSent(); $this->assertSame(0, Release::count());
        foreach (['synthetic-token', 'synthetic-machine-secret', 'request_key', 'callback_hash', 'task_identity'] as $secret) $this->assertStringNotContainsString($secret, json_encode([$read, $report]));
    }
    public function test_cost_baseline_includes_existing_source_and_powered_off_retained_instances(): void
    {
        $old = $this->task('accepted', '9002'); $this->owned($old); $this->remote['9002']['status'] = 'offline';
        $read = $this->capture(); $cost = $read['cost'];
        $this->assertSame('verified', $cost['state'], json_encode($cost)); $this->assertSame(9600, $cost['baseline_cents']);
        $this->assertSame(4800, $cost['source_cents']); $this->assertSame(4800, $cost['retained_cents']); $this->assertSame(20400, $cost['available_cents']);
        $this->assertFalse($cost['actual_bill']); $this->assertFalse(config('machine_provision.allow_live')); $this->assertFalse(config('machine_acceptance.allow_dns'));
    }
    public function test_region_price_is_used_and_shared_capacity_includes_baseline_and_reservations(): void
    {
        $this->mode = 'regional'; $this->attempt(); $cost = $this->capture()['cost'];
        $this->assertSame(5230, $cost['baseline_cents']); $this->assertSame(15230, $cost['allocated_cents']);
        $this->finance()->assertCapacity($this->service->id, 1, 14770);
        $this->code('shared_monthly_budget', fn () => $this->finance()->assertCapacity($this->service->id, 1, 14771));
    }
    #[DataProvider('unverifiedCosts')]
    public function test_unverified_cost_or_inventory_is_never_presented_as_zero(string $mode): void
    {
        $this->mode = $mode;
        if ($mode === 'backup') $this->remote['9001']['backups']['enabled'] = true;
        if ($mode === 'ipv4') $this->remote['9001']['ipv4'][] = '8.8.8.8';
        if ($mode === 'restricted') $this->remote = [];
        $cost = $this->capture()['cost'];
        $this->assertSame('incomplete', $cost['state'], json_encode($cost)); $this->assertNull($cost['baseline_cents']); $this->assertNull($cost['available_cents']);
        $this->assertNull($cost['source_cents']);
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
    }
    public static function unverifiedCosts(): array { return array_map(fn ($value) => [$value], ['failed', 'incomplete', 'restricted', 'scope_changes', 'backup', 'ipv4', 'volume', 'unsupported', 'identity']); }
    public function test_unknown_retained_cost_does_not_hide_verified_source_cost_or_turn_into_zero(): void
    {
        $this->owned($this->task('accepted', '9002')); $this->remote['9002']['backups']['enabled'] = true;
        $cost = $this->capture()['cost']; $this->assertSame('incomplete', $cost['state']);
        $this->assertSame(4800, $cost['source_cents']); $this->assertNull($cost['retained_cents']); $this->assertNull($cost['available_cents']);
    }
    public function test_new_retained_resource_or_expired_snapshot_blocks_new_reservations(): void
    {
        $this->capture(); Snapshot::query()->update(['valid_until' => time() - 1]);
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
        $this->capture(); $this->owned($this->task('accepted', '9002'));
        $this->assertSame('expired', $this->read()['cost']['state']);
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
    }
    public function test_failed_refresh_does_not_leave_an_older_verified_baseline_usable(): void
    {
        $previous = $this->capture()['cost']['snapshot_id']; $this->mode = 'failed'; $cost = $this->capture()['cost'];
        $this->assertNotSame($previous, $cost['snapshot_id']); $this->assertSame('incomplete', $cost['state']);
        $this->assertNull($cost['baseline_cents']); $this->assertNull($cost['available_cents']);
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
    }
    public function test_canonical_account_aliases_do_not_count_the_same_cloud_instance_twice(): void
    {
        $alias = Account::query()->create(['name' => 'Alias fixture', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-other-token']]);
        $identity = DB::table('v2_machine_cloud_identity')->where('account_id', $this->account->id)->first();
        DB::table('v2_machine_cloud_identity')->insert(['account_id' => $alias->id, 'provider' => 'linode',
            'credential_key' => app(MachineCloudCredentialHealth::class)->credentialKey($alias), 'identity_key' => $identity->identity_key, 'verified_at' => time(), 'checked_at' => time()]);
        $copy = $this->task('accepted', '9001'); $payload = $copy->payload; $payload['request']['label'] = $this->source->payload['request']['label'];
        $copy->fill(['account_id' => $alias->id, 'payload' => $payload])->save(); $this->owned($copy);
        $this->remote['9001']['label'] = $payload['request']['label']; $this->remote['9001']['tags'][] = $this->source->marker;
        $cost = $this->capture()['cost']; $this->assertSame('verified', $cost['state'], json_encode($cost));
        $this->assertCount(2, $cost['items']); $this->assertSame(4800, $cost['baseline_cents']); $this->assertSame(4800, $cost['allocated_cents']);
    }
    public function test_queued_never_dispatched_attempt_releases_once_and_cancels_only_local_task(): void
    {
        $attempt = $this->attempt('queued'); $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->assertTrue($preview['eligible'], json_encode($preview)); $this->assertSame('not_dispatched', $preview['proof_kind']);
        $result = $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id);
        $again = $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id);
        $this->assertSame($result, $again); $this->assertSame(1, Release::count()); $this->assertSame('cancelled', Task::query()->find($attempt->task_id)->status);
        $this->assertSame('released', $attempt->fresh()->status); $this->assertSame(10000, $this->read()['cost']['released_cents']); Http::assertNothingSent();
    }
    public function test_definite_rejection_can_release_without_erasing_attempt_or_failure_evidence(): void
    {
        $attempt = $this->attempt(); $before = Task::query()->find($attempt->task_id)->only(['dispatched_at', 'evidence', 'installation_id', 'marker']);
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id); $this->assertSame('definite_rejection', $preview['proof_kind']);
        $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id);
        $this->assertSame($before, Task::query()->find($attempt->task_id)->only(array_keys($before))); $this->assertSame(1, Attempt::count());
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate { public function assertAllowed(string $operation): void {} });
        $this->code('budget_released', fn () => app(MachineFailoverNativeBridge::class)->assertAttempt($attempt->fresh()));
        Http::assertNothingSent();
    }
    public function test_unknown_create_cannot_be_released_even_after_complete_empty_inventory(): void
    {
        $attempt = $this->attempt('unknown'); $this->capture(); $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->assertFalse($preview['eligible']); $this->assertContains('original_intent_only', $preview['reasons']); $this->assertNull($preview['proof_token']);
        $this->assertSame(0, Release::count());
    }
    public function test_known_absent_instance_requires_fresh_complete_unrestricted_evidence(): void
    {
        $attempt = $this->attempt('unknown', '9002'); unset($this->remote['9002']);
        $this->assertFalse($this->finance()->previewRelease($attempt->id, $this->admin->id)['eligible']);
        $this->capture(); $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->assertTrue($preview['eligible'], json_encode($preview)); $this->assertSame('confirmed_absent', $preview['proof_kind']);
        $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id);
        $this->assertSame('cancelled', Task::query()->find($attempt->task_id)->status); $this->assertSame(1, Release::count());
        $this->assertSame(4800, $this->read()['cost']['allocated_cents']);
    }
    public function test_stopped_but_existing_instance_is_not_absence_or_free_budget(): void
    {
        $attempt = $this->attempt('unknown', '9002'); $this->remote['9002']['status'] = 'offline'; $this->capture();
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->assertFalse($preview['eligible']); $this->assertContains('budget_absence_unverified', $preview['reasons']);
        $this->assertSame(14800, $this->read()['cost']['allocated_cents']);
    }
    public function test_refresh_or_credential_revision_change_invalidates_prior_release_proof(): void
    {
        $attempt = $this->attempt('unknown', '9002'); unset($this->remote['9002']); $this->capture();
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id); $this->capture();
        $this->code('budget_proof_invalid', fn () => $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id));
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id); $this->account->fill(['revision' => 2])->save();
        $this->code('budget_proof_invalid', fn () => $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, Release::count());
    }
    public function test_dns_reference_and_running_member_block_release_of_an_absent_candidate(): void
    {
        $attempt = $this->attempt('unknown', '9002'); unset($this->remote['9002']); $instance = $this->owned(Task::query()->find($attempt->task_id)); $this->capture();
        MachineDnsTransition::query()->create(['id' => (string) Str::uuid(), 'request_key' => str_repeat('e', 64), 'dns_id' => 1, 'dns_revision' => 1,
            'policy_revision' => 1, 'admin_id' => $this->admin->id, 'to_instance_id' => $instance->id, 'to_ip' => '8.8.4.4', 'state' => 'needs_review', 'deadline_at' => time() + 100]);
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id); $this->assertContains('budget_resource_in_use', $preview['reasons']);
        MachineDnsTransition::query()->delete(); ExecutionMember::query()->whereKey($attempt->execution_member_id)->update(['phase' => 'creating']);
        $this->assertContains('budget_task_running', $this->finance()->previewRelease($attempt->id, $this->admin->id)['reasons']);
    }
    public function test_forged_expired_or_another_admins_release_token_does_not_credit_budget(): void
    {
        $attempt = $this->attempt(); $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->code('budget_proof_invalid', fn () => $this->finance()->release($attempt->id, 'forged', str_repeat('a', 32), $this->admin->id));
        $other = User::query()->create(['is_admin' => true]);
        $this->code('budget_proof_invalid', fn () => $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $other->id));
        $token = json_decode(app('encrypter')->decryptString($preview['proof_token']), true); $token['valid_until'] = time() - 1;
        $expired = app('encrypter')->encryptString(json_encode($token));
        $this->code('budget_proof_invalid', fn () => $this->finance()->release($attempt->id, $expired, str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, Release::count()); Http::assertNothingSent();
    }
    public function test_fresh_agent_report_blocks_release_even_if_cloud_inventory_says_absent(): void
    {
        $attempt = $this->attempt('unknown', '9002'); unset($this->remote['9002']); $this->capture(); $task = Task::query()->find($attempt->task_id);
        $inventory = \Mockery::mock(\App\Services\ServerMachine\MachineAddressInventory::class);
        app()->instance(\App\Services\ServerMachine\MachineAddressInventory::class, $inventory);
        $inventory->shouldReceive('snapshots')->andReturn([$this->machine->id => [$task->installation_id => ['at' => time()]]]);
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->assertFalse($preview['eligible']); $this->assertContains('budget_resource_in_use', $preview['reasons']);
    }
    public function test_release_is_denied_if_admin_authority_is_revoked_after_preview(): void
    {
        $attempt = $this->attempt(); $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->admin->fill(['banned' => true])->save();
        $this->code('permission_revoked', fn () => $this->finance()->release($attempt->id, $preview['proof_token'], str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, Release::count()); Http::assertNothingSent();
    }
    public function test_one_request_id_cannot_release_a_different_attempt(): void
    {
        $first = $this->attempt(); $proof = $this->finance()->previewRelease($first->id, $this->admin->id);
        $this->finance()->release($first->id, $proof['proof_token'], str_repeat('a', 32), $this->admin->id);
        $second = $this->attempt(); $before = $second->status; $proof = $this->finance()->previewRelease($second->id, $this->admin->id);
        $this->code('request_conflict', fn () => $this->finance()->release($second->id, $proof['proof_token'], str_repeat('a', 32), $this->admin->id));
        $this->assertSame(1, Release::count()); $this->assertSame($before, $second->fresh()->status); Http::assertNothingSent();
    }
    public function test_new_candidate_uses_its_reservation_and_does_not_double_count_as_baseline(): void
    {
        $this->capture(); $attempt = $this->attempt('unknown', '9002'); $this->owned(Task::query()->find($attempt->task_id));
        $cost = $this->read()['cost']; $this->assertSame('verified', $cost['state'], json_encode($cost)); $this->assertSame(4800, $cost['baseline_cents']);
        $this->assertSame(14800, $cost['allocated_cents']); $this->assertSame(1, $cost['pending_quote_count']);
        $this->capture(); $this->assertSame(14800, $this->read()['cost']['allocated_cents']);
        $this->price = 150; $this->capture(); $this->assertSame(30000, $this->read()['cost']['allocated_cents']);
        $this->code('shared_monthly_budget', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
    }
    public function test_explicitly_unverified_candidate_cost_blocks_new_reservations_despite_its_upper_bound(): void
    {
        $this->capture(); $this->attempt('unknown', '9002');
        $this->assertSame('verified', $this->read()['cost']['state']);
        $this->remote['9002']['backups']['enabled'] = true; $cost = $this->capture()['cost'];
        $this->assertSame('incomplete', $cost['state']); $this->assertNull($cost['available_cents']);
        $this->assertSame(10000, $cost['reserved_cents']); $this->assertSame(0, $cost['released_cents']);
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
    }
    public function test_controller_rejects_verdicts_and_requires_admin_before_validation(): void
    {
        $controller = new MachineFailoverBudgetController();
        foreach (['workspace', 'capture', 'report', 'preview', 'release'] as $method) {
            $request = Request::create('/synthetic', 'POST', ['passed' => true]); $request->setUserResolver(fn () => $this->admin);
            $response = $controller->{$method}($request, $this->finance()); $this->assertSame(422, $response->getStatusCode());
        }
        $request = Request::create('/synthetic', 'POST'); $request->setUserResolver(fn () => new User(['is_admin' => false]));
        try { $controller->release($request, $this->finance()); $this->fail('Expected admin denial'); } catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        Http::assertNothingSent();
    }
    public function test_audit_schema_is_not_discarded_by_downgrade(): void
    {
        $this->capture(); $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_07_190000_create_machine_failover_finance.php'))->down();
    }
    private function review(): MachineFailoverReview { return app(MachineFailoverReview::class); }
    private function late(Attempt $attempt, string $id = '9002'): void
    {
        $task = Task::query()->findOrFail($attempt->task_id); $request = $task->payload['request'];
        $this->remote[$id] = ['id' => (int) $id, 'label' => $request['label'], 'tags' => [$task->marker], 'type' => $request['type'],
            'region' => $request['region'], 'image' => $request['image'], 'status' => 'running',
            'created' => $task->provider_created_at ?: gmdate('Y-m-d\TH:i:s\Z', $task->dispatched_at + 2),
            'ipv4' => ['8.8.4.4'], 'ipv6' => '', 'backups' => ['enabled' => false]];
    }
    private function settle(Attempt $attempt): void
    {
        $preview = $this->finance()->previewRelease($attempt->id, $this->admin->id);
        $this->finance()->release($attempt->id, $preview['proof_token'], bin2hex(random_bytes(16)), $this->admin->id);
        $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->review()->act($check['check_id'], 'close', bin2hex(random_bytes(16)), $this->admin->id);
    }
    public function test_late_candidate_is_read_only_then_claimed_once_and_never_resumes_the_old_task(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt);
        $before = Task::query()->find($attempt->task_id)->toArray();
        $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->assertSame('found', $check['state']); $this->assertTrue($check['can_claim']); $this->assertFalse($check['can_close']);
        $this->assertSame($before, Task::query()->find($attempt->task_id)->toArray());
        $result = $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $this->admin->id);
        $this->assertSame($result, $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $this->admin->id));
        $task = Task::query()->find($attempt->task_id); $this->assertSame('9002', $task->external_id); $this->assertSame('needs_review', $task->status);
        $this->assertSame(1, ReviewAction::where('operation', 'claim')->count()); $this->assertSame(0, Release::count());
        $check = $this->review()->read($attempt->id, null, $this->admin->id); $this->assertTrue($check['can_close']);
        $this->review()->act($check['check_id'], 'close', str_repeat('b', 32), $this->admin->id);
        $this->assertSame('retained', $this->review()->summary($attempt->execution_member_id, $attempt->id)['resolution']);
        $this->assertSame(10000, $this->read()['cost']['reserved_cents']); $this->assertSame(0, $this->read()['cost']['released_cents']);
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate { public function assertAllowed(string $operation): void {} });
        $this->code('review_hold', fn () => app(MachineFailoverNativeBridge::class)->assertAttempt($attempt->fresh()));
    }
    #[DataProvider('unsafeReviewCases')]
    public function test_late_candidate_review_rejects_unsafe_cloud_views(string $mode): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt); $reason = 'review_identity_mismatch';
        if ($mode === 'duplicate') { $this->late($attempt, '9003'); $reason = 'duplicate_candidates'; }
        elseif ($mode === 'image') $this->remote['9002']['image'] = 'linode/debian11';
        elseif ($mode === 'created') $this->remote['9002']['created'] = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
        elseif ($mode === 'restricted') { $this->mode = 'restricted'; $reason = 'cost_scope_unknown'; }
        elseif ($mode === 'incomplete') { $this->mode = 'incomplete'; $reason = 'cost_inventory_incomplete'; }
        $this->code($reason, fn () => $this->review()->read($attempt->id, null, $this->admin->id));
        $this->assertNull(Task::query()->find($attempt->task_id)->external_id); $this->assertSame(0, ReviewAction::count());
    }
    public static function unsafeReviewCases(): array { return [['duplicate'], ['image'], ['created'], ['restricted'], ['incomplete']]; }
    public function test_review_proof_is_actor_bound_expiring_and_rechecks_cloud_before_claim(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt); $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $other = User::query()->create(['is_admin' => true]);
        $this->code('review_proof_expired', fn () => $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $other->id));
        ReviewCheck::query()->whereKey($check['check_id'])->update(['valid_until' => time() - 1]);
        $this->code('review_proof_expired', fn () => $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $this->admin->id));
        $check = $this->review()->read($attempt->id, null, $this->admin->id); unset($this->remote['9002']);
        $this->code('review_context_changed', fn () => $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, ReviewAction::count());
    }
    public function test_unknown_unidentified_create_cannot_be_closed_or_archived(): void
    {
        $attempt = $this->attempt('unknown'); $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->assertSame('unresolved', $check['state']); $this->assertFalse($check['can_close']);
        $this->code('review_action_blocked', fn () => $this->review()->act($check['check_id'], 'close', str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, ReviewAction::count());
    }
    public function test_archived_rejections_do_not_create_a_lifetime_hundred_task_limit_or_erase_audit(): void
    {
        for ($i = 0; $i < 101; $i++) $this->settle($this->attempt());
        $cost = $this->capture()['cost'];
        $this->assertSame('verified', $cost['state'], json_encode($cost)); $this->assertSame(101, $cost['archived_count']);
        $this->assertSame(4800, $cost['baseline_cents']); $this->assertSame(0, $cost['reserved_cents'] - $cost['released_cents']);
        $this->assertSame(102, Task::count()); $this->assertSame(101, Attempt::count()); $this->assertSame(101, Release::count());
        $this->assertSame(101, ReviewAction::where('operation', 'archive')->count());
        $this->finance()->assertCapacity($this->service->id, 1, 10000);
    }
    public function test_archived_absent_resource_is_still_watched_and_reappearance_blocks_budget_until_restored(): void
    {
        $attempt = $this->attempt('unknown', '9002'); $this->late($attempt); $saved = $this->remote['9002']; unset($this->remote['9002']);
        $this->capture(); $this->settle($attempt); $cost = $this->capture()['cost'];
        $this->assertSame(1, $cost['watched_count']); $this->assertSame(4800, $cost['allocated_cents']);
        $this->remote['9002'] = $saved; $cost = $this->capture()['cost'];
        $this->assertSame('incomplete', $cost['state']); $this->assertContains('archived_resource_returned', $cost['blockers']);
        $this->assertSame(9600, $cost['allocated_cents']); $this->assertNull($cost['available_cents']);
        $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->review()->act($check['check_id'], 'restore', str_repeat('c', 32), $this->admin->id);
        $cost = $this->capture()['cost']; $this->assertSame('verified', $cost['state']); $this->assertSame(9600, $cost['allocated_cents']);
        $this->assertSame(1, Release::count());
    }
    public function test_history_keeps_old_unresolved_runs_visible_and_paginates_without_resetting_audit(): void
    {
        $attempt = $this->attempt(); $old = Execution::query()->first(); $old->fill(['created_at' => time() - 86400])->save();
        for ($i = 0; $i < 55; $i++) {
            $run = $old->replicate(); $run->id = (string) Str::uuid(); $run->request_key = bin2hex(random_bytes(32));
            $run->status = 'completed'; $run->created_at = time(); $run->save();
        }
        $history = app(MachineFailoverHistory::class); $page = $history->page($this->service->id, 1, $this->admin->id, 'all');
        $this->assertSame($old->id, $page['items'][0]['id']); $this->assertCount(20, $page['items']); $this->assertSame(56, $page['total']);
        $next = $history->page($this->service->id, 1, $this->admin->id, 'all', $page['next_cursor']);
        $this->assertEmpty(array_intersect(array_column($page['items'], 'id'), array_column($next['items'], 'id')));
        $this->code('history_changed', fn () => $history->page($this->service->id, 1, $this->admin->id, 'closed', $page['next_cursor']));
        $this->settle($attempt);
        $this->code('history_changed', fn () => $history->page($this->service->id, 1, $this->admin->id, 'all', $page['next_cursor']));
        $this->assertSame(0, $history->page($this->service->id, 1, $this->admin->id, 'open')['total']);
    }
    public function test_stopped_member_without_an_attempt_can_be_closed_without_inventing_resource_or_release(): void
    {
        $attempt = $this->attempt(); $member = ExecutionMember::query()->find($attempt->execution_member_id);
        $attempt->delete(); $member->fill(['attempt_id' => null])->save();
        $check = $this->review()->read(null, $member->id, $this->admin->id);
        $this->assertTrue($check['can_close']); $this->assertSame('no_intent', $check['state']);
        $this->review()->act($check['check_id'], 'close', str_repeat('a', 32), $this->admin->id);
        $this->assertSame('no_intent', $this->review()->summary($member->id, null)['resolution']);
        $this->assertSame(0, Release::count()); Http::assertNothingSent();
    }
    public function test_proof_view_does_not_describe_an_expired_historical_receipt_as_a_current_pass(): void
    {
        $attempt = $this->attempt('unknown', '9002'); $instance = $this->owned(Task::query()->find($attempt->task_id));
        $instance->fill(['verification' => ['receipt_sha256' => str_repeat('a', 64)], 'verified_until' => time() - 1])->save();
        DB::table('v2_machine_acceptance_check')->insert(['id' => str_repeat('b', 32), 'instance_id' => $instance->id,
            'policy_revision' => 1, 'request_sha256' => str_repeat('c', 64), 'state' => 'passed', 'receipt_sha256' => str_repeat('a', 64), 'issued_at' => time() - 120, 'deadline_at' => time() - 1]);
        $payload = $attempt->payload; $payload['forwarding_receipt'] = str_repeat('d', 64); $attempt->fill(['payload' => $payload])->save();
        $proof = app(\App\Services\ServerMachine\Cloud\MachineFailoverProofView::class)->forAttempt($attempt);
        $this->assertSame('expired', $proof['forwarding_proof']['state']); $this->assertSame(time() - 1, $proof['forwarding_proof']['valid_until']);
        $instance->fill(['verified_until' => time() + 120])->save();
        $this->assertSame('historical', app(\App\Services\ServerMachine\Cloud\MachineFailoverProofView::class)->forAttempt($attempt)['forwarding_proof']['state']);
    }
    public function test_a_review_read_invalidates_older_cost_evidence_even_before_a_claim_or_close(): void
    {
        $attempt = $this->attempt('unknown', '9002'); $this->late($attempt); $saved = $this->remote['9002']; unset($this->remote['9002']);
        $this->capture(); $this->settle($attempt); $this->capture(); $this->assertSame('verified', $this->read()['cost']['state']);
        $this->remote['9002'] = $saved; $this->review()->read($attempt->id, null, $this->admin->id);
        $this->assertSame('expired', $this->read()['cost']['state']);
        $this->code('cost_baseline_required', fn () => $this->finance()->assertCapacity($this->service->id, 1, 1));
    }
    public function test_review_cannot_close_a_reserved_attempt_without_a_task_until_its_budget_is_released(): void
    {
        $attempt = $this->attempt('queued'); Task::query()->whereKey($attempt->task_id)->delete(); $attempt->fill(['task_id' => null])->save();
        $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->assertSame('not_created', $check['state']); $this->assertFalse($check['can_close']);
        $this->code('review_action_blocked', fn () => $this->review()->act($check['check_id'], 'close', str_repeat('a', 32), $this->admin->id));
        $this->assertSame(10000, $this->read()['cost']['reserved_cents']);
    }
    public function test_review_rechecks_authority_and_stopped_state_instead_of_replaying_a_run(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt);
        Execution::query()->update(['status' => 'running']);
        $this->code('review_not_stopped', fn () => $this->review()->read($attempt->id, null, $this->admin->id));
        Http::assertNothingSent(); Execution::query()->update(['status' => 'needs_review']);
        $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->duringRead = function ($url) { if (str_contains($url, '/linode/instances?')) $this->admin->fill(['banned' => true])->save(); };
        $this->code('permission_revoked', fn () => $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $this->admin->id));
        $this->assertNull(Task::query()->find($attempt->task_id)->external_id); $this->assertSame(0, ReviewAction::count());
    }
    public function test_controller_rejects_client_verdicts_in_review_and_history_requests(): void
    {
        $controller = new MachineFailoverBudgetController();
        foreach (['history' => app(MachineFailoverHistory::class), 'review' => $this->review(), 'resolve' => $this->review()] as $method => $service) {
            $request = Request::create('/synthetic', 'POST', ['passed' => true, 'external_id' => 'attacker-chosen']); $request->setUserResolver(fn () => $this->admin);
            $this->assertSame(422, $controller->{$method}($request, $service)->getStatusCode());
        }
        Http::assertNothingSent();
    }
    public function test_superseded_review_check_cannot_authorize_a_later_claim(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt); $first = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->review()->read($attempt->id, null, $this->admin->id);
        $this->code('review_proof_expired', fn () => $this->review()->act($first['check_id'], 'claim', str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, ReviewAction::count());
    }
    public function test_review_during_cost_capture_prevents_older_inventory_from_becoming_fresh_again(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt); $once = false;
        $this->duringRead = function ($url) use ($attempt, &$once) {
            if (!$once && str_contains($url, '/linode/types/')) {
                $once = true;
                ReviewCheck::query()->create(['id' => (string) Str::uuid(), 'member_id' => $attempt->execution_member_id, 'attempt_id' => $attempt->id,
                    'admin_id' => $this->admin->id, 'context_hash' => str_repeat('a', 64), 'payload' => ['state' => 'unresolved', 'candidate' => null],
                    'checked_at' => time(), 'valid_until' => time() + 120]);
            }
        };
        $this->code('cost_context_changed', fn () => $this->capture());
        $this->assertNotSame('verified', $this->read()['cost']['state']); $this->assertSame(0, ReviewAction::count());
    }
    public function test_a_new_active_admin_can_review_an_orphaned_run_without_restoring_the_original_admins_authority(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt); $this->admin->fill(['banned' => true])->save(); $other = User::query()->create(['is_admin' => true]);
        $check = $this->review()->read($attempt->id, null, $other->id);
        $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $other->id);
        $this->assertSame($this->admin->id, Execution::query()->first()->admin_id);
        $this->assertSame('needs_review', Task::query()->find($attempt->task_id)->status); $this->assertSame($other->id, ReviewAction::query()->first()->admin_id);
    }
    public function test_proof_expiry_during_provider_readback_rolls_back_claim_metadata(): void
    {
        $attempt = $this->attempt('unknown'); $this->late($attempt); $check = $this->review()->read($attempt->id, null, $this->admin->id);
        $this->duringRead = function ($url) use ($check) {
            if (str_contains($url, '/linode/instances?')) ReviewCheck::query()->whereKey($check['check_id'])->update(['valid_until' => time() - 1]);
        };
        $this->code('review_proof_expired', fn () => $this->review()->act($check['check_id'], 'claim', str_repeat('a', 32), $this->admin->id));
        $this->assertNull(Task::query()->find($attempt->task_id)->external_id); $this->assertSame(0, ReviewAction::count());
    }
    public function test_review_schema_downgrade_never_discards_review_evidence(): void
    {
        $this->review()->read($this->attempt()->id, null, $this->admin->id); $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_07_200000_create_machine_failover_review.php'))->down();
    }
    public function test_cancelled_but_unsettled_attempt_stays_in_open_review_until_locally_settled(): void
    {
        $attempt = $this->attempt('queued'); Execution::query()->update(['status' => 'cancelled']);
        ExecutionMember::query()->update(['phase' => 'cancelled']); $history = app(MachineFailoverHistory::class);
        $this->assertSame(1, $history->page($this->service->id, 1, $this->admin->id, 'open')['total']);
        $this->assertSame(1, $this->read()['runs'][0]['needs_review']); $this->settle($attempt);
        $this->assertSame(0, $history->page($this->service->id, 1, $this->admin->id, 'open')['total']);
        $this->assertSame('closed', $this->read()['runs'][0]['review_state']);
    }
}
