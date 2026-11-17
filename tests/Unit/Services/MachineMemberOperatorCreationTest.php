<?php
namespace Tests\Unit\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberOperatorCreation as Operator;
use Keli\Acceptance\MemberProviderBindings as Provider;
use Tests\TestCase;

class MachineMemberOperatorCreationTest extends TestCase
{
    private string $directory;
    private array $scope;
    private array $request;
    private array $row;
    private array $opened = [];
    private int $posts = 0;
    private bool $present = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) $this->markTestSkipped('Linux owner and private journal fixture');
        foreach (['member_provider_bindings', 'member_cli_authority', 'member_prerequisite_import',
            'member_control_creation', 'member_operator_creation'] as $name)
            require_once base_path('tools/machine-acceptance/' . $name . '.php');
        Http::preventStrayRequests();
        config(['machine_provision.allow_live' => false]);
        $this->directory = sys_get_temp_dir() . '/r35-operator-create-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $bootstrap = "#cloud-config\nssh_pwauth: false\ndisable_root: false\n";
        $this->scope = ['run_id' => Provider::RUN, 'task_id' => '11111111-1111-4111-8111-111111111111',
            'account_id' => 71, 'bundle_sha256' => str_repeat('a', 64), 'bootstrap_sha256' => hash('sha256', $bootstrap),
            'started_at' => time() - 5, 'deadline_at' => time() + 7190, 'baseline_ids' => ['700'],
            'plan' => ['region' => 'sg-sin-2', 'size' => 'g6-standard-2', 'image' => 'linode/debian12',
                'authorized_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestOnly fixture']]];
        $this->write('creation-bootstrap.json', ['content' => $bootstrap]);
        $this->write('creation-key.json', ['key' => base64_encode(str_repeat('k', 32))]);
        $this->write('provider-credentials.json', ['schema' => 1, 'run_id' => Provider::RUN, 'baseline_ids' => ['700'],
            'linode' => ['api_token' => 'synthetic-no-live-token'], 'aliyun' => ['access_key_id' => 'synthetic', 'access_key_secret' => 'synthetic-secret']]);
        $this->permit();
        $suffix = str_replace('-', '', $this->scope['task_id']);
        $this->row = ['id' => 800, 'label' => 'keli-' . $suffix, 'tags' => ['keli-create-' . $suffix],
            'region' => 'sg-sin-2', 'type' => 'g6-standard-2', 'image' => 'linode/debian12',
            'created' => gmdate('Y-m-d\TH:i:s'), 'ipv4' => ['9.9.9.10'], 'status' => 'running'];
    }

    private function write(string $name, array $value): void
    {
        file_put_contents($this->directory . '/' . $name, json_encode($value, JSON_UNESCAPED_SLASHES));
        chmod($this->directory . '/' . $name, 0600);
    }

    private function permit(): void
    {
        $this->write('creation-scope.json', $this->scope);
        $this->write('creation-permit.json', ['schema' => 1, 'run_id' => Provider::RUN, 'operation' => 'control-create', 'authorized' => true,
            'scope_sha256' => Provider::digest($this->scope), 'operator_machine_sha256' => hash_file('sha256', '/etc/machine-id'),
            'credentials_sha256' => hash_file('sha256', $this->directory . '/provider-credentials.json'),
            'journal_key_sha256' => hash_file('sha256', $this->directory . '/creation-key.json')]);
        $scope = array_intersect_key($this->scope, array_flip(['run_id', 'bundle_sha256', 'started_at', 'deadline_at']));
        $this->request = ['run_id' => Provider::RUN, 'bundle_sha256' => $scope['bundle_sha256'], 'stage' => 'control-create',
            'predecessors_sha256' => hash('sha256', '{}'), 'request_id' => substr(Provider::digest([$scope, 'control-create']), 0, 32)];
    }

    private function operator(): Operator
    {
        $operator = new Operator($this->directory, Provider::digest($this->scope));
        $this->opened[] = $operator;
        return $operator;
    }

    private function call(Operator $operator, string $operation): array
    {
        $request = ['operation' => $operation, 'scope_sha256' => Provider::digest($this->scope), 'request' => $this->request];
        $reply = $operator->dispatch($request);
        $this->assertSame(Provider::digest($request), $reply['request_sha256']);
        $this->assertFalse(config('machine_provision.allow_live'));
        return $reply['result'];
    }

    private function fake(bool $losePost = false, bool $hide = false): void
    {
        Http::fake(function ($request) use ($losePost, $hide) {
            if ($request->method() === 'POST') {
                ++$this->posts; $this->present = true;
                $this->assertSame('https://api.linode.com/v4/linode/instances', $request->url());
                if ($losePost) throw new ConnectionException('synthetic-private-error');
                return Http::response($this->row);
            }
            $this->assertSame('GET', $request->method());
            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/v4/regions/sg-sin-2' => ['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']],
                '/v4/images/linode%2Fdebian12' => ['id' => 'linode/debian12', 'status' => 'available', 'deprecated' => false, 'capabilities' => ['cloud-init']],
                '/v4/linode/types/g6-standard-2' => ['id' => 'g6-standard-2', 'class' => 'standard'],
                '/v4/account/settings' => ['backups_enabled' => false],
                '/v4/linode/instances' => ['pages' => 1, 'data' => $this->present && !$hide ? [$this->row] : []],
                default => throw new \RuntimeException('unexpected-fixture-request'),
            });
        });
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->opened) as $operator) $operator->close();
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') as $path) unlink($path);
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_actual_adapter_polls_same_control_after_process_reconstruction(): void
    {
        $this->fake(); $first = $this->operator();
        $one = $this->call($first, 'control-create'); $this->assertFalse($one['ready']); $first->close();
        $second = $this->operator(); $two = $this->call($second, 'control-create');
        $this->assertTrue($two['ready']); $this->assertSame($one['pin'], $two['pin']); $this->assertSame(1, $this->posts);
        $this->assertFalse($this->call($second, 'control-readback')['ready']);
        try { $this->call($second, 'control-create'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_stopped_or_expired', $error->getMessage()); }
        $this->assertSame(1, $this->posts); $second->close(); $this->assertFalse(config('machine_provision.allow_live'));
    }

    public function test_connection_operation_keeps_live_switch_off_and_stop_prevents_resolution(): void
    {
        $this->fake(); $operator = $this->operator();
        $created = $this->call($operator, 'control-create'); $operator->close();
        $operator = $this->operator();
        $connection = $this->call($operator, 'control-connection');
        $this->assertSame($created['pin'], $connection['pin']);
        $this->assertSame(['control_sha256' => Provider::digest($created['pin']), 'host' => '9.9.9.10', 'port' => 22], $connection['connection']);
        $this->assertSame(1, $this->posts);
        $this->call($operator, 'control-readback'); $count = count(Http::recorded());
        try { $this->call($operator, 'control-connection'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_stopped_or_expired', $error->getMessage()); }
        $this->assertCount($count, Http::recorded()); $this->assertFalse(config('machine_provision.allow_live'));
    }

    public function test_lost_post_ack_retains_ciphertext_and_first_error_without_recreating(): void
    {
        $this->fake(true); $operator = $this->operator();
        try { $this->call($operator, 'control-create'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_control_create_unconfirmed', $error->getMessage()); }
        $state = json_decode(file_get_contents($this->directory . '/journal-control-creation.json'), true);
        $first = file_get_contents($this->directory . '/journal-first-error.json'); $operator->close();
        $this->assertTrue($this->call($this->operator(), 'control-readback')['resolved']);
        $after = json_decode(file_get_contents($this->directory . '/journal-control-creation.json'), true);
        $this->assertSame($state['request_ciphertext'], $after['request_ciphertext']);
        $this->assertSame($state['first_error'], $after['first_error']); $this->assertSame(1, $this->posts);
        $this->assertSame($first, file_get_contents($this->directory . '/journal-first-error.json'));
        foreach (glob($this->directory . '/journal-*.json') as $path) {
            $value = file_get_contents($path);
            foreach (['synthetic-private-error', 'synthetic-no-live-token', 'root_pass', 'cloud-config'] as $secret)
                $this->assertStringNotContainsString($secret, $value);
        }
    }

    public function test_unknown_create_is_not_zero_resources_or_a_new_post(): void
    {
        $this->fake(true, true); $operator = $this->operator();
        try { $this->call($operator, 'control-create'); } catch (\RuntimeException) { }
        $value = $this->call($operator, 'control-readback');
        $this->assertFalse($value['resolved']); $this->assertTrue($value['dispatched']); $this->assertSame(1, $this->posts);
    }

    public function test_expired_scope_or_changed_material_never_enables_creation(): void
    {
        Http::fake(); $this->scope['started_at'] = time() - 7300; $this->scope['deadline_at'] = time() - 101; $this->permit();
        $operator = $this->operator();
        try { $this->call($operator, 'control-create'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_operator_creation_expired', $error->getMessage()); }
        $this->assertFalse($this->call($operator, 'control-readback')['dispatched']);
        $this->write('provider-credentials.json', ['changed' => true]);
        try { $this->call($operator, 'control-readback'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_operator_creation_material_changed', $error->getMessage()); }
        Http::assertNothingSent(); $this->assertFalse(config('machine_provision.allow_live'));
    }

    public function test_cleanup_permission_and_changed_scope_are_not_creation_permission(): void
    {
        Http::fake();
        $path = $this->directory . '/creation-permit.json'; $permit = json_decode(file_get_contents($path), true);
        foreach ([['operation' => 'provider-cleanup'], ['authorized' => 1], ['operator_machine_sha256' => str_repeat('0', 64)]] as $change) {
            $this->write('creation-permit.json', array_replace($permit, $change));
            try { $this->operator(); $this->fail(); }
            catch (\RuntimeException $error) { $this->assertSame('member_operator_creation_permit_invalid', $error->getMessage()); }
        }
        $this->write('creation-permit.json', $permit); $operator = $this->operator(); $operator->close();
        $this->scope['bundle_sha256'] = str_repeat('b', 64); $this->permit();
        try { $this->operator(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_operator_journal_changed', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_actual_cli_readback_before_dispatch_uses_no_application_or_database(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
            base_path('tools/machine-acceptance/member_operator_creation_entry.php'), $this->directory], $this->directory,
            ['APP_ENV' => 'operator-no-application', 'DB_CONNECTION' => 'operator-no-database']);
        $process->setInput(json_encode(['operation' => 'control-readback', 'scope_sha256' => Provider::digest($this->scope), 'request' => $this->request]));
        $process->setTimeout(10);
        $this->assertSame(0, $process->run());
        $reply = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($reply['ok']); $this->assertTrue($reply['result']['resolved']);
        $this->assertFalse($reply['result']['dispatched']); $this->assertNull($reply['result']['pin']);
        $this->assertSame('', $process->getErrorOutput());
    }
}
