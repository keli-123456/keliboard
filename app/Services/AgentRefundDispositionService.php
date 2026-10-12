<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\User;
use App\Models\UserSyncState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class AgentRefundDispositionService
{
    public function preview(Order $order, User $user, int $page = 1): array
    {
        $profile = Schema::hasTable('v2_agent_profile')
            ? AgentProfile::query()->where('user_id', $user->id)->first()
            : null;
        $hasOwnerships = Schema::hasTable('v2_agent_user');
        if ($hasOwnerships && AgentUser::query()->where('agent_user_id', $user->id)
            ->whereNotIn('sub_user_id', User::query()->select('id'))->exists()) {
            throw new \InvalidArgumentException('下级归属记录包含不存在的用户，请先人工核对');
        }
        $total = 0;
        $available = 0;
        $sync = app(UserSyncService::class);
        $digest = hash_init('sha256');
        if ($hasOwnerships) {
            // Hash only entitlement/ownership fields: ordinary traffic updates must not invalidate confirmation.
            foreach ($this->subordinates($user)->orderBy('v2_user.id')->cursor() as $child) {
                $total++;
                $available += (int) $sync->computeSnapshot($child)['available'];
                hash_update($digest, json_encode([
                    (int) $child->id, $child->email, $child->site_id, $child->invite_user_id,
                    $child->plan_id, $child->group_id, $child->expired_at,
                    $child->transfer_enable, (bool) $child->banned,
                    (bool) $child->is_admin, (bool) $child->is_staff,
                ], JSON_THROW_ON_ERROR));
            }
        }
        $isAgent = $profile !== null || $total > 0;
        if ($isAgent && (!Schema::hasTable('v2_agent_profile') || !Schema::hasColumn('v2_order', 'agent_refund_disposition'))) {
            throw new \InvalidArgumentException('请先完成数据库迁移，再处置代理退款');
        }
        $page = min(max(1, $page), max(1, (int) ceil($total / 10)));
        $children = $hasOwnerships && $total > 0
            ? $this->subordinates($user)->with('plan:id,name')->orderBy('v2_user.id')->skip(($page - 1) * 10)->take(10)->get()
                ->map(fn (User $child): array => [
                    'id' => (int) $child->id,
                    'email' => (string) $child->email,
                    'plan_name' => $child->plan?->name,
                    'expired_at' => $child->expired_at,
                    'banned' => (bool) $child->banned,
                    'available' => $sync->computeSnapshot($child)['available'],
                ])->all()
            : [];
        $token = hash_hmac('sha256', json_encode([
            $order->id, $order->user_id, $order->trade_no, $order->status,
            $order->total_amount, $order->refund_disposed_at,
            $user->id, $user->balance, (bool) $user->banned, $profile?->status,
            hash_final($digest),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return [
            'trade_no' => $order->trade_no,
            'user_id' => (int) $user->id,
            'user_email' => (string) $user->email,
            'balance' => (int) $user->balance,
            'is_agent' => $isAgent,
            'agent_status' => $profile?->status,
            'subordinate_count' => $total,
            'available_count' => $available,
            'scope' => 'direct',
            'preview_token' => $token,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / 10)),
            'users' => $children,
            'already_processed' => (bool) $order->refund_disposed_at,
            'agent_disposition' => $order->agent_refund_disposition,
        ];
    }

    public function apply(Order $order, User $user, array $options, int $adminId): ?array
    {
        // The refund service holds the owner lock, shared with agent provisioning operations.
        $ownerships = Schema::hasTable('v2_agent_user')
            ? AgentUser::query()->where('agent_user_id', $user->id)->orderBy('sub_user_id')->lockForUpdate()->get()
            : collect();
        $children = $ownerships->isNotEmpty()
            ? User::query()->whereIn('id', $ownerships->pluck('sub_user_id'))->orderBy('id')->lockForUpdate()->get()
            : collect();
        if (Schema::hasTable('v2_agent_profile')) {
            AgentProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
        }
        $preview = $this->preview($order, $user);
        if (isset($options['preview_token']) && !hash_equals($preview['preview_token'], (string) $options['preview_token'])) {
            throw new \InvalidArgumentException('订单或下级用户状态已变化，请重新预览后确认');
        }
        if (!$preview['is_agent']) {
            if (!empty($options['agent_action'])) {
                throw new \InvalidArgumentException('该用户没有代理关系，不能执行下级处置');
            }
            return null;
        }
        $action = $options['agent_action'] ?? null;
        if (!in_array($action, ['preserve', 'freeze', 'transfer'], true)
            || empty($options['preview_token'])
            || !in_array($options['acknowledged'] ?? false, [true, 1, '1'], true)) {
            throw new \InvalidArgumentException('代理退款必须预览并明确确认下级处理方式');
        }
        $reason = trim((string) ($options['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('请填写不超过 500 字的退款处置原因');
        }
        if ($action !== 'preserve' && $children->contains(fn (User $child): bool =>
            (int) $child->id === (int) $user->id || $child->is_admin || $child->is_staff)) {
            throw new \InvalidArgumentException('下级包含管理员、员工或异常归属，请先人工核对');
        }

        $target = null;
        $targetEmail = trim((string) ($options['target_agent_email'] ?? ''));
        if ($action !== 'transfer' && $targetEmail !== '') {
            throw new \InvalidArgumentException('只有转移归属时才能指定接收代理');
        }
        if ($action === 'transfer' && $targetEmail !== '') {
            $target = $this->transferTarget($user, $targetEmail, $children->count());
        }
        $frozenCount = 0;
        $hasSyncStates = Schema::hasTable('user_sync_states');
        foreach ($children as $child) {
            if ($action === 'freeze') {
                if ($hasSyncStates) {
                    // Seed legacy users before banning so the delta contains an actual deletion.
                    UserSyncState::query()->firstOrCreate(
                        ['user_id' => $child->id], app(UserSyncService::class)->computeSnapshot($child)
                    );
                }
                if (!$child->banned) {
                    $child->banned = true;
                    $child->banned_reason = sprintf('代理退款关联冻结：%s', $order->trade_no);
                    $child->saveOrFail();
                    $frozenCount++;
                }
                if ($hasSyncStates) {
                    app(UserSyncService::class)->syncUser($child, 'agent_refund_freeze');
                }
                (new AuthService($child))->removeAllSessions();
            } elseif ($action === 'transfer' && (int) $child->invite_user_id === (int) $user->id) {
                $child->invite_user_id = $target?->id;
                $child->saveOrFail();
            }
        }
        if ($action === 'transfer') {
            $query = AgentUser::query()->whereIn('id', $ownerships->pluck('id'));
            if ($target) {
                $query->update(['agent_user_id' => $target->id, 'updated_at' => time()]);
            } else {
                $query->delete();
            }
        }
        AgentProfile::query()->updateOrCreate(['user_id' => $user->id], [
            'status' => AgentCenterService::STATUS_DISABLED,
            'disabled_at' => time(),
        ]);

        return [
            'scope' => 'direct',
            'action' => $action,
            'subordinate_count' => $children->count(),
            'available_count' => $preview['available_count'],
            'frozen_count' => $frozenCount,
            'target_agent_user_id' => $target?->id,
            'target_agent_email' => $target?->email,
            'previous_agent_status' => $preview['agent_status'],
            'agent_disabled' => true,
            'reason' => $reason,
            'admin_id' => $adminId,
            'processed_at' => time(),
        ];
    }

    private function subordinates(User $user): Builder
    {
        return User::query()->whereIn('id', AgentUser::query()->select('sub_user_id')->where('agent_user_id', $user->id));
    }

    private function transferTarget(User $source, string $email, int $count): User
    {
        $target = User::query()->where('email', $email)->lockForUpdate()->first();
        if (!$target || $target->banned || $target->is_admin || $target->is_staff || (int) $target->id === (int) $source->id) {
            throw new \InvalidArgumentException('接收代理不存在、已封禁或身份不适合接管');
        }
        $profile = AgentProfile::query()->where('user_id', $target->id)->lockForUpdate()->first();
        if ($profile?->status !== AgentCenterService::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('接收代理未启用');
        }
        $seen = [];
        $id = (int) $target->id;
        while ($id > 0) {
            if ($id === (int) $source->id || isset($seen[$id])) {
                throw new \InvalidArgumentException('不能转移给原代理的下级或循环归属账号');
            }
            $seen[$id] = true;
            $id = (int) AgentUser::query()->where('sub_user_id', $id)->lockForUpdate()->value('agent_user_id');
        }
        $limit = admin_setting('agent_center_user_limit', null);
        $limit = max(0, (int) ($limit === null || $limit === '' ? admin_setting('agent_center_daily_create_limit', 20) : $limit));
        if ($limit > 0 && AgentUser::query()->where('agent_user_id', $target->id)->count() + $count > $limit) {
            throw new \InvalidArgumentException('转移后将超过接收代理的用户数量上限');
        }
        return $target;
    }
}
