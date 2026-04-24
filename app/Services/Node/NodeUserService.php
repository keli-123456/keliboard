<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Models\UserSyncEvent;
use App\Services\ServerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class NodeUserService
{
    private static bool $deltaUnionQueryDisabled = false;

    public function buildUserCacheEntry($node): array
    {
        $users = ServerService::getAvailableUsers($node);
        $eTagContext = hash_init('sha1');
        foreach ($users as $index => $user) {
            $userId = (int) data_get($user, 'id', 0);
            $uuid = (string) data_get($user, 'uuid', '');
            $speedLimit = (int) data_get($user, 'speed_limit', 0);
            $deviceLimit = (int) data_get($user, 'device_limit', 0);

            if (is_object($user)) {
                $user->id = $userId;
                $user->uuid = $uuid;
                $user->speed_limit = $speedLimit;
                $user->device_limit = $deviceLimit;
            } elseif (is_array($user)) {
                $user['id'] = $userId;
                $user['uuid'] = $uuid;
                $user['speed_limit'] = $speedLimit;
                $user['device_limit'] = $deviceLimit;
                $users[$index] = $user;
            }

            hash_update($eTagContext, "{$userId}:{$uuid}:{$speedLimit}:{$deviceLimit};");
        }

        $response = ['users' => $users];
        $eTag = hash_final($eTagContext);
        $body = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'etag' => $eTag,
            'body' => $body === false ? '{"users":[]}' : $body,
        ];
    }

    public function buildDeltaResponse($node, int $since, $requestedLimit): array
    {
        $limit = $this->resolveDeltaLimit($requestedLimit);
        $maxId = (int) (UserSyncEvent::query()->max('id') ?? 0);

        // First sync or expired delta window requires a full snapshot.
        if ($since <= 0 || $maxId <= 0) {
            return $this->buildFullDeltaResponse($node, $maxId);
        }

        $oldestId = (int) (UserSyncEvent::query()->orderBy('id', 'asc')->value('id') ?? 0);
        if ($oldestId > 0 && $since < $oldestId) {
            return $this->buildFullDeltaResponse($node, $maxId);
        }

        $groupIds = $this->resolveGroupIds($node);
        if (empty($groupIds)) {
            return [
                'full' => false,
                'revision' => $maxId,
                'deleted' => [],
                'upsert' => [],
            ];
        }

        $events = $this->queryUserDeltaEvents($since, $groupIds, $limit);
        [$deleted, $upsert] = $this->buildDeltaChanges($events, $groupIds);

        $nextRevision = $maxId;
        if ($events->isNotEmpty() && $events->count() >= $limit) {
            $nextRevision = (int) $events->last()->id;
        }

        return [
            'full' => false,
            'revision' => $nextRevision,
            'deleted' => $deleted,
            'upsert' => $upsert,
        ];
    }

    private function resolveDeltaLimit($requestedLimit): int
    {
        $limitCfg = (int) admin_setting('user_sync_delta_limit', config('user_sync.delta_limit', 5000));
        if ($limitCfg <= 0) {
            $limitCfg = 5000;
        }

        $limit = (int) ($requestedLimit ?? $limitCfg);
        if ($limit <= 0) {
            $limit = $limitCfg;
        }

        return min($limit, $limitCfg);
    }

    private function buildFullDeltaResponse($node, int $revision): array
    {
        return [
            'full' => true,
            'revision' => $revision,
            'users' => ServerService::getAvailableUsers($node),
        ];
    }

    private function resolveGroupIds($node): array
    {
        $groups = (array) ($node->group_ids ?? []);

        return array_values(array_unique(array_map('intval', $groups)));
    }

    /**
     * Query delta events with an index-friendly UNION strategy and automatic
     * fallback to the legacy OR predicate when needed.
     */
    private function queryUserDeltaEvents(int $since, array $groupIds, int $limit): Collection
    {
        if (!$this->shouldUseUnionQueryForDelta()) {
            return $this->queryUserDeltaEventsLegacy($since, $groupIds, $limit);
        }

        try {
            $groupMatches = UserSyncEvent::query()
                ->select(['id'])
                ->where('id', '>', $since)
                ->whereIn('group_id', $groupIds);

            $oldGroupMatches = UserSyncEvent::query()
                ->select(['id'])
                ->where('id', '>', $since)
                ->whereIn('old_group_id', $groupIds);

            $distinctIds = DB::query()
                ->fromSub($groupMatches->union($oldGroupMatches), 'delta_match_ids')
                ->select(['id'])
                ->distinct()
                ->orderBy('id', 'asc')
                ->limit($limit);

            return UserSyncEvent::query()
                ->joinSub($distinctIds, 'delta_ids', function ($join) {
                    $join->on('user_sync_events.id', '=', 'delta_ids.id');
                })
                ->orderBy('user_sync_events.id', 'asc')
                ->get(['user_sync_events.*']);
        } catch (Throwable) {
            self::$deltaUnionQueryDisabled = true;

            return $this->queryUserDeltaEventsLegacy($since, $groupIds, $limit);
        }
    }

    private function queryUserDeltaEventsLegacy(int $since, array $groupIds, int $limit): Collection
    {
        return UserSyncEvent::query()
            ->where('id', '>', $since)
            ->where(function ($q) use ($groupIds) {
                $q->whereIn('group_id', $groupIds)
                    ->orWhereIn('old_group_id', $groupIds);
            })
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
    }

    private function shouldUseUnionQueryForDelta(): bool
    {
        if (self::$deltaUnionQueryDisabled) {
            return false;
        }

        return (bool) config('user_sync.use_union_query_for_delta', true);
    }

    private function buildDeltaChanges(Collection $events, array $groupIds): array
    {
        $deleted = [];
        $upsert = [];

        foreach ($events as $event) {
            $oldVisible = (bool) $event->old_available
                && $event->old_group_id !== null
                && in_array((int) $event->old_group_id, $groupIds, true);
            $newVisible = (bool) $event->available
                && $event->group_id !== null
                && in_array((int) $event->group_id, $groupIds, true);

            if ($oldVisible && !$newVisible) {
                $deleted[] = [
                    'id' => (int) $event->user_id,
                    'uuid' => (string) ($event->old_uuid ?: $event->uuid),
                    'speed_limit' => 0,
                    'device_limit' => 0,
                ];
                continue;
            }

            if ($oldVisible && $newVisible && $event->old_uuid && $event->old_uuid !== $event->uuid) {
                $deleted[] = [
                    'id' => (int) $event->user_id,
                    'uuid' => (string) $event->old_uuid,
                    'speed_limit' => 0,
                    'device_limit' => 0,
                ];
            }

            if ($newVisible) {
                $upsert[] = [
                    'id' => (int) $event->user_id,
                    'uuid' => (string) $event->uuid,
                    'speed_limit' => (int) $event->speed_limit,
                    'device_limit' => (int) $event->device_limit,
                ];
            }
        }

        return [$deleted, $upsert];
    }
}
