<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AgentDomainSelfService
{
    public const VERIFICATION_TYPE_TXT = 'txt';
    public const RECORD_PREFIX = '_keli-agent.';
    public const VALUE_PREFIX = 'keli-agent-verification=';

    /** @var callable|null */
    private $txtResolver;

    public function __construct(?callable $txtResolver = null)
    {
        $this->txtResolver = $txtResolver;
    }

    public function createPending(User $agent, string $rawDomain, ?string $remark): array
    {
        $domainName = $this->normalizeDomain($rawDomain);
        if (!$this->validDomain($domainName)) {
            throw new ApiException('Invalid domain');
        }

        return DB::transaction(function () use ($agent, $domainName, $remark): array {
            $this->activeProfile($agent, true);

            if (AgentDomain::query()->where('domain', $domainName)->exists()) {
                throw new ApiException('Domain already assigned');
            }

            $limit = $this->domainLimit();
            if ($limit <= 0 || AgentDomain::query()->where('agent_user_id', $agent->id)->count() >= $limit) {
                throw new ApiException('Domain limit reached');
            }

            $now = time();
            try {
                $domain = AgentDomain::query()->create([
                    'agent_user_id' => (int) $agent->id,
                    'domain' => $domainName,
                    'status' => AgentDomain::STATUS_PENDING,
                    'is_primary' => false,
                    'remark' => $this->cleanNullableString($remark, 255),
                    'verification_token' => $this->verificationToken(),
                    'verification_type' => self::VERIFICATION_TYPE_TXT,
                    'verified_at' => null,
                    'last_checked_at' => null,
                    'verification_error' => null,
                    'created_by_agent_id' => (int) $agent->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                if (AgentDomain::query()->where('domain', $domainName)->exists()) {
                    throw new ApiException('Domain already assigned');
                }

                throw $exception;
            }

            return $this->payload($domain->fresh() ?: $domain);
        });
    }

    public function verify(User $agent, int $id): array
    {
        $this->activeProfile($agent);
        $domain = $this->ownedDomain($agent, $id);
        $this->assertVerificationAvailable($domain, $agent);
        $proof = $this->verificationProof($domain);

        try {
            $records = $this->resolveTxt($proof['record_name']);
        } catch (\Throwable) {
            $this->markVerificationFailure($agent, $id, 'DNS lookup failed, try again');
            throw new ApiException('DNS lookup failed, try again');
        }

        $records = array_map(static fn ($value): string => trim((string) $value), $records);
        if (!in_array($proof['record_value'], $records, true)) {
            $this->markVerificationFailure($agent, $id, 'Domain verification record not found');
            throw new ApiException('Domain verification record not found');
        }

        return DB::transaction(function () use ($agent, $id, $proof): array {
            $domain = $this->ownedDomain($agent, $id, true);
            $this->assertVerificationAvailable($domain, $agent);
            $this->assertVerificationProofCurrent($domain, $proof);

            $now = time();
            $domain->status = AgentDomain::STATUS_ACTIVE;
            $domain->verified_at = $now;
            $domain->last_checked_at = $now;
            $domain->verification_error = null;
            $domain->updated_at = $now;
            $domain->save();

            return $this->payload($domain->fresh() ?: $domain);
        });
    }

    public function delete(User $agent, int $id): bool
    {
        $this->activeProfile($agent);

        return DB::transaction(function () use ($agent, $id): bool {
            $domain = $this->ownedDomain($agent, $id, true);
            if (!$this->createdByAgent($domain, $agent)) {
                throw new ApiException('Domain cannot be deleted');
            }

            if ($this->enabledAgentPaymentUsesDomain($domain)) {
                throw new ApiException('Domain is used by an enabled payment method');
            }

            return (bool) $domain->delete();
        });
    }

    public function payload(AgentDomain $domain): array
    {
        return [
            'id' => (int) $domain->id,
            'agent_user_id' => (int) $domain->agent_user_id,
            'domain' => (string) $domain->domain,
            'status' => (string) $domain->status,
            'is_primary' => (bool) $domain->is_primary,
            'remark' => $domain->remark,
            'source' => $domain->created_by_agent_id !== null ? 'agent' : 'admin',
            'verified_at' => $this->timestampValue($domain->verified_at),
            'last_checked_at' => $this->timestampValue($domain->last_checked_at),
            'verification_error' => $domain->verification_error,
            'verification' => [
                'type' => (string) $domain->verification_type,
                'record_name' => $this->recordName($domain),
                'record_value' => $this->showVerificationProof($domain)
                    ? self::VALUE_PREFIX . (string) $domain->verification_token
                    : '',
            ],
        ];
    }

    public function domainLimit(): int
    {
        return max(0, (int) admin_setting('agent_center_domain_limit', 1));
    }

    private function activeProfile(User $agent, bool $lock = false): AgentProfile
    {
        $query = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE);

        if ($lock) {
            $query->lockForUpdate();
        }

        $profile = $query->first();

        if (!$profile) {
            throw new ApiException('Agent permission is not active');
        }

        return $profile;
    }

    private function ownedDomain(User $agent, int $id, bool $lock = false): AgentDomain
    {
        $query = AgentDomain::query()
            ->where('agent_user_id', $agent->id)
            ->where('id', $id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $domain = $query->first();

        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }

        return $domain;
    }

    private function assertVerificationAvailable(AgentDomain $domain, User $agent): void
    {
        if (
            !$this->createdByAgent($domain, $agent)
            || (string) $domain->status !== AgentDomain::STATUS_PENDING
            || (string) $domain->verification_type !== self::VERIFICATION_TYPE_TXT
            || trim((string) $domain->verification_token) === ''
        ) {
            throw new ApiException('Domain verification is unavailable');
        }
    }

    private function createdByAgent(AgentDomain $domain, User $agent): bool
    {
        return $domain->created_by_agent_id !== null
            && (int) $domain->created_by_agent_id === (int) $agent->id;
    }

    private function verificationProof(AgentDomain $domain): array
    {
        $token = (string) $domain->verification_token;

        return [
            'id' => (int) $domain->id,
            'domain' => (string) $domain->domain,
            'verification_token' => $token,
            'verification_type' => (string) $domain->verification_type,
            'record_name' => $this->recordName($domain),
            'record_value' => self::VALUE_PREFIX . $token,
        ];
    }

    private function assertVerificationProofCurrent(AgentDomain $domain, array $proof): void
    {
        if (
            (int) $domain->id !== (int) $proof['id']
            || (string) $domain->domain !== (string) $proof['domain']
            || (string) $domain->verification_token !== (string) $proof['verification_token']
            || (string) $domain->verification_type !== (string) $proof['verification_type']
            || $this->recordName($domain) !== (string) $proof['record_name']
            || self::VALUE_PREFIX . (string) $domain->verification_token !== (string) $proof['record_value']
        ) {
            throw new ApiException('Domain verification is unavailable');
        }
    }

    private function enabledAgentPaymentUsesDomain(AgentDomain $domain): bool
    {
        return Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_domain_id', $domain->id)
            ->where('enable', true)
            ->exists();
    }

    private function showVerificationProof(AgentDomain $domain): bool
    {
        return $domain->created_by_agent_id !== null
            && (string) $domain->status === AgentDomain::STATUS_PENDING
            && (string) $domain->verification_type === self::VERIFICATION_TYPE_TXT
            && trim((string) $domain->verification_token) !== '';
    }

    private function normalizeDomain(string $rawDomain): string
    {
        return app(AgentDomainResolver::class)->normalizeHost($rawDomain);
    }

    private function validDomain(string $domain): bool
    {
        if (
            $domain === ''
            || $domain === 'localhost'
            || str_contains($domain, '*')
            || str_contains($domain, ':')
            || filter_var($domain, FILTER_VALIDATE_IP)
            || $this->reservedHost($domain)
        ) {
            return false;
        }

        if (strlen($domain) > 255 || !str_contains($domain, '.')) {
            return false;
        }

        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)
            ) {
                return false;
            }
        }

        return !ctype_digit((string) end($labels));
    }

    private function reservedHost(string $domain): bool
    {
        foreach (['app_url', 'subscribe_url'] as $key) {
            foreach ($this->reservedHostEntries(admin_setting($key, '')) as $entry) {
                $host = $this->normalizeDomain($entry);
                if ($host !== '' && $host === $domain) {
                    return true;
                }
            }
        }

        return false;
    }

    private function reservedHostEntries($value): array
    {
        $items = is_array($value) ? $value : [$value];
        $entries = [];

        foreach ($items as $item) {
            foreach (explode(',', (string) $item) as $entry) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    private function recordName(AgentDomain $domain): string
    {
        return self::RECORD_PREFIX . (string) $domain->domain;
    }

    private function resolveTxt(string $recordName): array
    {
        if ($this->txtResolver) {
            return (array) call_user_func($this->txtResolver, $recordName);
        }

        if (!function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($recordName, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => isset($record['txt']) && is_string($record['txt'])
                ? $record['txt']
                : null,
            $records
        )));
    }

    private function markVerificationFailure(User $agent, int $id, string $message): void
    {
        DB::transaction(function () use ($agent, $id, $message): void {
            $domain = $this->ownedDomain($agent, $id, true);
            $this->assertVerificationAvailable($domain, $agent);

            $now = time();
            $domain->last_checked_at = $now;
            $domain->verification_error = $message;
            $domain->updated_at = $now;
            $domain->save();
        });
    }

    private function verificationToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function cleanNullableString(?string $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }

    private function timestampValue($value): ?int
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return (int) $value;
    }
}
