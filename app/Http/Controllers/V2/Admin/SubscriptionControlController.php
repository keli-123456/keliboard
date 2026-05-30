<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;

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
}
