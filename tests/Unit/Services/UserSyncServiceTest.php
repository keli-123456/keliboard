<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserSyncService;
use Tests\TestCase;

final class UserSyncServiceTest extends TestCase
{
    public function test_compute_snapshot_marks_regular_active_user_available(): void
    {
        $snapshot = (new UserSyncService())->computeSnapshot($this->makeNodeUser());

        $this->assertTrue($snapshot['available']);
    }

    public function test_compute_snapshot_excludes_users_without_plan_from_node_users(): void
    {
        $snapshot = (new UserSyncService())->computeSnapshot($this->makeNodeUser([
            'plan_id' => null,
        ]));

        $this->assertFalse($snapshot['available']);
    }

    public function test_compute_snapshot_excludes_admin_and_staff_users_from_node_users(): void
    {
        $service = new UserSyncService();

        $admin = $service->computeSnapshot($this->makeNodeUser([
            'is_admin' => true,
        ]));
        $staff = $service->computeSnapshot($this->makeNodeUser([
            'is_staff' => true,
        ]));

        $this->assertFalse($admin['available']);
        $this->assertFalse($staff['available']);
    }

    private function makeNodeUser(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'id' => 1,
            'uuid' => 'user-uuid',
            'group_id' => 10,
            'plan_id' => 20,
            'transfer_enable' => 1024,
            'u' => 100,
            'd' => 200,
            'expired_at' => time() + 3600,
            'banned' => false,
            'is_admin' => false,
            'is_staff' => false,
            'speed_limit' => 0,
            'device_limit' => 0,
        ], $overrides));

        return $user;
    }
}
