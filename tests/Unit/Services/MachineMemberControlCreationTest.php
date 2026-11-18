<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberControlCreation as Control;
use Keli\Acceptance\MemberProviderBindings as Provider;
use Tests\TestCase;

class MachineMemberControlCreationTest extends TestCase
{
    private array $journal = [];
    private array $scope;
    private array $row;
    private int $now;
    private bool $allowed = true;
    private bool $failSave = false;
    private string $bootstrap = "#cloud-config\nssh_pwauth: false\ndisable_root: false\n";
    private MachineCloudAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tools/machine-acceptance/member_provider_bindings.php');
        require_once base_path('tools/machine-acceptance/member_control_creation.php');
        Http::preventStrayRequests();
        app()->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        $this->now = time() - 5;
        config(['machine_provision.allow_live' => true, 'machine_provision.supported_sizes' => ['g6-standard-2'],
            'machine_provision.supported_images' => ['linode/debian12']]);
        $this->account = new MachineCloudAccount();
        $this->account->forceFill(['id' => 71, 'provider' => 'linode', 'credentials' => ['api_token' => 'synthetic-only']]);
        $this->scope = ['run_id' => Provider::RUN, 'task_id' => '00000000-0000-4000-8000-000000000071',
            'account_id' => 71, 'bundle_sha256' => str_repeat('a', 64), 'bootstrap_sha256' => hash('sha256', $this->bootstrap),
            'started_at' => $this->now, 'deadline_at' => $this->now + 7200, 'baseline_ids' => ['700'],
            'plan' => ['region' => 'sg-sin-2', 'size' => 'g6-standard-2', 'image' => 'linode/debian12',
                'authorized_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestOnly test-only']]];
        $suffix = str_replace('-', '', $this->scope['task_id']);
        $this->row = ['id' => 800, 'label' => 'keli-' . $suffix, 'tags' => ['keli-create-' . $suffix],
            'type' => 'g6-standard-2', 'region' => 'sg-sin-2', 'image' => 'linode/debian12',
            'created' => gmdate('Y-m-d\TH:i:s', $this->now), 'ipv4' => ['45.79.1.10'], 'status' => 'running'];
    }

    private function control(): Control
    {
        return new Control($this->scope, $this->account, $this->bootstrap, fn () => $this->allowed,
            fn ($k) => $this->journal[$k] ?? null, function ($k, $v): void {
                if ($this->failSave) throw new \RuntimeException('journal unavailable');
                $this->journal[$k] = $v;
            }, fn () => $this->now);
    }

    private function fake(bool $losePost = false, bool $emptyList = false, bool $rejectPost = false): void
    {
        Http::fake(function ($r) use ($losePost, $emptyList, $rejectPost) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($r->method() === 'DELETE') return Http::response('', 204);
            if ($r->method() === 'POST') {
                if ($losePost) throw new ConnectionException('secret must not appear');
                if ($rejectPost) return Http::response(
                    ['errors' => [['field' => 'type', 'reason' => 'invalid size, private detail']]], 400);
                return Http::response($this->row);
            }
            return Http::response(match ($path) {
                '/v4/regions/sg-sin-2' => ['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']],
                '/v4/images/linode%2Fdebian12' => ['id' => 'linode/debian12', 'capabilities' => ['cloud-init'],
                    'deprecated' => false, 'status' => 'available'],
                '/v4/linode/types/g6-standard-2' => ['id' => 'g6-standard-2', 'class' => 'standard'],
                '/v4/account/settings' => ['backups_enabled' => false],
                '/v4/linode/instances' => ['pages' => 1, 'data' => $emptyList ? [] : [$this->row]],
                '/v4/linode/instances/800' => $this->row,
                default => throw new \LogicException('unexpected fake request'),
            });
        });
    }

    public function test_existing_client_creates_once_reads_back_and_exact_cleanup_accepts_native_label(): void
    {
        $this->fake();
        $first = $this->control()->advance();
        $this->assertFalse($first['ready']);
        $second = $this->control()->advance();
        $this->assertTrue($second['ready']); $this->assertSame($first['pin'], $second['pin']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['label'] === $this->row['label']
            && $r['tags'] === $this->row['tags'] && $r['backups_enabled'] === false);
        $pin = $second['pin'];
        $provider = new Provider(['run_id' => Provider::RUN, 'pins' => [Provider::digest($pin)], 'baseline_ids' => ['700']],
            ['api_token' => 'synthetic-only'], ['access_key_id' => 'test', 'access_key_secret' => 'test'], fn () => null,
            fn ($k) => $this->journal[$k] ?? null, function ($k, $v): void { $this->journal[$k] = $v; });
        $this->assertTrue($provider->dispatch('control-delete', $pin)['dispatched']);
        $this->assertFalse($provider->dispatch('control-delete', $pin)['dispatched']);
        $encoded = json_encode($this->journal);
        $this->assertStringNotContainsString('root_pass', $encoded);
        $this->assertStringNotContainsString('synthetic-only', $encoded);
        $this->assertStringNotContainsString('cloud-config', $encoded);
    }

    public function test_lost_create_response_reconciles_original_request_without_recreating(): void
    {
        $this->fake(true);
        try { $this->control()->advance(); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_create_unconfirmed', $e->getMessage()); }
        $state = $this->journal['control-creation'];
        $this->assertTrue($this->control()->readback(true)['resolved']);
        $this->assertFalse($this->control()->readback(true)['ready']);
        $this->assertSame($state['request_ciphertext'], $this->journal['control-creation']['request_ciphertext']);
        $this->assertSame($state['first_error'], $this->journal['control-creation']['first_error']);
        $this->assertStringNotContainsString('secret must not appear', json_encode($this->journal));
        $this->expectExceptionMessage('member_control_stopped_or_expired');
        $this->control()->advance();
    }

    public function test_empty_read_after_dispatch_is_unresolved_not_zero_resources(): void
    {
        $this->fake(false, true); $this->control()->advance();
        $result = $this->control()->readback(true);
        $this->assertFalse($result['resolved']); $this->assertNotNull($result['pin']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_guard_and_journal_failure_prevent_any_request(): void
    {
        $this->fake(); $this->allowed = false;
        try { $this->control()->advance(); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_authority_required', $e->getMessage()); }
        $this->allowed = true; $this->failSave = true;
        try { $this->control()->advance(); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('journal unavailable', $e->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_same_id_changed_identity_or_foreign_baseline_is_not_adopted(): void
    {
        $this->fake(); $this->control()->advance();
        $this->row['created'] = gmdate('Y-m-d\TH:i:s', $this->now + 1);
        $this->expectExceptionMessage('member_control_identity_changed');
        $this->control()->readback(true);
    }

    public function test_changed_bundle_cannot_resume_existing_request(): void
    {
        $this->fake(); $this->control()->advance(); $this->scope['bundle_sha256'] = str_repeat('b', 64);
        $this->expectExceptionMessage('member_control_scope_changed'); $this->control();
    }

    public function test_bootstrap_change_clock_rollback_and_disabled_execution_are_fail_closed(): void
    {
        $this->fake(); config(['machine_provision.allow_live' => false]);
        try { $this->control()->advance(); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_execution_disabled', $e->getMessage()); }
        $this->assertCount(0, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->now -= 1;
        try { $this->control()->readback(true); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_clock_rollback', $e->getMessage()); }
        $this->bootstrap .= '# changed';
        $this->expectExceptionMessage('member_control_scope_invalid'); $this->control();
    }

    public function test_stage_binding_echoes_exact_request_and_changed_request_is_rejected(): void
    {
        $this->fake();
        $request = ['run_id' => Provider::RUN, 'bundle_sha256' => $this->scope['bundle_sha256'],
            'stage' => 'control-create', 'request_id' => str_repeat('a', 32), 'predecessors_sha256' => str_repeat('b', 64)];
        $stale = $this->control();
        $one = $this->control()->dispatch('control-create', $request);
        $two = $stale->dispatch('control-create', $request);
        $this->assertSame(Provider::digest($request), $one['request_sha256']);
        $this->assertSame($one['pin'], $two['pin']); $this->assertTrue($two['ready']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $request['request_id'] = str_repeat('c', 32);
        $this->expectExceptionMessage('member_control_request_changed');
        $this->control()->dispatch('control-create', $request);
    }

    public function test_running_without_public_ipv4_is_not_installation_ready(): void
    {
        $this->fake(); $this->row['ipv4'] = []; $this->row['ipv6'] = '2600:3c00::1/128';
        $this->control()->advance();
        $this->assertFalse($this->control()->advance()['ready']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_baseline_instance_cannot_be_adopted_and_expiry_never_dispatches(): void
    {
        $this->fake(); $this->scope['baseline_ids'][] = '800';
        try { $this->control()->advance(); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_create_unconfirmed', $e->getMessage()); }
        $this->assertNull($this->journal['control-creation']['pin']);
        try { $this->control()->readback(true); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_identity_changed', $e->getMessage()); }
        $this->now = $this->scope['deadline_at'];
        $this->expectExceptionMessage('member_control_stopped_or_expired'); $this->control()->advance();
    }

    public function test_definite_provider_rejection_is_preserved_without_raw_message_or_resource_invention(): void
    {
        $this->fake(false, false, true);
        try { $this->control()->advance(); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_control_create_rejected', $e->getMessage()); }
        $reply = $this->control()->readback(true);
        $this->assertTrue($reply['resolved']); $this->assertTrue($reply['creation_rejected']);
        $this->assertTrue($reply['dispatched']); $this->assertNull($reply['pin']);
        $this->assertSame('template_invalid', $this->journal['control-creation']['rejection']['kind']);
        $this->assertStringNotContainsString('private detail', json_encode($this->journal));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_connection_readback_is_identity_bound_and_does_not_enable_creation(): void
    {
        $this->fake(); $created = $this->control()->advance();
        $ciphertext = $this->journal['control-creation']['request_ciphertext'];
        config(['machine_provision.allow_live' => false]);
        $reply = $this->control()->connection();
        $this->assertSame($created['pin'], $reply['pin']);
        $this->assertSame(['control_sha256' => Provider::digest($created['pin']), 'host' => '45.79.1.10', 'port' => 22], $reply['connection']);
        $this->assertSame($reply, $this->control()->connection());
        $this->assertSame($ciphertext, $this->journal['control-creation']['request_ciphertext']);
        $this->assertFalse(config('machine_provision.allow_live'));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() !== 'GET'));
        $this->assertStringNotContainsString('root_pass', json_encode($reply));
    }

    public function test_connection_never_adopts_changed_address_or_identity(): void
    {
        $this->fake(); $this->control()->advance(); $initial = $this->control()->connection();
        $this->row['ipv4'] = ['45.79.1.11'];
        try { $this->control()->connection(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_connection_changed', $error->getMessage()); }
        $this->assertSame($initial['connection'], $this->journal['control-connection']);
        $this->row['ipv4'] = ['45.79.1.10'];
        $this->row['created'] = gmdate('Y-m-d\TH:i:s', $this->now + 1);
        $this->expectExceptionMessage('member_control_identity_changed'); $this->control()->connection();
    }

    public function test_connection_requires_exactly_one_public_ipv4_and_running_state(): void
    {
        $this->fake(); $this->control()->advance();
        foreach ([['ipv4' => []], ['ipv4' => ['127.0.0.1']], ['ipv4' => ['10.0.0.1']],
            ['ipv4' => ['45.79.1.10', '45.79.1.11']], ['ipv4' => ['45.79.1.10'], 'status' => 'booting']] as $change) {
            $this->row = array_replace($this->row, $change);
            try { $this->control()->connection(); $this->fail(); }
            catch (\RuntimeException $error) { $this->assertSame('member_control_connection_not_ready', $error->getMessage()); }
            $this->assertArrayNotHasKey('control-connection', $this->journal);
        }
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() !== 'GET'));
    }

    public function test_connection_before_create_or_after_stop_or_expiry_has_no_cloud_io(): void
    {
        $this->fake();
        try { $this->control()->connection(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_connection_not_created', $error->getMessage()); }
        Http::assertNothingSent(); $this->control()->advance(); $this->control()->readback(true);
        $count = count(Http::recorded());
        try { $this->control()->connection(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_stopped_or_expired', $error->getMessage()); }
        $this->now = $this->scope['deadline_at'];
        try { $this->control()->connection(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_stopped_or_expired', $error->getMessage()); }
        $this->assertCount($count, Http::recorded());
    }

    public function test_connection_incomplete_provider_read_is_not_a_guess_or_create_retry(): void
    {
        $this->fake(false, true); $this->control()->advance();
        try { $this->control()->connection(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_connection_unresolved', $error->getMessage()); }
        $this->assertArrayNotHasKey('control-connection', $this->journal);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() !== 'GET'));
    }
}
