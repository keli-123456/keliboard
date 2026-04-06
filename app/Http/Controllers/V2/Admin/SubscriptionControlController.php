<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SubscriptionControlController extends Controller
{
    private const RECENT_EVENTS_KEY = 'subscription_control:recent_events';

    public function overview(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        $events = Cache::get(self::RECENT_EVENTS_KEY, []);
        if (!is_array($events)) {
            $events = [];
        }

        $events = array_values(array_filter($events, fn($item) => is_array($item)));
        usort($events, fn(array $a, array $b) => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
        $events = array_slice($events, 0, $limit);

        $blockedToday = (int) Cache::get('subscription_control:blocked_count:' . date('Y-m-d'), 0);
        $resetCount = count(array_filter($events, fn(array $event) => in_array((string) ($event['action'] ?? ''), ['reset_token', 'reset_token_uuid'], true)));

        return $this->success([
            'stats' => [
                'blocked_today' => $blockedToday,
                'recent_event_count' => count($events),
                'recent_reset_count' => $resetCount,
                'last_trigger_at' => $events[0]['created_at'] ?? null,
            ],
            'recent_events' => $events,
        ]);
    }
}
