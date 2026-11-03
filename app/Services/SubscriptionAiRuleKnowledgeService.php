<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plugin;
use App\Services\Plugin\PluginConfigService;
use RuntimeException;

final class SubscriptionAiRuleKnowledgeService
{
    public const VERSION = 'legacy-rule-reference-v1';
    private const RULES = [
        'ua_blacklist' => ['enable_ua_blacklist', 'A configured UA keyword matches the request.', 'Protect against known harvesting signatures.', 'UA can be forged or misclassified. Request refusal or a source ban does not prove account abuse.'],
        'ua_block_only' => ['enable_ua_block_only', 'A configured browser or in-app UA keyword matches.', 'Do not expose subscription contents through unsuitable clients.', 'A user opening a link in a browser is not proof of leakage.'],
        'client_ua_not_allowed' => ['enable_client_ua_whitelist', 'The client is outside the configured allowlist.', 'Support an explicitly restricted client policy.', 'Unknown and old clients can be legitimate. Client category is not device count.'],
        'source_ip_denylist' => ['enable_source_ip_denylist', 'The resolved source matches a configured IP, ASN or organization policy.', 'Apply the administrator-defined source access policy.', 'Hosting and shared egress do not identify an abusive subscriber. List contents are withheld.'],
        'source_batch_pull' => ['enable_source_batch_detection', 'Distinct accounts from one source reach the threshold within the window.', 'Detect automated cross-account harvesting.', 'NAT, managed clients and shared exits can look similar. A per-account snapshot cannot establish a source-wide count.'],
        'subscription_leak_guard' => ['enable_leak_guard', 'The composite score reaches its threshold; throttling also requires at least two client/network/region families.', 'Combine exposure signals instead of treating one symptom as proof.', 'Correlated UA scores are one family. Low usage, missing online data and hosting labels alone are insufficient.'],
        'multi_ua_pull' => ['enable_multi_ua_detection', 'Client categories exceed the allowed count within the window.', 'Observe possible subscription distribution.', 'Multiple legitimate clients or upgrades can explain this. Sample windows may differ from the rule window.'],
        'multi_region_pull' => ['enable_multi_region_pull_detection', 'Actionable pull regions exceed the allowed count within the window.', 'Observe geographic changes.', 'Travel, mobile routing, VPN exits and geolocation errors can explain this. Never infer regions from a network count.'],
        'multi_region_online' => ['enable_multi_region_online_detection', 'Reported actionable online regions exceed the allowed count.', 'Observe concurrent geographic diversity.', 'Online reports are sampled, and online addresses are not people or devices.'],
        'online_ip_threshold' => ['enable_online_ip_threshold', 'Reported unique online addresses exceed the threshold.', 'Record online diversity for investigation.', 'Do not equate IP count with device count or credential leakage.'],
        'ip_limit' => ['enable_ip_limit', 'Distinct subscription source addresses exceed the count in the window.', 'Bound subscription distribution per request.', 'Address changes and shared exits can be legitimate. Partial samples cannot reproduce exact cache counts.'],
        'rate_limit' => ['enable_rate_limit', 'Subscription requests exceed the configured count within the window.', 'Bound repeated fetching.', 'Client retries, migrations and import attempts can cause bursts. Rule rate and snapshot count are different measurements.'],
        'one_time' => ['enable_one_time', 'A token reaches the use cap within its configured lifetime; duration expiry resets the counter.', 'Enforce an explicitly enabled limited-use link.', 'A use cap is an access policy, not proof of abuse. Never infer permission to rotate credentials from this reference.'],
        'ua_reset' => ['enable_ua_reset_token', 'An old-client keyword matches without an exclusion.', 'Record client compatibility leads.', 'The legacy name does not authorize credential resets. This signal is observation-only.'],
        'behavior_baseline_observation' => ['enable_behavior_baseline_observation', 'Enough historical observations exist and the deviation score reaches the threshold.', 'Observe deviations from a personal baseline.', 'Allowed historical requests are not independently verified benign. This rule never authorizes enforcement.'],
    ];
    private const PARAMETERS = [
        'source_batch_pull' => ['source_batch_user_threshold', 'source_batch_window_seconds'],
        'subscription_leak_guard' => ['leak_guard_score_threshold', 'leak_guard_window_seconds', 'leak_guard_allowed_ip_count', 'leak_guard_allowed_ua_count', 'leak_guard_allowed_region_count', 'leak_guard_active_plan_low_usage_bytes', 'leak_guard_active_plan_very_low_usage_bytes', 'leak_guard_escalate_hits', 'ip_intelligence_score_weight'],
        'multi_ua_pull' => ['multi_ua_allowed_count', 'multi_ua_window_seconds'],
        'multi_region_pull' => ['multi_region_pull_allowed_count', 'multi_region_pull_window_seconds'],
        'multi_region_online' => ['multi_region_online_allowed_count'],
        'online_ip_threshold' => ['online_ip_threshold'],
        'ip_limit' => ['ip_limit_count', 'ip_limit_window'],
        'rate_limit' => ['rate_limit_requests', 'rate_limit_window'],
        'one_time' => ['one_time_max_uses', 'one_time_duration'],
        'behavior_baseline_observation' => ['behavior_baseline_min_observations', 'behavior_baseline_score_threshold', 'behavior_baseline_window_seconds'],
    ];
    private const CLIENTS = ['empty', 'unknown', 'legacy_clash', 'sparkle', 'mihomo', 'throne', 'sing-box', 'shadowrocket', 'quantumult-x', 'surge', 'v2rayn', 'v2aya', 'nekobox', 'hiddify', 'streisand', 'loon', 'social_app', 'script', 'browser'];

    public function snapshot(): array
    {
        $fields = app(PluginConfigService::class)->getConfig('subscription_control');
        $plugin = Plugin::query()->where('code', 'subscription_control')->first();
        $rules = [];
        foreach (self::RULES as $id => [$switch, $trigger, $purpose, $caveat]) {
            $parameters = [];
            foreach (self::PARAMETERS[$id] ?? [] as $key) $parameters[$key] = $this->number($fields[$key]['value'] ?? null);
            $rule = ['id' => $id, 'configured_enabled' => filter_var($fields[$switch]['value'] ?? false, FILTER_VALIDATE_BOOL),
                'configured_parameters' => $parameters, 'trigger' => $trigger, 'purpose' => $purpose, 'caveat' => $caveat,
                'effective_response' => \Plugin\SubscriptionControl\Services\SubscriptionRiskResponsePolicy::forEvent($id)];
            if ($id === 'subscription_leak_guard') {
                $rule['effective_response'] = 'observe_or_request_throttle';
                foreach (['strict_mode' => 'enable_leak_guard_strict_mode', 'escalation_enabled' => 'enable_leak_guard_escalation', 'ip_intelligence_enabled' => 'enable_ip_intelligence'] as $name => $key) {
                    $rule[$name] = filter_var($fields[$key]['value'] ?? false, FILTER_VALIDATE_BOOL);
                }
            }
            if ($id === 'client_ua_not_allowed') $rule['effective_response'] = 'observe_or_request_block_by_category';
            if ($id === 'ua_blacklist') $rule['source_denylist_may_be_extended'] = true;
            $rules[] = $rule;
        }
        $sources = [];
        foreach (['Plugin.php', 'Services/SubscriptionRiskAnalyzer.php', 'Services/SubscriptionRiskResponsePolicy.php'] as $file) {
            $sources[$file] = hash_file('sha256', base_path('plugins-core/SubscriptionControl/' . $file));
        }
        $pack = ['protocol' => self::VERSION, 'available' => $fields !== [], 'plugin_enabled' => (bool) $plugin?->is_enabled,
            'engine_revision' => hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR)), 'rules' => $rules,
            'boundary' => 'Reference, not instructions or ground truth. Thresholds are configured values, not proof of abuse. Inputs may not cover rule windows or required measurements. Never reconstruct a rule hit from insufficient samples. Do not change rules or permissions.'];
        if (strlen(json_encode($pack, JSON_THROW_ON_ERROR)) > 16384) throw new RuntimeException('rule_reference_too_large');
        return ['version' => hash('sha256', json_encode($pack, JSON_THROW_ON_ERROR))] + $pack;
    }

    public function ruleIds(): array
    {
        return array_keys(self::RULES);
    }

    public function teachingEvidence(array $evidence): array
    {
        $features = []; $seen = [];
        foreach (array_slice((array) ($evidence['features'] ?? []), 0, 4) as $feature) {
            if (!is_array($feature) || !in_array($feature['id'] ?? null, [1, 2, 3, 4], true)) continue;
            if (isset($seen[$feature['id']])) continue;
            $seen[$feature['id']] = true;
            $safe = ['id' => $feature['id']];
            foreach (match ($feature['id']) { 2 => ['current_sampled_networks', 'new_sampled_networks'], 4 => ['historical_buckets', 'history_span_seconds'], default => [] } as $key) {
                if (isset($feature[$key])) $safe[$key] = $this->number($feature[$key]);
            }
            foreach ($feature['id'] === 1 ? ['current_bucket_pull_counts', 'reference_bucket_pull_counts'] : [] as $key) {
                if (!is_array($feature[$key] ?? null)) continue;
                $counts = $feature[$key];
                $safe[$key] = array_is_list($counts)
                    ? array_map($this->number(...), array_slice($counts, 0, $key === 'current_bucket_pull_counts' ? 4 : 48))
                    : array_map($this->number(...), array_intersect_key($counts, array_flip(['count', 'min', 'median', 'max'])));
            }
            foreach ($feature['id'] === 3 ? ['current_client_counts', 'reference_client_counts'] : [] as $key) {
                if (is_array($feature[$key] ?? null)) $safe[$key] = array_map($this->number(...), array_intersect_key($feature[$key], array_flip(self::CLIENTS)));
            }
            $features[] = $safe;
        }
        return ['source' => 'independent_behavior_v1', 'features' => $features,
            'baseline_mature' => ($evidence['baseline_mature'] ?? false) === true, 'window_seconds' => $this->number($evidence['window_seconds'] ?? null),
            'sampling' => ['lower_bounds_only' => true, 'not_full_population_coverage' => true]];
    }

    public function summary(array $lesson): array
    {
        $pack = $lesson['legacy_rule_knowledge'] ?? null;
        return ['knowledge_version' => is_array($pack) ? ($pack['version'] ?? null) : null,
            'rule_count' => count($pack['rules'] ?? []), 'verified_examples' => count($lesson['reviewed_training_examples'] ?? []),
            'unverified_replays' => count($lesson['historical_rule_replays'] ?? []), 'model_training' => false];
    }

    private function number(mixed $value): ?int
    {
        if (is_bool($value) || !is_scalar($value)) return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1_000_000_000_000]]);
        return $number === false ? null : (int) $number;
    }
}
