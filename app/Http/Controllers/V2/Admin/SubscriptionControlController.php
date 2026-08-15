<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSubscriptionControlAiReviewJob;
use App\Services\SubscriptionControlAiAdvisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;
use Plugin\SubscriptionControl\Services\SubscriptionSourceIpBlockService;

class SubscriptionControlController extends Controller
{
    private const RECENT_EVENTS_KEY = 'subscription_control:recent_events';

    public function overview(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        $email = trim((string) $request->input('email', ''));
        $store = new SubscriptionControlEventStore();
        $events = $store->recent($limit, $email);

        if (empty($events)) {
            $events = $this->recentEventsFromCache($limit, $email);
        }

        $blockedToday = (int) Cache::get('subscription_control:blocked_count:' . date('Y-m-d'), 0);
        $resetCount = count(array_filter($events, fn(array $event) => in_array((string) ($event['action'] ?? ''), ['reset_token', 'reset_token_uuid'], true)));
        $ipIntelligenceStats = $this->ipIntelligenceStats($events);

        return $this->success([
            'stats' => [
                'blocked_today' => $blockedToday,
                'recent_event_count' => count($events),
                'recent_reset_count' => $resetCount,
                'last_trigger_at' => $events[0]['created_at'] ?? null,
            ] + $ipIntelligenceStats,
            'recent_events' => $events,
        ]);
    }

    public function sourceIpBlocks(SubscriptionSourceIpBlockService $service): JsonResponse
    {
        return $this->success($service->list());
    }

    public function unblockSourceIp(Request $request, SubscriptionSourceIpBlockService $service): JsonResponse
    {
        $data = $request->validate([
            'entry' => ['required', 'string', 'max:128'],
        ]);

        return $this->success($service->unblock((string) $data['entry']));
    }

    public function aiAdvisor(SubscriptionControlAiAdvisorService $service): JsonResponse
    {
        return $this->success($service->overview());
    }

    public function analyzeWithAi(Request $request, SubscriptionControlAiAdvisorService $service): JsonResponse
    {
        $data = $request->validate([
            'window_days' => ['nullable', 'integer', 'min:3', 'max:30'],
        ]);

        try {
            $review = $service->create((int) ($request->user()?->id ?? 0), (int) ($data['window_days'] ?? 7));
            GenerateSubscriptionControlAiReviewJob::dispatch((int) $review->id)->afterResponse();

            return $this->success($service->serialize($review));
        } catch (\RuntimeException $exception) {
            return $this->advisorFailure($exception->getMessage());
        }
    }

    public function applyAiSuggestions(Request $request, int $reviewId, SubscriptionControlAiAdvisorService $service): JsonResponse
    {
        $data = $request->validate([
            'suggestion_ids' => ['required', 'array', 'min:1', 'max:9'],
            'suggestion_ids.*' => ['required', 'string', 'max:64'],
        ]);

        try {
            return $this->success($service->serialize($service->apply($reviewId, $data['suggestion_ids'])));
        } catch (\RuntimeException $exception) {
            return $this->advisorFailure($exception->getMessage());
        }
    }

    public function rollbackAiSuggestions(int $reviewId, SubscriptionControlAiAdvisorService $service): JsonResponse
    {
        try {
            return $this->success($service->serialize($service->rollback($reviewId)));
        } catch (\RuntimeException $exception) {
            return $this->advisorFailure($exception->getMessage());
        }
    }

    private function advisorFailure(string $code): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'message' => $code,
            'data' => null,
            'error' => $code,
        ], 422);
    }

    private function recentEventsFromCache(int $limit, string $email = ''): array
    {
        $events = Cache::get(self::RECENT_EVENTS_KEY, []);
        if (!is_array($events)) {
            return [];
        }

        $events = array_values(array_filter($events, fn($item) => is_array($item)));
        $retentionCutoff = time() - (3 * 86400);
        $events = array_values(array_filter($events, fn(array $event): bool => (int) ($event['created_at'] ?? 0) >= $retentionCutoff));
        if ($email !== '') {
            $needle = strtolower($email);
            $events = array_values(array_filter($events, function (array $event) use ($needle): bool {
                $eventEmail = strtolower(trim((string) ($event['email'] ?? '')));

                return $eventEmail !== '' && str_contains($eventEmail, $needle);
            }));
        }

        usort($events, fn(array $a, array $b) => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
        return array_slice($events, 0, $limit);
    }

    private function ipIntelligenceStats(array $events): array
    {
        $stats = [
            'ip_intelligence_event_count' => 0,
            'ip_intelligence_labeled_count' => 0,
            'ip_intelligence_hosting_count' => 0,
            'ip_intelligence_proxy_count' => 0,
            'ip_intelligence_unknown_count' => 0,
        ];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $stats['ip_intelligence_event_count']++;
            $type = strtolower(trim((string) ($event['ip_type'] ?? 'unknown')));
            if ($type === 'hosting') {
                $stats['ip_intelligence_labeled_count']++;
                $stats['ip_intelligence_hosting_count']++;
            } elseif ($type === 'proxy') {
                $stats['ip_intelligence_labeled_count']++;
                $stats['ip_intelligence_proxy_count']++;
            } elseif (in_array($type, ['residential', 'private'], true)) {
                $stats['ip_intelligence_labeled_count']++;
            } else {
                $stats['ip_intelligence_unknown_count']++;
            }
        }

        return $stats;
    }
}
