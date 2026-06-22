<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentDomainResolver;
use App\Services\AgentDomainSelfService;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentDomainSelfServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPaymentTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->bindTestSettings([
            'agent_center_domain_limit' => 1,
            'app_url' => 'https://sp.huhu.icu',
        ]);
    }

    public function test_active_agent_can_create_pending_domain_under_limit(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $payload = app(AgentDomainSelfService::class)->createPending(
            $agent,
            'https://Agent.Example.Test/path',
            'Primary storefront'
        );

        $this->assertSame('agent.example.test', $payload['domain']);
        $this->assertSame(AgentDomain::STATUS_PENDING, $payload['status']);
        $this->assertFalse($payload['is_primary']);
        $this->assertSame('Primary storefront', $payload['remark']);
        $this->assertSame('agent', $payload['source']);
        $this->assertSame('txt', $payload['verification']['type']);
        $this->assertSame('_keli-agent.agent.example.test', $payload['verification']['record_name']);
        $this->assertStringStartsWith('keli-agent-verification=', $payload['verification']['record_value']);

        $domain = AgentDomain::query()->first();
        $this->assertNotNull($domain);
        $this->assertSame($agent->id, (int) $domain->agent_user_id);
        $this->assertSame('agent.example.test', $domain->domain);
        $this->assertSame(AgentDomain::STATUS_PENDING, $domain->status);
    }

    public function test_duplicate_domain_fails(): void
    {
        $owner = $this->createActiveAgent('owner@example.test');
        $agent = $this->createActiveAgent('agent@example.test');
        $this->createDomain($owner, 'agent.example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        app(AgentDomainSelfService::class)->createPending($agent, 'https://agent.example.test', null);
    }

    public function test_insert_time_unique_conflict_maps_to_domain_already_assigned(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = new class extends AgentDomainSelfService {
            private bool $conflictInserted = false;

            protected function createDomainRow(array $attributes): AgentDomain
            {
                if (!$this->conflictInserted) {
                    $this->conflictInserted = true;
                    AgentDomain::query()->create(array_merge($attributes, [
                        'remark' => 'conflicting insert',
                    ]));
                }

                return parent::createDomainRow($attributes);
            }
        };

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        $service->createPending($agent, 'agent.example.test', null);
    }

    public function test_domain_limit_defaults_to_one(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);

        $service->createPending($agent, 'first.example.test', null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain limit reached');

        $service->createPending($agent, 'second.example.test', null);
    }

    public function test_negative_domain_limit_clamps_to_zero_and_blocks_create(): void
    {
        $this->bindTestSettings([
            'agent_center_domain_limit' => -5,
            'app_url' => 'https://sp.huhu.icu',
        ]);
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);

        $this->assertSame(0, $service->domainLimit());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain limit reached');

        $service->createPending($agent, 'agent.example.test', null);
    }

    public function test_invalid_hosts_fail(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);

        foreach (['127.0.0.1', 'localhost', '*.example.test', 'https:///bad'] as $host) {
            try {
                $service->createPending($agent, $host, null);
                $this->fail("Expected invalid domain exception for {$host}.");
            } catch (ApiException $exception) {
                $this->assertSame('Invalid domain', $exception->getMessage());
            }
        }

        $this->assertSame(0, AgentDomain::query()->count());
    }

    public function test_reserved_platform_host_from_app_url_fails(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid domain');

        app(AgentDomainSelfService::class)->createPending($agent, 'https://sp.huhu.icu', null);
    }

    public function test_reserved_subscribe_hosts_from_comma_separated_urls_fail(): void
    {
        $this->bindTestSettings([
            'agent_center_domain_limit' => 1,
            'app_url' => 'https://sp.huhu.icu',
            'subscribe_url' => 'https://sub1.example.test,https://sub2.example.test/path',
        ]);
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);

        foreach (['sub1.example.test', 'https://sub2.example.test'] as $host) {
            try {
                $service->createPending($agent, $host, null);
                $this->fail("Expected invalid domain exception for {$host}.");
            } catch (ApiException $exception) {
                $this->assertSame('Invalid domain', $exception->getMessage());
            }
        }

        $this->assertSame(0, AgentDomain::query()->count());
    }

    public function test_pending_domain_does_not_resolve_until_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        app(AgentDomainSelfService::class)->createPending($agent, 'agent.example.test', null);

        $this->assertNull(app(AgentDomainResolver::class)->resolveHost('agent.example.test'));
    }

    public function test_verify_activates_domain_when_txt_matches(): void
    {
        $txtRecords = [];
        $service = $this->serviceWithTxtRecords($txtRecords);
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $txtRecords[$pending['verification']['record_name']] = [
            $pending['verification']['record_value'],
        ];

        $verified = $service->verify($agent, $pending['id']);

        $this->assertSame(AgentDomain::STATUS_ACTIVE, $verified['status']);
        $this->assertNull($verified['verification_error']);
        $this->assertNotNull($verified['verified_at']);
        $context = app(AgentDomainResolver::class)->resolveHost('agent.example.test');
        $this->assertNotNull($context);
        $this->assertSame($agent->id, $context['agent_user_id']);
        $this->assertSame('agent.example.test', $context['domain']);
    }

    public function test_verify_uses_dns_over_https_when_system_txt_lookup_is_empty(): void
    {
        $recordName = null;
        $recordValue = null;
        Http::fake(function ($request) use (&$recordName, &$recordValue) {
            $this->assertSame('cloudflare-dns.com', $request->toPsrRequest()->getUri()->getHost());
            $this->assertSame($recordName, $request['name']);
            $this->assertSame('TXT', $request['type']);

            return Http::response([
                'Status' => 0,
                'Answer' => [
                    ['type' => 16, 'data' => '"' . $recordValue . '"'],
                ],
            ]);
        });
        $service = new class extends AgentDomainSelfService {
            protected function resolveSystemTxt(string $recordName): array
            {
                return [];
            }
        };
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $recordName = $pending['verification']['record_name'];
        $recordValue = $pending['verification']['record_value'];

        $verified = $service->verify($agent, $pending['id']);

        $this->assertSame(AgentDomain::STATUS_ACTIVE, $verified['status']);
        $this->assertNull($verified['verification_error']);
        Http::assertSentCount(1);
    }

    public function test_verify_uses_dns_over_https_when_system_txt_lookup_is_stale(): void
    {
        $recordName = null;
        $recordValue = null;
        Http::fake(function ($request) use (&$recordName, &$recordValue) {
            $this->assertSame($recordName, $request['name']);

            return Http::response([
                'Status' => 0,
                'Answer' => [
                    ['type' => 16, 'data' => '"' . $recordValue . '"'],
                ],
            ]);
        });
        $service = new class extends AgentDomainSelfService {
            protected function resolveSystemTxt(string $recordName): array
            {
                return ['keli-agent-verification=old-token'];
            }
        };
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $recordName = $pending['verification']['record_name'];
        $recordValue = $pending['verification']['record_value'];

        $verified = $service->verify($agent, $pending['id']);

        $this->assertSame(AgentDomain::STATUS_ACTIVE, $verified['status']);
        $this->assertNull($verified['verification_error']);
        Http::assertSentCount(1);
    }

    public function test_verify_rechecks_locked_domain_state_before_activating(): void
    {
        $txtRecords = [];
        $domainId = null;
        $service = new AgentDomainSelfService(
            static function (string $name) use (&$txtRecords, &$domainId): array {
                if ($domainId !== null) {
                    AgentDomain::query()
                        ->where('id', $domainId)
                        ->update([
                            'status' => AgentDomain::STATUS_ACTIVE,
                            'updated_at' => time(),
                        ]);
                }

                return $txtRecords[$name] ?? [];
            }
        );
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $domainId = $pending['id'];
        $txtRecords[$pending['verification']['record_name']] = [
            $pending['verification']['record_value'],
        ];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain verification is unavailable');

        $service->verify($agent, $pending['id']);
    }

    public function test_verify_rechecks_active_agent_profile_before_activating(): void
    {
        $txtRecords = [];
        $agent = $this->createActiveAgent('agent@example.test');
        $service = new AgentDomainSelfService(
            static function (string $name) use (&$txtRecords, $agent): array {
                AgentProfile::query()
                    ->where('user_id', $agent->id)
                    ->update([
                        'status' => AgentCenterService::STATUS_DISABLED,
                        'disabled_at' => time(),
                        'updated_at' => time(),
                    ]);

                return $txtRecords[$name] ?? [];
            }
        );
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $txtRecords[$pending['verification']['record_name']] = [
            $pending['verification']['record_value'],
        ];

        try {
            $service->verify($agent, $pending['id']);
            $this->fail('Expected inactive agent exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Agent permission is not active', $exception->getMessage());
        }

        $domain = AgentDomain::query()->find($pending['id']);
        $this->assertNotNull($domain);
        $this->assertSame(AgentDomain::STATUS_PENDING, $domain->status);
        $this->assertNull($domain->verified_at);
    }

    public function test_verify_rechecks_locked_proof_snapshot_before_activating(): void
    {
        $domainId = null;
        $oldRecordValue = null;
        $service = new AgentDomainSelfService(
            static function (string $name) use (&$domainId, &$oldRecordValue): array {
                if ($domainId !== null) {
                    AgentDomain::query()
                        ->where('id', $domainId)
                        ->update([
                            'verification_token' => 'changed-token',
                            'updated_at' => time(),
                        ]);
                }

                return [$oldRecordValue];
            }
        );
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $domainId = $pending['id'];
        $oldRecordValue = $pending['verification']['record_value'];

        try {
            $service->verify($agent, $pending['id']);
            $this->fail('Expected verification unavailable exception for stale TXT proof.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain verification is unavailable', $exception->getMessage());
        }

        $domain = AgentDomain::query()->find($pending['id']);
        $this->assertNotNull($domain);
        $this->assertSame(AgentDomain::STATUS_PENDING, $domain->status);
        $this->assertSame('changed-token', $domain->verification_token);
        $this->assertNull($domain->verified_at);
    }

    public function test_verify_rechecks_locked_domain_snapshot_before_activating(): void
    {
        $domainId = null;
        $oldRecordValue = null;
        $service = new AgentDomainSelfService(
            static function (string $name) use (&$domainId, &$oldRecordValue): array {
                if ($domainId !== null) {
                    AgentDomain::query()
                        ->where('id', $domainId)
                        ->update([
                            'domain' => 'changed.example.test',
                            'updated_at' => time(),
                        ]);
                }

                return [$oldRecordValue];
            }
        );
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);
        $domainId = $pending['id'];
        $oldRecordValue = $pending['verification']['record_value'];

        try {
            $service->verify($agent, $pending['id']);
            $this->fail('Expected verification unavailable exception for stale domain proof.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain verification is unavailable', $exception->getMessage());
        }

        $domain = AgentDomain::query()->find($pending['id']);
        $this->assertNotNull($domain);
        $this->assertSame(AgentDomain::STATUS_PENDING, $domain->status);
        $this->assertSame('changed.example.test', $domain->domain);
        $this->assertNull($domain->verified_at);
    }

    public function test_verify_fails_safely_when_txt_missing_or_wrong(): void
    {
        $txtRecords = [];
        $service = $this->serviceWithTxtRecords($txtRecords);
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = $service->createPending($agent, 'agent.example.test', null);

        try {
            $service->verify($agent, $pending['id']);
            $this->fail('Expected missing TXT verification exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain verification record not found', $exception->getMessage());
        }

        $domain = AgentDomain::query()->find($pending['id']);
        $this->assertNotNull($domain);
        $this->assertSame(AgentDomain::STATUS_PENDING, $domain->status);
        $this->assertNotNull($domain->last_checked_at);
        $this->assertSame('Domain verification record not found', $domain->verification_error);

        $txtRecords[$pending['verification']['record_name']] = ['keli-agent-verification=wrong'];

        try {
            $service->verify($agent, $pending['id']);
            $this->fail('Expected wrong TXT verification exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain verification record not found', $exception->getMessage());
        }

        $domain->refresh();
        $this->assertSame(AgentDomain::STATUS_PENDING, $domain->status);
        $this->assertSame('Domain verification record not found', $domain->verification_error);
    }

    public function test_admin_created_domain_without_token_cannot_be_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain verification is unavailable');

        app(AgentDomainSelfService::class)->verify($agent, $domain->id);
    }

    public function test_pending_admin_created_domain_with_token_cannot_be_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test', AgentDomain::STATUS_PENDING, [
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'admin-token',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain verification is unavailable');

        app(AgentDomainSelfService::class)->verify($agent, $domain->id);
    }

    public function test_pending_self_service_domain_with_wrong_verification_type_cannot_be_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test', AgentDomain::STATUS_PENDING, [
            'created_by_agent_id' => $agent->id,
            'verification_type' => 'cname',
            'verification_token' => 'agent-token',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain verification is unavailable');

        app(AgentDomainSelfService::class)->verify($agent, $domain->id);
    }

    public function test_pending_self_service_domain_without_token_cannot_be_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);

        foreach (['blank' => '', 'null' => null] as $label => $token) {
            $domain = $this->createDomain($agent, "{$label}.example.test", AgentDomain::STATUS_PENDING, [
                'created_by_agent_id' => $agent->id,
                'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
                'verification_token' => $token,
            ]);

            try {
                $service->verify($agent, $domain->id);
                $this->fail("Expected verification unavailable exception for {$label} token.");
            } catch (ApiException $exception) {
                $this->assertSame('Domain verification is unavailable', $exception->getMessage());
            }
        }
    }

    public function test_non_pending_self_service_domain_cannot_be_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);

        foreach ([AgentDomain::STATUS_ACTIVE, AgentDomain::STATUS_DISABLED] as $status) {
            $domain = $this->createDomain($agent, "{$status}.example.test", $status, [
                'created_by_agent_id' => $agent->id,
                'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
                'verification_token' => "{$status}-token",
            ]);

            try {
                $service->verify($agent, $domain->id);
                $this->fail("Expected verification unavailable exception for {$status} domain.");
            } catch (ApiException $exception) {
                $this->assertSame('Domain verification is unavailable', $exception->getMessage());
            }
        }
    }

    public function test_agent_cannot_delete_another_agents_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        $domain = $this->createDomain($otherAgent, 'other.example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain does not exist');

        app(AgentDomainSelfService::class)->delete($agent, $domain->id);
    }

    public function test_delete_rechecks_active_agent_profile_before_deleting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test', AgentDomain::STATUS_ACTIVE, [
            'created_by_agent_id' => $agent->id,
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'agent-token',
        ]);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update([
                'status' => AgentCenterService::STATUS_DISABLED,
                'disabled_at' => time(),
                'updated_at' => time(),
            ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent permission is not active');

        try {
            app(AgentDomainSelfService::class)->delete($agent, $domain->id);
        } finally {
            $this->assertSame(1, AgentDomain::query()->where('id', $domain->id)->count());
        }
    }

    public function test_admin_created_owned_domain_cannot_be_deleted(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain cannot be deleted');

        app(AgentDomainSelfService::class)->delete($agent, $domain->id);
    }

    public function test_enabled_agent_payment_from_another_owner_blocks_domain_delete(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test', AgentDomain::STATUS_ACTIVE, [
            'created_by_agent_id' => $agent->id,
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'agent-token',
        ]);
        Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $otherAgent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => 'dirtyref',
            'payment' => 'FAKEPAY',
            'name' => 'Dirty Ref Pay',
            'config' => [],
            'enable' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain is used by an enabled payment method');

        app(AgentDomainSelfService::class)->delete($agent, $domain->id);
    }

    public function test_payload_source_uses_non_null_agent_creator_and_hides_stale_proof_values(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $service = app(AgentDomainSelfService::class);
        $zeroSource = $this->createDomain($agent, 'zero.example.test', AgentDomain::STATUS_PENDING, [
            'created_by_agent_id' => 0,
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'zero-token',
        ]);
        $active = $this->createDomain($agent, 'active.example.test', AgentDomain::STATUS_ACTIVE, [
            'created_by_agent_id' => $agent->id,
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'active-token',
        ]);
        $admin = $this->createDomain($agent, 'admin.example.test', AgentDomain::STATUS_PENDING, [
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'admin-token',
        ]);

        $this->assertSame('agent', $service->payload($zeroSource)['source']);
        $this->assertSame(
            AgentDomainSelfService::VALUE_PREFIX . 'zero-token',
            $service->payload($zeroSource)['verification']['record_value']
        );
        $this->assertSame('', $service->payload($active)['verification']['record_value']);
        $this->assertSame('', $service->payload($admin)['verification']['record_value']);
    }

    public function test_active_domain_bound_to_enabled_agent_payment_cannot_be_deleted(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createDomain($agent, 'agent.example.test', AgentDomain::STATUS_ACTIVE, [
            'created_by_agent_id' => $agent->id,
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'verification_token' => 'agent-token',
        ]);
        Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => 'agentpay1',
            'payment' => 'FAKEPAY',
            'name' => 'Agent Pay',
            'config' => [],
            'enable' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain is used by an enabled payment method');

        app(AgentDomainSelfService::class)->delete($agent, $domain->id);
    }

    private function serviceWithTxtRecords(array &$txtRecords): AgentDomainSelfService
    {
        return new AgentDomainSelfService(
            static function (string $name) use (&$txtRecords): array {
                return $txtRecords[$name] ?? [];
            }
        );
    }

    private function createActiveAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 10000,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createDomain(
        User $agent,
        string $domain,
        string $status = AgentDomain::STATUS_PENDING,
        array $attributes = []
    ): AgentDomain {
        return AgentDomain::query()->create(array_merge([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => $status,
            'is_primary' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }
}
