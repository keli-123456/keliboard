<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineCredentialHealthController;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudGroup;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask as Task;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\CloudFailurePolicy;
use App\Services\ServerMachine\Cloud\CloudRequestFailure;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineCredentialHealthTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineCloudCredentialHealth $health;
    private User $admin;
    private Account $a;
    private Account $b;
    private Policy $policy;
    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('c', 32), 'AES-256-CBC'));
        config(['machine_provision' => require base_path('config/machine_provision.php')]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_07_120000_add_provider_groups_to_machine_failover.php',
            '2026_11_07_160000_create_machine_credential_health.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $group = MachineCloudGroup::query()->create(['name' => 'Synthetic pool', 'name_key' => hash('sha256', 'pool'), 'revision' => 1]);
        $this->a = $this->account('linode', 'synthetic-token-a', $group->id); $this->b = $this->account('linode', 'synthetic-token-b', $group->id);
        $this->policy = new Policy(['machine_id' => 1, 'slot' => 'synthetic-slot', 'provider' => 'linode', 'credential_group_id' => $group->id,
            'daily_limit' => 2, 'cooldown_seconds' => 0, 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12']);
        $this->health = new MachineCloudCredentialHealth(); Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
    }
    private function account(string $provider, string $token, int $group = 1): Account
    {
        return Account::query()->create(['name' => 'Synthetic ' . $provider, 'provider' => $provider, 'revision' => 1, 'group_id' => $group, 'group_revision' => 1, 'credentials' => ['api_token' => $token]]);
    }
    private function error(int $status, array $body = [], array $headers = []): CloudRequestFailure { return new CloudRequestFailure((new CloudFailurePolicy())->response('linode', 'POST', $status, $body, $headers)); }
    private function bind(Account $account, string $entity): array
    {
        $this->http(fn () => Http::response(['euuid' => $entity, 'email' => 'private@example.test', 'credit_card' => ['last_four' => '9999']]));
        return $this->health->verify($account->id, $account->revision, $this->admin->id);
    }
    private function http(callable $reply): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) use ($reply) { $this->assertSame('GET', $request->method()); return $reply($request); });
    }
    private function code(string $code, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $code); } catch (CloudException $error) { $this->assertSame($code, $error->getMessage()); }
    }
    private function history(Account $account): Task
    {
        $id = (string) Str::uuid();
        return Task::query()->create(['id' => $id, 'request_key' => hash('sha256', $id), 'admin_id' => $this->admin->id, 'account_id' => $account->id,
            'account_revision' => 1, 'machine_id' => 1, 'machine_authority' => hash('sha256', 'synthetic'), 'plan_hash' => hash('sha256', 'plan'),
            'slot' => 'other-slot', 'installation_id' => substr(hash('sha256', $id), 0, 32), 'callback_hash' => hash('sha256', 'secret' . $id), 'marker' => hash('sha256', 'marker' . $id),
            'payload' => ['plan' => []], 'status' => 'failed', 'deadline_at' => time() + 900]);
    }
    public function test_opening_health_is_read_only_and_never_exposes_provider_identity_or_secrets(): void
    {
        $report = $this->health->report($this->a->id, $this->admin->id);
        $this->assertSame('unknown', $report['health']['state']); $this->assertFalse($report['automatic_rotation']);
        $this->assertSame(0, DB::table('v2_machine_credential_event')->count()); Http::assertNothingSent();
    }
    public function test_authentication_failure_is_idempotent_survives_rename_and_only_matching_read_proof_recovers_it(): void
    {
        $this->health->record($this->a, $this->error(401), 'create', 'intent-a', [], 'intent-a');
        $this->health->record($this->a, $this->error(401), 'create', 'intent-a', [], 'intent-a');
        $this->assertSame(1, DB::table('v2_machine_credential_event')->count()); $this->assertFalse($this->health->status($this->a)['eligible']);
        $this->a->fill(['name' => 'Renamed', 'revision' => 2])->save(); $this->assertFalse($this->health->status($this->a)['eligible']);
        $other = $this->bind($this->b, 'account-other-0001'); $this->assertFalse($this->health->status($this->a)['eligible']);
        $own = $this->bind($this->a, 'account-own-000001'); $this->assertTrue($own['health']['eligible']); $this->assertFalse($own['health']['creation_verified']);
        $json = json_encode($own); foreach (['synthetic-token-a', 'private@example.test', '9999', 'account-own-000001', 'credential_key', 'identity_key'] as $value) $this->assertStringNotContainsString($value, $json);
        $this->assertSame('read_verified', $other['health']['state']);
    }
    public function test_read_permission_does_not_clear_create_permission_or_billing_restrictions(): void
    {
        foreach ([403, 402] as $status) $this->health->record($this->a, $this->error($status), 'create', 'request-' . $status);
        $value = $this->bind($this->a, 'account-shared-0001'); $this->assertFalse($value['health']['eligible']);
        $this->assertSame('review', $value['health']['state']); $this->assertCount(2, $value['health']['reasons']);
    }
    public function test_verified_aliases_share_account_failures_and_unverified_identities_fail_closed(): void
    {
        $this->health->record($this->a, $this->error(402), 'create', 'billing');
        $this->assertFalse($this->health->status($this->b)['eligible']);
        $this->bind($this->a, 'account-shared-0001'); $this->bind($this->b, 'account-shared-0001');
        $this->assertSame([$this->a->id, $this->b->id], $this->health->sameAccountIds($this->a));
        $this->assertFalse($this->health->status($this->b)['eligible']); $this->assertSame(2, $this->health->status($this->a)['shared_credentials']);
        $other = $this->account('linode', 'synthetic-token-c'); $this->bind($other, 'account-independent-0001');
        $this->assertTrue($this->health->status($other)['eligible']);
    }
    public function test_duplicate_token_is_one_credential_even_with_two_local_rows(): void
    {
        $this->b->fill(['credentials' => $this->a->credentials])->save();
        $this->health->record($this->a, $this->error(401), 'create', 'auth');
        $this->assertFalse($this->health->status($this->b)['eligible']);
    }
    public function test_cooldown_expiry_is_probation_not_a_claim_of_success_and_rate_limits_are_provider_wide(): void
    {
        $this->health->record($this->a, $this->error(429, [], ['Retry-After' => '900']), 'create', 'rate');
        $this->assertFalse($this->health->status($this->b)['eligible']); $this->assertGreaterThanOrEqual(time() + 899, $this->health->status($this->b)['retry_at']);
        DB::table('v2_machine_credential_health')->update(['blocked_until' => time() - 1]);
        $value = $this->health->status($this->b); $this->assertTrue($value['eligible']); $this->assertSame('probation', $value['state']);
    }
    public function test_only_a_fresh_valid_read_recovers_an_expired_backoff_not_an_account_or_create_restriction(): void
    {
        $this->health->record($this->a, $this->error(429), 'preflight', 'rate');
        DB::table('v2_machine_credential_health')->update(['blocked_until' => time() - 1]);
        $report = $this->bind($this->a, 'account-recovery-0001');
        $this->assertTrue($report['health']['eligible']); $this->assertSame('read_verified', $report['health']['state']);
        $this->health->record($this->a, $this->error(403), 'create', 'forbidden');
        $this->health->authenticatedRead($this->a, 'inventory'); $this->assertFalse($this->health->status($this->a)['eligible']);
    }
    public function test_account_read_cannot_shorten_an_active_provider_backoff(): void
    {
        $this->health->record($this->a, $this->error(429, [], ['Retry-After' => '900']), 'create', 'rate');
        $report = $this->bind($this->a, 'account-rate-000001');
        $this->assertFalse($report['health']['eligible']); $this->assertSame('cooldown', $report['health']['state']);
        $this->assertGreaterThanOrEqual(time() + 899, $report['health']['retry_at']);
    }
    public function test_malformed_read_then_valid_read_recovers_only_that_read_and_preserves_account_identity(): void
    {
        $this->bind($this->a, 'account-original-0001'); $identity = $this->health->identityKey($this->a);
        $this->http(fn () => Http::response(['unexpected' => 'private-body']));
        $this->code('provider_invalid', fn () => $this->health->verify($this->a->id, 1, $this->admin->id));
        $this->assertFalse($this->health->status($this->a)['eligible']);
        $this->assertSame($identity, $this->health->identityKey($this->a));
        $report = $this->bind($this->a, 'account-original-0001');
        $this->assertTrue($report['health']['eligible']); $this->assertSame('read_verified', $report['health']['state']);
        $this->assertStringNotContainsString('private-body', json_encode($report));
    }
    public function test_inventory_authentication_proof_cannot_refresh_expired_account_identity_or_clear_billing(): void
    {
        $this->bind($this->a, 'account-expired-0001');
        DB::table('v2_machine_cloud_identity')->update(['verified_at' => time() - 90000]);
        $this->health = new MachineCloudCredentialHealth();
        $this->health->record($this->a, $this->error(401), 'inventory', 'auth');
        $this->health->record($this->a, $this->error(402), 'create', 'billing');
        $this->health->authenticatedRead($this->a, 'inventory'); $report = $this->health->report($this->a->id, $this->admin->id);
        $this->assertFalse($report['health']['eligible']); $this->assertSame('unknown', $report['health']['identity']);
        $this->assertSame(['billing_locked'], array_column($report['health']['reasons'], 'kind'));
        $this->assertSame(1, DB::table('v2_machine_credential_event')->where('kind', 'auth_recovered')->count());
    }
    public function test_old_active_restriction_cannot_be_hidden_by_many_newer_expired_cooldowns(): void
    {
        $this->health->record($this->a, $this->error(402), 'create', 'billing');
        DB::table('v2_machine_credential_health')->update(['observed_at' => time() - 500]);
        $rate = ['provider' => 'linode', 'kind' => 'rate_limited', 'scope' => 'provider', 'account_id' => $this->b->id,
            'scope_key' => hash('sha256', 'linode:provider:egress'), 'last_source_key' => hash('sha256', 'synthetic-source'),
            'state' => 'cooldown', 'operation' => 'inventory', 'http_status' => 429, 'failures' => 1,
            'blocked_until' => time() - 1, 'observed_at' => time(), 'cleared_at' => null];
        for ($i = 0; $i < 101; $i++) DB::table('v2_machine_credential_health')->insert($rate + ['credential_key' => hash('sha256', 'synthetic-' . $i)]);
        $report = $this->health->status($this->a);
        $this->assertFalse($report['eligible']); $this->assertSame('review', $report['state']);
        $this->assertSame('billing_locked', $report['reasons'][0]['kind']); $this->assertCount(100, $report['reasons']);
    }
    public function test_audit_cap_retains_health_and_deduplicates_the_latest_failure(): void
    {
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'account_id' => $this->a->id,
                'credential_key' => $this->health->credentialKey($this->a), 'source_key' => hash('sha256', 'synthetic-' . $i),
                'kind' => 'auth_invalid', 'scope' => 'credential', 'state' => 'review', 'operation' => 'inventory',
                'http_status' => 401, 'blocked_until' => null, 'occurred_at' => time()];
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('v2_machine_credential_event')->insert($chunk);
        $this->health->record($this->a, $this->error(401), 'create', 'latest');
        $this->health->record($this->a, $this->error(401), 'create', 'latest');
        $this->assertSame(10000, DB::table('v2_machine_credential_event')->count());
        $this->assertSame(1, DB::table('v2_machine_credential_health')->first()->failures);
        $this->assertFalse($this->health->status($this->a)['eligible']);
        $this->assertCount(50, $this->health->report($this->a->id, $this->admin->id)['events']);
    }
    public function test_rebinding_merges_health_without_discarding_audit_and_downgrade_is_refused(): void
    {
        $this->bind($this->a, 'account-original-0001');
        $this->health->record($this->a, $this->error(402), 'create', 'billing-verified');
        DB::table('v2_machine_cloud_identity')->update(['verified_at' => time() - 90000]);
        $this->health = new MachineCloudCredentialHealth();
        $this->health->record($this->a, $this->error(402), 'create', 'billing-unverified');
        $this->assertSame(2, DB::table('v2_machine_credential_health')->count());
        $this->bind($this->a, 'account-original-0001');
        $this->assertSame(1, DB::table('v2_machine_credential_health')->count());
        $this->assertSame(2, DB::table('v2_machine_credential_event')->count());
        $this->assertFalse($this->health->status($this->a)['eligible']);
        try { (require base_path('database/migrations/2026_11_07_160000_create_machine_credential_health.php'))->down(); $this->fail('Expected audit retention'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertTrue(Schema::hasTable('v2_machine_credential_event'));
    }
    public function test_locked_account_in_a_successful_digitalocean_read_is_not_marked_ready(): void
    {
        $account = $this->account('digitalocean', 'synthetic-do-locked');
        $this->http(fn () => Http::response(['account' => ['uuid' => 'account-locked-0001', 'status' => 'locked']]));
        $report = $this->health->verify($account->id, 1, $this->admin->id);
        $this->assertFalse($report['health']['eligible']); $this->assertSame('billing_locked', $report['health']['reasons'][0]['kind']);
        $this->assertFalse($report['health']['creation_verified']);
    }
    public function test_unknown_outcome_blocks_new_selection_but_does_not_block_reconciling_the_original_intent(): void
    {
        $this->health->record($this->a, $this->error(503), 'create', 'lost-request', [], 'original-intent');
        $this->assertFalse($this->health->status($this->a)['eligible']);
        $this->assertTrue($this->health->status($this->a, [], 'original-intent')['eligible']);
        $this->assertFalse($this->health->status($this->a, [], 'other-intent')['eligible']);
        $this->health->resolveIntent($this->a, 'original-intent'); $this->assertTrue($this->health->status($this->a)['eligible']);
    }
    public function test_group_selection_skips_bad_credentials_and_shares_budget_by_verified_account_identity(): void
    {
        $this->bind($this->a, 'account-shared-0001'); $this->bind($this->b, 'account-shared-0001');
        $this->health->record($this->a, $this->error(401), 'preflight', 'auth');
        $credentials = new MachineFailoverCredentials();
        $this->assertSame($this->b->id, $credentials->selection($this->policy)['account_id']);
        $this->history($this->a); $this->history($this->a);
        $this->code('credential_group_exhausted', fn () => $credentials->selection($this->policy)); Http::assertSentCount(1);
    }
    public function test_failed_or_stale_identity_reads_cannot_overwrite_a_verified_binding(): void
    {
        $this->bind($this->a, 'account-original-0001');
        $this->code('credential_identity_changed', fn () => $this->bind($this->a, 'account-different-0001'));
        $this->http(function () { $this->a->fill(['revision' => 2])->save(); return Http::response(['euuid' => 'account-original-0001']); });
        $this->code('account_changed', fn () => $this->health->verify($this->a->id, 1, $this->admin->id));
        $this->assertSame(1, DB::table('v2_machine_cloud_identity')->count());
    }
    public function test_digitalocean_team_identity_takes_precedence_and_vultr_never_infers_an_account_from_email(): void
    {
        $doA = $this->account('digitalocean', 'synthetic-do-a'); $doB = $this->account('digitalocean', 'synthetic-do-b');
        $this->http(fn () => Http::response(['account' => ['uuid' => 'user-individual-0001', 'team' => ['uuid' => 'team-shared-000001']]]));
        $this->health->verify($doA->id, 1, $this->admin->id); $this->health->verify($doB->id, 1, $this->admin->id);
        $this->assertSame([$doA->id, $doB->id], $this->health->sameAccountIds($doA));
        $vultr = $this->account('vultr', 'synthetic-vultr'); $this->http(fn () => Http::response(['account' => ['email' => 'private@example.test', 'balance' => 10]]));
        $report = $this->health->verify($vultr->id, 1, $this->admin->id);
        $this->assertSame('unknown', $report['health']['identity']); $this->assertSame('read_verified', $report['health']['state']);
        $this->assertNull($report['health']['shared_credentials']);
    }
    public function test_admin_endpoints_reject_forged_health_proofs_before_any_provider_call(): void
    {
        $controller = new MachineCredentialHealthController();
        $request = Request::create('/synthetic', 'POST', ['id' => $this->a->id, 'expected_revision' => 1, 'confirmed' => true, 'identity_key' => 'forged']);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->verify($request, $this->health)->getStatusCode());
        $this->admin->fill(['banned' => true])->save();
        try { $controller->report($request, $this->health); $this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        Http::assertNothingSent();
    }
}
