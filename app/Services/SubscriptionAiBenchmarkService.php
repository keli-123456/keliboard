<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SubscriptionAiAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SubscriptionAiBenchmarkService
{
    public const RUNS = 'v2_subscription_ai_benchmark';
    public const CASES = 'v2_subscription_ai_benchmark_case';
    public const GRANTS = 'v2_subscription_ai_admission';
    public const HOLDOUT_SIZE = 600;
    public const PROTOCOL = 'paired-shadow-v1';
    public const RULE_INTENTS = [
        'Detect credential sharing or automated harvesting, not ordinary network changes.',
        'UA/IP/region counts do not prove devices or abuse; all can have legitimate explanations.',
        'Distinguish an explicit hostile scanner from a browser opening a link or a normal client.',
        'Use independent corroboration; prefer observation when evidence is incomplete.',
    ];

    public function available(): bool
    {
        return Schema::hasTable(self::RUNS) && Schema::hasTable(self::CASES) && Schema::hasTable(self::GRANTS) && Schema::hasTable('v2_subscription_ai_benchmark_label');
    }

    public function contextKey(array $settings): string
    {
        $provider = SubscriptionRiskAiSettings::forProvider(app(TicketAiAssistantService::class)->providerSettingsForInternalUse());
        $files = [];
        foreach (['app/Services/SubscriptionRiskAiSettings.php', 'app/Services/TicketAiProviderClient.php', 'app/Services/SubscriptionAiAutomationService.php', 'app/Services/SubscriptionAiBehaviorService.php', 'app/Services/SubscriptionAiBenchmarkService.php', 'plugins-core/SubscriptionControl/Plugin.php', 'plugins-core/SubscriptionControl/Services/SubscriptionRiskAnalyzer.php', 'plugins-core/SubscriptionControl/config.json'] as $file) {
            $files[$file] = hash_file('sha256', base_path($file));
        }
        unset($settings['ai_auto_enabled'], $settings['ai_auto_mode']);
        $settings['ai_auto_max_seconds'] = min(120, $settings['ai_auto_max_seconds']);
        $settings['ai_auto_allow_reset_token'] = false; $settings['ai_auto_allow_suspend'] = false;
        return hash_hmac('sha256', json_encode([
            'protocol' => self::PROTOCOL, 'settings' => $settings, 'files' => $files,
            'provider' => array_intersect_key($provider, array_flip(['base_url', 'model', 'api_key', 'temperature', 'allow_private_provider', 'reasoning_effort', 'timeout', 'max_tokens', 'json_mode'])),
            'rules' => DB::table('v2_plugins')->where('code', 'subscription_control')->value('config'),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function latest(int $siteId): ?object
    {
        return DB::table(self::RUNS)->where('site_id', $siteId)->orderByDesc('id')->first();
    }

    public function start(int $siteId, int $adminId, bool $confirmed): array
    {
        if (!$this->available()) throw new RuntimeException('benchmark_migration_required');
        if (!$confirmed) throw new RuntimeException('authorization_required');
        if (!config('app.key')) throw new RuntimeException('benchmark_context_changed');
        $settings = app(SubscriptionAiAutomationService::class)->settings();
        if (!$settings['ai_auto_enabled'] || $settings['ai_auto_mode'] !== 'shadow') throw new RuntimeException('shadow_required');
        if (!SubscriptionAiAutomationService::allowsSite($settings, $siteId)) throw new RuntimeException('site_not_authorized');
        $provider = SubscriptionRiskAiSettings::forProvider(app(TicketAiAssistantService::class)->providerSettingsForInternalUse());
        if (!($provider['enabled'] ?? false) || empty($provider['base_url']) || empty($provider['model']) || empty($provider['api_key'])) throw new RuntimeException('ai_disabled');
        $context = $this->contextKey($settings);
        $lock = Cache::store('redis')->lock('subscription_ai:benchmark:start:' . $siteId, 10);
        $created = $lock->get(function () use ($siteId, $adminId, $context): bool {
            DB::transaction(function () use ($siteId, $adminId, $context): void {
                $old = $this->latest($siteId);
                if ($old && $old->status === 'collecting') throw new RuntimeException('benchmark_in_progress');
                $examples = DB::table(self::CASES . ' as c')->join(self::RUNS . ' as r', 'r.id', '=', 'c.run_id')
                    ->where('r.site_id', $siteId)->where('c.cohort', 'training')->whereIn('c.truth', ['benign', 'abuse'])->orderByDesc('c.id')->limit(4)
                    ->get(['c.evidence', 'c.truth', 'c.legacy_prediction'])->map(fn($row) => [
                        'evidence' => json_decode($row->evidence, true, 32, JSON_THROW_ON_ERROR), 'truth' => $row->truth,
                        'old_rule_intervened' => $row->legacy_prediction === null ? null : (bool) $row->legacy_prediction,
                    ])->all();
                $teaching = json_encode(['rule_intents' => self::RULE_INTENTS, 'reviewed_training_examples' => $examples], JSON_THROW_ON_ERROR);
                if ($old && $old->context_key === $context && $old->teaching === $teaching && DB::table(self::CASES)->where('run_id', $old->id)->exists()) throw new RuntimeException('benchmark_duplicate_experiment');
                DB::table(self::RUNS)->insert(['site_id' => $siteId, 'context_key' => $context, 'teaching' => $teaching, 'status' => 'collecting', 'created_by' => $adminId, 'created_at' => time()]);
                if ($old) DB::table(self::GRANTS)->where('run_id', $old->id)->whereNull('revoked_at')->update(['revoked_at' => time()]);
            });
            return true;
        });
        if (!$created) throw new RuntimeException('benchmark_busy');
        return $this->overview($siteId);
    }

    /** One frozen case per account/site, enrolled before inference; failed jobs stay in the cohort. */
    public function enroll(SubscriptionAiAction $action, array $settings): void
    {
        if (!$this->available() || $settings['ai_auto_mode'] !== 'shadow' || ($action->evidence['source'] ?? '') !== 'independent_behavior_v1') return;
        $run = $this->latest($action->site_id);
        if (!$run || $run->status !== 'collecting' || $run->context_key !== $this->contextKey($settings)) return;
        DB::transaction(function () use ($run, $action): void {
            $run = DB::table(self::RUNS)->where('id', $run->id)->lockForUpdate()->first();
            if ($run->status !== 'collecting') return;
            $subject = hash_hmac('sha256', $action->site_id . ':' . $action->user_id, (string) config('app.key'));
            if (DB::table(self::CASES)->where('run_id', $run->id)->where('subject_hash', $subject)->exists()) return;
            $prior = DB::table(self::CASES . ' as c')->join('v2_subscription_ai_action as a', 'a.id', '=', 'c.action_id')
                ->where('a.user_id', $action->user_id)->where('a.site_id', $action->site_id)->orderBy('c.id')->first(['c.run_id', 'c.cohort']);
            if ($prior && (int) $prior->run_id !== (int) $run->id && $prior->cohort === 'holdout') return;
            $outcomes = $action->evidence['observed_outcomes'] ?? [];
            $legacy = ($outcomes['ai_blocked'] ?? 0) > 0 || ($outcomes['error'] ?? 0) > 0 || array_sum($outcomes) === 0
                ? null : (($outcomes['legacy_blocked'] ?? 0) > 0);
            DB::table(self::CASES)->insert([
                'run_id' => $run->id, 'action_id' => $action->id, 'subject_hash' => $subject,
                'cohort' => $prior->cohort ?? (hexdec(substr($subject, 0, 6)) % 5 === 0 ? 'training' : 'holdout'),
                'evidence' => json_encode($this->blindEvidence($action->evidence), JSON_THROW_ON_ERROR),
                'legacy_prediction' => $legacy, 'evidence_age_seconds' => max(0, time() - $action->evidence['last_event_at']), 'created_at' => time(),
            ]);
            $holdout = DB::table(self::CASES)->where('run_id', $run->id)->where('cohort', 'holdout')->count();
            $total = DB::table(self::CASES)->where('run_id', $run->id)->count();
            if ($holdout >= self::HOLDOUT_SIZE || $total >= 1000) DB::table(self::RUNS)->where('id', $run->id)->update(['status' => 'sealed']);
        });
    }

    public function blindEvidence(array $evidence): array
    {
        return array_intersect_key($evidence, array_flip(['source', 'features', 'feature_ids', 'event_ids', 'event_families', 'families', 'baseline_mature', 'last_event_at', 'window_seconds', 'sampling', 'reference']));
    }

    public function lesson(SubscriptionAiAction $action, array $settings): ?array
    {
        if (!$this->available()) return null;
        $case = DB::table(self::CASES)->where('action_id', $action->id)->first();
        if (!$case && $settings['ai_auto_mode'] === 'shadow') return null;
        $run = $case ? DB::table(self::RUNS)->where('id', $case->run_id)->first() : $this->latest($action->site_id);
        if (!$run) throw new RuntimeException('benchmark_context_changed');
        if ($run->status === 'cancelled' || $run->context_key !== $this->contextKey($settings)) throw new RuntimeException('benchmark_context_changed');
        return json_decode($run->teaching, true, 32, JSON_THROW_ON_ERROR);
    }

    public function finish(int $actionId, ?array $decision, array $usage = []): void
    {
        if (!$this->available()) return;
        $case = DB::table(self::CASES)->where('action_id', $actionId)->whereNull('completed_at')->first();
        if (!$case) return;
        $evidence = json_decode($case->evidence, true, 32, JSON_THROW_ON_ERROR);
        DB::table(self::CASES)->where('id', $case->id)->whereNull('completed_at')->update([
            'prediction' => $decision ? (($evidence['baseline_mature'] ?? false) && in_array($decision['action'], ['throttle', 'temporary_block', 'reset_token', 'suspend_subscription'], true)) : null,
            'prediction_ok' => $decision !== null, 'completed_at' => time(),
            'latency_ms' => max(0, (time() - $case->created_at) * 1000),
            'total_tokens' => ($usage['total_tokens'] ?? 0) > 0 ? (int) $usage['total_tokens'] : null,
        ]);
    }

    /** Separate from AI-action reviews; no model/rule answer is returned in this queue. */
    public function label(int $caseId, string $truth, bool $attested, int $adminId): array
    {
        if (!$this->available()) throw new RuntimeException('benchmark_migration_required');
        if (!$attested || !in_array($truth, ['benign', 'abuse', 'uncertain'], true)) throw new RuntimeException('independent_review_required');
        $case = DB::table(self::CASES)->where('id', $caseId)->first();
        if (!$case) throw new RuntimeException('benchmark_case_missing');
        $run = DB::table(self::RUNS)->where('id', $case->run_id)->first();
        DB::transaction(function () use ($run, $caseId, $truth, $adminId): void {
            DB::table(self::RUNS)->where('id', $run->id)->lockForUpdate()->first();
            $case = DB::table(self::CASES)->where('id', $caseId)->lockForUpdate()->first();
            if (!$case->completed_at) throw new RuntimeException('prediction_not_frozen');
            DB::table(self::CASES)->where('id', $caseId)->update(['truth' => $truth, 'truth_by' => $adminId, 'truth_at' => time()]);
            DB::table('v2_subscription_ai_benchmark_label')->insert(['case_id' => $caseId, 'truth' => $truth, 'admin_id' => $adminId, 'created_at' => time()]);
            DB::table(self::GRANTS)->where('run_id', $run->id)->whereNull('revoked_at')->update(['revoked_at' => time()]);
        });
        return $this->overview($run->site_id);
    }

    public function overview(int $siteId): array
    {
        if (!$this->available()) return ['available' => false, 'site_id' => $siteId, 'report' => ['eligible' => false, 'reasons' => ['benchmark_migration_required']], 'cases' => [], 'run' => null, 'pilot' => null];
        $run = $this->latest($siteId);
        $settings = app(SubscriptionAiAutomationService::class)->settings();
        $cases = $run ? DB::table(self::CASES . ' as c')->leftJoin('v2_subscription_ai_action as a', 'a.id', '=', 'c.action_id')->where('c.run_id', $run->id)->whereNotNull('c.completed_at')->whereNull('c.truth')->orderBy('c.id')->limit(20)->get(['c.id', 'c.cohort', 'c.evidence', 'c.created_at', 'a.user_id'])->map(fn($case) => [
            'id' => (int) $case->id, 'user_id' => $case->user_id === null ? null : (int) $case->user_id, 'cohort' => $case->cohort, 'evidence' => json_decode($case->evidence, true, 32, JSON_THROW_ON_ERROR), 'created_at' => (int) $case->created_at,
        ])->all() : [];
        $pilot = $run ? DB::table(self::GRANTS)->where('run_id', $run->id)->whereNull('revoked_at')->where('expires_at', '>', time())->latest('id')->first() : null;
        $reviewed = $run ? DB::table(self::CASES . ' as c')->leftJoin('v2_subscription_ai_action as a', 'a.id', '=', 'c.action_id')->where('c.run_id', $run->id)->whereNotNull('c.truth')->orderByDesc('c.truth_at')->orderByDesc('c.id')->limit(20)->get(['c.id', 'c.cohort', 'c.evidence', 'c.created_at', 'c.truth', 'a.user_id'])->map(fn($case) => [
            'id' => (int) $case->id, 'user_id' => $case->user_id === null ? null : (int) $case->user_id, 'cohort' => $case->cohort,
            'evidence' => json_decode($case->evidence, true, 32, JSON_THROW_ON_ERROR), 'created_at' => (int) $case->created_at, 'truth' => $case->truth,
        ])->all() : [];
        return ['available' => true, 'site_id' => $siteId, 'run' => $run ? ['id' => (int) $run->id, 'site_id' => (int) $run->site_id, 'status' => $run->status, 'created_at' => (int) $run->created_at] : null,
            'report' => $run ? $this->report($run, $settings) : ['eligible' => false, 'reasons' => ['benchmark_not_started']], 'cases' => $cases, 'reviewed_cases' => $reviewed,
            'pilot' => $pilot ? ['user_ids' => json_decode($pilot->user_ids, true), 'expires_at' => (int) $pilot->expires_at, 'approved_by' => (int) $pilot->approved_by] : null];
    }

    private function report(object $run, array $settings): array
    {
        $rows = DB::table(self::CASES)->where('run_id', $run->id)->where('cohort', 'holdout')->orderBy('id')->limit(self::HOLDOUT_SIZE)->get();
        $normal = 0; $abuse = 0; $wins = 0; $losses = 0; $missLosses = 0; $aiFp = 0; $oldFp = 0; $aiFn = 0; $oldFn = 0;
        $reasons = []; $latencies = []; $tokens = 0; $usageComplete = $rows->isNotEmpty(); $latencyComplete = $rows->isNotEmpty();
        if ($run->status === 'cancelled') $reasons[] = 'benchmark_cancelled';
        if ($run->context_key !== $this->contextKey($settings)) $reasons[] = 'benchmark_context_changed';
        if ($run->created_at < time() - 30 * 86400) $reasons[] = 'benchmark_expired';
        if ($rows->count() !== self::HOLDOUT_SIZE || $run->status === 'collecting') $reasons[] = 'sample_incomplete';
        foreach ($rows as $row) {
            if (!$row->completed_at || !$row->prediction_ok || $row->legacy_prediction === null || $row->prediction === null) $reasons[] = 'prediction_incomplete';
            if (!in_array($row->truth, ['benign', 'abuse'], true)) $reasons[] = 'independent_labels_incomplete';
            if ($row->latency_ms === null || $row->total_tokens === null || $row->evidence_age_seconds > 300) $reasons[] = 'operational_evidence_incomplete';
            if ($row->latency_ms === null) $latencyComplete = false; else $latencies[] = (int) $row->latency_ms;
            if ($row->total_tokens === null) $usageComplete = false; else $tokens += (int) $row->total_tokens;
            $ai = (bool) $row->prediction; $old = (bool) $row->legacy_prediction;
            if ($row->truth === 'benign') {
                $normal++; $aiFp += (int) $ai; $oldFp += (int) $old;
                $wins += (int) (!$ai && $old); $losses += (int) ($ai && !$old);
            } elseif ($row->truth === 'abuse') {
                $abuse++; $aiFn += (int) !$ai; $oldFn += (int) !$old; $missLosses += (int) (!$ai && $old);
            }
        }
        if ($normal < 200 || $abuse < 200) $reasons[] = 'class_coverage_insufficient';
        $fpP = $this->pairedP($wins, $losses);
        $missUpper = $this->binomialUpper($missLosses, $abuse);
        if ($fpP > 0.025 || $aiFp >= $oldFp) $reasons[] = 'false_positive_advantage_unproven';
        if ($aiFn > $oldFn || $missUpper > 0.02) $reasons[] = 'miss_safety_unproven';
        sort($latencies); $p95 = $latencies ? $latencies[(int) ceil(count($latencies) * 0.95) - 1] : null;
        if ($p95 === null || $p95 > 120000 || $tokens > self::HOLDOUT_SIZE * 8000) $reasons[] = 'operational_budget_exceeded';
        return ['eligible' => $reasons === [], 'reasons' => array_values(array_unique($reasons)), 'protocol' => self::PROTOCOL,
            'sample_count' => $rows->count(), 'sample_target' => self::HOLDOUT_SIZE, 'normal' => $normal, 'abuse' => $abuse,
            'ai_false_positives' => $aiFp, 'rule_false_positives' => $oldFp, 'ai_misses' => $aiFn, 'rule_misses' => $oldFn,
            'fp_p_value' => $fpP, 'extra_miss_upper' => $missUpper, 'queue_model_p95_ms' => $p95, 'total_tokens' => $tokens,
            'usage_complete' => $usageComplete, 'latency_complete' => $latencyComplete,
            'selected_sample_only' => true, 'cpu_memory_and_money_require_manual_signoff' => true];
    }

    /** Exact one-sided sign test on discordant benign pairs. */
    public function pairedP(int $wins, int $losses): float
    {
        return $wins + $losses === 0 ? 1.0 : $this->cdf($losses, $wins + $losses, 0.5);
    }

    /** One-sided 97.5% Clopper-Pearson bound on additional misses; no credit for offsetting wins. */
    public function binomialUpper(int $losses, int $count): float
    {
        if ($count === 0 || $losses >= $count) return 1.0;
        $low = $losses / $count; $high = 1.0;
        for ($i = 0; $i < 48; $i++) {
            $mid = ($low + $high) / 2;
            if ($this->cdf($losses, $count, $mid) > 0.025) $low = $mid; else $high = $mid;
        }
        return $high;
    }

    private function cdf(int $k, int $n, float $p): float
    {
        if ($p <= 0) return 1.0;
        if ($p >= 1) return $k >= $n ? 1.0 : 0.0;
        $term = $n * log1p(-$p); $logs = [$term];
        for ($i = 1; $i <= $k; $i++) { $term += log($n - $i + 1) - log($i) + log($p) - log1p(-$p); $logs[] = $term; }
        $max = max($logs); $sum = 0.0;
        foreach ($logs as $value) $sum += exp($value - $max);
        return min(1.0, exp($max) * $sum);
    }

    public function approve(int $runId, array $userIds, bool $confirmed, bool $operationsConfirmed, int $adminId): array
    {
        if (!$this->available() || !$confirmed || !$operationsConfirmed) throw new RuntimeException('manual_signoff_required');
        $userIds = array_values(array_unique($userIds));
        if (!$userIds || count($userIds) > 10 || array_filter($userIds, fn($id) => !is_int($id) || $id < 1)) throw new RuntimeException('invalid_pilot_scope');
        $run = DB::table(self::RUNS)->where('id', $runId)->first();
        if (!$run) throw new RuntimeException('benchmark_not_started');
        DB::transaction(function () use ($run, $userIds, $adminId): void {
            $locked = DB::table(self::RUNS)->where('id', $run->id)->lockForUpdate()->first();
            if ((int) $this->latest($run->site_id)->id !== (int) $run->id) throw new RuntimeException('benchmark_context_changed');
            $settings = app(SubscriptionAiAutomationService::class)->settings();
            $report = $this->report($locked, $settings);
            if (!$report['eligible']) throw new RuntimeException('benchmark_not_ready');
            $users = User::query()->whereIn('id', $userIds)->get();
            if ($users->count() !== count($userIds) || $users->contains(fn($user) => (int) ($user->site_id ?? 0) !== (int) $run->site_id || $user->is_admin || $user->is_staff || $user->banned)) throw new RuntimeException('invalid_pilot_scope');
            DB::table(self::GRANTS)->where('run_id', $run->id)->whereNull('revoked_at')->update(['revoked_at' => time()]);
            DB::table(self::GRANTS)->insert(['run_id' => $run->id, 'user_ids' => json_encode($userIds, JSON_THROW_ON_ERROR), 'report_hash' => hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)), 'approved_by' => $adminId, 'approved_at' => time(), 'operations_confirmed' => true, 'expires_at' => time() + 3600]);
        });
        return $this->overview($run->site_id);
    }

    public function cancel(int $runId, int $adminId): array
    {
        if (!$this->available()) throw new RuntimeException('benchmark_migration_required');
        $run = DB::table(self::RUNS)->where('id', $runId)->first();
        if (!$run) throw new RuntimeException('benchmark_not_started');
        DB::transaction(function () use ($run, $adminId): void {
            DB::table(self::RUNS)->where('id', $run->id)->lockForUpdate()->first();
            DB::table(self::RUNS)->where('id', $run->id)->update(['status' => 'cancelled', 'cancelled_by' => $adminId, 'cancelled_at' => time()]);
            DB::table(self::GRANTS)->where('run_id', $run->id)->whereNull('revoked_at')->update(['revoked_at' => time()]);
        });
        return $this->overview($run->site_id);
    }

    public function permitsMode(array $settings): bool
    {
        if ($settings['ai_auto_mode'] === 'shadow') return true;
        if (!$this->available() || $settings['ai_auto_mode'] !== 'independent' || $settings['ai_auto_allow_reset_token'] || $settings['ai_auto_allow_suspend'] || $settings['ai_auto_max_seconds'] > 120) return false;
        $runs = DB::table(self::GRANTS . ' as g')->join(self::RUNS . ' as r', 'r.id', '=', 'g.run_id')->whereNull('g.revoked_at')->where('g.operations_confirmed', true)->where('g.expires_at', '>', time())->get(['r.*']);
        $key = $this->contextKey($settings);
        foreach ($runs as $run) if ($run->status !== 'cancelled' && $run->created_at >= time() - 30 * 86400 && $run->context_key === $key && (int) $this->latest($run->site_id)->id === (int) $run->id) return true;
        return false;
    }

    public function permits(User $user, array $settings, string $action = 'observe'): bool
    {
        if ($user->is_admin || $user->is_staff || $user->banned) return false;
        if (!SubscriptionAiAutomationService::allowsSite($settings, (int) ($user->site_id ?? 0))) return false;
        if (!$this->permitsMode($settings) || in_array($action, ['reset_token', 'suspend_subscription'], true)) return false;
        $run = $this->latest((int) ($user->site_id ?? 0));
        if (!$run || $run->status === 'cancelled' || $run->context_key !== $this->contextKey($settings)) return false;
        $grants = DB::table(self::GRANTS)->where('run_id', $run->id)->whereNull('revoked_at')->where('operations_confirmed', true)->where('expires_at', '>', time())->get();
        foreach ($grants as $grant) if (in_array((int) $user->id, json_decode($grant->user_ids, true), true)) return true;
        return false;
    }

    public function revokeAllPilots(): void
    {
        if ($this->available()) DB::table(self::GRANTS)->whereNull('revoked_at')->update(['revoked_at' => time()]);
    }
}
