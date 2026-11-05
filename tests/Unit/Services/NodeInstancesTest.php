<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\NodeInstancesController;
use App\Jobs\UpdateAliveDataJob;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\Node\NodeReportSource;
use App\Services\NodeRealtime\NodeRealtimeAuthenticator;
use App\Services\ServerMachine\MachineIdentityGuard;
use App\Services\ServerService;
use App\Services\UserOnlineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class NodeInstancesTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        \Illuminate\Database\Eloquent\Model::clearBootedModels();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(new \Illuminate\Events\Dispatcher(app()));
        $this->bindTestSettings(['device_limit_mode' => 0, 'server_push_interval' => 60, 'server_token' => 'local-shared-test']);
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->increments('id'); $table->string('name'); $table->string('type'); $table->string('runtime')->default('v2node');
            $table->integer('machine_id')->nullable(); $table->integer('parent_id')->nullable(); $table->string('code')->nullable();
            $table->boolean('enabled')->default(true); $table->integer('sort')->default(0); $table->json('protocol_settings')->nullable();
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->increments('id'); $table->string('name'); $table->string('token'); $table->boolean('is_active')->default(true);
            $table->json('load_status')->nullable(); $table->integer('last_seen_at')->nullable(); $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('online_count')->default(0); $table->timestamp('last_online_at')->nullable();
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        (require base_path('database/migrations/2026_11_04_000001_create_server_machine_bindings.php'))->up();
        DB::table('v2_user')->insert([['id' => 1], ['id' => 2]]);
    }
    private function machine(string $name): ServerMachine { return ServerMachine::create(['name' => $name, 'token' => $name . '-test-token', 'is_active' => true]); }
    private function node(?int $machineId = null, array $settings = [], string $type = 'trojan'): Server {
        return Server::create(['name' => 'linsg1 test', 'type' => $type, 'machine_id' => $machineId, 'enabled' => true, 'protocol_settings' => $settings]);
    }
    private function request(string $id, ?int $machine = null): Request {
        $request = Request::create('/local-test', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_KELI_INSTANCE_ID' => $id]);
        $request->attributes->set('node_report_source', NodeReportSource::resolve($request, $machine)); return $request;
    }
    public function test_report_source_does_not_trust_a_posted_machine_id_or_source_ip(): void {
        $r = $this->request(str_repeat('a', 32)); $r->merge(['machine_id' => 999]);
        $source = NodeReportSource::resolve($r);
        $this->assertNull($source['machine_id']); $this->assertSame('agent:' . str_repeat('a', 32), $source['key']);
        $this->assertSame('machine:3:' . str_repeat('a', 32), NodeReportSource::resolve($r, 3)['key']);
        $this->assertNull(NodeReportSource::installation('not-an-id'));
    }
    public function test_machine_overview_selects_latest_attributed_online_report_without_summing(): void
    {
        $primary = $this->machine('primary');
        $replica = $this->machine('replica');
        $unbound = $this->machine('unbound');
        $node = $this->node($primary->id);
        $second = $this->node($replica->id);
        DB::table('v2_server_machine_binding')->insert(['server_id' => $node->id, 'machine_id' => $replica->id, 'created_at' => time()]);
        $registry = app(NodeInstanceRegistry::class);
        $now = time();
        $registry->record($node, $this->request(str_repeat('a', 32), $primary->id), ['online_users' => 7, 'last_online_at' => $now - 200]);
        $registry->record($node, $this->request(str_repeat('b', 32), $replica->id), ['online_users' => 3, 'last_online_at' => $now - 12]);
        $registry->record($second, $this->request(str_repeat('b', 32), $replica->id), ['online_users' => 0, 'last_online_at' => $now - 2]);
        $registry->record($node, $this->request(str_repeat('c', 32), $unbound->id), ['online_users' => 99, 'last_online_at' => $now]);

        $overview = app(\App\Services\ServerMachine\MachineReportingInstances::class)->listOverview([$primary->id, $replica->id, $unbound->id]);
        $this->assertSame([$node->id, $second->id], array_column($overview['nodes'][$replica->id], 'id'));
        $this->assertSame($second->id, $overview['activity'][$replica->id]['node_id']);
        $this->assertSame(0, $overview['activity'][$replica->id]['count']);
        $this->assertSame('bbbbbbbb', $overview['activity'][$replica->id]['instance']);
        $this->assertSame($now - 200, $overview['activity'][$primary->id]['reported_at']);
        $this->assertNull($overview['activity'][$primary->id]['count']);
        $this->assertArrayNotHasKey($unbound->id, $overview['activity']);
    }
    public function test_two_instances_keep_independent_snapshots_and_same_logical_node_device_policy(): void {
        $a = 'agent:' . str_repeat('a', 32); $b = 'agent:' . str_repeat('b', 32);
        $now = microtime(true);
        (new UpdateAliveDataJob([1 => ['198.51.100.1']], 'trojan', 9, $now, $a))->handle();
        (new UpdateAliveDataJob([1 => ['198.51.100.1', '198.51.100.2'], 2 => ['198.51.100.3']], 'trojan', 9, $now - 1, $b))->handle();
        $data = Cache::get(UserOnlineService::cacheKey(1));
        $this->assertSame(2, $data['alive_ip']); $this->assertSame(2, $data['online_count']);
        $this->assertSame(2, count(array_filter($data, 'is_array')));
        (new UpdateAliveDataJob([], 'trojan', 9, $now + 1, $a))->handle();
        $this->assertSame(2, app(UserOnlineService::class)->getOnlineCount(1));
        $this->assertSame([1, 2], Cache::get(UserOnlineService::nodeUsersCacheKey('trojan', 9, $b)));
        (new UpdateAliveDataJob([], 'trojan', 9, $now - 2, $b))->handle();
        $this->assertSame(2, app(UserOnlineService::class)->getOnlineCount(1));
    }
    public function test_delayed_first_snapshot_is_not_revived_as_a_fresh_instance(): void {
        (new UpdateAliveDataJob([1 => ['198.51.100.1']], 'trojan', 9, microtime(true) - 200, 'machine:1'))->handle();
        $this->assertSame(0, app(UserOnlineService::class)->getOnlineCount(1));
    }
    public function test_instances_are_not_split_by_ip_and_legacy_remains_explicitly_unidentified(): void {
        $node = $this->node(); $registry = new NodeInstanceRegistry();
        $r = $this->request(str_repeat('a', 32)); $registry->record($node, $r);
        $r->server->set('REMOTE_ADDR', '198.51.100.10'); $registry->record($node, $r);
        $registry->record($node, Request::create('/legacy'));
        $rows = $registry->snapshot($node->id); $this->assertCount(2, $rows);
        $this->assertSame('198.51.100.10', $rows[0]['reported_ip']); $this->assertFalse($rows[1]['identified']);
    }
    public function test_active_instance_registry_is_bounded(): void {
        $node = $this->node(); $registry = new NodeInstanceRegistry();
        for ($i = 0; $i < NodeInstanceRegistry::LIMIT; $i++) $this->assertTrue($registry->record($node, $this->request(str_pad(dechex($i), 32, '0', STR_PAD_LEFT))));
        $this->assertFalse($registry->record($node, $this->request(str_repeat('f', 32))));
        $this->assertCount(NodeInstanceRegistry::LIMIT, $registry->snapshot($node->id));
    }
    public function test_instance_summary_excludes_expired_history_and_keeps_bindings_unchanged(): void
    {
        $primary = $this->machine('primary');
        $replica = $this->machine('replica');
        $node = $this->node($primary->id);
        (new NodeMachineBinding())->replaceReplicas($node->id, [$replica->id]);
        $registry = new NodeInstanceRegistry();
        $now = time();
        $staleAt = $now - UserOnlineService::nodeDataExpirySeconds() - 5;
        $rows = [
            'current' => ['key' => 'current', 'identified' => true, 'last_seen_at' => $now],
            'offline' => ['key' => 'offline', 'identified' => true, 'last_seen_at' => $staleAt],
            'expired' => ['key' => 'expired', 'identified' => true, 'last_seen_at' => $now - NodeInstanceRegistry::RETENTION - 5],
            'legacy' => ['key' => 'legacy', 'identified' => false, 'last_seen_at' => $staleAt],
        ];
        $key = $registry->key($node->id);
        Cache::put($key, $rows);

        $this->assertSame(['current', 'offline', 'legacy'], array_column($registry->snapshot($node->id), 'key'));
        $this->assertSame(['observed' => 2, 'online' => 1, 'unidentified' => false, 'bound_machines' => 2],
            $registry->summaries([$node])[$node->id]);
        $this->assertSame($rows, Cache::get($key));

        $rows['current']['last_seen_at'] = $staleAt;
        $rows['legacy']['last_seen_at'] = $now;
        Cache::put($key, $rows);
        $this->assertSame(['observed' => 2, 'online' => 0, 'unidentified' => true, 'bound_machines' => 2],
            $registry->summaries([$node])[$node->id]);
        $this->assertSame([$primary->id, $replica->id], NodeMachineBinding::machineIds($node));
    }

    public function test_machine_addresses_keep_families_and_changes_with_the_reporting_installation(): void
    {
        $machine = $this->machine('addresses');
        $view = app(\App\Services\ServerMachine\MachineAddressInventory::class);
        $a = str_repeat('a', 32); $b = str_repeat('b', 32);
        $now = time();
        $v6 = '2606:4700:4700::1001';
        $view->record($machine, $a, ['public_ipv4' => '1.0.0.1', 'public_ipv6' => $v6], $now - 30);
        $view->record($machine, $a, ['public_ipv4' => '1.1.1.1', 'public_ipv6' => $v6, 'panel_seen' => $v6, 'local' => ['10.0.0.1']], $now - 20);
        $view->record($machine, $b, ['public_ipv4' => '8.8.4.4'], $now - 10);
        $records = $view->snapshots([$machine])[$machine->id];
        $groups = $view->groups($records, [['reported_ip' => '192.0.2.9', 'last_seen_at' => $now]], ['ip' => ['public_ipv4' => '9.9.9.9']], true, $now);
        $this->assertSame([$b, $a, null], array_column($groups, 'installation_id'));
        $this->assertSame(['8.8.4.4'], array_column($groups[0]['ips'], 'ip'));
        $this->assertSame(['1.1.1.1', $v6], array_column($groups[1]['ips'], 'ip'));
        $this->assertSame('1.0.0.1', $groups[1]['changes'][0]['from']);
        $this->assertSame([['ip' => '192.0.2.9', 'observed' => true]], $groups[2]['ips']);
        $this->assertSame([], $view->groups([], [], ['ip' => ['public_ipv4' => '9.9.9.9']], true, $now));
        $machine->token = 'rotated';
        $this->assertSame([], $view->snapshots([$machine])[$machine->id]);
    }
    public function test_machine_summary_deduplicates_reporters_and_keeps_ip_history_and_traffic_separate(): void
    {
        (require base_path('database/migrations/2026_11_04_000002_add_traffic_batch_reporters.php'))->up();
        $machine = $this->machine('shared'); $replica = $this->machine('replica');
        $one = $this->node($machine->id); $two = $this->node($machine->id);
        (new NodeMachineBinding())->replaceReplicas($two->id, [$replica->id]);
        $registry = app(NodeInstanceRegistry::class);
        $stable = $this->request(str_repeat('a', 32), $machine->id);
        $legacy = $this->request('', $machine->id);
        $registry->record($one, $stable);
        $registry->record($two, $stable);
        $registry->record($one, $legacy);
        $guard = app(MachineIdentityGuard::class);
        $this->assertFalse($guard->conflicted($machine->id), 'Same-address legacy handover alone is not a conflict');
        $legacy->server->set('REMOTE_ADDR', '2001:db8::1'); $registry->record($one, $legacy);
        $this->assertTrue($guard->conflicted($machine->id));
        $this->assertTrue($guard->observe($machine->id, $stable));
        $stable->server->set('REMOTE_ADDR', '2001:db8::2'); $registry->record($one, $stable);
        $stable->server->set('REMOTE_ADDR', '2001:0db8::2'); $registry->record($one, $stable);
        $registry->record($two, $this->request(str_repeat('b', 32), $replica->id));
        $view = app(\App\Services\ServerMachine\MachineReportingInstances::class);
        $stableKey = NodeReportSource::from($stable)['key'];
        DB::table('v2_server_instance_traffic')->insert(['server_id' => $one->id, 'reporter_key' => $stableKey,
            'record_at' => strtotime(date('Y-m-d')), 'upload' => 0, 'download' => 400, 'updated_at' => time()]);
        $summaries = $view->listOverview([$machine->id, $replica->id])['reporters'];
        $this->assertCount(2, $summaries[$machine->id]);
        $this->assertCount(1, $summaries[$replica->id]);
        $data = app(\App\Services\ServerMachine\MachineInsightService::class)->snapshot($machine);
        $instances = collect($data['reporting_instances'])->keyBy('key');
        $this->assertCount(2, $instances);
        $this->assertSame(400, $instances[$stableKey]['nodes'][0]['today_download']);
        $this->assertSame(0, $instances[$stableKey]['nodes'][0]['today_upload']);
        $this->assertNull($instances[$stableKey]['nodes'][1]['today_download']);
        $changes = $instances[$stableKey]['nodes'][0]['ip_changes'];
        $this->assertCount(1, $changes);
        $this->assertSame('203.0.113.7', $changes[0]['from']);
        $this->assertSame('2001:db8::2', $changes[0]['to']);
        $this->assertSame([], $instances[NodeReportSource::from($legacy)['key']]['nodes'][0]['ip_changes']);
        $this->assertNull($instances[NodeReportSource::from($legacy)['key']]['nodes'][0]['today_download']);
        $reports = collect($summaries[$machine->id])->keyBy('key');
        $this->assertSame([], $view->billedToday([$machine->id => $reports[NodeReportSource::from($legacy)['key']]]));
        $this->assertSame([], $view->billedToday([$replica->id => $summaries[$replica->id][0]]));
        $this->assertSame([0, 400, strtotime('tomorrow')], array_values($view->billedToday([$machine->id => $reports[$stableKey]])[$machine->id]));
        DB::table('v2_server_instance_traffic')->insert(['server_id' => $two->id, 'reporter_key' => $stableKey,
            'record_at' => strtotime(date('Y-m-d')), 'upload' => 20, 'download' => 60, 'updated_at' => time()]);
        $this->assertSame([20, 460, strtotime('tomorrow')], array_values($view->billedToday([$machine->id => $reports[$stableKey]])[$machine->id]));
        $second = $this->request(str_repeat('c', 32), $machine->id);
        $registry->record($one, $second);
        $secondKey = NodeReportSource::from($second)['key'];
        DB::table('v2_server_instance_traffic')->insert(['server_id' => $one->id, 'reporter_key' => $secondKey,
            'record_at' => strtotime('today'), 'upload' => 12, 'download' => 34, 'updated_at' => time()]);
        $sources = $view->listSources([$machine->id => [
            ['installation_id' => str_repeat('a', 32), 'ips' => [['ip' => '2001:db8::2', 'observed' => true]], 'changes' => $changes],
            ['installation_id' => str_repeat('c', 32), 'ips' => [['ip' => '203.0.113.7', 'observed' => true]], 'changes' => []],
            ['installation_id' => null, 'ips' => [['ip' => '2001:db8::1', 'observed' => true]], 'changes' => []],
        ]], $view->listOverview([$machine->id])['reporters'])[$machine->id];
        $this->assertCount(2, $sources);
        $this->assertSame([20, 460, strtotime('tomorrow')], array_values($sources[0]['billing']));
        $this->assertSame([12, 34, strtotime('tomorrow')], array_values($sources[1]['billing']));
        $this->assertSame('2001:db8::2', $sources[0]['change']['to']);
        $this->assertArrayNotHasKey('change', $sources[1]);
    }
    public function test_replica_binding_keeps_the_primary_and_authenticates_only_assigned_active_machines(): void {
        $a = $this->machine('a'); $b = $this->machine('b'); $c = $this->machine('c'); $node = $this->node($a->id);
        (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id], NodeMachineBinding::revision($node));
        $this->assertSame($a->id, $node->fresh()->machine_id);
        $this->assertSame([$node->id], ServerService::getMachineNodes($b)->modelKeys());
        $auth = new NodeRealtimeAuthenticator();
        $params = ['node_id' => $node->id, 'node_type' => 'v2node', 'machine_id' => $b->id, 'token' => 'b-test-token'];
        $this->assertSame($b->id, $auth->authenticate($params)['machine']->id);
        $this->assertNull($auth->authenticate(array_replace($params, ['machine_id' => $c->id, 'token' => 'c-test-token'])));
        (new NodeMachineBinding())->replaceReplicas($node->id, []);
        $this->assertNull($auth->authenticate($params));
    }
    public function test_global_node_realtime_connections_are_scoped_by_installation(): void {
        $node = $this->node(); $auth = new NodeRealtimeAuthenticator();
        $params = ['node_id' => $node->id, 'node_type' => 'trojan', 'token' => 'local-shared-test'];
        $a = $auth->authenticate($params + ['instance_id' => str_repeat('a', 32)]);
        $b = $auth->authenticate($params + ['instance_id' => str_repeat('b', 32)]);
        $this->assertNotNull($a); $this->assertNotNull($b); $this->assertNotSame($a['connection_key'], $b['connection_key']);
        $this->assertNull($auth->authenticate($params + ['instance_id' => 'invalid']));
    }
    public function test_two_installations_using_one_machine_credential_are_flagged_without_ip_heuristics(): void {
        $guard = new MachineIdentityGuard();
        $this->assertFalse($guard->observe(1, $this->request(str_repeat('a', 32))));
        $changedIp = $this->request(str_repeat('a', 32)); $changedIp->server->set('REMOTE_ADDR', '198.51.100.1');
        $this->assertFalse($guard->observe(1, $changedIp));
        $this->assertTrue($guard->observe(1, $this->request(str_repeat('b', 32))));
        $this->assertTrue($guard->conflicted(1)); $this->assertFalse($guard->conflicted(2));
    }
    public function test_per_machine_automatic_certificate_sharing_is_rejected(): void {
        $a = $this->machine('a'); $b = $this->machine('b');
        $node = $this->node($a->id, ['tls_settings' => ['cert_mode' => 'self']], 'trusttunnel');
        $this->expectException(ValidationException::class);
        (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id]);
    }
    public function test_hy2_independently_issued_certificate_pins_cannot_be_shared(): void {
        $a = $this->machine('a'); $b = $this->machine('b');
        foreach (['self', 'dns', 'http'] as $mode) {
            $node = $this->node($a->id, ['version' => 2, 'tls_settings' => ['cert_mode' => $mode]], 'hysteria');
            try { (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id]); $this->fail('Local certificate was shared'); }
            catch (ValidationException) { $this->assertSame([$a->id], NodeMachineBinding::machineIds($node)); }
        }
    }
    public function test_manual_shared_hy2_material_keeps_a_distinct_replica_binding(): void {
        $a = $this->machine('a'); $b = $this->machine('b');
        $node = $this->node($a->id, ['version' => 2, 'tls_settings' => ['cert_mode' => 'file'], 'ech' => ['enabled' => true, 'mode' => 'manual']], 'hysteria');
        (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id]);
        $this->assertSame([$a->id, $b->id], NodeMachineBinding::machineIds($node));
    }
    public function test_instance_api_never_exposes_machine_tokens_or_real_user_ips(): void {
        $a = $this->machine('a'); $node = $this->node($a->id);
        $r = Request::create('/local-test', 'GET', ['id' => $node->id]);
        $body = (new NodeInstancesController())->fetch($r)->getData(true)['data'];
        $this->assertSame($a->id, $body['machines'][0]['id']);
        $json = json_encode($body); $this->assertStringNotContainsString('test-token', $json);
        $this->assertArrayNotHasKey('token', $body['available_machines'][0]);
    }
    public function test_stale_binding_revision_cannot_overwrite_another_save(): void {
        $a = $this->machine('a'); $b = $this->machine('b'); $node = $this->node($a->id);
        $revision = NodeMachineBinding::revision($node);
        (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id], $revision);
        try { (new NodeMachineBinding())->replaceReplicas($node->id, [], $revision); $this->fail('Stale bindings accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertSame([$a->id, $b->id], NodeMachineBinding::machineIds($node));
    }
    public function test_shared_node_cannot_later_switch_to_per_machine_pinned_material(): void {
        $a = $this->machine('a'); $b = $this->machine('b'); $node = $this->node($a->id);
        (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id]);
        $node->type = 'trusttunnel'; $node->protocol_settings = ['tls_settings' => ['cert_mode' => 'self']];
        try { $node->save(); $this->fail('Incompatible shared node saved'); } catch (ValidationException) {}
        $this->assertSame('trojan', $node->fresh()->type);
    }
    public function test_conflicting_installations_cannot_receive_a_queued_upgrade(): void {
        $machine = $this->machine('a'); $guard = new MachineIdentityGuard();
        $guard->observe($machine->id, $this->request(str_repeat('a', 32)));
        $guard->observe($machine->id, $this->request(str_repeat('b', 32)));
        $method = new \ReflectionMethod(\App\Http\Controllers\V2\Server\MachineController::class, 'buildUpgradeCommand');
        $this->assertNull($method->invoke(new \App\Http\Controllers\V2\Server\MachineController(), $machine, 'https://panel.example.test'));
    }
    public function test_replica_receipt_is_not_presented_as_another_machines_success(): void {
        $a = $this->machine('a'); $b = $this->machine('b'); $node = $this->node($a->id);
        (new NodeMachineBinding())->replaceReplicas($node->id, [$b->id]);
        $registry = new NodeInstanceRegistry();
        $registry->record($node, $this->request(str_repeat('a', 32), $a->id));
        $registry->record($node, $this->request(str_repeat('b', 32), $b->id));
        app()->instance(\App\Services\NodeRealtime\NodeRealtimeStatusService::class, new class(app(\App\Services\NodeRealtime\NodeRealtimeSettings::class)) extends \App\Services\NodeRealtime\NodeRealtimeStatusService {
            public function receiptsForServers(array $serverIds): array {
                return [$serverIds[0] => ['config' => ['status' => 'applied', 'event_id' => 'other-instance', 'updated_at' => date(DATE_ATOM)]]];
            }
        });
        Cache::put('node_instance_receipt:' . $node->id . ':machine:' . $a->id . ':' . str_repeat('a', 32) . ':config', ['status' => 'restart_required', 'event_id' => 'a-pending', 'updated_at' => date(DATE_ATOM)], 3600);
        $insight = app(\App\Services\ServerMachine\MachineInsightService::class);
        $this->assertSame('restart_required', $insight->snapshot($a)['nodes'][0]['config']['status']);
        $this->assertNull($insight->snapshot($b)['nodes'][0]['config']);
    }
    public function test_parent_display_cache_is_refreshed_without_changing_source_identity(): void {
        $key = 'agent:' . str_repeat('a', 32);
        (new UpdateAliveDataJob([1 => ['198.51.100.1']], 'trojan', 9, null, $key, null, 7))->handle();
        $this->assertSame(1, Cache::get(\App\Utils\CacheKey::get('SERVER_TROJAN_ONLINE_USER', 7)));
        $this->assertSame([1], Cache::get(UserOnlineService::nodeUsersCacheKey('trojan', 9, $key)));
    }
    public function test_legacy_and_identified_snapshots_do_not_overwrite_each_other_during_upgrade(): void {
        $node = $this->node(); $registry = new NodeInstanceRegistry();
        $registry->record($node, Request::create('/legacy'), ['last_online_at' => time()]);
        $registry->record($node, $this->request(str_repeat('a', 32)), ['last_online_at' => time()]);
        (new UpdateAliveDataJob([1 => ['198.51.100.1']], 'trojan', $node->id))->handle();
        (new UpdateAliveDataJob([2 => ['198.51.100.2']], 'trojan', $node->id, null, 'agent:' . str_repeat('a', 32)))->handle();
        $cache = \App\Utils\CacheKey::get('SERVER_TROJAN_ONLINE_USER', $node->id);
        $this->assertSame(2, Cache::get($cache));
        (new UpdateAliveDataJob([], 'trojan', $node->id))->handle();
        $this->assertSame(1, Cache::get($cache));
        $this->assertSame(1, app(UserOnlineService::class)->getOnlineCount(2));
    }
}
