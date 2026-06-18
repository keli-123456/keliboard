# Agent Self-Service Domains Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let active agents self-add one verified storefront domain by default, with DNS TXT ownership checks before the domain can route storefront traffic.

**Architecture:** Extend the existing agent-domain model with verification metadata and add a focused `AgentDomainSelfService` backend service. User APIs create pending domains, verify TXT records, and delete owned domains; admin APIs continue to manage all domains and expose verification state. Frontend changes add compact self-service controls in the existing agent-center domains tab and show verification state in admin monitoring.

**Tech Stack:** Laravel PHP services/controllers/routes/migrations, PHPUnit in-memory tests with injectable DNS resolver, React TypeScript in `keli-user` and `keli-admin`, Vitest helper tests, Vite builds.

---

## Files And Responsibilities

- Create `keliboard/database/migrations/2026_06_18_000002_extend_agent_domain_verification.php`: add self-service verification fields.
- Modify `keliboard/app/Models/AgentDomain.php`: add `pending` status and casts for verification timestamps.
- Modify `keliboard/tests/Support/InteractsWithInMemoryDatabase.php`: add the same columns to in-memory `v2_agent_domain`.
- Create `keliboard/app/Services/AgentDomainSelfService.php`: domain validation, limit enforcement, pending creation, DNS TXT verification, and owner-safe deletion.
- Create `keliboard/tests/Unit/Services/AgentDomainSelfServiceTest.php`: backend behavior coverage.
- Modify `keliboard/app/Http/Controllers/V1/User/AgentCommerceController.php`: add user-side create/verify/delete domain endpoints and richer domain payloads.
- Modify `keliboard/app/Http/Routes/V1/UserRoute.php`: register user-side domain routes.
- Modify `keliboard/app/Services/AgentPaymentService.php`: only allow active domains in payment binding/listing.
- Modify `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`: include verification fields and source in domain payload.
- Modify `keliboard/app/Http/Requests/Admin/ConfigSave.php` and `keliboard/app/Http/Controllers/V2/Admin/ConfigController.php`: expose `agent_center_domain_limit` with default `1`.
- Modify `keli-user/src/services/agentCommerce.ts`: add domain create/verify/delete APIs and types.
- Modify `keli-user/src/lib/agentDomain.ts`: add helper formatting DNS instructions and domain counts.
- Add `keli-user/src/lib/agentDomain.test.ts`: frontend helper tests.
- Modify `keli-user/src/pages/AgentCenterPage.tsx`: add self-service domain UI in existing domains tab.
- Modify `keli-user/src/locales/zh/translation.json` and `keli-user/src/locales/en/translation.json`: add user-facing domain texts.
- Modify `keli-admin/src/services/agentCommerce.ts`: add verification fields to admin domain type.
- Modify `keli-admin/src/pages/agent/agentCommerceDisplay.ts` and `.test.ts`: add domain source/status display helpers.
- Modify `keli-admin/src/pages/agent/AgentCommercePage.tsx`: show self-service source and verification metadata.
- Modify `keli-admin/src/locales/zh/translation.json` and `keli-admin/src/locales/en/translation.json`: add admin texts.

---

## Task 1: Domain Schema And Model

**Files:**
- Create: `keliboard/database/migrations/2026_06_18_000002_extend_agent_domain_verification.php`
- Modify: `keliboard/app/Models/AgentDomain.php`
- Modify: `keliboard/tests/Support/InteractsWithInMemoryDatabase.php`

- [ ] **Step 1: Create the migration**

Create `database/migrations/2026_06_18_000002_extend_agent_domain_verification.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_agent_domain', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_agent_domain', 'verification_token')) {
                $table->string('verification_token', 128)->nullable()->after('remark');
            }
            if (!Schema::hasColumn('v2_agent_domain', 'verification_type')) {
                $table->string('verification_type', 16)->nullable()->after('verification_token');
            }
            if (!Schema::hasColumn('v2_agent_domain', 'verified_at')) {
                $table->integer('verified_at')->nullable()->after('verification_type');
            }
            if (!Schema::hasColumn('v2_agent_domain', 'last_checked_at')) {
                $table->integer('last_checked_at')->nullable()->after('verified_at');
            }
            if (!Schema::hasColumn('v2_agent_domain', 'verification_error')) {
                $table->string('verification_error', 255)->nullable()->after('last_checked_at');
            }
            if (!Schema::hasColumn('v2_agent_domain', 'created_by_agent_id')) {
                $table->integer('created_by_agent_id')->nullable()->index()->after('created_by_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_agent_domain', function (Blueprint $table): void {
            foreach ([
                'verification_token',
                'verification_type',
                'verified_at',
                'last_checked_at',
                'verification_error',
                'created_by_agent_id',
            ] as $column) {
                if (Schema::hasColumn('v2_agent_domain', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
```

- [ ] **Step 2: Update `AgentDomain` constants and casts**

In `app/Models/AgentDomain.php`, add:

```php
public const STATUS_PENDING = 'pending';
```

and extend `$casts`:

```php
'verified_at' => 'timestamp',
'last_checked_at' => 'timestamp',
```

- [ ] **Step 3: Update in-memory schema**

In `tests/Support/InteractsWithInMemoryDatabase.php`, inside `createAgentCommerceTables()` and the `v2_agent_domain` schema, add:

```php
$table->string('verification_token', 128)->nullable();
$table->string('verification_type', 16)->nullable();
$table->integer('verified_at')->nullable();
$table->integer('last_checked_at')->nullable();
$table->string('verification_error', 255)->nullable();
$table->integer('created_by_agent_id')->nullable()->index();
```

- [ ] **Step 4: Commit schema work**

Run:

```bash
git add database/migrations/2026_06_18_000002_extend_agent_domain_verification.php app/Models/AgentDomain.php tests/Support/InteractsWithInMemoryDatabase.php
git commit -m "Add agent domain verification fields"
```

---

## Task 2: Backend Self-Service Domain Service

**Files:**
- Create: `keliboard/app/Services/AgentDomainSelfService.php`
- Create: `keliboard/tests/Unit/Services/AgentDomainSelfServiceTest.php`

- [ ] **Step 1: Write failing service tests**

Create `tests/Unit/Services/AgentDomainSelfServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentDomainSelfService;
use App\Services\AgentDomainResolver;
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

    public function test_agent_can_create_pending_domain_under_limit(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $payload = $this->service()->createPending($agent, 'https://Agent.Example.Test/path', 'shop');

        $this->assertSame('agent.example.test', $payload['domain']);
        $this->assertSame(AgentDomain::STATUS_PENDING, $payload['status']);
        $this->assertSame('_keli-agent.agent.example.test', $payload['verification']['record_name']);
        $this->assertStringStartsWith('keli-agent-verification=', $payload['verification']['record_value']);
        $this->assertSame(1, AgentDomain::query()->where('agent_user_id', $agent->id)->count());
    }

    public function test_duplicate_domain_fails(): void
    {
        $firstAgent = $this->createActiveAgent('first@example.test');
        $secondAgent = $this->createActiveAgent('second@example.test');
        $this->service()->createPending($firstAgent, 'agent.example.test', '');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        $this->service()->createPending($secondAgent, 'agent.example.test', '');
    }

    public function test_domain_limit_defaults_to_one(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $this->service()->createPending($agent, 'one.example.test', '');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain limit reached');

        $this->service()->createPending($agent, 'two.example.test', '');
    }

    public function test_rejects_invalid_hosts(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        foreach (['127.0.0.1', 'localhost', '*.example.test', 'https:///bad'] as $host) {
            try {
                $this->service()->createPending($agent, $host, '');
                $this->fail('Expected invalid domain for ' . $host);
            } catch (ApiException $exception) {
                $this->assertSame('Invalid domain', $exception->getMessage());
            }
        }
    }

    public function test_rejects_reserved_platform_host(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid domain');

        $this->service()->createPending($agent, 'sp.huhu.icu', '');
    }

    public function test_pending_domain_does_not_resolve_until_verified(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $this->service()->createPending($agent, 'agent.example.test', '');

        $this->assertNull(app(AgentDomainResolver::class)->resolveHost('agent.example.test'));
    }

    public function test_verify_activates_domain_when_txt_matches(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $payload = $this->service()->createPending($agent, 'agent.example.test', '');
        $token = str_replace('keli-agent-verification=', '', $payload['verification']['record_value']);

        $verified = $this->service([
            '_keli-agent.agent.example.test' => ['keli-agent-verification=' . $token],
        ])->verify($agent, (int) $payload['id']);

        $this->assertSame(AgentDomain::STATUS_ACTIVE, $verified['status']);
        $this->assertNotNull($verified['verified_at']);
        $this->assertSame($agent->id, app(AgentDomainResolver::class)->resolveHost('agent.example.test')['agent_user_id']);
    }

    public function test_verify_fails_safely_when_txt_missing(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $payload = $this->service()->createPending($agent, 'agent.example.test', '');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain verification record not found');

        $this->service(['_keli-agent.agent.example.test' => ['wrong']])->verify($agent, (int) $payload['id']);
    }

    public function test_agent_cannot_delete_another_agents_domain(): void
    {
        $owner = $this->createActiveAgent('owner@example.test');
        $other = $this->createActiveAgent('other@example.test');
        $payload = $this->service()->createPending($owner, 'agent.example.test', '');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain does not exist');

        $this->service()->delete($other, (int) $payload['id']);
    }

    public function test_active_domain_bound_to_enabled_payment_cannot_be_deleted(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $payload = $this->service()->createPending($agent, 'agent.example.test', '');
        $domain = AgentDomain::query()->find($payload['id']);
        $domain->status = AgentDomain::STATUS_ACTIVE;
        $domain->verified_at = time();
        $domain->save();
        Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => 'agentpay000000000000000000000001',
            'payment' => 'FAKEPAY',
            'name' => 'Agent Pay',
            'config' => [],
            'enable' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain is used by an enabled payment method');

        $this->service()->delete($agent, $domain->id);
    }

    private function service(array $txtRecords = []): AgentDomainSelfService
    {
        return new AgentDomainSelfService(
            static fn (string $name): array => $txtRecords[$name] ?? []
        );
    }

    private function createActiveAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
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
}
```

- [ ] **Step 2: Run test and verify it fails**

Run on the test machine or local PHP environment:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentDomainSelfServiceTest
```

Expected: FAIL because `App\Services\AgentDomainSelfService` does not exist.

- [ ] **Step 3: Implement `AgentDomainSelfService`**

Create `app/Services/AgentDomainSelfService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;

class AgentDomainSelfService
{
    public const VERIFICATION_TYPE_TXT = 'txt';
    public const RECORD_PREFIX = '_keli-agent.';
    public const VALUE_PREFIX = 'keli-agent-verification=';

    public function __construct(private $txtResolver = null) {}

    public function createPending(User $agent, string $rawDomain, ?string $remark): array
    {
        $this->assertActiveAgent($agent);
        $domain = $this->normalizeAndValidateDomain($rawDomain);
        $this->assertDomainAvailable($domain);
        $this->assertUnderLimit($agent);

        $now = time();
        $token = Str::random(48);
        $row = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'remark' => $remark ? mb_substr($remark, 0, 255) : null,
            'verification_token' => $token,
            'verification_type' => self::VERIFICATION_TYPE_TXT,
            'created_by_agent_id' => $agent->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->payload($row);
    }

    public function verify(User $agent, int $id): array
    {
        $domain = $this->ownedDomain($agent, $id);
        $recordName = $this->recordName((string) $domain->domain);
        $expected = $this->recordValue((string) $domain->verification_token);
        $now = time();

        try {
            $records = $this->resolveTxt($recordName);
        } catch (\Throwable) {
            $domain->last_checked_at = $now;
            $domain->verification_error = 'DNS lookup failed, try again';
            $domain->updated_at = $now;
            $domain->save();
            throw new ApiException('DNS lookup failed, try again');
        }

        if (!in_array($expected, $records, true)) {
            $domain->last_checked_at = $now;
            $domain->verification_error = 'Domain verification record not found';
            $domain->updated_at = $now;
            $domain->save();
            throw new ApiException('Domain verification record not found');
        }

        $domain->status = AgentDomain::STATUS_ACTIVE;
        $domain->verified_at = $now;
        $domain->last_checked_at = $now;
        $domain->verification_error = null;
        $domain->updated_at = $now;
        $domain->save();

        return $this->payload($domain);
    }

    public function delete(User $agent, int $id): bool
    {
        $domain = $this->ownedDomain($agent, $id);

        $used = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_id', $agent->id)
            ->where('owner_domain_id', $domain->id)
            ->where('enable', true)
            ->exists();
        if ($used) {
            throw new ApiException('Domain is used by an enabled payment method');
        }

        $domain->delete();

        return true;
    }

    public function payload(AgentDomain $domain): array
    {
        $token = (string) $domain->verification_token;
        return [
            'id' => (int) $domain->id,
            'agent_user_id' => (int) $domain->agent_user_id,
            'domain' => (string) $domain->domain,
            'status' => (string) $domain->status,
            'is_primary' => (bool) $domain->is_primary,
            'remark' => $domain->remark,
            'source' => $domain->created_by_agent_id ? 'agent' : 'admin',
            'verified_at' => $domain->verified_at ? (int) $domain->verified_at : null,
            'last_checked_at' => $domain->last_checked_at ? (int) $domain->last_checked_at : null,
            'verification_error' => $domain->verification_error,
            'verification' => [
                'type' => self::VERIFICATION_TYPE_TXT,
                'record_name' => $this->recordName((string) $domain->domain),
                'record_value' => $token !== '' ? $this->recordValue($token) : '',
            ],
        ];
    }

    public function domainLimit(): int
    {
        return max(0, (int) admin_setting('agent_center_domain_limit', 1));
    }

    private function assertActiveAgent(User $agent): void
    {
        $profile = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->first();
        if (!$profile) {
            throw new ApiException('Agent permission is not active');
        }
    }

    private function normalizeAndValidateDomain(string $rawDomain): string
    {
        $domain = app(AgentDomainResolver::class)->normalizeHost($rawDomain);
        if ($domain === '' || $domain === 'localhost' || str_contains($domain, '*') || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new ApiException('Invalid domain');
        }
        if ($this->isReservedHost($domain)) {
            throw new ApiException('Invalid domain');
        }
        return $domain;
    }

    private function isReservedHost(string $domain): bool
    {
        foreach (['app_url', 'subscribe_url'] as $key) {
            $value = trim((string) admin_setting($key, ''));
            if ($value === '') {
                continue;
            }
            $host = app(AgentDomainResolver::class)->normalizeHost($value);
            if ($host !== '' && $host === $domain) {
                return true;
            }
        }
        return false;
    }

    private function assertDomainAvailable(string $domain): void
    {
        if (AgentDomain::query()->where('domain', $domain)->exists()) {
            throw new ApiException('Domain already assigned');
        }
    }

    private function assertUnderLimit(User $agent): void
    {
        $limit = $this->domainLimit();
        if ($limit <= 0) {
            throw new ApiException('Domain limit reached');
        }
        $count = AgentDomain::query()->where('agent_user_id', $agent->id)->count();
        if ($count >= $limit) {
            throw new ApiException('Domain limit reached');
        }
    }

    private function ownedDomain(User $agent, int $id): AgentDomain
    {
        $domain = AgentDomain::query()
            ->where('id', $id)
            ->where('agent_user_id', $agent->id)
            ->first();
        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }
        return $domain;
    }

    private function recordName(string $domain): string
    {
        return self::RECORD_PREFIX . $domain;
    }

    private function recordValue(string $token): string
    {
        return self::VALUE_PREFIX . $token;
    }

    private function resolveTxt(string $recordName): array
    {
        if (is_callable($this->txtResolver)) {
            return array_values(array_map('strval', call_user_func($this->txtResolver, $recordName)));
        }

        $records = dns_get_record($recordName, DNS_TXT) ?: [];
        return array_values(array_filter(array_map(
            static fn (array $record): string => (string) ($record['txt'] ?? ''),
            $records
        )));
    }
}
```

- [ ] **Step 4: Run service tests and verify pass**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentDomainSelfServiceTest
```

Expected: PASS.

- [ ] **Step 5: Commit backend service**

Run:

```bash
git add app/Services/AgentDomainSelfService.php tests/Unit/Services/AgentDomainSelfServiceTest.php
git commit -m "Add agent self-service domain service"
```

---

## Task 3: User API And Payment Domain Filtering

**Files:**
- Modify: `keliboard/app/Http/Controllers/V1/User/AgentCommerceController.php`
- Modify: `keliboard/app/Http/Routes/V1/UserRoute.php`
- Modify: `keliboard/app/Services/AgentPaymentService.php`
- Modify: `keliboard/tests/Unit/Services/AgentDomainSelfServiceTest.php`

- [ ] **Step 1: Add payment-selector test**

In `tests/Unit/Services/AgentDomainSelfServiceTest.php`, add:

```php
public function test_pending_domains_are_not_available_for_payment_binding(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    $pending = $this->service()->createPending($agent, 'pending.example.test', '');
    $active = $this->service()->createPending($agent, 'active.example.test', '');
    $activeDomain = AgentDomain::query()->find($active['id']);
    $activeDomain->status = AgentDomain::STATUS_ACTIVE;
    $activeDomain->verified_at = time();
    $activeDomain->save();

    $domains = AgentDomain::query()
        ->where('agent_user_id', $agent->id)
        ->where('status', AgentDomain::STATUS_ACTIVE)
        ->pluck('domain', 'id')
        ->all();

    $this->assertArrayNotHasKey($pending['id'], $domains);
    $this->assertSame('active.example.test', $domains[$active['id']]);
}
```

- [ ] **Step 2: Run service test and verify it passes**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentDomainSelfServiceTest
```

Expected: PASS. This test documents the query rule used by payment binding.

- [ ] **Step 3: Wire user controller methods**

In `app/Http/Controllers/V1/User/AgentCommerceController.php`, add:

```php
use App\Services\AgentDomainSelfService;
```

Add methods:

```php
public function saveDomain(Request $request)
{
    $params = $request->validate([
        'domain' => 'required|string|max:255',
        'remark' => 'nullable|string|max:255',
    ]);

    return $this->success($this->domainService()->createPending(
        $request->user(),
        (string) $params['domain'],
        $params['remark'] ?? null
    ));
}

public function verifyDomain(Request $request, int $id)
{
    return $this->success($this->domainService()->verify($request->user(), $id));
}

public function deleteDomain(Request $request, int $id)
{
    return $this->success($this->domainService()->delete($request->user(), $id));
}

private function domainService(): AgentDomainSelfService
{
    return app(AgentDomainSelfService::class);
}
```

Update `domainList()` payload mapping to use the service:

```php
->map(fn (AgentDomain $domain): array => $this->domainService()->payload($domain))
```

Return the count limit in `commerceSummary()`:

```php
'domain_limit' => $this->domainService()->domainLimit(),
```

- [ ] **Step 4: Register user routes**

In `app/Http/Routes/V1/UserRoute.php`, after the existing `GET /agent/domains` route add:

```php
$router->post('/agent/domains', [AgentCommerceController::class, 'saveDomain']);
$router->post('/agent/domains/{id}/verify', [AgentCommerceController::class, 'verifyDomain']);
$router->post('/agent/domains/{id}/delete', [AgentCommerceController::class, 'deleteDomain']);
```

- [ ] **Step 5: Filter payment domain ids to active domains**

In `app/Services/AgentPaymentService.php`, find the owner-domain validation. Ensure the selected `owner_domain_id` query includes:

```php
->where('status', AgentDomain::STATUS_ACTIVE)
```

Also ensure any domain list exposed to agents for payment binding filters active status only.

- [ ] **Step 6: Run backend tests**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentDomainSelfServiceTest
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceServiceTest
```

Expected: PASS.

- [ ] **Step 7: Commit user API**

Run:

```bash
git add app/Http/Controllers/V1/User/AgentCommerceController.php app/Http/Routes/V1/UserRoute.php app/Services/AgentPaymentService.php tests/Unit/Services/AgentDomainSelfServiceTest.php
git commit -m "Expose agent self-service domain APIs"
```

---

## Task 4: Admin Config And Domain Monitoring

**Files:**
- Modify: `keliboard/app/Http/Requests/Admin/ConfigSave.php`
- Modify: `keliboard/app/Http/Controllers/V2/Admin/ConfigController.php`
- Modify: `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`
- Modify: `keliboard/tests/Unit/Http/AdminAgentCommerceControllerTest.php`

- [ ] **Step 1: Add admin payload test assertions**

In `tests/Unit/Http/AdminAgentCommerceControllerTest.php`, when creating the fixture `AgentDomain`, include:

```php
'status' => AgentDomain::STATUS_PENDING,
'verification_token' => 'token-123',
'verification_type' => 'txt',
'verified_at' => null,
'last_checked_at' => 1710000000,
'verification_error' => 'Domain verification record not found',
'created_by_agent_id' => $agent->id,
```

Then assert:

```php
$domains = $this->responsePayload($controller->domains())['data'];
$this->assertSame('agent', $domains[0]['source']);
$this->assertSame('txt', $domains[0]['verification_type']);
$this->assertSame(1710000000, $domains[0]['last_checked_at']);
$this->assertSame('Domain verification record not found', $domains[0]['verification_error']);
```

- [ ] **Step 2: Run admin test and verify it fails**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest
```

Expected: FAIL because the new payload fields are not returned.

- [ ] **Step 3: Add config validation and read default**

In `app/Http/Requests/Admin/ConfigSave.php`, add:

```php
'agent_center_domain_limit' => 'integer|min:0|max:1000',
```

In `app/Http/Controllers/V2/Admin/ConfigController.php`, where agent center config is returned, add:

```php
'agent_center_domain_limit' => max(0, (int) admin_setting('agent_center_domain_limit', 1)),
```

- [ ] **Step 4: Add admin domain payload fields**

In `app/Http/Controllers/V2/Admin/AgentCommerceController.php`, update `domainPayload()` to include:

```php
'source' => $domain->created_by_agent_id ? 'agent' : 'admin',
'verification_type' => (string) ($domain->verification_type ?? ''),
'verified_at' => $this->timestampValue($domain->verified_at),
'last_checked_at' => $this->timestampValue($domain->last_checked_at),
'verification_error' => (string) ($domain->verification_error ?? ''),
```

Do not return `verification_token` in admin list responses.

- [ ] **Step 5: Run admin test and verify pass**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest
```

Expected: PASS.

- [ ] **Step 6: Commit admin backend support**

Run:

```bash
git add app/Http/Requests/Admin/ConfigSave.php app/Http/Controllers/V2/Admin/ConfigController.php app/Http/Controllers/V2/Admin/AgentCommerceController.php tests/Unit/Http/AdminAgentCommerceControllerTest.php
git commit -m "Expose agent domain verification state"
```

---

## Task 5: User Frontend Domain UI

**Files:**
- Modify: `keli-user/src/services/agentCommerce.ts`
- Create: `keli-user/src/lib/agentDomain.ts`
- Create: `keli-user/src/lib/agentDomain.test.ts`
- Modify: `keli-user/src/pages/AgentCenterPage.tsx`
- Modify: `keli-user/src/locales/zh/translation.json`
- Modify: `keli-user/src/locales/en/translation.json`

- [ ] **Step 1: Add frontend helper test**

Create `src/lib/agentDomain.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import { buildDomainLimitSummary, getDomainStatusTone } from './agentDomain';

describe('agent domain helpers', () => {
  it('builds domain limit summary', () => {
    expect(buildDomainLimitSummary(1, 1)).toEqual({ used: 1, limit: 1, reached: true });
    expect(buildDomainLimitSummary(0, 1)).toEqual({ used: 0, limit: 1, reached: false });
  });

  it('maps domain statuses to tones', () => {
    expect(getDomainStatusTone('active')).toBe('success');
    expect(getDomainStatusTone('pending')).toBe('warning');
    expect(getDomainStatusTone('disabled')).toBe('neutral');
  });
});
```

- [ ] **Step 2: Run helper test and verify it fails**

Run:

```bash
npm run test -- agentDomain
```

Expected: FAIL because `src/lib/agentDomain.ts` does not exist.

- [ ] **Step 3: Implement helper**

Create `src/lib/agentDomain.ts`:

```ts
export const buildDomainLimitSummary = (used: number, limit: number) => {
  const safeUsed = Math.max(0, Number.isFinite(Number(used)) ? Number(used) : 0);
  const safeLimit = Math.max(0, Number.isFinite(Number(limit)) ? Number(limit) : 0);
  return {
    used: safeUsed,
    limit: safeLimit,
    reached: safeLimit <= 0 || safeUsed >= safeLimit,
  };
};

export const getDomainStatusTone = (status?: string | null) => {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'active') return 'success' as const;
  if (normalized === 'pending') return 'warning' as const;
  return 'neutral' as const;
};
```

- [ ] **Step 4: Update service types and APIs**

In `src/services/agentCommerce.ts`, extend `AgentDomainRow`:

```ts
source?: 'agent' | 'admin' | string;
verified_at?: number | null;
last_checked_at?: number | null;
verification_error?: string | null;
verification?: {
  type: string;
  record_name: string;
  record_value: string;
};
```

Add methods:

```ts
saveDomain(payload: { domain: string; remark?: string }) {
  return api.post('/user/agent/domains', payload);
},

verifyDomain(id: number) {
  return api.post(`/user/agent/domains/${id}/verify`);
},

deleteDomain(id: number) {
  return api.post(`/user/agent/domains/${id}/delete`);
},
```

- [ ] **Step 5: Update AgentCenterPage state and handlers**

In `src/pages/AgentCenterPage.tsx`, add states:

```ts
const [domainDialogOpen, setDomainDialogOpen] = useState(false);
const [domainInput, setDomainInput] = useState('');
const [domainRemark, setDomainRemark] = useState('');
const [domainSaving, setDomainSaving] = useState(false);
const [domainBusyId, setDomainBusyId] = useState<number | null>(null);
```

Add handlers:

```ts
const saveAgentDomain = async () => {
  const domain = domainInput.trim();
  if (!domain) {
    notify.error(t('agentCenter.domainRequired'));
    return;
  }
  setDomainSaving(true);
  try {
    await agentCommerceService.saveDomain({ domain, remark: domainRemark.trim() || undefined });
    notify.success(t('agentCenter.domainCreated'));
    setDomainDialogOpen(false);
    setDomainInput('');
    setDomainRemark('');
    await loadData();
  } catch (err: any) {
    notify.error(errorMessageFrom(err, t('common.saveFailed')));
  } finally {
    setDomainSaving(false);
  }
};

const verifyAgentDomain = async (id: number) => {
  setDomainBusyId(id);
  try {
    await agentCommerceService.verifyDomain(id);
    notify.success(t('agentCenter.domainVerified'));
    await loadData();
  } catch (err: any) {
    notify.error(errorMessageFrom(err, t('agentCenter.domainVerifyFailed')));
    await loadData();
  } finally {
    setDomainBusyId(null);
  }
};

const deleteAgentDomain = async (id: number) => {
  setDomainBusyId(id);
  try {
    await agentCommerceService.deleteDomain(id);
    notify.success(t('agentCenter.domainDeleted'));
    await loadData();
  } catch (err: any) {
    notify.error(errorMessageFrom(err, t('common.deleteFailed')));
  } finally {
    setDomainBusyId(null);
  }
};
```

Use the `domain_limit` returned by summary when present. If the current `summary()` response is not already normalized in this page, set the limit from `commerceSummary.domain_limit || 1`.

- [ ] **Step 6: Update domain tab rendering**

In the existing domains tab:

- Add button `t('agentCenter.addDomain')`.
- Show `t('agentCenter.domainLimitSummary', { used, limit })`.
- For pending rows, show `domain.verification.record_name`, `domain.verification.record_value`, a verify button, and a delete button.
- For active rows, keep the current reverse proxy snippet.
- Hide pending domains from payment owner-domain selector by filtering `domain.status === 'active'`.

- [ ] **Step 7: Add translations**

Add Chinese keys under `agentCenter`:

```json
"addDomain": "添加域名",
"domainRequired": "请输入域名",
"domainCreated": "域名已提交，请按提示添加 DNS 验证记录",
"domainVerified": "域名验证成功",
"domainVerifyFailed": "域名验证失败",
"domainDeleted": "域名已删除",
"domainLimitSummary": "已使用 {{used}} / {{limit}} 个域名",
"domainVerifyRecordName": "TXT 主机记录",
"domainVerifyRecordValue": "TXT 记录值",
"verifyDomain": "验证域名"
```

Add English equivalents:

```json
"addDomain": "Add domain",
"domainRequired": "Enter a domain",
"domainCreated": "Domain submitted. Add the DNS verification record.",
"domainVerified": "Domain verified",
"domainVerifyFailed": "Domain verification failed",
"domainDeleted": "Domain deleted",
"domainLimitSummary": "{{used}} / {{limit}} domains used",
"domainVerifyRecordName": "TXT host",
"domainVerifyRecordValue": "TXT value",
"verifyDomain": "Verify domain"
```

- [ ] **Step 8: Run user frontend tests and build**

Run:

```bash
npm run test -- agentDomain
npm run build
```

Expected: PASS. Existing Browserslist and chunk-size warnings are acceptable.

- [ ] **Step 9: Commit user frontend**

Run:

```bash
git add src/services/agentCommerce.ts src/lib/agentDomain.ts src/lib/agentDomain.test.ts src/pages/AgentCenterPage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Add agent domain self-service UI"
```

---

## Task 6: Admin Frontend Domain Monitoring

**Files:**
- Modify: `keli-admin/src/services/agentCommerce.ts`
- Modify: `keli-admin/src/pages/agent/agentCommerceDisplay.ts`
- Modify: `keli-admin/src/pages/agent/agentCommerceDisplay.test.ts`
- Modify: `keli-admin/src/pages/agent/AgentCommercePage.tsx`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`

- [ ] **Step 1: Add display helper test**

In `src/pages/agent/agentCommerceDisplay.test.ts`, add:

```ts
import { getAgentDomainSourceLabelKey } from "./agentCommerceDisplay";

it("maps agent domain source labels", () => {
  expect(getAgentDomainSourceLabelKey("agent")).toBe("agent_commerce.domain_source.agent");
  expect(getAgentDomainSourceLabelKey("admin")).toBe("agent_commerce.domain_source.admin");
  expect(getAgentDomainSourceLabelKey("")).toBe("agent_commerce.domain_source.unknown");
});
```

- [ ] **Step 2: Run helper test and verify it fails**

Run:

```bash
npm run test -- agentCommerceDisplay
```

Expected: FAIL because `getAgentDomainSourceLabelKey` does not exist.

- [ ] **Step 3: Add admin service fields**

In `src/services/agentCommerce.ts`, extend `AdminAgentDomain`:

```ts
source?: "agent" | "admin" | string;
verification_type?: string;
verified_at?: number | null;
last_checked_at?: number | null;
verification_error?: string | null;
```

- [ ] **Step 4: Implement helper**

In `src/pages/agent/agentCommerceDisplay.ts`, add:

```ts
export const getAgentDomainSourceLabelKey = (source?: string | null) => {
  const normalized = String(source || "").toLowerCase();
  if (normalized === "agent") return "agent_commerce.domain_source.agent";
  if (normalized === "admin") return "agent_commerce.domain_source.admin";
  return "agent_commerce.domain_source.unknown";
};
```

- [ ] **Step 5: Update admin domain table**

In `src/pages/agent/AgentCommercePage.tsx`:

- Import `getAgentDomainSourceLabelKey`.
- Include `item.source` and `item.verification_error` in domain search.
- Under domain name, show the source label.
- Under remark or status area, show verification error in muted or danger text when present.
- Add `verified_at` or `last_checked_at` text in the created-at column as a second line.

- [ ] **Step 6: Add admin translations**

Under `agent_commerce`, add Chinese:

```json
"domain_source": {
  "agent": "代理提交",
  "admin": "管理员创建",
  "unknown": "未知来源"
},
"verification_error": "验证失败：{{error}}",
"verified_at": "验证于 {{time}}",
"last_checked_at": "检查于 {{time}}"
```

Add English:

```json
"domain_source": {
  "agent": "Agent submitted",
  "admin": "Admin created",
  "unknown": "Unknown source"
},
"verification_error": "Verification failed: {{error}}",
"verified_at": "Verified at {{time}}",
"last_checked_at": "Checked at {{time}}"
```

- [ ] **Step 7: Run admin tests and build**

Run:

```bash
npm run test -- agentCommerceDisplay
npm run build
```

Expected: PASS.

- [ ] **Step 8: Commit admin frontend**

Run:

```bash
git add src/services/agentCommerce.ts src/pages/agent/agentCommerceDisplay.ts src/pages/agent/agentCommerceDisplay.test.ts src/pages/agent/AgentCommercePage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Display agent domain verification state"
```

---

## Task 7: Full Verification, Remote Smoke, And Push

**Files:**
- No new files unless verification finds defects.

- [ ] **Step 1: Check repo status**

Run:

```bash
git status --short --branch
```

from:

- `keliboard`
- `keli-user`
- `keli-admin`

Expected: only planned commits ahead; `keli-user` may still show unrelated untracked `design-audits/`, `dev_server.err.log`, and `dev_server.out.log`.

- [ ] **Step 2: Run backend targeted tests**

Run from `keliboard` on the test machine if local PHP is unavailable:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentDomainSelfServiceTest
./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceServiceTest
```

Expected: PASS.

- [ ] **Step 3: Run frontend verification**

Run from `keli-user`:

```bash
npm run test -- agentDomain
npm run build
```

Run from `keli-admin`:

```bash
npm run test -- agentCommerceDisplay
npm run build
```

Expected: PASS.

- [ ] **Step 4: Remote backend smoke**

Copy changed backend files to `/root/keliboard-test` on `165.232.158.117`, then run:

```powershell
ssh -i C:\Users\Administrator\.ssh\codex_keli_ed25519 root@165.232.158.117 "cd /root/keliboard-test && ./vendor/bin/phpunit --testsuite Unit --filter AgentDomainSelfServiceTest"
ssh -i C:\Users\Administrator\.ssh\codex_keli_ed25519 root@165.232.158.117 "cd /root/keliboard-test && ./vendor/bin/phpunit --testsuite Unit --filter AgentCommerce"
```

Expected: PASS.

- [ ] **Step 5: Push all touched repos**

Run:

```bash
git push
```

from each repo with commits:

- `keliboard`
- `keli-user`
- `keli-admin`

- [ ] **Step 6: Final report**

Report:

- commit hashes per repo
- verification commands and pass counts
- whether the test-machine backend smoke passed
- known warnings, especially existing frontend chunk-size or Browserslist warnings
