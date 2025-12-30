<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSyncEvent;
use App\Models\UserSyncState;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserSyncService
{
    public function computeSnapshot(User $user, ?int $now = null): array
    {
        $now = $now ?? time();

        $groupId = $user->group_id;
        $expiredAt = $user->expired_at;
        $transferEnable = $user->transfer_enable;
        $used = ($user->u ?? 0) + ($user->d ?? 0);

        $available = true;
        if ($groupId === null) {
            $available = false;
        }
        if ($user->banned) {
            $available = false;
        }
        if ($expiredAt !== null && (int) $expiredAt > 0 && (int) $expiredAt < $now) {
            $available = false;
        }
        if ($transferEnable === null) {
            $available = false;
        } elseif ($used >= (int) $transferEnable) {
            $available = false;
        }

        return [
            'user_id' => (int) $user->id,
            'group_id' => $groupId === null ? null : (int) $groupId,
            'uuid' => (string) $user->uuid,
            'speed_limit' => (int) ($user->speed_limit ?? 0),
            'device_limit' => (int) ($user->device_limit ?? 0),
            'available' => (bool) $available,
        ];
    }

    public function syncUser(User $user, string $reason = 'user_sync'): void
    {
        $new = $this->computeSnapshot($user);

        DB::transaction(function () use ($new, $reason) {
            $state = UserSyncState::query()->lockForUpdate()->find($new['user_id']);
            if (!$state) {
                UserSyncState::query()->create($new);
                // For a brand new user, emit an event so nodes can pick it up via delta.
                if ($reason === 'created') {
                    $this->insertEvent([
                        'user_id' => $new['user_id'],
                        'old_group_id' => $new['group_id'],
                        'group_id' => $new['group_id'],
                        'old_available' => false,
                        'available' => $new['available'],
                        'old_uuid' => null,
                        'uuid' => $new['uuid'],
                        'speed_limit' => $new['speed_limit'],
                        'device_limit' => $new['device_limit'],
                    ]);
                }
                return;
            }

            $old = [
                'user_id' => (int) $state->user_id,
                'group_id' => $state->group_id === null ? null : (int) $state->group_id,
                'uuid' => (string) $state->uuid,
                'speed_limit' => (int) $state->speed_limit,
                'device_limit' => (int) $state->device_limit,
                'available' => (bool) $state->available,
            ];

            $changed = (
                $old['group_id'] !== $new['group_id']
                || $old['uuid'] !== $new['uuid']
                || $old['speed_limit'] !== $new['speed_limit']
                || $old['device_limit'] !== $new['device_limit']
                || $old['available'] !== $new['available']
            );
            if (!$changed) {
                return;
            }

            $this->insertEvent([
                'user_id' => $new['user_id'],
                'old_group_id' => $old['group_id'],
                'group_id' => $new['group_id'],
                'old_available' => $old['available'],
                'available' => $new['available'],
                'old_uuid' => $old['uuid'],
                'uuid' => $new['uuid'],
                'speed_limit' => $new['speed_limit'],
                'device_limit' => $new['device_limit'],
            ]);

            $state->fill($new);
            $state->save();
        });
    }

    public function syncUserById(int $userId, string $reason = 'user_sync'): void
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return;
        }
        try {
            $this->syncUser($user, $reason);
        } catch (Throwable) {
            // Best-effort: avoid breaking traffic pipeline / user updates.
        }
    }

    public function markUserDeleted(int $userId): void
    {
        try {
            DB::transaction(function () use ($userId) {
                $state = UserSyncState::query()->lockForUpdate()->find($userId);
                if (!$state) {
                    return;
                }
                if (!$state->available) {
                    return;
                }

                $old = [
                    'user_id' => (int) $state->user_id,
                    'group_id' => $state->group_id === null ? null : (int) $state->group_id,
                    'uuid' => (string) $state->uuid,
                    'available' => (bool) $state->available,
                ];

                $this->insertEvent([
                    'user_id' => $old['user_id'],
                    'old_group_id' => $old['group_id'],
                    'group_id' => $old['group_id'],
                    'old_available' => $old['available'],
                    'available' => false,
                    'old_uuid' => $old['uuid'],
                    'uuid' => $old['uuid'],
                    'speed_limit' => (int) $state->speed_limit,
                    'device_limit' => (int) $state->device_limit,
                ]);

                $state->available = false;
                $state->save();
            });
        } catch (Throwable) {
        }
    }

    private function insertEvent(array $data): void
    {
        $data['created_at'] = now();
        UserSyncEvent::query()->create($data);
    }
}
