<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserOnlineService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnforceSubscriptionControl extends Command
{
    protected $signature = 'subscription-control:enforce';
    protected $description = 'Enforce subscription control based on sustained online IP threshold';

    private const RECENT_EVENTS_KEY = 'subscription_control:recent_events';
    private const BLOCKED_COUNT_PREFIX = 'subscription_control:blocked_count:';
    private const STATE_PREFIX = 'subscription_control:state:';

    public function handle(UserOnlineService $onlineService): int
    {
        if (!$this->settingBool('subscription_control_enable', false)) {
            $this->info(json_encode([
                'enabled' => false,
                'scanned' => 0,
                'enforced' => 0,
                'skipped' => 0,
            ], JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $threshold = $this->settingInt('subscription_control_online_ip_threshold', 6, 1);
        $requiredWindows = $this->settingInt('subscription_control_required_windows', 3, 1);
        $windowSeconds = $this->settingInt('subscription_control_window_seconds', 300, 60);
        $cooldownMinutes = $this->settingInt('subscription_control_cooldown_minutes', 120, 1);
        $maxPerRun = $this->settingInt('subscription_control_max_per_run', 100, 1);
        $recentEventLimit = $this->settingInt('subscription_control_recent_event_limit', 500, 10);
        $action = $this->settingAction('subscription_control_action', 'reset_token');
        $whitelist = $this->parseWhitelist((string) admin_setting('subscription_control_whitelist_user_ids', ''));

        $candidates = $this->collectCandidates($onlineService, $threshold, $maxPerRun);
        $now = time();
        $enforced = 0;
        $skipped = 0;
        $blockedToday = 0;

        foreach ($candidates as $userId => $onlineIpCount) {
            $uid = (int) $userId;
            if ($uid <= 0) {
                $skipped++;
                continue;
            }
            if (isset($whitelist[$uid])) {
                $skipped++;
                continue;
            }

            $stateKey = self::STATE_PREFIX . $uid;
            $state = Cache::get($stateKey, []);
            if (!is_array($state)) {
                $state = [];
            }

            $cooldownUntil = (int) ($state['cooldown_until'] ?? 0);
            if ($cooldownUntil > $now) {
                $skipped++;
                continue;
            }

            $lastAt = (int) ($state['updated_at'] ?? 0);
            $streak = (int) ($state['streak'] ?? 0);
            if ($lastAt <= 0 || ($now - $lastAt) > $windowSeconds) {
                $streak = 0;
            }
            $streak++;

            $state['streak'] = $streak;
            $state['updated_at'] = $now;

            $stateTTLMinutes = max(10, $cooldownMinutes * 3);
            if ($streak < $requiredWindows) {
                Cache::put($stateKey, $state, now()->addMinutes($stateTTLMinutes));
                continue;
            }

            $user = User::query()->find($uid);
            if (!$user) {
                $state['streak'] = 0;
                Cache::put($stateKey, $state, now()->addMinutes($stateTTLMinutes));
                $skipped++;
                continue;
            }
            if ((bool) ($user->is_admin ?? false) || (bool) ($user->is_staff ?? false) || !$user->isActive()) {
                $state['streak'] = 0;
                Cache::put($stateKey, $state, now()->addMinutes($stateTTLMinutes));
                $skipped++;
                continue;
            }

            $changed = $this->applyAction($user, $action);
            if ($changed === false) {
                $state['streak'] = 0;
                Cache::put($stateKey, $state, now()->addMinutes($stateTTLMinutes));
                $skipped++;
                continue;
            }

            $state['streak'] = 0;
            $state['cooldown_until'] = $now + ($cooldownMinutes * 60);
            Cache::put($stateKey, $state, now()->addMinutes($stateTTLMinutes));

            $event = [
                'user_id' => $uid,
                'email' => (string) $user->email,
                'online_ip_count' => (int) $onlineIpCount,
                'threshold' => $threshold,
                'required_windows' => $requiredWindows,
                'window_seconds' => $windowSeconds,
                'action' => $action,
                'created_at' => $now,
            ];
            $this->pushRecentEvent($event, $recentEventLimit);

            if ($action !== 'log_only') {
                $blockedToday += $this->incrementBlockedToday($now);
            }
            $enforced++;
        }

        $result = [
            'enabled' => true,
            'threshold' => $threshold,
            'required_windows' => $requiredWindows,
            'window_seconds' => $windowSeconds,
            'action' => $action,
            'scanned' => count($candidates),
            'enforced' => $enforced,
            'skipped' => $skipped,
            'blocked_today_increment' => $blockedToday,
        ];

        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function applyAction(User $user, string $action): bool
    {
        if ($action === 'log_only') {
            return true;
        }

        try {
            $user->token = Helper::guid();
            if ($action === 'reset_token_uuid') {
                $user->uuid = Helper::guid(true);
            }
            return (bool) $user->save();
        } catch (\Throwable $e) {
            Log::warning('subscription-control action failed', [
                'user_id' => $user->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function pushRecentEvent(array $event, int $limit): void
    {
        $events = Cache::get(self::RECENT_EVENTS_KEY, []);
        if (!is_array($events)) {
            $events = [];
        }

        array_unshift($events, $event);
        if (count($events) > $limit) {
            $events = array_slice($events, 0, $limit);
        }

        Cache::put(self::RECENT_EVENTS_KEY, $events, now()->addDays(14));
    }

    private function incrementBlockedToday(int $now): int
    {
        $dayKey = self::BLOCKED_COUNT_PREFIX . date('Y-m-d', $now);
        Cache::add($dayKey, 0, now()->addDays(2));
        Cache::increment($dayKey);
        return 1;
    }

    private function settingBool(string $key, bool $default): bool
    {
        $raw = admin_setting($key, $default);
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            return $parsed;
        }
        return (bool) $raw;
    }

    private function settingInt(string $key, int $default, int $min): int
    {
        $raw = admin_setting($key, $default);
        $value = (int) $raw;
        if ($value < $min) {
            return $min;
        }
        return $value;
    }

    private function settingAction(string $key, string $default): string
    {
        $raw = trim((string) admin_setting($key, $default));
        return in_array($raw, ['reset_token', 'reset_token_uuid', 'log_only'], true)
            ? $raw
            : $default;
    }

    /**
     * @return array<int, true>
     */
    private function parseWhitelist(string $raw): array
    {
        $result = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $part) {
            $uid = (int) $part;
            if ($uid > 0) {
                $result[$uid] = true;
            }
        }
        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function collectCandidates(UserOnlineService $onlineService, int $threshold, int $maxPerRun): array
    {
        $candidates = $onlineService->getUsersExceedingOnlineIpThreshold($threshold, $maxPerRun);
        if (!empty($candidates) || UserOnlineService::isRealtimeIndexReady()) {
            arsort($candidates, SORT_NUMERIC);
            return array_slice($candidates, 0, $maxPerRun, true);
        }

        // Fallback path: realtime index not ready yet (cold start), use DB + fresh cache counts.
        $bufferLimit = max($maxPerRun * 3, $maxPerRun);
        User::query()
            ->where('online_count', '>', 0)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$candidates, $onlineService, $threshold, $bufferLimit): bool {
                if ($users->isEmpty()) {
                    return true;
                }

                $ids = $users->pluck('id')
                    ->map(fn($id): int => (int) $id)
                    ->all();
                $counts = $onlineService->getOnlineCounts($ids);

                foreach ($counts as $userId => $count) {
                    $uid = (int) $userId;
                    $onlineCount = (int) $count;
                    if ($uid <= 0 || $onlineCount <= $threshold) {
                        continue;
                    }
                    $this->pushTopCandidate($candidates, $uid, $onlineCount, $bufferLimit);
                }

                return true;
            }, 'id');

        arsort($candidates, SORT_NUMERIC);
        return array_slice($candidates, 0, $maxPerRun, true);
    }

    /**
     * Keep only the highest-count candidates while scanning fallback sources.
     *
     * @param array<int, int> $candidates
     */
    private function pushTopCandidate(array &$candidates, int $userId, int $onlineCount, int $limit): void
    {
        if (isset($candidates[$userId])) {
            if ($onlineCount > $candidates[$userId]) {
                $candidates[$userId] = $onlineCount;
            }
            return;
        }

        if (count($candidates) < $limit) {
            $candidates[$userId] = $onlineCount;
            return;
        }

        $minUserId = null;
        $minCount = null;
        foreach ($candidates as $uid => $count) {
            if ($minCount === null || $count < $minCount) {
                $minCount = $count;
                $minUserId = $uid;
            }
        }

        if ($minUserId !== null && $minCount !== null && $onlineCount > $minCount) {
            unset($candidates[$minUserId]);
            $candidates[$userId] = $onlineCount;
        }
    }
}
