<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineDnsController;
use App\Http\Middleware\RequestLog;
use App\Jobs\SyncMachineDnsJob;
use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use App\Models\ServerMachine;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\MachineDnsService;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineDnsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineDnsService $service;
    private MachineAddressInventory $inventory;
    private ServerMachine $machine;
    private MachineDnsCredential $credential;
    private string $instance = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $zone = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private string $record = 'cccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('d', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        (require base_path('database/migrations/2026_11_05_160000_create_machine_dns_tables.php'))->up();
        Schema::create('v2_server_machine', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps();
        });
        $this->machine = ServerMachine::query()->create(['name' => 'Synthetic machine', 'token' => 'synthetic-machine-token', 'is_active' => true]);
        $this->inventory = new MachineAddressInventory();
        $this->service = new MachineDnsService(new DnsProviderClient(), $this->inventory);
        $this->credential = MachineDnsCredential::query()->create(['name' => 'CF synthetic', 'provider' => 'cloudflare', 'credentials' => ['api_token' => 'synthetic-dns-token'], 'revision' => 1]);
        $this->report('8.8.8.8');
    }

    private function report(string $ip, ?string $instance = null, ?int $at = null): void
    {
        $this->inventory->record($this->machine, $instance ?? $this->instance,
            [str_contains($ip, ':') ? 'public_ipv6' : 'public_ipv4' => $ip], $at ?? time());
    }

    private function remote(string $ip = '1.1.1.1'): array
    {
        return ['id' => $this->record, 'name' => 'node.example.com', 'type' => 'A', 'content' => $ip, 'ttl' => 300, 'proxied' => false];
    }

    private function fake(array $responses = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function bind(): MachineDnsBinding
    {
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => $this->remote()])]);
        return $this->service->createBinding(['credential_id' => $this->credential->id, 'machine_id' => $this->machine->id,
            'installation_id' => $this->instance, 'zone' => $this->zone, 'record_id' => $this->record,
            'hostname' => 'node.example.com', 'type' => 'A'], 7);
    }

    private function input(): array
    {
        return ['credential_id' => $this->credential->id, 'machine_id' => $this->machine->id,
            'installation_id' => $this->instance, 'zone' => $this->zone, 'record_id' => $this->record,
            'hostname' => 'node.example.com', 'type' => 'A'];
    }

    private function previewInput(): array
    {
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => $this->remote()])]);
        $preview = $this->service->preview($this->input());
        return $this->input() + ['preview_checked_at' => $preview['checked_at'], 'credential_revision' => $preview['credential_revision'],
            'expected_current_ip' => $preview['current_ip'], 'expected_target_ip' => $preview['target_ip']];
    }

    public function test_preview_is_read_only_and_returns_current_and_same_installation_target(): void
    {
        $input = $this->previewInput();
        $this->assertSame('1.1.1.1', $input['expected_current_ip']);
        $this->assertSame('8.8.8.8', $input['expected_target_ip']);
        $this->assertSame(0, MachineDnsBinding::query()->count());
        $this->assertSame(0, DB::table('v2_machine_dns_log')->count());
        Http::assertSentCount(1); Http::assertSent(fn ($request) => $request->method() === 'GET');
        $binding = $this->service->createBinding($input, 7);
        $this->assertFalse($binding->enabled);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET'); Bus::assertNothingDispatched();
    }

    public function test_save_rechecks_dns_after_preview_and_refuses_a_changed_address(): void
    {
        $input = $this->previewInput();
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => $this->remote('9.9.9.9')])]);
        try { $this->service->createBinding($input, 7); $this->fail('Changed record must be previewed again'); }
        catch (DnsException $error) { $this->assertSame('preview_record_changed', $error->getMessage()); }
        $this->assertSame(0, MachineDnsBinding::query()->count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_save_rechecks_source_credential_and_preview_age(): void
    {
        $input = $this->previewInput();
        $this->report('9.9.9.9');
        try { $this->service->createBinding($input, 7); $this->fail('Changed target must be previewed again'); }
        catch (DnsException $error) { $this->assertSame('source_changed', $error->getMessage()); }
        $this->report('8.8.8.8');
        $this->credential->revision = 2; $this->credential->save();
        try { $this->service->createBinding($input, 7); $this->fail('Changed credential must be previewed again'); }
        catch (DnsException $error) { $this->assertSame('config_changed', $error->getMessage()); }
        $input['credential_revision'] = 2; $input['preview_checked_at'] = time() - 121;
        try { $this->service->createBinding($input, 7); $this->fail('Expired preview'); }
        catch (DnsException $error) { $this->assertSame('preview_expired', $error->getMessage()); }
        $input['preview_checked_at'] = time() + 31;
        try { $this->service->createBinding($input, 7); $this->fail('Future preview'); }
        catch (DnsException $error) { $this->assertSame('preview_expired', $error->getMessage()); }
        $this->assertSame(0, MachineDnsBinding::query()->count());
    }

    public function test_catalog_pages_are_bounded_and_only_return_selected_fields(): void
    {
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
            ['id' => $this->zone, 'name' => 'Example.COM', 'private_metadata' => 'do-not-return'],
        ], 'result_info' => ['total_pages' => 3]])]);
        $result = $this->service->catalog(['credential_id' => $this->credential->id, 'page' => 2, 'search' => 'example.com'], false);
        $this->assertSame(['items' => [['id' => $this->zone, 'name' => 'example.com']], 'page' => 2, 'has_more' => true], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.cloudflare.com/client/v4/zones?')
            && $request['page'] === 2 && $request['per_page'] === 50 && $request['name'] === 'example.com');
        $this->assertStringNotContainsString('synthetic-dns-token', json_encode($result));
    }

    public function test_cloudflare_record_catalog_filters_family_and_does_not_expose_extra_metadata(): void
    {
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [$this->remote() + ['comment' => 'private-comment']], 'result_info' => ['total_pages' => 1]])]);
        $result = $this->service->catalog(['credential_id' => $this->credential->id, 'zone' => $this->zone, 'type' => 'A', 'search' => 'node'], true);
        $this->assertSame('node.example.com', $result['items'][0]['hostname']);
        $this->assertFalse($result['items'][0]['locked']);
        $this->assertStringNotContainsString('private-comment', json_encode($result));
        Http::assertSent(fn ($request) => $request['type'] === 'A' && $request['name.contains'] === 'node' && $request->method() === 'GET');
    }

    public function test_unsupported_wildcard_or_private_records_are_disabled_not_a_broken_catalog(): void
    {
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
            array_replace($this->remote(), ['name' => '*.example.com']),
            array_replace($this->remote(), ['id' => str_repeat('d', 32), 'content' => '127.0.0.1']),
        ], 'result_info' => ['total_pages' => 1]])]);
        $result = $this->service->catalog(['credential_id' => $this->credential->id, 'zone' => $this->zone, 'type' => 'A'], true);
        $this->assertTrue($result['items'][0]['locked']); $this->assertTrue($result['items'][1]['locked']);
    }

    public function test_aliyun_catalog_uses_signed_read_operations_and_keeps_record_line(): void
    {
        $credential = MachineDnsCredential::query()->create(['name' => 'Ali synthetic', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret'], 'revision' => 1]);
        $this->fake(['alidns.aliyuncs.com/*' => Http::sequence()->push(['Domains' => ['Domain' => [['DomainName' => 'example.com']]], 'TotalCount' => 51])
            ->push(['DomainRecords' => ['Record' => [['RecordId' => '123', 'DomainName' => 'example.com', 'RR' => '@', 'Type' => 'AAAA',
                'Value' => '2606:4700:4700::1111', 'TTL' => 600, 'Line' => 'telecom', 'Status' => 'ENABLE']]], 'TotalCount' => 1])]);
        $zones = $this->service->catalog(['credential_id' => $credential->id, 'search' => 'example'], false);
        $this->assertTrue($zones['has_more']); $this->assertSame('example.com', $zones['items'][0]['id']);
        $records = $this->service->catalog(['credential_id' => $credential->id, 'zone' => 'example.com', 'type' => 'AAAA'], true);
        $this->assertSame('telecom', $records['items'][0]['line']);
        $this->assertSame('example.com', $records['items'][0]['hostname']);
        Http::assertSent(fn ($request) => $request['Action'] === 'DescribeDomainRecords' && $request['TypeKeyWord'] === 'AAAA' && $request['PageSize'] === 50 && isset($request['Signature']));
        Http::assertNotSent(fn ($request) => !in_array($request['Action'], ['DescribeDomains', 'DescribeDomainRecords'], true));
    }

    public function test_catalog_never_uses_untrusted_paths_and_masks_malformed_provider_errors(): void
    {
        $this->fake();
        foreach ([['zone' => '../../secrets', 'type' => 'A'], ['zone' => $this->zone, 'type' => 'TXT'], ['page' => 10001]] as $patch) {
            try { $this->service->catalog($patch + ['credential_id' => $this->credential->id], isset($patch['zone'])); $this->fail('Invalid catalog parameters'); }
            catch (DnsException $error) { $this->assertSame('invalid_record', $error->getMessage()); }
        }
        Http::assertNothingSent();
        foreach ([['success' => true, 'result' => [], 'result_info' => ['total_pages' => 'bad']], ['success' => true, 'result' => ['not-a-list'], 'result_info' => ['total_pages' => 1]]] as $body) {
            $this->fake(['api.cloudflare.com/*' => Http::response($body)]);
            try { $this->service->catalog(['credential_id' => $this->credential->id], false); $this->fail('Invalid provider body'); }
            catch (DnsException $error) { $this->assertSame('provider_rejected', $error->getMessage()); }
        }
    }

    public function test_catalog_and_preview_require_admin_before_contacting_provider(): void
    {
        $this->fake();
        foreach (['zones', 'records', 'preview'] as $action) {
            $request = Request::create('/dns/' . $action, 'GET');
            $request->setUserResolver(fn () => (object) ['is_admin' => false]);
            try { (new MachineDnsController())->$action($request, $this->service); $this->fail('Admin only'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        Http::assertNothingSent();
    }

    private function enable(MachineDnsBinding $binding): MachineDnsBinding
    {
        $this->service->changeBinding($binding->id, $binding->revision, true, false, 7);
        return $binding->fresh();
    }

    public function test_credentials_are_encrypted_and_never_returned_in_overview_or_model_json(): void
    {
        $raw = DB::table('v2_machine_dns_credential')->value('credentials');
        $this->assertStringNotContainsString('synthetic-dns-token', $raw);
        $this->assertSame('synthetic-dns-token', $this->credential->fresh()->credentials['api_token']);
        $this->assertStringNotContainsString('synthetic-dns-token', json_encode($this->service->overview()));
        $this->assertStringNotContainsString('credentials', $this->credential->toJson());
    }

    public function test_binding_only_reads_record_and_is_disabled_until_explicitly_enabled(): void
    {
        $binding = $this->bind();
        $this->assertFalse($binding->enabled);
        $this->assertSame('1.1.1.1', $binding->last_ip);
        $this->service->sync($binding->id, 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        $this->assertSame(0, $this->service->schedule());
    }

    public function test_sync_patches_only_content_then_verifies_provider_record(): void
    {
        $binding = $this->enable($this->bind());
        $this->fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => true, 'result' => $this->remote()])
            ->push(['success' => true, 'result' => $this->remote('8.8.8.8')])
            ->push(['success' => true, 'result' => $this->remote('8.8.8.8')])]);
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('8.8.8.8', $binding->fresh()->last_ip);
        $this->assertSame('synced', $binding->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === ['content' => '8.8.8.8']);
        $this->assertSame('synced', DB::table('v2_machine_dns_log')->orderByDesc('id')->value('action'));
        $count = count(Http::recorded());
        $this->service->sync($binding->id, $binding->revision);
        $this->assertCount($count, Http::recorded());
    }

    public function test_external_record_edits_stop_automation_without_overwriting(): void
    {
        $binding = $this->enable($this->bind());
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => $this->remote('9.9.9.9')])]);
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('record_changed', $binding->fresh()->status);
        $this->assertFalse($binding->fresh()->enabled);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_stale_source_and_other_instance_never_supply_a_target(): void
    {
        $binding = $this->enable($this->bind());
        $this->report('8.8.8.8', null, time() - 181);
        $this->report('9.9.9.9', str_repeat('e', 32));
        $this->fake();
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('source_unavailable', $binding->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_observed_peer_and_retained_history_are_not_used_as_current_public_address(): void
    {
        $binding = $this->enable($this->bind());
        $this->inventory->record($this->machine, $this->instance, ['panel_seen' => '8.8.8.8'], time());
        $this->fake();
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('source_unavailable', $binding->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cloud_power_hold_blocks_dns_writes_until_matching_instance_recovers(): void
    {
        $binding = $this->enable($this->bind());
        Schema::create('v2_machine_cloud_resource', function (Blueprint $table) {
            $table->integer('machine_id'); $table->string('installation_id'); $table->boolean('dns_hold');
        });
        DB::table('v2_machine_cloud_resource')->insert(['machine_id' => $this->machine->id, 'installation_id' => $this->instance, 'dns_hold' => true]);
        $this->fake(); $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('cloud_recovery_pending', $binding->fresh()->status); Http::assertNothingSent();
        $this->assertTrue($binding->fresh()->enabled);
    }

    public function test_machine_token_rotation_disables_old_binding_without_using_a_new_identity(): void
    {
        $binding = $this->enable($this->bind());
        $this->machine->token = 'rotated-synthetic-token'; $this->machine->save();
        $this->fake();
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('identity_changed', $binding->fresh()->status);
        $this->assertFalse($binding->fresh()->enabled);
        Http::assertNothingSent();
    }

    public function test_old_queued_revision_and_deleted_binding_do_not_run(): void
    {
        $binding = $this->enable($this->bind());
        $this->fake();
        $this->service->sync($binding->id, 1);
        $this->service->changeBinding($binding->id, $binding->revision, false, true, 7);
        $this->service->sync($binding->id, $binding->revision);
        Http::assertNothingSent();
    }

    public function test_duplicate_dns_record_cannot_be_bound_to_another_instance_or_credential(): void
    {
        $this->bind();
        $this->expectException(DnsException::class); $this->expectExceptionMessage('record_in_use');
        $this->bind();
    }

    public function test_record_name_mismatch_is_not_updated(): void
    {
        $binding = $this->enable($this->bind());
        $this->fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => array_replace($this->remote(), ['name' => 'other.example.com'])])]);
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('record_mismatch', $binding->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_readback_failure_does_not_claim_success_or_erase_the_old_baseline(): void
    {
        $binding = $this->enable($this->bind());
        $this->fake(['api.cloudflare.com/*' => Http::sequence()->push(['success' => true, 'result' => $this->remote()])
            ->push(['success' => true, 'result' => $this->remote()])->push(['success' => true, 'result' => $this->remote()])]);
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('verification_failed', $binding->fresh()->status);
        $this->assertSame('1.1.1.1', $binding->fresh()->last_ip);
    }

    public function test_provider_errors_are_reduced_to_codes_without_body_or_credentials(): void
    {
        $binding = $this->enable($this->bind());
        $this->fake(['api.cloudflare.com/*' => Http::response(['message' => 'synthetic-dns-token/private-body'], 403)]);
        $this->service->sync($binding->id, $binding->revision);
        $json = json_encode($this->service->overview());
        $this->assertSame('provider_forbidden', $binding->fresh()->status);
        $this->assertStringNotContainsString('synthetic-dns-token', $json);
        $this->assertStringNotContainsString('private-body', $json);
    }

    public function test_scheduler_uses_existing_admin_queue_and_disabled_bindings_do_not_dispatch(): void
    {
        $binding = $this->bind();
        $this->assertSame(0, $this->service->schedule());
        $binding = $this->enable($binding);
        $this->assertSame(1, $this->service->schedule());
        Bus::assertDispatched(SyncMachineDnsJob::class, fn ($job) => $job->bindingId === $binding->id && $job->queue === 'admin_operations' && $job->connection === 'redis');
    }

    public function test_dns_api_requires_admin_not_machine_token_or_staff(): void
    {
        $request = Request::create('/dns', 'GET');
        $request->setUserResolver(fn () => (object) ['is_admin' => false]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MachineDnsController())->fetch($request, $this->service);
    }

    public function test_aliyun_signature_is_order_independent_and_binds_post_method(): void
    {
        $parameters = ['Timestamp' => '2026-11-05T08:00:00Z', 'Action' => 'DescribeDomainRecordInfo', 'RecordId' => '123', 'AccessKeyId' => 'synthetic-id'];
        $first = DnsProviderClient::signAliyun($parameters, 'synthetic-secret');
        $this->assertSame($first, DnsProviderClient::signAliyun(array_reverse($parameters, true), 'synthetic-secret'));
        ksort($parameters);
        $expected = base64_encode(hash_hmac('sha1', 'POST&%2F&' . rawurlencode(http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)), 'synthetic-secret&', true));
        $this->assertSame($expected, $first['Signature']);
    }

    public function test_aliyun_update_preserves_line_ttl_and_only_uses_fixed_https_endpoint(): void
    {
        $credential = new MachineDnsCredential(['provider' => 'aliyun', 'credentials' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret']]);
        $binding = new MachineDnsBinding(['zone' => 'example.com', 'record_id' => '123', 'hostname' => 'node.example.com', 'type' => 'A']);
        $this->fake(['alidns.aliyuncs.com/*' => Http::response(['RecordId' => '123'])]);
        (new DnsProviderClient())->update($credential, $binding, ['ttl' => 600, 'line' => 'telecom'], '8.8.8.8');
        Http::assertSent(fn ($request) => $request->url() === 'https://alidns.aliyuncs.com/' && $request->method() === 'POST'
            && $request['RR'] === 'node' && $request['TTL'] === 600 && $request['Line'] === 'telecom' && $request['Value'] === '8.8.8.8');
    }

    public function test_request_audit_redacts_all_dns_credential_fields(): void
    {
        $method = new \ReflectionMethod(RequestLog::class, 'sanitizePayload');
        $safe = $method->invoke(new RequestLog(), ['api_token' => 'secret', 'access_key_id' => 'secret', 'access_key_secret' => 'secret']);
        $this->assertSame(['api_token' => '[REDACTED]', 'access_key_id' => '[REDACTED]', 'access_key_secret' => '[REDACTED]'], $safe);
    }

    public function test_credential_updates_preserve_blank_secrets_and_require_current_revision(): void
    {
        $this->service->saveCredential(['id' => $this->credential->id, 'name' => 'Renamed', 'provider' => 'cloudflare', 'expected_revision' => 1, 'api_token' => ''], 7);
        $this->assertSame('synthetic-dns-token', $this->credential->fresh()->credentials['api_token']);
        $this->assertSame(2, $this->credential->fresh()->revision);
        $this->expectExceptionMessage('config_changed');
        $this->service->saveCredential(['id' => $this->credential->id, 'name' => 'Stale', 'provider' => 'cloudflare', 'expected_revision' => 1], 7);
    }

    public function test_credentials_in_use_cannot_be_deleted(): void
    {
        $this->bind(); $this->expectExceptionMessage('credential_in_use');
        $this->service->deleteCredential($this->credential->id, 1, 7);
    }

    public function test_ipv6_binding_cannot_borrow_another_instances_ipv6(): void
    {
        $this->report('2606:4700:4700::1111', str_repeat('d', 32));
        $this->fake();
        try {
            $this->service->createBinding(['credential_id' => $this->credential->id, 'machine_id' => $this->machine->id,
                'installation_id' => $this->instance, 'zone' => $this->zone, 'record_id' => $this->record,
                'hostname' => 'node.example.com', 'type' => 'AAAA'], 7);
            $this->fail('An IPv4-only instance cannot supply IPv6');
        } catch (DnsException $error) { $this->assertSame('source_unavailable', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_disabled_machine_and_rate_limits_never_write_and_use_backoff(): void
    {
        $binding = $this->enable($this->bind());
        $this->machine->is_active = false; $this->machine->save();
        $this->fake(); $this->service->sync($binding->id, $binding->revision);
        Http::assertNothingSent();
        $this->assertGreaterThanOrEqual(time() + 299, $binding->fresh()->next_attempt_at);
        $this->machine->is_active = true; $this->machine->save();
        $this->fake(['api.cloudflare.com/*' => Http::response([], 429)]);
        $this->service->sync($binding->id, $binding->revision);
        $this->assertSame('provider_rate_limited', $binding->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_legacy_reporters_and_private_addresses_cannot_be_bound(): void
    {
        $this->inventory->record($this->machine, 'legacy', ['public_ipv4' => '127.0.0.1'], time());
        $rows = $this->service->instances($this->machine->id);
        $this->assertCount(1, $rows);
        $this->assertSame($this->instance, $rows[0]['installation_id']);
        $this->assertSame('8.8.8.8', $rows[0]['ipv4']);
        $this->assertNull($rows[0]['ipv6']);
    }

    public function test_scheduler_is_inert_before_migration(): void
    {
        Schema::drop('v2_machine_dns_log');
        $this->assertSame(0, $this->service->schedule());
        $this->assertFalse($this->service->overview()['available']);
        Bus::assertNothingDispatched();
    }
}
