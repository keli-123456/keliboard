<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\SubscriptionControl\Services\SubscriptionClientIpResolver;
use Plugin\SubscriptionControl\Services\SubscriptionRiskAnalyzer;

class SubscriptionAiBehaviorService
{
    public const TABLE = 'v2_subscription_ai_behavior';
    private const DIMENSIONS = 16;

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasTable('v2_subscription_ai_policy_version');
    }

    public function credential(User $user): string
    {
        return hash_hmac('sha256', $user->token . ':' . $user->uuid, (string) config('app.key'));
    }

    /** Observe all access outcomes, not just old-rule alarms. No model or queue call here. */
    public function capture(User $user, \Illuminate\Http\Request $request, array $config, string $outcome, bool $deferAllowedSnapshot = false): void
    {
        $settings = Cache::store('redis')->remember('subscription_ai:policy', 10, fn() => app(SubscriptionAiAutomationService::class)->settings());
        if (!$settings['ai_auto_enabled'] || ($settings['ai_auto_mode'] ?? 'shadow') === 'legacy'
            || !SubscriptionAiAutomationService::allowsSite($settings, (int) ($user->site_id ?? 0))
            || $user->is_admin || $user->is_staff || $user->banned || trim((string) $user->token) === '' || !config('app.key')) return;
        if (!Cache::store('redis')->remember('subscription_ai:behavior_available', 10, fn() => $this->available())) return;
        $ip = (new SubscriptionClientIpResolver($config))->resolve($request)['client_ip'];
        $client = (new SubscriptionRiskAnalyzer([]))->classifyUserAgent((string) $request->header('User-Agent', ''))['category'];
        $this->observe($user, (string) $ip, (string) $client, $outcome, deferSnapshot: $deferAllowedSnapshot && $outcome === 'allowed');
    }

    /** Successful render candidates, not proof of client receipt or subsequent use. */
    public function captureResponse(User $user, array $servers): void
    {
        $settings = Cache::store('redis')->remember('subscription_ai:policy', 10, fn() => app(SubscriptionAiAutomationService::class)->settings());
        if (!$settings['ai_auto_enabled'] || ($settings['ai_auto_mode'] ?? 'shadow') === 'legacy'
            || !SubscriptionAiAutomationService::allowsSite($settings, (int) ($user->site_id ?? 0))
            || $user->is_admin || $user->is_staff || $user->banned || trim((string) $user->token) === '' || !config('app.key')
            || !Cache::store('redis')->remember('subscription_ai:behavior_available', 10, fn() => $this->available())) return;
        $ids = [];
        foreach ($servers as $server) {
            $id = is_array($server) ? ($server['id'] ?? null) : null;
            if (is_int($id) && $id > 0) $ids[$id] = $id;
            if (count($ids) > self::DIMENSIONS) break;
        }
        $this->observe($user, '', '', 'allowed', array_values($ids), true);
    }

    public function observe(User $user, string $ip, string $client, string $outcome, array $responseNodes = [], bool $responseOnly = false, bool $deferSnapshot = false): void
    {
        $now = time(); $bucket = intdiv($now, 300) * 300;
        $identity = ['user_id' => (int) $user->id, 'site_id' => (int) ($user->site_id ?? 0), 'credential_hash' => $this->credential($user), 'bucket_at' => $bucket];
        $key = 'subscription_ai:behavior:' . hash('sha256', json_encode($identity));
        // Nonblocking per-account lock; an unavailable buffer must never hold up subscriptions.
        Cache::store('redis')->lock($key . ':lock', 3)->get(function () use ($identity, $key, $now, $ip, $client, $outcome, $responseNodes, $responseOnly, $deferSnapshot): void {
            $state = Cache::store('redis')->get($key);
            if (!is_array($state)) {
                $admission = 'subscription_ai:behavior_admissions:' . intdiv($now, 60);
                Cache::store('redis')->add($admission, 0, 120);
                if (Cache::store('redis')->increment($admission) > 120) return;
                $stored = DB::table(self::TABLE)->where($identity)->value('sample');
                $state = $stored ? json_decode($stored, true, 32, JSON_THROW_ON_ERROR) : ['pulls' => 0, 'ips' => [], 'clients' => [], 'outcomes' => [], 'capped' => false, 'first_at' => $now];
            }
            if ($responseOnly) {
                $state['response_count'] = min(1000000, ($state['response_count'] ?? 0) + 1);
                $state['response_nodes'] ??= [];
                foreach ($responseNodes as $id) $this->increment($state['response_nodes'], (string) $id, $state['capped']);
            } else {
                $state['pulls'] = min(1000000, $state['pulls'] + 1);
                $packed = @inet_pton($ip);
                $fingerprint = hash_hmac('sha256', $identity['credential_hash'] . ':' . ($packed === false ? 'unknown' : bin2hex($packed)), (string) config('app.key'));
                $this->increment($state['ips'], $fingerprint, $state['capped']);
                $this->increment($state['clients'], $client, $state['capped']);
                $outcome = in_array($outcome, ['allowed', 'legacy_blocked', 'ai_blocked', 'error'], true) ? $outcome : 'error';
                $state['outcomes'][$outcome] = min(1000000, ($state['outcomes'][$outcome] ?? 0) + 1);
            }
            $state['last_at'] = $now;
            Cache::store('redis')->put($key, $state, 600);
            // A successful response shares this snapshot budget with its access observation.
            if ($deferSnapshot) return;
            // At most one persisted snapshot per account/minute and 60 globally/minute.
            // Buffered tails and contention may be missing; evidence explicitly declares lower bounds.
            if (!Cache::store('redis')->add($key . ':snapshot', true, 60)) return;
            $budget = 'subscription_ai:behavior_writes:' . intdiv($now, 60);
            Cache::store('redis')->add($budget, 0, 120);
            if (Cache::store('redis')->increment($budget) > 60) return;
            DB::table(self::TABLE)->updateOrInsert($identity, ['sample' => json_encode($state, JSON_THROW_ON_ERROR), 'last_seen_at' => $now, 'snapshot_at' => $now]);
        });
    }

    public function evidence(User $user): array
    {
        $now = time();
        $scope = fn() => DB::table(self::TABLE)->where('user_id', $user->id)->where('site_id', (int) ($user->site_id ?? 0))->where('credential_hash', $this->credential($user));
        $current = $scope()->whereBetween('last_seen_at', [$now - 900, $now])->orderByDesc('bucket_at')->limit(4)->get();
        // A one-hour gap keeps the current episode out of its own reference period.
        $history = $scope()->whereBetween('bucket_at', [$now - 7 * 86400, $now - 3600])->orderByDesc('bucket_at')->limit(2016)->get();
        $confirmed = DB::table('v2_subscription_ai_action')->where('user_id', $user->id)->where('site_id', (int) ($user->site_id ?? 0))
            ->where('credential_hash', $this->credential($user))->where('review_verdict', 'confirmed')->where('created_at', '>=', $now - 8 * 86400)->limit(100)->get(['created_at']);
        $recent = $this->summary($current);
        $referenceRows = $history->filter(function ($row) use ($confirmed): bool {
            $sample = json_decode($row->sample, true, 32, JSON_THROW_ON_ERROR);
            foreach ($confirmed as $review) if ($row->bucket_at >= $review->created_at - 900 && $row->bucket_at <= $review->created_at) return false;
            return ($sample['outcomes']['allowed'] ?? 0) > 0 && array_sum($sample['outcomes']) === ($sample['outcomes']['allowed'] ?? 0);
        });
        $reference = $this->summary($referenceRows);
        $mature = $referenceRows->count() >= 8 && $reference['last_at'] - $reference['first_at'] >= 86400;
        $ids = $current->pluck('id')->map(fn($id) => (int) $id)->all();
        // Features are measurements, not rule hits or a handcrafted fraud score.
        $features = [
            ['id' => 1, 'family' => 'timing', 'current_bucket_pull_counts' => $recent['bucket_pulls'], 'reference_bucket_pull_counts' => $reference['bucket_pulls']],
            ['id' => 2, 'family' => 'network', 'current_sampled_networks' => count($recent['ips']), 'new_sampled_networks' => count(array_diff_key($recent['ips'], $reference['ips']))],
            ['id' => 3, 'family' => 'client', 'current_client_counts' => $recent['clients'], 'reference_client_counts' => $reference['clients']],
            ['id' => 4, 'family' => 'reference', 'historical_buckets' => $referenceRows->count(), 'history_span_seconds' => max(0, $reference['last_at'] - $reference['first_at']), 'mature' => $mature],
        ];
        return ['source' => 'independent_behavior_v1', 'feature_ids' => [1, 2, 3, 4], 'features' => $features, 'event_ids' => [1, 2, 3, 4],
            'entry_protection' => app(SubscriptionEntryProtectionService::class)->forUser($user, array_map('intval', array_keys($recent['response_nodes'])), $recent['response_count']),
            'event_families' => [1 => 'timing', 2 => 'network', 3 => 'client', 4 => 'reference'], 'families' => ['timing', 'network', 'client', 'reference'],
            'behavior_bucket_ids' => $ids, 'baseline_mature' => $mature, 'last_event_at' => $recent['last_at'], 'window_seconds' => 900,
            'sampling' => ['lower_bounds_only' => true, 'not_full_population_coverage' => true, 'cache_tails_may_be_missing' => true, 'identity_dimensions_capped' => $recent['capped'] || $reference['capped'], 'global_write_budget_per_minute' => 60, 'global_new_buffer_budget_per_minute' => 120],
            'reference' => ['first_at' => $reference['first_at'], 'last_at' => $reference['last_at'], 'unreviewed_history_is_not_verified_legitimate' => true],
            'observed_outcomes' => $recent['outcomes'], 'triggered_events_only' => false];
    }

    private function summary($rows): array
    {
        $result = ['ips' => [], 'clients' => [], 'outcomes' => [], 'bucket_pulls' => [], 'first_at' => 0, 'last_at' => 0, 'capped' => false, 'response_nodes' => [], 'response_count' => 0];
        foreach ($rows as $row) {
            $sample = json_decode($row->sample, true, 32, JSON_THROW_ON_ERROR);
            foreach (['ips', 'clients', 'outcomes'] as $field) foreach ($sample[$field] as $key => $count) $result[$field][$key] = ($result[$field][$key] ?? 0) + $count;
            foreach ((array) ($sample['response_nodes'] ?? []) as $id => $count) {
                if (count($result['response_nodes']) < self::DIMENSIONS || isset($result['response_nodes'][$id])) $result['response_nodes'][$id] = true;
                else $result['capped'] = true;
            }
            $result['response_count'] += max(0, (int) ($sample['response_count'] ?? 0));
            $result['bucket_pulls'][] = $sample['pulls'];
            $result['first_at'] = $result['first_at'] ? min($result['first_at'], $sample['first_at']) : $sample['first_at'];
            $result['last_at'] = max($result['last_at'], $sample['last_at']);
            $result['capped'] = $result['capped'] || $sample['capped'];
        }
        // Keep provider input bounded independently of the user's historical volume.
        if (count($result['bucket_pulls']) > 48) {
            $sorted = $result['bucket_pulls']; sort($sorted);
            $result['bucket_pulls'] = ['count' => count($sorted), 'min' => min($sorted), 'median' => $sorted[intdiv(count($sorted), 2)], 'max' => max($sorted)];
        }
        return $result;
    }

    private function increment(array &$counts, string $key, bool &$capped): void
    {
        if (isset($counts[$key]) || count($counts) < self::DIMENSIONS) $counts[$key] = min(1000000, ($counts[$key] ?? 0) + 1);
        else $capped = true;
    }

    public function prune(): void
    {
        if (!$this->available()) return;
        $ids = DB::table(self::TABLE)->where('bucket_at', '<', time() - 7 * 86400)->limit(2000)->pluck('id');
        DB::table(self::TABLE)->whereIn('id', $ids)->delete();
    }
}
