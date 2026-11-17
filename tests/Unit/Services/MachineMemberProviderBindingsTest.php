<?php
namespace Tests\Unit\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberProviderBindings as Bindings;
use Tests\TestCase;

class MachineMemberProviderBindingsTest extends TestCase
{
    private array $journal = [];
    private array $control;
    private array $dns;
    private array $cloudRow;
    private array $dnsRow;
    private bool $failSave = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tools/machine-acceptance/member_provider_bindings.php');
        Http::preventStrayRequests();
        $this->control = ['run_id' => Bindings::RUN, 'external_id' => '800', 'label' => Bindings::RUN . '-control',
            'marker' => Bindings::RUN . '-owned-control', 'size' => 'g6-standard-2', 'region' => 'sg-sin-2',
            'image' => 'linode/debian12', 'provider_created_at' => '2026-11-16T10:00:00Z'];
        $this->cloudRow = ['id' => 800, 'label' => $this->control['label'], 'tags' => [$this->control['marker']],
            'type' => $this->control['size'], 'region' => $this->control['region'], 'image' => $this->control['image'],
            'created' => '2026-11-16T10:00:00'];
        $this->dns = ['run_id' => Bindings::RUN, 'record_id' => '5001', 'name' => 'edge-a-' . Bindings::RUN . '.dnscloudcloud.top',
            'type' => 'A', 'line' => 'default', 'value' => '192.0.2.10', 'marker' => 'keli-set-' . str_repeat('a', 48)];
        $this->dnsRow = ['RecordId' => '5001', 'DomainName' => 'dnscloudcloud.top',
            'RR' => 'edge-a-' . Bindings::RUN, 'Type' => 'A', 'Line' => 'default', 'Value' => '192.0.2.10',
            'Remark' => $this->dns['marker'], 'TTL' => 600];
    }

    private function bindings(array $baseline = [], ?\Closure $guard = null, array $additional = []): Bindings
    {
        return new Bindings(['run_id' => Bindings::RUN, 'pins' => array_map(Bindings::digest(...), [$this->control, $this->dns, ...$additional]),
            'baseline_ids' => $baseline], ['api_token' => 'synthetic-only'],
            ['access_key_id' => 'synthetic-only', 'access_key_secret' => 'synthetic-only'], $guard ?? fn () => null,
            fn ($key) => $this->journal[$key] ?? null, function ($key, $value): void {
                if ($this->failSave) throw new \RuntimeException('journal unavailable');
                $this->journal[$key] = $value;
            });
    }

    public function test_cloud_delete_has_two_identity_reads_and_does_not_claim_absence_from_ack(): void
    {
        Http::fake(fn ($r) => $r->method() === 'DELETE' ? Http::response('', 204) : Http::response($this->cloudRow));
        $result = $this->bindings()->dispatch('control-delete', $this->control);
        $this->assertSame('owned', $result['status']); $this->assertTrue($result['dispatched']);
        $this->assertSame(Bindings::digest($this->control), $result['pin_sha256']);
        Http::assertSentCount(3);
        $this->assertCount(1, $this->journal);
        $again = $this->bindings()->dispatch('control-delete', $this->control);
        $this->assertTrue($again['reconciliation_required']); $this->assertFalse($again['dispatched']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
    }

    public function test_dns_selection_accepts_only_prebound_values_and_delete_rechecks_the_selected_pin(): void
    {
        $next = array_replace($this->dns, ['value' => '9.9.9.11']);
        $this->dnsRow['Value'] = $next['value'];
        Http::fake(fn ($r) => Http::response($r['Action'] === 'DeleteDomainRecord' ? ['RecordId' => '5001'] : $this->dnsRow));
        $provider = $this->bindings(additional: [$next]);
        $this->assertSame($next, $provider->resolveDnsPin([$this->dns, $next]));
        $this->dnsRow['Value'] = '8.8.8.99';
        try { $provider->dispatch('dns-delete', $next); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_provider_dns_identity_changed', $error->getMessage()); }
        $this->assertSame([], $this->journal);
        Http::assertSentCount(2);
    }

    public function test_dns_selection_rejects_unknown_value_or_changed_marker_and_never_adopts_it(): void
    {
        $next = array_replace($this->dns, ['value' => '9.9.9.11']);
        Http::fake(fn () => Http::response($this->dnsRow));
        $provider = $this->bindings(additional: [$next]);
        foreach ([['Value', '8.8.8.99'], ['Remark', 'foreign']] as [$field, $value]) {
            $old = $this->dnsRow; $this->dnsRow[$field] = $value;
            try { $provider->resolveDnsPin([$this->dns, $next]); $this->fail(); }
            catch (\RuntimeException $error) { $this->assertSame('member_provider_dns_identity_changed', $error->getMessage()); }
            $this->dnsRow = $old;
        }
        $this->assertSame([], $this->journal);
        Http::assertSentCount(2);
    }

    public function test_dns_selection_requires_scoped_pins_for_one_exact_record_before_reading(): void
    {
        Http::fake();
        $next = array_replace($this->dns, ['value' => '9.9.9.11']);
        try { $this->bindings()->resolveDnsPin([$this->dns, $next]); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_provider_pin_changed', $error->getMessage()); }
        $other = array_replace($next, ['record_id' => '5002']);
        try { $this->bindings(additional: [$other])->resolveDnsPin([$this->dns, $other]); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_provider_dns_pins_invalid', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_control_address_requires_exact_owned_single_public_ipv4(): void
    {
        $this->cloudRow['ipv4'] = ['10.0.0.1', '9.9.9.10'];
        Http::fake(fn () => Http::response($this->cloudRow));
        $this->assertSame('9.9.9.10', $this->bindings()->controlAddress($this->control));
        $this->cloudRow['ipv4'][] = '8.8.8.8';
        $this->expectExceptionMessage('member_provider_control_address_unconfirmed');
        $this->bindings()->controlAddress($this->control);
    }

    public function test_dns_uses_real_aliyun_signed_exact_id_and_compact_marker_reader(): void
    {
        $row = $this->dnsRow;
        $row['Remark'] = \App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider::aliyunMarker($row['Remark']);
        Http::fake(fn ($r) => Http::response($r['Action'] === 'DeleteDomainRecord' ? ['RecordId' => '5001'] : $row));
        $result = $this->bindings()->dispatch('dns-delete', $this->dns);
        $this->assertSame('owned', $result['status']); $this->assertTrue($result['dispatched']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === 'https://alidns.aliyuncs.com/' && $r['RecordId'] === '5001'
            && $r['Action'] === 'DeleteDomainRecord' && is_string($r['Signature']) && $r['Signature'] !== '');
        $this->assertStringNotContainsString('synthetic-only', json_encode($this->journal));
    }

    public function test_pin_changed_or_baseline_cloud_never_reaches_provider(): void
    {
        Http::fake();
        foreach ([$this->control + ['unexpected' => true], array_replace($this->control, ['external_id' => '700'])] as $pin) {
            try { $this->bindings()->dispatch('control-delete', $pin); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertSame('member_provider_pin_changed', $e->getMessage()); }
        }
        try { $this->bindings(['800'])->dispatch('control-delete', $this->control); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_provider_cloud_pin_invalid', $e->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_foreign_or_changed_cloud_and_dns_are_never_deleted(): void
    {
        foreach (['cloud', 'dns'] as $kind) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            $row = $kind === 'cloud' ? array_replace($this->cloudRow, ['tags' => ['foreign']])
                : array_replace($this->dnsRow, ['Value' => '192.0.2.99']);
            Http::fake(fn () => Http::response($row));
            try { $this->bindings()->dispatch($kind === 'cloud' ? 'control-delete' : 'dns-delete',
                    $kind === 'cloud' ? $this->control : $this->dns); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertStringEndsWith('identity_changed', $e->getMessage()); }
            Http::assertSentCount(1);
        }
        $this->assertSame([], $this->journal);
    }

    public function test_identity_change_during_intent_write_blocks_delete(): void
    {
        Http::fakeSequence()->push($this->cloudRow)->push(array_replace($this->cloudRow, ['created' => '2026-11-16T10:00:01']));
        try { $this->bindings()->dispatch('control-delete', $this->control); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_provider_cloud_identity_changed', $e->getMessage()); }
        Http::assertSentCount(2);
        $this->assertCount(1, $this->journal);
    }

    public function test_failed_journal_prevents_any_delete(): void
    {
        Http::fake(fn () => Http::response($this->cloudRow)); $this->failSave = true;
        try { $this->bindings()->dispatch('control-delete', $this->control); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('journal unavailable', $e->getMessage()); }
        Http::assertSentCount(1);
    }

    public function test_lost_delete_reply_is_not_replayed(): void
    {
        Http::fake(function ($r) {
            if ($r->method() === 'DELETE') throw new ConnectionException('private-provider-message');
            return Http::response($this->cloudRow);
        });
        try { $this->bindings()->dispatch('control-delete', $this->control); $this->fail(); }
        catch (ConnectionException) {}
        $result = $this->bindings()->dispatch('control-delete', $this->control);
        $this->assertFalse($result['dispatched']); $this->assertTrue($result['reconciliation_required']);
        $this->assertStringNotContainsString('private-provider-message', json_encode($this->journal));
    }

    public function test_only_explicit_exact_absence_is_accepted(): void
    {
        Http::fake(['api.linode.com/*' => Http::response('', 404),
            'alidns.aliyuncs.com/*' => Http::response(['Code' => 'InvalidRR.NoExist'], 400)]);
        $this->assertSame('absent', $this->bindings()->dispatch('control-inspect', $this->control)['status']);
        $this->assertSame('absent', $this->bindings()->dispatch('dns-inspect', $this->dns)['status']);
        $this->assertSame([], $this->journal);
        foreach ([401, 403, 429, 500] as $status) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            Http::fake(fn () => Http::response(['Code' => 'InvalidRR.NoExist'], $status));
            foreach (['control-inspect' => $this->control, 'dns-inspect' => $this->dns] as $op => $pin) {
                try { $this->bindings()->dispatch($op, $pin); $this->fail(); }
                catch (\RuntimeException $e) { $this->assertStringNotContainsString('synthetic-only', $e->getMessage()); }
            }
        }
    }

    public function test_guard_is_checked_before_network_and_operation_is_allowlisted(): void
    {
        Http::fake();
        try { $this->bindings([], fn () => throw new \RuntimeException('stopped'))
            ->dispatch('dns-delete', $this->dns); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('stopped', $e->getMessage()); }
        try { $this->bindings()->dispatch('create', $this->control); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_provider_operation_invalid', $e->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_unknown_blank_remark_and_changed_ttl_do_not_authorize_deletion(): void
    {
        foreach ([['Remark' => ''], ['TTL' => 60], ['Locked' => true]] as $patch) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            Http::fake(fn () => Http::response(array_replace($this->dnsRow, $patch)));
            try { $this->bindings()->dispatch('dns-delete', $this->dns); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertSame('member_provider_dns_identity_changed', $e->getMessage()); }
            Http::assertSentCount(1);
        }
        $this->assertSame([], $this->journal);
    }

    public function test_baseline_ids_cannot_change_type_to_evade_existing_resource_protection(): void
    {
        Http::fake();
        try { $this->bindings([800])->dispatch('control-delete', $this->control); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_provider_scope_required', $e->getMessage()); }
        Http::assertNothingSent();
    }
}
