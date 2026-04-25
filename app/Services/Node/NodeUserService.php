<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Models\UserSyncEvent;
use App\Services\ServerService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class NodeUserService
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private static bool $deltaUnionQueryDisabled = false;

    public function buildUserCacheEntry($node): array
    {
        return $this->encodeAvailableUsers($node, '{"users":[', ']}', true);
    }

    public function buildDeltaResponseEntry($node, int $since, $requestedLimit): array
    {
        $state = $this->resolveDeltaState($since);
        if ($state['full']) {
            return [
                'raw' => true,
                'body' => $this->encodeFullDeltaSnapshot($node, $state['revision']),
            ];
        }

        return [
            'raw' => false,
            'data' => $this->buildIncrementalDeltaResponse($node, $since, $requestedLimit, $state['revision']),
        ];
    }

    private function encodeFullDeltaSnapshot($node, int $revision): string
    {
        $entry = $this->encodeAvailableUsers($node, '{"full":true,"revision":' . $revision . ',"users":[', ']}', false);

        return $entry['body'];
    }

    private function encodeAvailableUsers($node, string $prefix, string $suffix, bool $withEtag): array
    {
        $eTagContext = hash_init('sha1');
        $body = $prefix;
        $first = true;

        ServerService::eachAvailableUser($node, function ($user) use (&$body, &$first, &$eTagContext, $withEtag): void {
            $row = $this->normalizeUserSnapshotRow($user);

            if ($withEtag) {
                hash_update($eTagContext, "{$row['id']}:{$row['uuid']}:{$row['speed_limit']}:{$row['device_limit']};");
            }

            $encoded = json_encode($row, self::JSON_FLAGS);
            if ($encoded === false) {
                $encoded = json_encode($this->coreUserSnapshotRow($row), self::JSON_FLAGS);
            }
            if ($encoded === false) {
                return;
            }

            if (!$first) {
                $body .= ',';
            }
            $body .= $encoded;
            $first = false;
        });

        $body .= $suffix;

        return [
            'etag' => $withEtag ? hash_final($eTagContext) : '',
            'body' => $body,
        ];
    }

    public function buildDeltaResponse($node, int $since, $requestedLimit): array
    {
        $state = $this->resolveDeltaState($since);
        if ($state['full']) {
            return $this->buildFullDeltaResponse($node, $state['revision']);
        }

        return $this->buildIncrementalDeltaResponse($node, $since, $requestedLimit, $state['revision']);
    }

    private function resolveDeltaState(int $since): array
    {
        $maxId = (int) (UserSyncEvent::query()->max('id') ?? 0);

        if ($since <= 0 || $maxId <= 0) {
            return [
                'full' => true,
                'revision' => $maxId,
            ];
        }

        $oldestId = (int) (UserSyncEvent::query()->orderBy('id', 'asc')->value('id') ?? 0);
        if ($oldestId > 0 && $since < $oldestId) {
            return [
                'full' => true,
                'revision' => $maxId,
            ];
        }

        return [
            'full' => false,
            'revision' => $maxId,
        ];
    }

    private function buildIncrementalDeltaResponse($node, int $since, $requestedLimit, int $maxId): array
    {
        $limit = $this->resolveDeltaLimit($requestedLimit);

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
            'users' => $this->normalizeUserSnapshotRows(ServerService::getAvailableUsers($node)),
        ];
    }

    private function normalizeUserSnapshotRows(Collection $users): array
    {
        $items = $users->all();
        unset($users);

        foreach ($items as $index => $user) {
            $items[$index] = $this->normalizeUserSnapshotRow($user);
        }

        return $items;
    }

    private function normalizeUserSnapshotRow($user): array
    {
        if ($user instanceof Arrayable) {
            $row = $user->toArray();
        } elseif (is_array($user)) {
            $row = $user;
        } elseif (is_object($user)) {
            $row = get_object_vars($user);
        } else {
            $row = [];
        }

        $row['id'] = (int) ($row['id'] ?? data_get($user, 'id', 0));
        $row['uuid'] = (string) ($row['uuid'] ?? data_get($user, 'uuid', ''));
        $row['speed_limit'] = (int) ($row['speed_limit'] ?? data_get($user, 'speed_limit', 0));
        $row['device_limit'] = (int) ($row['device_limit'] ?? data_get($user, 'device_limit', 0));

        return $row;
    }

    private function coreUserSnapshotRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'speed_limit' => (int) $row['speed_limit'],
            'device_limit' => (int) $row['device_limit'],
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
