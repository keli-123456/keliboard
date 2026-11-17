<?php
namespace Tests\Unit\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberOperatorProvider as Operator;
use Keli\Acceptance\MemberProviderBindings as Provider;
use Tests\TestCase;

class MachineMemberOperatorProviderTest extends TestCase
{
    private string $directory;
    private array $scope;
    private array $control;
    private array $dns;
    private array $cloud;
    private array $record;
    private array $permits;
    private array $opened = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) $this->markTestSkipped('Linux ownership and flock required');
        foreach (['member_provider_bindings', 'member_cli_authority', 'member_dns_initialization', 'member_prerequisite_import', 'member_operator_provider'] as $name)
            require_once base_path('tools/machine-acceptance/' . $name . '.php');
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir() . '/r35-operator-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $task = '11111111-1111-4111-8111-111111111111'; $suffix = str_replace('-', '', $task);
        $this->control = ['run_id' => Provider::RUN, 'external_id' => '800', 'task_id' => $task,
            'label' => 'keli-' . $suffix, 'marker' => 'keli-create-' . $suffix, 'region' => 'sg-sin-2',
            'size' => 'g6-standard-2', 'image' => 'linode/debian12', 'provider_created_at' => '2026-11-18T01:00:00Z'];
        $this->cloud = ['id' => 800, 'label' => $this->control['label'], 'tags' => [$this->control['marker']],
            'region' => 'sg-sin-2', 'type' => 'g6-standard-2', 'image' => 'linode/debian12', 'created' => '2026-11-18T01:00:00'];
        $this->dns = ['run_id' => Provider::RUN, 'record_id' => '5001', 'name' => 'edge-a-' . Provider::RUN . '.dnscloudcloud.top',
            'type' => 'A', 'line' => 'default', 'value' => '9.9.9.11', 'marker' => 'keli-set-' . str_repeat('a', 48)];
        $this->record = ['RecordId' => '5001', 'DomainName' => 'dnscloudcloud.top', 'RR' => 'edge-a-' . Provider::RUN,
            'Type' => 'A', 'Line' => 'default', 'Value' => '9.9.9.11', 'Remark' => $this->dns['marker'], 'TTL' => 600];
        $this->scope = ['run_id' => Provider::RUN, 'bundle_sha256' => str_repeat('b', 64),
            'baseline_ids' => ['9'], 'pins' => [$this->control, $this->dns], 'dns_receipts' => []];
        $this->permit();
    }

    private function write(string $name, array $value): void
    {
        file_put_contents($this->directory . '/' . $name, json_encode($value, JSON_UNESCAPED_SLASHES));
        chmod($this->directory . '/' . $name, 0600);
    }

    private function permit(): void
    {
        $this->write('provider-scope.json', $this->scope);
        $this->write('provider-credentials.json', ['schema' => 1, 'run_id' => Provider::RUN,
            'linode' => ['api_token' => 'synthetic-no-live-token'], 'aliyun' => ['access_key_id' => 'synthetic-access',
                'access_key_secret' => 'synthetic-secret'], 'baseline_ids' => $this->scope['baseline_ids']]);
        $this->permits = ['schema' => 1, 'run_id' => Provider::RUN, 'operation' => 'provider-cleanup', 'authorized' => true,
            'scope_sha256' => Provider::digest($this->scope), 'credentials_sha256' => hash_file('sha256', $this->directory . '/provider-credentials.json'),
            'operator_machine_sha256' => hash_file('sha256', '/etc/machine-id')];
        $this->write('provider-permit.json', $this->permits);
    }

    private function operator(): Operator
    {
        $operator = new Operator($this->directory, Provider::digest($this->scope));
        $this->opened[] = $operator;
        return $operator;
    }

    private function call(Operator $operator, string $operation, ?array $pin = null): array
    {
        $request = ['operation' => $operation, 'pin' => $pin ?? $this->control, 'scope_sha256' => Provider::digest($this->scope)];
        $reply = $operator->dispatch($request);
        $this->assertSame(Provider::digest($request), $reply['request_sha256']);
        return $reply['result'];
    }

    protected function tearDown(): void
    {
        foreach ($this->opened as $operator) $operator->close();
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') as $path) unlink($path);
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_control_deletion_and_readback_survive_operator_restart_without_a_controller_application(): void
    {
        $removed = false;
        Http::fake(function ($request) use (&$removed) {
            if ($request->method() === 'DELETE') { $removed = true; return Http::response('', 204); }
            return $removed ? Http::response('', 404) : Http::response($this->cloud);
        });
        $operator = $this->operator();
        $this->assertSame('owned', $this->call($operator, 'control-delete')['status']);
        $operator->close();
        $this->assertSame('absent', $this->call($this->operator(), 'control-inspect')['status']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
        foreach (glob($this->directory . '/journal-*.json') as $path)
            $this->assertStringNotContainsString('synthetic-no-live-token', file_get_contents($path));
    }

    public function test_lost_delete_ack_is_never_reissued_and_first_error_is_safe(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'DELETE') throw new ConnectionException('synthetic-private-response');
            return Http::response($this->cloud);
        });
        $operator = $this->operator();
        try { $this->call($operator, 'control-delete'); $this->fail(); } catch (ConnectionException) {}
        $first = file_get_contents($this->directory . '/journal-first-error.json');
        $operator->close();
        $operator = $this->operator();
        $this->assertFalse($this->call($operator, 'control-delete')['dispatched']);
        $this->assertTrue($this->call($operator, 'control-delete')['reconciliation_required']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
        $this->assertStringNotContainsString('synthetic-private-response', $first);
        $this->assertSame($first, file_get_contents($this->directory . '/journal-first-error.json'));
    }

    public function test_scope_or_permit_drift_is_not_adopted_on_restart(): void
    {
        Http::fake();
        $operator = $this->operator(); $operator->close();
        $this->scope['bundle_sha256'] = str_repeat('c', 64); $this->permit();
        try { $this->operator(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_operator_binding_changed', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_permission_links_and_concurrent_owner_are_refused_before_network(): void
    {
        Http::fake();
        chmod($this->directory . '/provider-permit.json', 0644);
        try { $this->operator(); $this->fail(); } catch (\RuntimeException $e) { $this->assertSame('member_operator_file_invalid', $e->getMessage()); }
        chmod($this->directory . '/provider-permit.json', 0600);
        $one = $this->operator();
        try { $this->operator(); $this->fail(); } catch (\RuntimeException $e) { $this->assertSame('member_operator_busy', $e->getMessage()); }
        $one->close();
        rename($this->directory . '/provider-permit.json', $this->directory . '/saved-permit.json');
        symlink($this->directory . '/saved-permit.json', $this->directory . '/provider-permit.json');
        try { $this->operator(); $this->fail(); } catch (\RuntimeException $e) { $this->assertSame('member_operator_file_invalid', $e->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_mid_request_credentials_drift_preserves_intent_without_delete(): void
    {
        $reads = 0;
        Http::fake(function () use (&$reads) {
            if (++$reads === 2) $this->write('provider-credentials.json', ['changed' => true]);
            return Http::response($this->cloud);
        });
        try { $this->call($this->operator(), 'control-delete'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_operator_material_changed', $error->getMessage()); }
        $this->assertCount(0, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
        $this->assertCount(1, glob($this->directory . '/journal-provider-delete-*.json'));
    }

    public function test_only_explicit_cleanup_permission_and_exact_allowed_pins_are_accepted(): void
    {
        Http::fake();
        foreach ([['authorized' => 1], ['operation' => 'create'], ['operator_machine_sha256' => str_repeat('0', 64)]] as $change) {
            $this->write('provider-permit.json', array_replace($this->permits, $change));
            try { $this->operator(); $this->fail(); }
            catch (\RuntimeException $error) { $this->assertSame('member_operator_permit_invalid', $error->getMessage()); }
        }
        $this->write('provider-permit.json', $this->permits);
        $operator = $this->operator();
        foreach (['control-create', 'stop', 'reboot', 'dns-create'] as $operation) {
            try { $this->call($operator, $operation); $this->fail(); }
            catch (\RuntimeException $error) { $this->assertSame('member_operator_request_invalid', $error->getMessage()); }
        }
        try { $this->call($operator, 'control-delete', array_replace($this->control, ['external_id' => '9'])); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_provider_pin_changed', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_blank_dns_remark_requires_original_exact_create_receipt(): void
    {
        $this->record['Remark'] = '';
        $state = ['create_sent' => true, 'rejected' => false, 'completed' => false, 'created_id' => $this->dns['record_id'],
            'request' => array_diff_key($this->dns, ['record_id' => true]) + ['ttl' => 600]];
        $this->scope['dns_receipts'] = [['pin' => $this->dns, 'state' => $state]];
        $this->permit();
        Http::fake(fn ($r) => Http::response($r['Action'] === 'DeleteDomainRecord' ? ['RecordId' => '5001'] : $this->record));
        $this->assertTrue($this->call($this->operator(), 'dns-delete', $this->dns)['dispatched']);
        Http::assertSentCount(3);
    }

    public function test_actual_cli_constructs_without_application_boot_and_rejects_create(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
            base_path('tools/machine-acceptance/member_operator_entry.php'), $this->directory], $this->directory,
            ['APP_ENV' => 'operator-no-application', 'DB_CONNECTION' => 'operator-no-database']);
        $process->setInput(json_encode(['operation' => 'control-create', 'pin' => $this->control,
            'scope_sha256' => Provider::digest($this->scope)]));
        $process->setTimeout(10);
        $this->assertSame(1, $process->run());
        $this->assertSame(['ok' => false, 'code' => 'operator_cleanup_unconfirmed'],
            json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR));
        $this->assertSame('', $process->getErrorOutput());
        $binding = json_decode(file_get_contents($this->directory . '/journal-binding.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame(Provider::digest($this->scope), $binding['scope_sha256']);
        $this->assertCount(0, glob($this->directory . '/journal-provider-delete-*.json'));
    }
}
