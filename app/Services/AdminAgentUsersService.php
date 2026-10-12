<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminAgentUsersService
{
    public const STATUSES = ['active', 'banned', 'expired', 'no_plan', 'exhausted'];

    public function fetch(int $agentUserId, array $filters): array
    {
        $agent = app(AgentOperationsService::class)->adminAgentQuery()->find($agentUserId);
        if (!$agent) {
            throw new ApiException('Agent not found');
        }

        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new ApiException('Invalid user status');
        }

        $case = $this->statusSql(time());
        $base = User::query()->join('v2_agent_user as ownership', 'ownership.sub_user_id', '=', 'v2_user.id')
            ->where('ownership.agent_user_id', $agentUserId);
        $summaryQuery = (clone $base)->selectRaw($case . ' AS user_status')->toBase();
        $summaryColumns = ['COUNT(*) AS total'];
        foreach (self::STATUSES as $key) {
            $summaryColumns[] = "COALESCE(SUM(CASE WHEN user_status = '{$key}' THEN 1 ELSE 0 END), 0) AS {$key}";
        }
        $summary = (array) DB::query()->fromSub($summaryQuery, 'agent_users')->selectRaw(implode(', ', $summaryColumns))->first();
        $summary = array_map(static fn ($value): int => (int) $value, $summary);

        $query = clone $base;
        if ($keyword !== '') {
            $query->where(function ($search) use ($keyword): void {
                $search->where('v2_user.email', 'like', '%' . $keyword . '%')
                    ->orWhere('ownership.remark', 'like', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $search->orWhere('v2_user.id', (int) $keyword);
                }
            });
        }
        if ($status !== '') {
            $query->whereRaw('(' . $case . ') = ?', [$status]);
        }

        $total = (clone $query)->count();
        $page = min($page, max(1, (int) ceil($total / $pageSize)));
        $fields = ['id', 'email', 'plan_id', 'expired_at', 'transfer_enable', 'u', 'd', 'balance', 'banned', 'created_at', 'last_login_at'];
        $users = $query->select(array_map(static fn ($key): string => 'v2_user.' . $key, $fields))
            ->addSelect(['ownership.remark', 'ownership.created_at as joined_at'])
            ->selectRaw($case . ' AS user_status')->with('plan:id,name')
            ->orderByDesc('v2_user.id')->forPage($page, $pageSize)->get();

        return [
            'agent_user_id' => $agentUserId,
            'agent_email' => (string) $agent->email,
            'scope' => 'direct',
            'summary' => $summary,
            'data' => $users->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'agent_user_id' => $agentUserId,
                'email' => (string) ($user->email ?? ''),
                'remark' => $user->remark,
                'plan_id' => $user->plan_id === null ? null : (int) $user->plan_id,
                'plan_name' => $user->plan?->name,
                'expired_at' => $user->expired_at === null ? null : (int) $user->expired_at,
                'transfer_enable' => (int) ($user->transfer_enable ?? 0),
                'u' => (int) ($user->u ?? 0),
                'd' => (int) ($user->d ?? 0),
                'balance' => (int) ($user->balance ?? 0),
                'banned' => (bool) $user->banned,
                'status' => (string) $user->user_status,
                'created_at' => $user->created_at === null ? null : (int) $user->created_at,
                'joined_at' => $user->joined_at === null ? null : (int) $user->joined_at,
                'last_login_at' => $user->last_login_at === null ? null : (int) $user->last_login_at,
            ])->all(),
            'total' => (int) $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    private function statusSql(int $now): string
    {
        // Keep availability consistent with UserSyncService, including zero/no expiry.
        return "CASE WHEN COALESCE(v2_user.banned, 0) <> 0 THEN 'banned'"
            . " WHEN v2_user.plan_id IS NULL OR v2_user.group_id IS NULL THEN 'no_plan'"
            . " WHEN v2_user.expired_at > 0 AND v2_user.expired_at < {$now} THEN 'expired'"
            . " WHEN v2_user.transfer_enable IS NULL OR COALESCE(v2_user.u, 0) + COALESCE(v2_user.d, 0) >= v2_user.transfer_enable THEN 'exhausted'"
            . " ELSE 'active' END";
    }
}
