<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;

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
        $this->activeProfile($agent);

        $domainName = $this->normalizeDomain($rawDomain);
        if (!$this->validDomain($domainName)) {
            throw new ApiException('Invalid domain');
        }

        if (AgentDomain::query()->where('domain', $domainName)->exists()) {
            throw new ApiException('Domain already assigned');
        }

        $limit = $this->domainLimit();
        if ($limit <= 0 || AgentDomain::query()->where('agent_user_id', $agent->id)->count() >= $limit) {
            throw new ApiException('Domain limit reached');
        }

        $now = time();
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

        return $this->payload($domain->fresh() ?: $domain);
    }

    public function verify(User $agent, int $id): array
    {
        $this->activeProfile($agent);
        $domain = $this->ownedDomain($agent, $id);
        $recordName = $this->recordName($domain);
        $expectedValue = self::VALUE_PREFIX . (string) $domain->verification_token;

        try {
            $records = $this->resolveTxt($recordName);
        } catch (\Throwable) {
            $this->markVerificationFailure($domain, 'DNS lookup failed, try again');
            throw new ApiException('DNS lookup failed, try again');
        }

        $records = array_map(static fn ($value): string => trim((string) $value), $records);
        if (!in_array($expectedValue, $records, true)) {
            $this->markVerificationFailure($domain, 'Domain verification record not found');
            throw new ApiException('Domain verification record not found');
        }

        $now = time();
        $domain->status = AgentDomain::STATUS_ACTIVE;
        $domain->verified_at = $now;
        $domain->last_checked_at = $now;
        $domain->verification_error = null;
        $domain->updated_at = $now;
        $domain->save();

        return $this->payload($domain->fresh() ?: $domain);
    }

    public function delete(User $agent, int $id): bool
    {
        $this->activeProfile($agent);
        $domain = $this->ownedDomain($agent, $id);

        $usedByPayment = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_id', $agent->id)
            ->where('owner_domain_id', $domain->id)
            ->where('enable', true)
            ->exists();

        if ($usedByPayment) {
            throw new ApiException('Domain is used by an enabled payment method');
        }

        return (bool) $domain->delete();
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
            'source' => $domain->created_by_agent_id ? 'agent' : 'admin',
            'verified_at' => $this->timestampValue($domain->verified_at),
            'last_checked_at' => $this->timestampValue($domain->last_checked_at),
            'verification_error' => $domain->verification_error,
            'verification' => [
                'type' => (string) $domain->verification_type,
                'record_name' => $this->recordName($domain),
                'record_value' => self::VALUE_PREFIX . (string) $domain->verification_token,
            ],
        ];
    }

    public function domainLimit(): int
    {
        return max(0, (int) admin_setting('agent_center_domain_limit', 1));
    }

    private function activeProfile(User $agent): AgentProfile
    {
        $profile = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->first();

        if (!$profile) {
            throw new ApiException('Agent permission is not active');
        }

        return $profile;
    }

    private function ownedDomain(User $agent, int $id): AgentDomain
    {
        $domain = AgentDomain::query()
            ->where('agent_user_id', $agent->id)
            ->where('id', $id)
            ->first();

        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }

        return $domain;
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
            $host = $this->normalizeDomain((string) admin_setting($key, ''));
            if ($host !== '' && $host === $domain) {
                return true;
            }
        }

        return false;
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

    private function markVerificationFailure(AgentDomain $domain, string $message): void
    {
        $domain->last_checked_at = time();
        $domain->verification_error = $message;
        $domain->updated_at = time();
        $domain->save();
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
