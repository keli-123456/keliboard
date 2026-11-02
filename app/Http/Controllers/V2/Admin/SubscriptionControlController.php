<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSubscriptionControlAiReviewJob;
use App\Services\SubscriptionControlAiAdvisorService;
use App\Services\SubscriptionControlCaseReviewService;
use App\Services\SubscriptionAiAutomationService;
use App\Services\SubscriptionAiBenchmarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;
use Plugin\SubscriptionControl\Services\SubscriptionSourceIpBlockService;

class SubscriptionControlController extends Controller
{
    private const RECENT_EVENTS_KEY = 'subscription_control:recent_events';

    public function benchmark(Request $request, SubscriptionAiBenchmarkService $service): JsonResponse
    {
        $data = $request->validate(['site_id' => ['required', 'integer', 'min:0']]);
        return $this->success($service->overview((int) $data['site_id']));
    }

    public function startBenchmark(Request $request, SubscriptionAiBenchmarkService $service): JsonResponse
    {
        $data = $request->validate(['site_id' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted']]);
        try { return $this->success($service->start((int) $data['site_id'], (int) $request->user()->id, true)); }
        catch (\RuntimeException $error) { return $this->advisorFailure($error->getMessage()); }
    }

    public function labelBenchmark(Request $request, SubscriptionAiBenchmarkService $service): JsonResponse
    {
        $data = $request->validate(['case_id' => ['required', 'integer', 'min:1'], 'truth' => ['required', 'in:benign,abuse,uncertain'], 'attested' => ['required', 'accepted']]);
        try { return $this->success($service->label((int) $data['case_id'], $data['truth'], true, (int) $request->user()->id)); }
        catch (\RuntimeException $error) { return $this->advisorFailure($error->getMessage()); }
    }

    public function approveBenchmark(Request $request, SubscriptionAiBenchmarkService $service): JsonResponse
    {
        $data = $request->validate(['run_id' => ['required', 'integer', 'min:1'], 'user_ids' => ['required', 'array', 'min:1', 'max:10'],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'confirmed' => ['required', 'accepted'], 'operations_confirmed' => ['required', 'accepted']]);
        try { return $this->success($service->approve((int) $data['run_id'], array_map('intval', $data['user_ids']), true, true, (int) $request->user()->id)); }
        catch (\RuntimeException $error) { return $this->advisorFailure($error->getMessage()); }
    }

    public function cancelBenchmark(Request $request, SubscriptionAiBenchmarkService $service): JsonResponse
    {
        $data = $request->validate(['run_id' => ['required', 'integer', 'min:1']]);
        try { return $this->success($service->cancel((int) $data['run_id'], (int) $request->user()->id)); }
        catch (\RuntimeException $error) { return $this->advisorFailure($error->getMessage()); }
    }

    public function automation(SubscriptionAiAutomationService $service): JsonResponse
    {
        return $this->success($service->overview());
    }

    public function saveAutomation(Request $request, SubscriptionAiAutomationService $service): JsonResponse
    {
        $data = $request->validate([
            'ai_auto_enabled' => ['required', 'boolean'], 'ai_auto_allow_reset_token' => ['required', 'boolean'],
            'ai_auto_allow_suspend' => ['required', 'boolean'], 'ai_auto_notify' => ['required', 'boolean'],
            'ai_auto_max_seconds' => ['required', 'integer', 'min:60', 'max:900'],
            'ai_auto_hourly_budget' => ['required', 'integer', 'min:1', 'max:30'],
            'ai_auto_active_limit' => ['required', 'integer', 'min:1', 'max:20'], 'confirmed' => ['required', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'ai_auto_mode' => ['required', 'in:shadow,independent,legacy'],
            'ai_auto_site_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'ai_auto_site_ids.*' => ['required', 'integer', 'min:0', 'distinct'],
        ]);
        try { return $this->success($service->saveSettings($data, (bool) $data['confirmed'], (int) $request->user()->id, (int) $data['expected_revision'])); }
        catch (\RuntimeException $error) { return $this->advisorFailure($error->getMessage()); }
    }

    public function runAutomation(SubscriptionAiAutomationService $service): JsonResponse
    {
        return $this->success($service->tick());
    }

    public function stopAutomation(Request $request, SubscriptionAiAutomationService $service): JsonResponse
    {
        return $this->success($service->saveSettings(array_merge($service->settings(), ['ai_auto_enabled' => false,
            'ai_auto_allow_reset_token' => false, 'ai_auto_allow_suspend' => false]), false, (int) $request->user()->id));
    }

    public function revokeAutomation(Request $request, int $actionId, SubscriptionAiAutomationService $service): JsonResponse
    {
        return $this->success($service->revoke($actionId, (int) $request->user()->id));
    }

    public function reviewAutomation(Request $request, int $actionId, SubscriptionAiAutomationService $service): JsonResponse
    {
        $data = $request->validate(['verdict' => ['required', 'in:confirmed,false_positive,uncertain']]);
        try { return $this->success($service->review($actionId, $data['verdict'], (int) $request->user()->id)); }
        catch (\RuntimeException $error) { return $this->advisorFailure($error->getMessage()); }
    }

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

    public function reviewHighRiskCase(
        Request $request,
        int $userId,
        SubscriptionControlCaseReviewService $service
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:watching,confirmed_leak,false_positive,cleared'],
            'note' => ['nullable', 'string', 'max:1000'],
            'suspicion_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'verdict' => ['nullable', 'string', 'max:64'],
            'confidence' => ['nullable', 'string', 'max:32'],
            'case_evidence' => ['nullable', 'array', 'max:16'],
            'case_evidence.*' => ['string', 'max:64'],
            'false_positive_factors' => ['nullable', 'array', 'max:12'],
            'false_positive_factors.*' => ['string', 'max:64'],
            'post_reset_retrigger_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'last_trigger_at' => ['nullable', 'integer', 'min:0'],
            'model_version' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            return $this->success($service->review(
                $userId,
                (string) $data['status'],
                isset($data['note']) ? (string) $data['note'] : null,
                (int) ($request->user()?->id ?? 0),
                $data
            ));
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
