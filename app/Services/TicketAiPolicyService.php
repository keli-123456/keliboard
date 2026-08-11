<?php

namespace App\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Site;
use App\Models\User;

class TicketAiPolicyService
{
    public const TONES = ['concise', 'warm', 'formal'];

    public function __construct(private ?TicketAiContentSanitizer $sanitizer = null)
    {
        $this->sanitizer ??= new TicketAiContentSanitizer();
    }

    /** @return array<int, array<string, mixed>> */
    public function normalizePolicies(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $policies = [];
        foreach (array_slice($raw, 0, 500) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $scope = $this->scopeFields($item);
            if ($scope === null) {
                continue;
            }
            $key = $this->scopeKey($scope);
            $tone = trim((string) ($item['tone'] ?? ''));
            $promises = is_array($item['prohibited_promises'] ?? null)
                ? array_values(array_unique(array_filter(array_map(
                    fn (mixed $value): string => $this->sanitizer->sanitize((string) $value, 120),
                    array_slice($item['prohibited_promises'], 0, 20)
                ))))
                : [];

            $policies[$key] = array_merge($scope, [
                'scope_key' => $key,
                'enabled' => array_key_exists('enabled', $item) ? (bool) $item['enabled'] : null,
                'knowledge_enabled' => array_key_exists('knowledge_enabled', $item) && $item['knowledge_enabled'] !== null
                    ? (bool) $item['knowledge_enabled']
                    : null,
                'tone' => in_array($tone, self::TONES, true) ? $tone : null,
                'extra_instruction' => $this->sanitizer->sanitize((string) ($item['extra_instruction'] ?? ''), 2000),
                'prohibited_promises' => $promises,
            ]);
        }

        return array_values($policies);
    }

    /** @return array<string, mixed> */
    public function resolve(array $scope, mixed $rawPolicies): array
    {
        $policies = collect($this->normalizePolicies($rawPolicies))->keyBy('scope_key');
        $resolved = [
            'enabled' => true,
            'knowledge_enabled' => null,
            'tone' => null,
            'extra_instruction' => '',
            'prohibited_promises' => [],
            'sources' => [],
        ];
        foreach ($this->resolutionKeys($scope) as $key) {
            $policy = $policies->get($key);
            if (!is_array($policy)) {
                continue;
            }
            foreach (['enabled', 'knowledge_enabled', 'tone'] as $field) {
                if (($policy[$field] ?? null) !== null) {
                    $resolved[$field] = $policy[$field];
                }
            }
            if (($policy['extra_instruction'] ?? '') !== '') {
                $resolved['extra_instruction'] = (string) $policy['extra_instruction'];
            }
            $resolved['prohibited_promises'] = array_values(array_unique(array_merge(
                $resolved['prohibited_promises'],
                (array) ($policy['prohibited_promises'] ?? [])
            )));
            $resolved['sources'][] = $key;
        }

        return $resolved;
    }

    /** @return array<int, array<string, mixed>> */
    public function targets(): array
    {
        $targets = [[
            'scope_key' => 'platform',
            'scope_type' => 'platform',
            'site_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'label' => '主站',
        ]];

        if ($this->hasTable('v2_site')) {
            Site::query()->orderBy('id')->get(['id', 'name'])->each(function (Site $site) use (&$targets): void {
                $targets[] = [
                    'scope_key' => 'site:' . (int) $site->id,
                    'scope_type' => 'site',
                    'site_id' => (int) $site->id,
                    'agent_user_id' => null,
                    'agent_domain_id' => null,
                    'label' => '站点 · ' . ((string) $site->name ?: "#{$site->id}"),
                ];
            });
        }

        $emails = [];
        if ($this->hasTable('v2_agent_profile') && $this->hasTable('v2_user')) {
            $profiles = AgentProfile::query()->orderBy('id')->get(['user_id']);
            $emails = User::query()
                ->whereIn('id', $profiles->pluck('user_id')->all())
                ->pluck('email', 'id')
                ->all();
            foreach ($profiles as $profile) {
                $userId = (int) $profile->user_id;
                $targets[] = [
                    'scope_key' => "agent:{$userId}:0",
                    'scope_type' => 'agent',
                    'site_id' => null,
                    'agent_user_id' => $userId,
                    'agent_domain_id' => null,
                    'label' => '代理 · ' . ($emails[$userId] ?? "#{$userId}"),
                ];
            }
        }
        if ($this->hasTable('v2_agent_domain')) {
            AgentDomain::query()->orderBy('id')->get(['id', 'agent_user_id', 'domain'])->each(
                function (AgentDomain $domain) use (&$targets, $emails): void {
                    $userId = (int) $domain->agent_user_id;
                    $targets[] = [
                        'scope_key' => "agent:{$userId}:" . (int) $domain->id,
                        'scope_type' => 'agent',
                        'site_id' => null,
                        'agent_user_id' => $userId,
                        'agent_domain_id' => (int) $domain->id,
                        'label' => '代理域名 · ' . (string) $domain->domain,
                    ];
                }
            );
        }

        return $targets;
    }

    /** @return array<string, mixed>|null */
    private function scopeFields(array $item): ?array
    {
        $type = (string) ($item['scope_type'] ?? '');
        if ($type === 'platform') {
            return ['scope_type' => 'platform', 'site_id' => null, 'agent_user_id' => null, 'agent_domain_id' => null];
        }
        if ($type === 'site' && (int) ($item['site_id'] ?? 0) > 0) {
            return ['scope_type' => 'site', 'site_id' => (int) $item['site_id'], 'agent_user_id' => null, 'agent_domain_id' => null];
        }
        if ($type === 'agent' && (int) ($item['agent_user_id'] ?? 0) > 0) {
            return [
                'scope_type' => 'agent',
                'site_id' => null,
                'agent_user_id' => (int) $item['agent_user_id'],
                'agent_domain_id' => (int) ($item['agent_domain_id'] ?? 0) ?: null,
            ];
        }

        return null;
    }

    /** @return array<int, string> */
    private function resolutionKeys(array $scope): array
    {
        $keys = ['platform'];
        if (($scope['type'] ?? null) === 'site' && (int) ($scope['site_id'] ?? 0) > 0) {
            $keys[] = 'site:' . (int) $scope['site_id'];
        }
        if (($scope['type'] ?? null) === 'agent' && (int) ($scope['agent_user_id'] ?? 0) > 0) {
            $userId = (int) $scope['agent_user_id'];
            $keys[] = "agent:{$userId}:0";
            if ((int) ($scope['agent_domain_id'] ?? 0) > 0) {
                $keys[] = "agent:{$userId}:" . (int) $scope['agent_domain_id'];
            }
        }

        return $keys;
    }

    private function scopeKey(array $scope): string
    {
        return match ($scope['scope_type']) {
            'site' => 'site:' . (int) $scope['site_id'],
            'agent' => 'agent:' . (int) $scope['agent_user_id'] . ':' . (int) ($scope['agent_domain_id'] ?? 0),
            default => 'platform',
        };
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
