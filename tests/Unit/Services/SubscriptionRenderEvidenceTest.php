<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\RecordSubscriptionRenderJob;
use App\Models\User;
use App\Services\ServerMachine\MachineRiskSettings;
use App\Services\ServerMachine\SubscriptionRenderEvidence;
use App\Support\AbstractProtocol;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionRenderEvidenceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->createUserTable(); $this->bindJsonResponseFactory();
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        config(['app.key' => 'synthetic-render-key']); $this->bindTestSettings([]);
        app()->instance('request', \Illuminate\Http\Request::create('https://synthetic.example.test/subscribe'));
        $this->bindTestUrlGenerator('https://synthetic.example.test');
        (require base_path('database/migrations/2026_11_03_000001_create_machine_risk_events.php'))->up();
        (require base_path('database/migrations/2026_11_03_000003_create_machine_risk_investigations.php'))->up();
        (require base_path('database/migrations/2026_11_03_000002_create_subscription_render_evidence.php'))->up();
        $this->user = User::query()->create(['site_id' => 2, 'group_id' => 1, 'token' => 'private-token', 'uuid' => 'private-uuid', 'email' => 'private@example.test']);
    }

    private function enable(): void
    {
        $p = app(MachineRiskSettings::class);
        $p->save(array_replace(MachineRiskSettings::DEFAULTS, ['enabled' => true]), $p->snapshot()['revision'], 1, true);
    }

    private function node(array $extra = []): array
    {
        return array_replace(['id' => 100, 'machine_id' => 27, 'name' => 'Synthetic node', 'type' => 'shadowsocks',
            'host' => 'private-endpoint.example.test', 'port' => 443, 'password' => 'private-user-password',
            'protocol_settings' => ['cipher' => 'aes-128-gcm']], $extra);
    }

    private function capture(array $extra = []): array
    {
        $s = app(SubscriptionRenderEvidence::class);
        $s->capture($this->user, ['nodes' => [$s->node($this->node($extra))], 'capped' => false], 'General');
        return Bus::dispatched(RecordSubscriptionRenderJob::class)->last()->receipt;
    }

    public function test_collection_is_explicit_and_does_not_depend_on_ai_automation(): void
    {
        $s = app(SubscriptionRenderEvidence::class); $this->assertFalse($s->collecting($this->user));
        $s->capture($this->user, ['nodes' => [$s->node($this->node())]], 'General'); Bus::assertNothingDispatched();
        $this->enable(); $this->assertTrue($s->collecting($this->user)); $this->capture();
        Bus::assertDispatchedTimes(RecordSubscriptionRenderJob::class, 1);
        $this->assertSame(0, DB::table(SubscriptionRenderEvidence::TABLE)->count());
        foreach (['is_admin', 'is_staff', 'banned'] as $field) {
            $user = clone $this->user; $user->$field = true; $this->assertFalse($s->collecting($user));
        }
    }

    public function test_fingerprints_do_not_contain_credentials_endpoints_or_account_data(): void
    {
        $this->enable(); $s = app(SubscriptionRenderEvidence::class); $receipt = $this->capture();
        $json = json_encode($receipt);
        foreach (['private-token', 'private-uuid', 'private@example.test', 'private-endpoint', 'private-user-password', 'protocol_settings'] as $secret) $this->assertStringNotContainsString($secret, $json);
        $original = $s->node($this->node());
        $this->assertSame($original, $s->node($this->node(['password' => 'other-user', 'name' => 'Other name'])));
        $this->assertNotSame($original['version'], $s->node($this->node(['port' => 8443]))['version']);
        $this->assertNotSame($original['version'], $s->node($this->node(['protocol_settings' => ['cipher' => 'aes-256-gcm']]))['version']);
        $this->assertNull($s->node($this->node(['id' => 0]))); $this->assertNull($s->node($this->node(['machine_id' => null])));
    }

    public function test_extra_queue_fields_are_stripped_before_dispatch(): void
    {
        $this->enable(); $s = app(SubscriptionRenderEvidence::class);
        $node = $s->node($this->node()) + ['token' => 'never-queue-this', 'host' => 'never-queue-host'];
        $s->capture($this->user, ['nodes' => [$node], 'capped' => false, 'secret' => 'never-queue-this'], 'General');
        $receipt = Bus::dispatched(RecordSubscriptionRenderJob::class)->first()->receipt;
        $this->assertStringNotContainsString('never-queue', json_encode($receipt));
        $s->persist($receipt); $stored = (array) DB::table(SubscriptionRenderEvidence::TABLE)->first();
        $this->assertStringNotContainsString('never-queue', json_encode($stored));
        $this->assertSame(['node_id', 'machine_id', 'version'], array_keys($receipt['nodes'][0]));
    }

    public function test_queue_redelivery_is_idempotent_and_credential_site_policy_changes_revoke_pending_writes(): void
    {
        $this->enable(); $s = app(SubscriptionRenderEvidence::class); $receipt = $this->capture();
        $s->persist($receipt); $s->persist($receipt); $this->assertSame(1, DB::table(SubscriptionRenderEvidence::TABLE)->count());
        foreach ([['token' => 'rotated'], ['site_id' => 3], ['is_staff' => true], ['banned' => true]] as $change) {
            $this->user->forceFill($change)->save(); $receipt['receipt_key'] = bin2hex(random_bytes(32)); $s->persist($receipt);
            $this->assertSame(1, DB::table(SubscriptionRenderEvidence::TABLE)->count());
            $this->user->forceFill(['token' => 'private-token', 'site_id' => 2, 'is_staff' => false, 'banned' => false])->save();
        }
        $receipt['generated_at'] = time() - 121; $s->persist($receipt);
        $receipt['generated_at'] = time() + 10; $s->persist($receipt);
        $this->assertSame(1, DB::table(SubscriptionRenderEvidence::TABLE)->count());
        app(MachineRiskSettings::class)->save(MachineRiskSettings::DEFAULTS, 2, 1, true);
        $s->persist($receipt); $this->assertSame(1, DB::table(SubscriptionRenderEvidence::TABLE)->count());
    }

    public function test_incidents_find_generated_but_not_online_accounts_and_freeze_historical_binding(): void
    {
        $this->enable(); $s = app(SubscriptionRenderEvidence::class); $receipt = $this->capture(); $receipt['generated_at'] = time() - 10; $s->persist($receipt);
        $second = $this->capture(['port' => 8443]); $second['generated_at'] = time() - 5; $s->persist($second);
        $result = $s->forMachine(27, time(), 'episode');
        $this->assertCount(1, $result['users']); $this->assertCount(2, $result['users'][0]['versions']);
        $this->assertSame(2, $result['users'][0]['receipts_lower_bound']); $this->assertFalse($result['client_receipt_proven']);
        $this->assertSame(0, $s->forMachine(99, time(), 'episode')['matched_receipts']);
        $this->assertSame(0, $s->forMachine(27, time() - 15, 'episode')['matched_receipts']);
        $this->user->forceFill(['token' => 'rotated'])->save(); $result = $s->forMachine(27, time(), 'episode');
        $this->assertSame(2, $result['excluded_identity_rows']); $this->assertSame([], $result['users']);
    }

    public function test_budgets_and_retention_are_bounded(): void
    {
        $this->enable(); $s = app(SubscriptionRenderEvidence::class);
        for ($i = 0; $i < 4; $i++) $this->capture();
        Bus::assertDispatchedTimes(RecordSubscriptionRenderJob::class, 2);
        $r = Bus::dispatched(RecordSubscriptionRenderJob::class)->first()->receipt;
        $s->persist($r); DB::table(SubscriptionRenderEvidence::TABLE)->update(['generated_at' => time() - 86401]);
        $s->prune(); $this->assertSame(0, DB::table(SubscriptionRenderEvidence::TABLE)->count());
        Cache::put('machine_risk:render_budget:' . intdiv(time(), 60), 30, 120);
        $this->user->id = 999; $this->capture(); Bus::assertDispatchedTimes(RecordSubscriptionRenderJob::class, 2);
    }

    public static function renderers(): array
    {
        return array_map(fn ($name) => [$name], ['General', 'Clash', 'ClashMeta', 'KeliCore', 'Loon', 'QuantumultX',
            'Shadowrocket', 'Shadowsocks', 'SingBox', 'Stash', 'Surfboard', 'Surge']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renderers')]
    public function test_all_built_in_renderers_record_emitted_nodes_without_changing_content(string $name): void
    {
        $class = 'App\\Protocols\\' . $name;
        $user = ['uuid' => 'private-uuid', 'token' => 'private-token', 'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => time() + 3600];
        /** @var AbstractProtocol $plain */
        $plain = new $class($user, [$this->node()], strtolower($name), '9999.0.0');
        $expected = $plain->handle()->getContent(); $this->assertNull($plain->generatedNodeEvidence());
        $observed = new $class($user, [$this->node()], strtolower($name), '9999.0.0');
        $observed->observeGeneratedNodes(true); $actual = $observed->handle()->getContent();
        $this->assertSame($expected, $actual);
        $nodes = array_values($observed->generatedNodeEvidence()['nodes']);
        $this->assertCount(1, $nodes, $name); $this->assertSame(100, $nodes[0]['node_id']);
        $this->assertArrayNotHasKey('password', $nodes[0]);
    }

    public function test_renderer_rejection_is_not_misreported_as_emission_and_node_count_is_capped(): void
    {
        $class = \App\Protocols\QuantumultX::class;
        $protocol = new $class(['uuid' => 'test'], [$this->node(['type' => 'vless', 'protocol_settings' => ['network' => 'grpc', 'tls' => 1]])], 'quantumultx', '9999');
        $protocol->observeGeneratedNodes(true); $protocol->handle(); $this->assertSame([], $protocol->generatedNodeEvidence()['nodes']);
        $nodes = array_map(fn ($id) => $this->node(['id' => $id]), range(1, 70));
        $protocol = new \App\Protocols\General([], $nodes, 'general', '9999');
        $protocol->observeGeneratedNodes(true); $protocol->handle();
        $this->assertCount(64, $protocol->generatedNodeEvidence()['nodes']); $this->assertTrue($protocol->generatedNodeEvidence()['capped']);
        $protocol = new \App\Protocols\General([], [$this->node(), $this->node(['name' => 'Navigation alias'])], 'general', '9999');
        $protocol->observeGeneratedNodes(true); $protocol->handle(); $this->assertCount(1, $protocol->generatedNodeEvidence()['nodes']);
    }
}
