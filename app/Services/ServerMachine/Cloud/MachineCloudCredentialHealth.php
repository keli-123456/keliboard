<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineCloudCredentialHealth
{
    private ?array $bindings = null;
    private bool $ready = false;
    private const KINDS = ['auth_invalid', 'permission_denied', 'billing_locked', 'quota_exhausted', 'capacity_unavailable',
        'template_invalid', 'rate_limited', 'provider_unavailable', 'outcome_unknown', 'request_rejected', 'resource_missing', 'response_invalid'];

    public function available(): bool
    {
        if ($this->ready) return true;
        foreach (['cloud_identity', 'credential_health', 'credential_event'] as $table) if (!Schema::hasTable('v2_machine_' . $table)) return false;
        return $this->ready = true;
    }
    public function credentialKey(Account $account): string
    {
        $value = $account->credentials['api_token'] ?? '';
        return hash_hmac('sha256', $account->provider . ':' . (is_string($value) ? $value : ''), app('encrypter')->getKey());
    }
    public function identityKey(Account $account): ?string
    {
        if (!$this->available()) return null;
        $this->bindings ??= DB::table('v2_machine_cloud_identity')->get()->keyBy('account_id')->all();
        $row = $this->bindings[$account->id] ?? null;
        return $row && $row->provider === $account->provider && hash_equals($row->credential_key, $this->credentialKey($account))
            && $row->verified_at >= time() - 86400 ? $row->identity_key : null;
    }
    public function sameAccountIds(Account $account): array
    {
        if (!$this->available()) return [$account->id];
        $identity = $this->identityKey($account); $ids = [];
        foreach (Account::query()->where('provider', $account->provider)->get() as $other) {
            $candidate = $this->identityKey($other);
            // Unknown identities cannot be assumed to be separate paid accounts.
            if ($identity === null || $candidate === null || hash_equals($identity, $candidate)) $ids[] = $other->id;
        }
        return $ids;
    }
    public function assertAccountIdentity(Account $account, array $body): void
    {
        $expected = $this->identityKey($account); $observed = $this->entity($account->provider, $body);
        if ($expected === null || $observed === null) throw new CloudException('account_identity_required');
        if (!hash_equals($expected, $observed)) throw new CloudException('credential_identity_changed');
    }
    private function contextKey(string $scope, Account $account, array $plan, string $intent): string
    {
        $value = match ($scope) {
            'credential' => $this->credentialKey($account),
            'account' => $this->identityKey($account) ?? 'unknown-account',
            'region' => (string) ($plan['region'] ?? ''),
            'template' => json_encode([$plan['region'] ?? '', $plan['size'] ?? '', $plan['image'] ?? ''], JSON_THROW_ON_ERROR),
            'intent' => $intent,
            default => 'egress',
        };
        return hash('sha256', $account->provider . ':' . $scope . ':' . $value);
    }
    public function record(Account $account, CloudRequestFailure $error, string $operation, string $source, array $plan = [], string $intent = ''): void
    {
        if (!$this->available()) return;
        $failure = $error->decision;
        if (($failure['provider'] ?? null) !== $account->provider || !in_array($failure['kind'] ?? null, self::KINDS, true)
            || !in_array($operation, ['inventory', 'identity', 'preflight', 'create', 'readback'], true)) throw new CloudException('credential_health_invalid');
        $key = $this->credentialKey($account); $scopeKey = $this->contextKey($failure['scope'], $account, $plan, $intent !== '' ? $intent : 'read:' . $operation);
        Cache::lock('machine-cloud:health:' . $account->provider, 30)->block(2, function () use ($account, $failure, $operation, $source, $key, $scopeKey) {
            $fresh = Account::query()->find($account->id);
            if (!$fresh || $fresh->revision !== $account->revision || !hash_equals($key, $this->credentialKey($fresh))) throw new CloudException('account_changed');
            DB::transaction(function () use ($account, $failure, $operation, $source, $key, $scopeKey) {
                $sourceKey = hash('sha256', $account->id . ':' . $key . ':' . $operation . ':' . $source . ':' . $failure['kind']);
                if (DB::table('v2_machine_credential_event')->where('source_key', $sourceKey)->exists()) return;
                $where = ['provider' => $account->provider, 'kind' => $failure['kind'], 'scope_key' => $scopeKey, 'credential_key' => $key];
                $prior = DB::table('v2_machine_credential_health')->where($where)->first();
                if ($prior && hash_equals($prior->last_source_key, $sourceKey)) return;
                $attempt = $prior && $prior->cleared_at === null ? min(20, $prior->failures + 1) : 1;
                $cooldown = max(0, (int) $failure['cooldown_seconds']);
                if (in_array($failure['kind'], ['rate_limited', 'provider_unavailable'], true)) $cooldown = max($cooldown, min(3600, $cooldown * 2 ** min(5, $attempt - 1)));
                $review = $failure['requires_review'] || in_array($failure['kind'], ['auth_invalid', 'permission_denied', 'billing_locked'], true);
                $until = $cooldown ? max(time() + $cooldown, (int) ($prior?->blocked_until ?? 0)) : null;
                $state = $review ? 'review' : ($cooldown ? 'cooldown' : 'blocked');
                DB::table('v2_machine_credential_health')->updateOrInsert($where, ['account_id' => $account->id, 'scope' => $failure['scope'],
                    'state' => $state, 'operation' => $operation, 'http_status' => $failure['http_status'], 'failures' => $attempt, 'last_source_key' => $sourceKey,
                    'blocked_until' => $until, 'observed_at' => time(), 'cleared_at' => null]);
                if (DB::table('v2_machine_credential_event')->where('account_id', $account->id)->count() < 10000) {
                    DB::table('v2_machine_credential_event')->insert(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'credential_key' => $key,
                        'source_key' => $sourceKey, 'kind' => $failure['kind'], 'scope' => $failure['scope'], 'state' => $state, 'operation' => $operation,
                        'http_status' => $failure['http_status'], 'blocked_until' => $until, 'occurred_at' => time()]);
                }
            });
        });
    }
    public function status(Account $account, array $plan = [], ?string $reconcileIntent = null): array
    {
        if (!$this->available()) return ['available' => false, 'eligible' => true, 'state' => 'unknown', 'identity' => 'unknown', 'reasons' => [], 'retry_at' => null];
        $credential = $this->credentialKey($account); $identity = $this->identityKey($account);
        $keys = [];
        foreach (['credential', 'account', 'region', 'template', 'provider'] as $scope) $keys[] = $this->contextKey($scope, $account, $plan, '');
        $keys[] = hash('sha256', $account->provider . ':account:unknown-account');
        $rows = DB::table('v2_machine_credential_health')->where('provider', $account->provider)->whereNull('cleared_at')
            ->where(fn ($query) => $query->whereIn('scope_key', $keys)->orWhere(fn ($q) => $q->where('scope', 'intent')->whereIn('kind', ['outcome_unknown', 'response_invalid'])->where('account_id', $account->id)->where('credential_key', $credential)
                ->when($reconcileIntent !== null, fn ($own) => $own->where('scope_key', '!=', $this->contextKey('intent', $account, [], $reconcileIntent)))))
            ->orderByDesc('observed_at')->get();
        $reasons = []; $retry = null; $review = false; $eligible = true;
        foreach ($rows as $row) {
            if ($row->scope === 'credential' && $row->kind === 'permission_denied' && !in_array($row->operation, ['preflight', 'create', 'readback'], true)) continue;
            $pending = $row->state !== 'cooldown' || $row->blocked_until === null || $row->blocked_until > time();
            $reasons[] = ['kind' => $row->kind, 'scope' => $row->scope, 'operation' => $row->operation, 'pending' => $pending];
            if ($pending) { $eligible = false; $review = $review || $row->state === 'review' || $row->scope === 'intent'; }
            if ($pending && $row->blocked_until !== null) $retry = max($retry ?? 0, $row->blocked_until);
        }
        $state = !$eligible ? ($review ? 'review' : 'cooldown') : ($reasons ? 'probation' : 'unknown');
        $binding = DB::table('v2_machine_cloud_identity')->where('account_id', $account->id)->first();
        if ($eligible && !$reasons && $binding && hash_equals($binding->credential_key, $credential) && $binding->checked_at >= time() - 86400) $state = 'read_verified';
        usort($reasons, fn ($a, $b) => (int) $b['pending'] <=> (int) $a['pending']);
        return ['available' => true, 'eligible' => $eligible, 'state' => $state, 'identity' => $identity === null ? 'unknown' : 'verified',
            'reasons' => array_slice($reasons, 0, 100), 'retry_at' => $retry, 'checked_at' => $binding && hash_equals($binding->credential_key, $credential) ? $binding->checked_at : null,
            'shared_credentials' => $identity === null ? null : Account::query()->where('provider', $account->provider)->get()
                ->filter(fn ($other) => $this->identityKey($other) === $identity)->count(), 'creation_verified' => false];
    }
    public function assertEligible(Account $account, array $plan = []): void
    {
        if (!$this->status($account, $plan)['eligible']) throw new CloudException('credential_health_blocked');
    }
    public function report(int $id, int $admin): array
    {
        $this->actor($admin); $account = Account::query()->findOrFail($id);
        $history = $this->available() ? DB::table('v2_machine_credential_event')->where('account_id', $id)->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(50)->get(['id', 'kind', 'scope', 'state', 'operation', 'http_status', 'blocked_until', 'occurred_at'])->toArray() : [];
        return ['account_id' => $id, 'account_revision' => $account->revision, 'provider' => $account->provider,
            'health' => $this->status($account), 'events' => $history, 'automatic_rotation' => false, 'creation_enabled' => false, 'dns_cutover' => false];
    }
    public function verify(int $id, int $revision, int $admin): array
    {
        $this->actor($admin); if (!$this->available()) throw new CloudException('credential_health_migration_required');
        return Cache::lock('machine-cloud:account:' . $id, 120)->block(2, function () use ($id, $revision, $admin) {
            $this->actor($admin); $account = Account::query()->findOrFail($id);
            if ($account->revision !== $revision) throw new CloudException('account_changed');
            $key = $this->credentialKey($account); $origin = match ($account->provider) {
                'linode' => 'https://api.linode.com/v4/account', 'digitalocean' => 'https://api.digitalocean.com/v2/account',
                'vultr' => 'https://api.vultr.com/v2/account', default => throw new CloudException('unsupported_provider'),
            };
            try {
                try { $response = Http::withToken($account->credentials['api_token'])->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->get($origin); }
                catch (\Throwable) { throw new CloudRequestFailure(app(CloudFailurePolicy::class)->transport($account->provider, 'GET')); }
                $body = strlen($response->body()) <= 524288 ? $response->json() : null;
                if (!$response->successful() || !is_array($body)) throw new CloudRequestFailure(app(CloudFailurePolicy::class)->response($account->provider, 'GET', $response->status(), $body, $response->headers()));
                $current = Account::query()->find($id); $this->actor($admin);
                if (!$current || $current->revision !== $revision || !hash_equals($key, $this->credentialKey($current))) throw new CloudException('account_changed');
                try { $entity = $this->entity($account->provider, $body); }
                catch (CloudException $error) {
                    if ($error->getMessage() !== 'provider_invalid') throw $error;
                    throw new CloudRequestFailure(app(CloudFailurePolicy::class)->response($account->provider, 'GET', $response->status()));
                }
                Cache::lock('machine-cloud:health:' . $account->provider, 30)->block(2, function () use ($account, $key, $entity) {
                    DB::transaction(function () use ($account, $key, $entity) {
                        $old = DB::table('v2_machine_cloud_identity')->where('account_id', $account->id)->first();
                        if ($old && hash_equals($old->credential_key, $key) && $old->identity_key !== null && $entity !== null && !hash_equals($old->identity_key, $entity)) throw new CloudException('credential_identity_changed');
                        DB::table('v2_machine_cloud_identity')->updateOrInsert(['account_id' => $account->id], ['provider' => $account->provider, 'credential_key' => $key,
                            'identity_key' => $entity, 'verified_at' => $entity === null ? null : time(), 'checked_at' => time()]);
                        $this->bindings = null;
                        // A successful account GET proves authentication, not create permission or billing readiness.
                        $this->clearAuthentication($account, $key, 'identity');
                        if ($entity !== null) $this->rebindAccountRules($account, $key);
                    });
                });
                $this->bindings = null;
                $restriction = app(CloudFailurePolicy::class)->accountRestriction($account->provider, $body);
                if ($restriction !== null) $this->record($account, new CloudRequestFailure($restriction), 'identity', (string) Str::uuid());
                return $this->report($id, $admin);
            } catch (CloudRequestFailure $error) {
                $this->record($account, $error, 'identity', (string) Str::uuid()); throw $error;
            } finally {
                $this->bindings = null;
            }
        });
    }
    private function entity(string $provider, array $body): ?string
    {
        $id = null;
        if ($provider === 'linode') $id = $body['euuid'] ?? null;
        elseif ($provider === 'digitalocean') {
            if (!is_array($body['account'] ?? null)) throw new CloudException('provider_invalid');
            $account = $body['account'];
            if (array_key_exists('team', $account) && $account['team'] !== null) {
                if (!is_array($account['team'])) throw new CloudException('provider_invalid');
                $id = $account['team']['uuid'] ?? null;
            } else $id = $account['uuid'] ?? null;
        } elseif (!is_array($body['account'] ?? null)) throw new CloudException('provider_invalid');
        if ($provider === 'vultr') {
            if (!is_numeric($body['account']['balance'] ?? null) || !is_finite((float) $body['account']['balance'])) throw new CloudException('provider_invalid');
            return null;
        }
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{12,80}$/D', $id)) throw new CloudException('provider_invalid');
        return hash_hmac('sha256', $provider . ':account:' . strtolower($id), app('encrypter')->getKey());
    }
    private function rebindAccountRules(Account $account, string $key): void
    {
        $scope = $this->contextKey('account', $account, [], '');
        foreach (DB::table('v2_machine_credential_health')->where('account_id', $account->id)->where('credential_key', $key)->where('scope', 'account')->get() as $rule) {
            if ($rule->scope_key === $scope) continue;
            $prior = DB::table('v2_machine_credential_health')->where('provider', $account->provider)->where('credential_key', $key)->where('kind', $rule->kind)->where('scope_key', $scope)->first();
            if (!$prior) DB::table('v2_machine_credential_health')->where('id', $rule->id)->update(['scope_key' => $scope]);
            else {
                DB::table('v2_machine_credential_health')->where('id', $prior->id)->update(['cleared_at' => null,
                    'state' => $rule->state === 'review' || $prior->state === 'review' ? 'review' : 'cooldown',
                    'blocked_until' => max((int) $prior->blocked_until, (int) $rule->blocked_until) ?: null,
                    'failures' => max($prior->failures, $rule->failures), 'observed_at' => max($prior->observed_at, $rule->observed_at)]);
                DB::table('v2_machine_credential_health')->where('id', $rule->id)->delete();
            }
        }
    }
    public function resolveIntent(Account $account, string $intent): void
    {
        if (!$this->available()) return;
        DB::table('v2_machine_credential_health')->where('provider', $account->provider)->where('account_id', $account->id)
            ->where('credential_key', $this->credentialKey($account))->where('scope', 'intent')
            ->where('scope_key', $this->contextKey('intent', $account, [], $intent))->update(['cleared_at' => time()]);
    }
    public function authenticatedRead(Account $account, string $operation): void
    {
        if (!$this->available() || !in_array($operation, ['inventory', 'preflight'], true)) return;
        $key = $this->credentialKey($account);
        Cache::lock('machine-cloud:health:' . $account->provider, 30)->block(2, function () use ($account, $key, $operation) {
            $fresh = Account::query()->find($account->id);
            if (!$fresh || $fresh->revision !== $account->revision || !hash_equals($key, $this->credentialKey($fresh))) throw new CloudException('account_changed');
            DB::transaction(function () use ($account, $key, $operation) {
                $binding = DB::table('v2_machine_cloud_identity')->where('account_id', $account->id)->first();
                $same = $binding && hash_equals($binding->credential_key, $key);
                DB::table('v2_machine_cloud_identity')->updateOrInsert(['account_id' => $account->id], ['provider' => $account->provider,
                    'credential_key' => $key, 'identity_key' => $same ? $binding->identity_key : null,
                    'verified_at' => $same ? $binding->verified_at : null, 'checked_at' => time()]);
                $this->clearAuthentication($account, $key, $operation);
            });
            $this->bindings = null;
        });
    }
    private function clearAuthentication(Account $account, string $key, string $operation): void
    {
        DB::table('v2_machine_credential_health')->where('provider', $account->provider)->where('credential_key', $key)
            ->whereIn('kind', ['rate_limited', 'provider_unavailable'])->where('state', 'cooldown')->where('blocked_until', '<=', time())
            ->update(['cleared_at' => time()]);
        DB::table('v2_machine_credential_health')->where('provider', $account->provider)->where('credential_key', $key)
            ->where('kind', 'response_invalid')->where('operation', $operation)->where('scope', 'intent')
            ->where('scope_key', $this->contextKey('intent', $account, [], 'read:' . $operation))->update(['cleared_at' => time()]);
        $changed = DB::table('v2_machine_credential_health')->where('credential_key', $key)->where('kind', 'auth_invalid')->whereNull('cleared_at')->update(['cleared_at' => time()]);
        if ($changed && DB::table('v2_machine_credential_event')->where('account_id', $account->id)->count() < 10000) {
            DB::table('v2_machine_credential_event')->insert(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'credential_key' => $key,
                'source_key' => hash('sha256', 'authentication-proof:' . Str::uuid()), 'kind' => 'auth_recovered', 'scope' => 'credential',
                'state' => 'cleared', 'operation' => $operation, 'http_status' => 200, 'blocked_until' => null, 'occurred_at' => time()]);
        }
    }
    private function actor(int $id): void
    {
        $user = User::query()->find($id, ['id', 'is_admin', 'banned']);
        if (!$user || !$user->is_admin || $user->banned) throw new CloudException('permission_revoked');
    }
}
