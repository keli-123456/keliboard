<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentLedger;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteUserScopeService;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentCenterService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PENDING = 'pending';

    public const LEDGER_UNLOCK = 'unlock';
    public const LEDGER_ASSIGN_PLAN = 'assign_plan';
    public const LEDGER_RESET_TRAFFIC = 'reset_traffic';
    public const LEDGER_RESET_SUBSCRIPTION = 'reset_subscription';
    public const LEDGER_DELETE_SUBORDINATE = 'delete_subordinate';
    public const LEDGER_GRANT_BONUS_DAYS = 'grant_bonus_days';
    public const LEDGER_REFUND = 'refund';
    public const LEDGER_ADMIN_ADJUST = 'admin_adjust';

    public function overview(User $agent): array
    {
        $this->assertEnabled();

        $profile = $this->profileFor($agent);
        $ownership = $this->subordinateOwnership($agent);
        $agent->refresh();

        return [
            'enabled' => true,
            'eligible' => $this->isEligible($agent),
            'profile' => $profile ? $this->profileSnapshot($profile) : null,
            'ownership' => $ownership ? $this->ownershipSnapshot($ownership) : null,
            'application' => $this->applicationSnapshot($profile, $ownership),
            'summary' => $this->summary($agent),
            'rules' => $this->rules(),
        ];
    }

    public function unlock(User $agent, ?Request $request = null): array
    {
        $this->assertEnabled();

        $profile = $this->profileFor($agent);
        if ($profile && $profile->status === self::STATUS_ACTIVE) {
            return $this->overview($agent);
        }

        if ($this->subordinateOwnership($agent)) {
            throw new ApiException('Agent application requires platform review');
        }

        if (!$this->isEligible($agent)) {
            throw new ApiException('Agent unlock threshold has not been reached');
        }

        $now = time();
        $status = $this->boolSetting('agent_center_auto_activate', true)
            ? self::STATUS_ACTIVE
            : self::STATUS_PENDING;

        $profile = AgentProfile::query()->updateOrCreate(
            ['user_id' => $agent->id],
            [
                'status' => $status,
                'level' => 'default',
                'cost_site_id' => $this->profileCostSiteId($profile, $request, $agent),
                'enabled_at' => $status === self::STATUS_ACTIVE ? $now : null,
                'disabled_at' => null,
                'updated_at' => $now,
            ]
        );

        return $this->overview($agent->fresh() ?: $agent);
    }

    public function apply(User $user, ?string $message = null, ?Request $request = null): array
    {
        $this->assertEnabled();

        $profile = $this->profileFor($user);
        if ($profile && $profile->status === self::STATUS_ACTIVE) {
            return $this->overview($user);
        }

        $ownership = $this->subordinateOwnership($user);
        $ticket = app(TicketService::class)->createTicket(
            $user->id,
            '代理开通申请',
            1,
            $this->applicationTicketMessage($user, $ownership, $message),
            [],
            [
                'agent_context' => [],
                'site_context' => [],
            ]
        );

        $now = time();
        AgentProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'status' => self::STATUS_PENDING,
                'level' => $profile?->level ?: 'default',
                'cost_site_id' => $this->profileCostSiteId($profile, $request, $user),
                'enabled_at' => null,
                'disabled_at' => null,
                'updated_at' => $now,
            ]
        );

        $overview = $this->overview($user->fresh() ?: $user);
        $overview['application']['ticket_id'] = (int) $ticket->id;

        return $overview;
    }

    public function listUsers(User $agent, ?string $keyword = null): array
    {
        $this->activeProfile($agent);
        $keyword = mb_substr(trim((string) $keyword), 0, 255);

        return AgentUser::query()
            ->with(['subordinate.plan:id,name'])
            ->where('agent_user_id', $agent->id)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $like = '%' . addcslashes($keyword, '\\%_') . '%';
                $query->where(function ($inner) use ($keyword, $like): void {
                    $inner->where('remark', 'like', $like)
                        ->orWhereHas('subordinate', function ($subQuery) use ($keyword, $like): void {
                            $subQuery->where('email', 'like', $like)
                                ->orWhere('token', 'like', $like)
                                ->orWhere('uuid', 'like', $like);

                            if (ctype_digit($keyword)) {
                                $subQuery->orWhere('id', (int) $keyword);
                            }
                        });

                    if (ctype_digit($keyword)) {
                        $inner->orWhere('sub_user_id', (int) $keyword);
                    }
                });
            })
            ->orderByDesc('id')
            ->get()
            ->map(fn (AgentUser $row) => $this->ownedUserSnapshot($row))
            ->values()
            ->all();
    }

    public function subscribeLink(User $agent, int $subUserId): array
    {
        $this->activeProfile($agent);
        $ownership = $this->ownership($agent, $subUserId);
        $subordinate = $ownership->subordinate ?: User::query()->find($ownership->sub_user_id);
        if (!$subordinate) {
            throw new ApiException('Target user does not exist');
        }

        $token = trim((string) $subordinate->token);
        if ($token === '') {
            throw new ApiException('Subscription token is unavailable');
        }

        return $this->subscriptionLinkPayload($token);
    }

    public function resetSubscription(User $agent, int $subUserId): array
    {
        $this->activeProfile($agent);

        return DB::transaction(function () use ($agent, $subUserId): array {
            $lockedAgent = User::query()->lockForUpdate()->find($agent->id);
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }

            $ownership = $this->ownership($lockedAgent, $subUserId);
            $subordinate = User::query()->lockForUpdate()->find($ownership->sub_user_id);
            if (!$subordinate) {
                throw new ApiException('Target user does not exist');
            }

            $subordinate->uuid = Helper::guid(true);
            $subordinate->token = Helper::guid();
            $subordinate->updated_at = time();
            $subordinate->save();

            $ledger = $this->ledgerEntry(
                $lockedAgent,
                $subordinate,
                self::LEDGER_RESET_SUBSCRIPTION,
                0,
                (int) $lockedAgent->balance,
                (int) $lockedAgent->balance,
                null,
                null,
                ['reason' => 'agent_manual']
            );

            $ownership->setRelation('subordinate', $subordinate->fresh(['plan:id,name']) ?: $subordinate);

            return array_merge($this->subscriptionLinkPayload((string) $subordinate->token), [
                'summary' => $this->summary($lockedAgent),
                'user' => $this->ownedUserSnapshot($ownership),
                'ledger' => $this->ledgerSnapshot($ledger),
            ]);
        });
    }

    public function createSubordinate(User $agent, array $payload): array
    {
        $this->activeProfile($agent);

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $remark = $this->cleanNullableString($payload['remark'] ?? null, 255);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Invalid email');
        }
        if (strlen($password) < 6) {
            throw new ApiException('Password must be at least 6 characters');
        }
        if (app(SiteUserScopeService::class)
            ->scopeUserQueryForSiteId(User::query(), null)
            ->where('email', $email)
            ->exists()) {
            throw new ApiException('Email already exists');
        }
        $assignment = $this->resolveOptionalPlanPrice($agent, $payload);

        return DB::transaction(function () use ($agent, $email, $password, $remark, $assignment): array {
            $lockedAgent = User::query()->lockForUpdate()->find($agent->id);
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }
            $this->assertUserLimit($lockedAgent);

            $now = time();
            $user = User::query()->create([
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'site_id' => null,
                'uuid' => $this->randomToken(32),
                'token' => $this->randomToken(32),
                'invite_user_id' => null,
                'expired_at' => 0,
                'transfer_enable' => 0,
                'u' => 0,
                'd' => 0,
                'balance' => 0,
                'commission_balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $ownership = AgentUser::query()->create([
                'agent_user_id' => $lockedAgent->id,
                'sub_user_id' => $user->id,
                'remark' => $remark,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $ledger = null;
            if ($assignment !== null) {
                [$plan, $period, $baseAmount, $bonusDays, $bonusDayPrice, $bonusAmount, $amount] = $assignment;
                $before = (int) $lockedAgent->balance;
                if ($before < $amount) {
                    throw new ApiException('Insufficient balance');
                }

                $lockedAgent->balance = $before - $amount;
                $lockedAgent->updated_at = time();
                $lockedAgent->save();

                $this->applyPlan($user, $plan, $period, $bonusDays);

                $ledger = $this->ledgerEntry(
                    $lockedAgent,
                    $user,
                    self::LEDGER_ASSIGN_PLAN,
                    -$amount,
                    $before,
                    (int) $lockedAgent->balance,
                    $plan,
                    $period,
                    [
                        'plan_name' => $plan->name,
                        'base_amount' => $baseAmount,
                        'bonus_days' => $bonusDays,
                        'bonus_day_price' => $bonusDayPrice,
                        'bonus_amount' => $bonusAmount,
                    ]
                );
            }

            $ownership->setRelation('subordinate', $user->fresh(['plan:id,name']) ?: $user);

            $result = [
                'user' => $this->ownedUserSnapshot($ownership),
                'summary' => $this->summary($lockedAgent),
            ];
            if ($ledger) {
                $result['ledger'] = $this->ledgerSnapshot($ledger);
            }

            return $result;
        });
    }

    public function deleteSubordinate(User $agent, int $subUserId): array
    {
        $this->activeProfile($agent);
        $ticketAttachments = collect();

        $result = DB::transaction(function () use ($agent, $subUserId, &$ticketAttachments): array {
            $lockedAgent = User::query()->lockForUpdate()->find($agent->id);
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }

            $ownership = AgentUser::query()
                ->where('agent_user_id', $lockedAgent->id)
                ->where('sub_user_id', $subUserId)
                ->lockForUpdate()
                ->first();
            if (!$ownership) {
                throw new ApiException('Target user is not managed by this agent');
            }

            $subordinate = User::query()->lockForUpdate()->find($ownership->sub_user_id);
            if (!$subordinate) {
                throw new ApiException('Target user does not exist');
            }

            $ticketCleanup = app(TicketCleanupService::class);
            $ticketIds = $subordinate->tickets()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ticketAttachments = $ticketCleanup->collectAttachmentsByTicketIds($ticketIds);

            AgentUser::query()->where('sub_user_id', $subordinate->id)->delete();
            $subordinate->orders()->delete();
            $subordinate->codes()->delete();
            $subordinate->stat()->delete();
            $ticketCleanup->deleteRowsByTicketIds($ticketIds);
            $subordinate->delete();

            $ledger = $this->ledgerEntry(
                $lockedAgent,
                null,
                self::LEDGER_DELETE_SUBORDINATE,
                0,
                (int) $lockedAgent->balance,
                (int) $lockedAgent->balance,
                null,
                null,
                ['deleted_user_id' => $subUserId, 'email' => $subordinate->email]
            );

            return [
                'deleted_user_id' => $subUserId,
                'summary' => $this->summary($lockedAgent),
                'ledger' => $this->ledgerSnapshot($ledger),
            ];
        });

        app(TicketCleanupService::class)->deleteAttachmentFiles($ticketAttachments);

        return $result;
    }

    public function previewAssignPlan(User $agent, int $subUserId, array $payload): array
    {
        $this->activeProfile($agent);
        $ownership = $this->ownership($agent, $subUserId);
        [$plan, $period, $baseAmount, $bonusDays, $bonusDayPrice, $bonusAmount, $amount] = $this->resolvePlanPrice($agent, $payload);

        return [
            'target_user' => $this->ownedUserSnapshot($ownership),
            'plan' => $this->planSnapshot($plan),
            'period' => $period,
            'base_amount' => $baseAmount,
            'bonus_days' => $bonusDays,
            'bonus_day_price' => $bonusDayPrice,
            'bonus_amount' => $bonusAmount,
            'amount' => $amount,
            'balance_after' => max(0, (int) $agent->balance - $amount),
        ];
    }

    public function assignPlan(User $agent, int $subUserId, array $payload): array
    {
        $this->activeProfile($agent);
        [$plan, $period, $baseAmount, $bonusDays, $bonusDayPrice, $bonusAmount, $amount] = $this->resolvePlanPrice($agent, $payload);

        return DB::transaction(function () use ($agent, $subUserId, $plan, $period, $baseAmount, $bonusDays, $bonusDayPrice, $bonusAmount, $amount): array {
            $lockedAgent = User::query()->lockForUpdate()->find($agent->id);
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }

            $ownership = $this->ownership($lockedAgent, $subUserId);
            $subordinate = User::query()->lockForUpdate()->find($ownership->sub_user_id);
            if (!$subordinate) {
                throw new ApiException('Target user does not exist');
            }

            $before = (int) $lockedAgent->balance;
            if ($before < $amount) {
                throw new ApiException('Insufficient balance');
            }

            $lockedAgent->balance = $before - $amount;
            $lockedAgent->updated_at = time();
            $lockedAgent->save();

            $this->applyPlan($subordinate, $plan, $period, $bonusDays);

            $ledger = $this->ledgerEntry(
                $lockedAgent,
                $subordinate,
                self::LEDGER_ASSIGN_PLAN,
                -$amount,
                $before,
                (int) $lockedAgent->balance,
                $plan,
                $period,
                [
                    'plan_name' => $plan->name,
                    'base_amount' => $baseAmount,
                    'bonus_days' => $bonusDays,
                    'bonus_day_price' => $bonusDayPrice,
                    'bonus_amount' => $bonusAmount,
                ]
            );

            $ownership->setRelation('subordinate', $subordinate->fresh(['plan:id,name']) ?: $subordinate);

            return [
                'summary' => $this->summary($lockedAgent),
                'user' => $this->ownedUserSnapshot($ownership),
                'ledger' => $this->ledgerSnapshot($ledger),
            ];
        });
    }

    public function previewResetTraffic(User $agent, int $subUserId): array
    {
        $this->activeProfile($agent);
        $ownership = $this->ownership($agent, $subUserId);
        $subordinate = $ownership->subordinate ?: User::query()->find($subUserId);
        if (!$subordinate) {
            throw new ApiException('Target user does not exist');
        }
        $plan = $subordinate->plan_id ? Plan::query()->find($subordinate->plan_id) : null;
        $amount = $plan ? $this->resetPrice($plan) : 0;

        return [
            'target_user' => $this->ownedUserSnapshot($ownership),
            'amount' => $amount,
            'balance_after' => max(0, (int) $agent->balance - $amount),
        ];
    }

    public function resetTraffic(User $agent, int $subUserId): array
    {
        $this->activeProfile($agent);
        if (!$this->boolSetting('agent_center_allow_traffic_reset', true)) {
            throw new ApiException('Traffic reset is disabled');
        }

        return DB::transaction(function () use ($agent, $subUserId): array {
            $lockedAgent = User::query()->lockForUpdate()->find($agent->id);
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }

            $ownership = $this->ownership($lockedAgent, $subUserId);
            $subordinate = User::query()->lockForUpdate()->find($ownership->sub_user_id);
            if (!$subordinate) {
                throw new ApiException('Target user does not exist');
            }

            $plan = $subordinate->plan_id ? Plan::query()->find($subordinate->plan_id) : null;
            $amount = $plan ? $this->resetPrice($plan) : 0;
            $before = (int) $lockedAgent->balance;
            if ($before < $amount) {
                throw new ApiException('Insufficient balance');
            }

            if ($amount > 0) {
                $lockedAgent->balance = $before - $amount;
                $lockedAgent->updated_at = time();
                $lockedAgent->save();
            }

            $subordinate->u = 0;
            $subordinate->d = 0;
            $subordinate->updated_at = time();
            $subordinate->save();

            $ledger = $this->ledgerEntry(
                $lockedAgent,
                $subordinate,
                self::LEDGER_RESET_TRAFFIC,
                -$amount,
                $before,
                (int) $lockedAgent->balance,
                $plan,
                Plan::PERIOD_RESET_TRAFFIC,
                ['plan_name' => $plan?->name]
            );

            $ownership->setRelation('subordinate', $subordinate->fresh(['plan:id,name']) ?: $subordinate);

            return [
                'summary' => $this->summary($lockedAgent),
                'user' => $this->ownedUserSnapshot($ownership),
                'ledger' => $this->ledgerSnapshot($ledger),
            ];
        });
    }

    public function previewBonusDays(User $agent, int $subUserId, array $payload): array
    {
        $this->activeProfile($agent);
        $ownership = $this->ownership($agent, $subUserId);
        $subordinate = $ownership->subordinate ?: User::query()->find($subUserId);
        if (!$subordinate) {
            throw new ApiException('Target user does not exist');
        }

        [$plan, $bonusDays, $bonusDayPrice, $amount, $previousExpiredAt, $newExpiredAt] = $this->resolveBonusDayGrant($subordinate, $payload);

        return [
            'target_user' => $this->ownedUserSnapshot($ownership),
            'plan' => $this->planSnapshot($plan),
            'bonus_days' => $bonusDays,
            'bonus_day_price' => $bonusDayPrice,
            'amount' => $amount,
            'balance_after' => max(0, (int) $agent->balance - $amount),
            'previous_expired_at' => $previousExpiredAt,
            'new_expired_at' => $newExpiredAt,
        ];
    }

    public function grantBonusDays(User $agent, int $subUserId, array $payload): array
    {
        $this->activeProfile($agent);

        return DB::transaction(function () use ($agent, $subUserId, $payload): array {
            $lockedAgent = User::query()->lockForUpdate()->find($agent->id);
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }

            $ownership = $this->ownership($lockedAgent, $subUserId);
            $subordinate = User::query()->lockForUpdate()->find($ownership->sub_user_id);
            if (!$subordinate) {
                throw new ApiException('Target user does not exist');
            }

            [$plan, $bonusDays, $bonusDayPrice, $amount, $previousExpiredAt, $newExpiredAt] = $this->resolveBonusDayGrant($subordinate, $payload);
            $before = (int) $lockedAgent->balance;
            if ($before < $amount) {
                throw new ApiException('Insufficient balance');
            }

            $lockedAgent->balance = $before - $amount;
            $lockedAgent->updated_at = time();
            $lockedAgent->save();

            $subordinate->expired_at = $newExpiredAt;
            $subordinate->updated_at = time();
            $subordinate->save();

            $ledger = $this->ledgerEntry(
                $lockedAgent,
                $subordinate,
                self::LEDGER_GRANT_BONUS_DAYS,
                -$amount,
                $before,
                (int) $lockedAgent->balance,
                $plan,
                null,
                [
                    'plan_name' => $plan->name,
                    'bonus_days' => $bonusDays,
                    'bonus_day_price' => $bonusDayPrice,
                    'bonus_amount' => $amount,
                    'previous_expired_at' => $previousExpiredAt,
                    'new_expired_at' => $newExpiredAt,
                ]
            );

            $ownership->setRelation('subordinate', $subordinate->fresh(['plan:id,name']) ?: $subordinate);

            return [
                'summary' => $this->summary($lockedAgent),
                'user' => $this->ownedUserSnapshot($ownership),
                'bonus_days' => $bonusDays,
                'bonus_day_price' => $bonusDayPrice,
                'amount' => $amount,
                'new_expired_at' => $newExpiredAt,
                'ledger' => $this->ledgerSnapshot($ledger),
            ];
        });
    }

    public function ledger(User $agent, int $limit = 50): array
    {
        $this->activeProfile($agent);

        return AgentLedger::query()
            ->where('agent_user_id', $agent->id)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (AgentLedger $ledger) => $this->ledgerSnapshot($ledger))
            ->values()
            ->all();
    }

    private function assertEnabled(): void
    {
        if (!$this->boolSetting('agent_center_enable', false)) {
            throw new ApiException('Agent center is disabled');
        }
    }

    private function activeProfile(User $agent): AgentProfile
    {
        $this->assertEnabled();
        $profile = $this->profileFor($agent);
        if (!$profile || $profile->status !== self::STATUS_ACTIVE) {
            throw new ApiException('Agent permission is not active');
        }
        return $profile;
    }

    private function profileFor(User $agent): ?AgentProfile
    {
        return AgentProfile::query()->where('user_id', $agent->id)->first();
    }

    private function subordinateOwnership(User $user): ?AgentUser
    {
        return AgentUser::query()
            ->with('agent:id,email')
            ->where('sub_user_id', $user->id)
            ->first();
    }

    private function profileCostSiteId(?AgentProfile $profile, ?Request $request, User $user): ?int
    {
        if ($request) {
            return $this->resolveInitialCostSiteId($request, $user);
        }

        if ($profile && $profile->cost_site_id) {
            return (int) $profile->cost_site_id;
        }

        return $this->resolveInitialCostSiteId(null, $user);
    }

    private function resolveInitialCostSiteId(?Request $request, User $user): ?int
    {
        if ($request) {
            $context = app(SiteResolver::class)->resolveRequest($request);
            $siteId = empty($context['site_id']) ? null : (int) $context['site_id'];
            return $this->activeSubSiteId($siteId);
        }

        return $this->activeSubSiteId($user->site_id ? (int) $user->site_id : null);
    }

    private function activeSubSiteId(?int $siteId): ?int
    {
        if (!$siteId) {
            return null;
        }

        try {
            $site = Site::query()
                ->where('id', $siteId)
                ->where('status', Site::STATUS_ACTIVE)
                ->where('is_default', false)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $site ? (int) $site->id : null;
    }

    private function applicationSnapshot(?AgentProfile $profile, ?AgentUser $ownership): array
    {
        $status = $profile?->status ?: 'not_applied';

        return [
            'status' => $status,
            'can_apply' => !in_array($status, [self::STATUS_ACTIVE, self::STATUS_PENDING], true),
            'requires_platform_review' => (bool) $ownership,
            'review_channel' => 'platform_ticket',
        ];
    }

    private function ownershipSnapshot(AgentUser $ownership): array
    {
        return [
            'agent_user_id' => (int) $ownership->agent_user_id,
            'agent_email' => $ownership->agent?->email,
            'sub_user_id' => (int) $ownership->sub_user_id,
            'remark' => $ownership->remark,
            'created_at' => $ownership->created_at ? (int) $ownership->created_at : null,
        ];
    }

    private function applicationTicketMessage(User $user, ?AgentUser $ownership, ?string $message): string
    {
        $lines = [
            '用户提交代理开通申请，请主站审核。',
            '申请用户：' . (string) $user->email . ' (#' . (int) $user->id . ')',
        ];

        if ($ownership) {
            $lines[] = '当前归属代理：' . (string) ($ownership->agent?->email ?: '-') . ' (#' . (int) $ownership->agent_user_id . ')';
            $lines[] = '说明：该用户当前是代理下级，不能自动开通代理，需要主站审核是否调整归属。';
        }

        $message = trim((string) $message);
        if ($message !== '') {
            $lines[] = '用户留言：' . $message;
        }

        return implode("\n", $lines);
    }

    private function isEligible(User $agent): bool
    {
        $mode = (string) admin_setting('agent_center_unlock_mode', 'balance_threshold');
        if ($mode === 'manual') {
            return false;
        }
        $threshold = max(0, (int) admin_setting('agent_center_unlock_balance', 0));
        return (int) $agent->balance >= $threshold;
    }

    private function assertUserLimit(User $agent): void
    {
        $limit = $this->agentUserLimit();
        if ($limit <= 0) {
            return;
        }
        $count = AgentUser::query()
            ->where('agent_user_id', $agent->id)
            ->count();
        if ($count >= $limit) {
            throw new ApiException('Agent user limit exceeded');
        }
    }

    private function agentUserLimit(): int
    {
        $value = admin_setting('agent_center_user_limit', null);
        if ($value === null || $value === '') {
            $value = admin_setting('agent_center_daily_create_limit', 20);
        }
        return max(0, (int) $value);
    }

    private function ownership(User $agent, int $subUserId): AgentUser
    {
        $ownership = AgentUser::query()
            ->with(['subordinate.plan:id,name'])
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $subUserId)
            ->first();
        if (!$ownership) {
            throw new ApiException('Target user is not managed by this agent');
        }
        return $ownership;
    }

    private function resolvePlanPrice(User $agent, array $payload): array
    {
        $planId = (int) ($payload['plan_id'] ?? 0);
        $period = $this->periodKey((string) ($payload['period'] ?? ''));
        if ($planId <= 0 || $period === '') {
            throw new ApiException('Invalid parameter');
        }

        $plan = Plan::query()->find($planId);
        if (!$plan || !$plan->sell) {
            throw new ApiException('Plan is not available');
        }
        if (!$this->planAllowed($plan)) {
            throw new ApiException('Plan is not allowed for agents');
        }

        $cost = app(AgentCostService::class)->resolveDiscounted($agent, $plan, $period);
        $discountedAmount = (int) $cost['amount'];
        $bonusDays = $this->bonusDays($payload);
        $bonusDayPrice = $this->bonusDayPrice();
        if ($bonusDays > 0 && $period === Plan::PERIOD_ONETIME) {
            throw new ApiException('Bonus days are not available for one-time plans');
        }
        if ($bonusDays > 0 && $bonusDayPrice <= 0) {
            throw new ApiException('Agent bonus day price is not configured');
        }

        $bonusAmount = $bonusDays * $bonusDayPrice;
        $amount = $discountedAmount + $bonusAmount;

        return [$plan, $period, $discountedAmount, $bonusDays, $bonusDayPrice, $bonusAmount, $amount];
    }

    private function resolveOptionalPlanPrice(User $agent, array $payload): ?array
    {
        if (!array_key_exists('plan_id', $payload) || $payload['plan_id'] === null || $payload['plan_id'] === '') {
            return null;
        }
        if (!array_key_exists('period', $payload) || trim((string) $payload['period']) === '') {
            throw new ApiException('Plan period is required');
        }

        return $this->resolvePlanPrice($agent, $payload);
    }

    private function bonusDays(array $payload): int
    {
        return max(0, min(365, (int) ($payload['bonus_days'] ?? 0)));
    }

    private function bonusDayPrice(): int
    {
        return max(0, (int) admin_setting('agent_center_bonus_day_price', 0));
    }

    private function resolveBonusDayGrant(User $subordinate, array $payload): array
    {
        $bonusDays = $this->bonusDays($payload);
        if ($bonusDays <= 0) {
            throw new ApiException('Bonus days are required');
        }
        if (!$subordinate->plan_id) {
            throw new ApiException('Target user has no active plan');
        }
        if ($subordinate->expired_at === null || (int) $subordinate->expired_at >= 4102444800) {
            throw new ApiException('Permanent plans do not need bonus days');
        }

        $plan = Plan::query()->find($subordinate->plan_id);
        if (!$plan) {
            throw new ApiException('Target user has no active plan');
        }

        $bonusDayPrice = $this->bonusDayPrice();
        if ($bonusDayPrice <= 0) {
            throw new ApiException('Agent bonus day price is not configured');
        }

        $previousExpiredAt = (int) ($subordinate->expired_at ?: 0);
        $base = max($previousExpiredAt, time());
        $newExpiredAt = $base + ($bonusDays * 86400);

        return [$plan, $bonusDays, $bonusDayPrice, $bonusDays * $bonusDayPrice, $previousExpiredAt, $newExpiredAt];
    }

    private function resetPrice(Plan $plan): int
    {
        if (!$this->boolSetting('agent_center_allow_traffic_reset', true)) {
            throw new ApiException('Traffic reset is disabled');
        }
        if ((string) admin_setting('agent_center_reset_price_mode', 'plan_reset_price') === 'free') {
            return 0;
        }
        $price = $plan->prices[Plan::PERIOD_RESET_TRAFFIC] ?? 0;
        $baseAmount = OrderService::amountToCents($price);
        $discountPercent = max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100)));
        return (int) round($baseAmount * ($discountPercent / 100));
    }

    private function planAllowed(Plan $plan): bool
    {
        $raw = trim((string) admin_setting('agent_center_allowed_plan_ids', ''));
        if ($raw === '') {
            return true;
        }
        $ids = array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', $raw)
        ));
        return in_array((int) $plan->id, $ids, true);
    }

    private function applyPlan(User $user, Plan $plan, string $period, int $bonusDays = 0): void
    {
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->transfer_enable = (int) $plan->transfer_enable * 1073741824;
        $user->speed_limit = $plan->speed_limit;
        $user->device_limit = $plan->device_limit;
        $user->u = 0;
        $user->d = 0;

        if ($period === Plan::PERIOD_ONETIME) {
            $user->expired_at = null;
        } else {
            $days = $this->periodDays($period) + max(0, $bonusDays);
            if ($days > 0) {
                $base = max((int) ($user->expired_at ?: 0), time());
                $user->expired_at = $base + ($days * 86400);
            }
        }

        $user->updated_at = time();
        $user->save();
    }

    private function periodDays(string $period): int
    {
        $periods = Plan::getAvailablePeriods();
        return (int) ($periods[$period]['days'] ?? 0);
    }

    private function periodKey(string $period): string
    {
        $period = trim($period);
        return Plan::LEGACY_PERIOD_MAPPING[$period] ?? $period;
    }

    private function subscriptionLinkPayload(string $token): array
    {
        $payload = [
            'subscribe_url' => Helper::getSubscribeUrl($token),
        ];
        $subscriptionProxy = app(SubscriptionProxyProbeService::class)->userPayload($token);
        $payload['subscription_proxy'] = $subscriptionProxy;
        if (!empty($subscriptionProxy['subscribe_url'])) {
            $payload['accelerated_subscribe_url'] = $subscriptionProxy['subscribe_url'];
        }

        return $payload;
    }

    private function ledgerEntry(
        User $agent,
        ?User $target,
        string $type,
        int $amount,
        int $before,
        int $after,
        ?Plan $plan = null,
        ?string $period = null,
        array $metadata = []
    ): AgentLedger {
        return AgentLedger::query()->create([
            'agent_user_id' => $agent->id,
            'target_user_id' => $target?->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'plan_id' => $plan?->id,
            'period' => $period,
            'metadata' => array_filter($metadata, static fn ($value) => $value !== null),
            'created_at' => time(),
        ]);
    }

    private function summary(User $agent): array
    {
        $agentId = (int) $agent->id;
        $monthStart = strtotime(date('Y-m-01'));
        $monthSpend = abs((int) AgentLedger::query()
            ->where('agent_user_id', $agentId)
            ->where('amount', '<', 0)
            ->where('created_at', '>=', $monthStart)
            ->sum('amount'));

        return [
            'balance' => (int) $agent->balance,
            'managed_users' => AgentUser::query()->where('agent_user_id', $agentId)->count(),
            'month_spending' => $monthSpend,
            'ledger_count' => AgentLedger::query()->where('agent_user_id', $agentId)->count(),
        ];
    }

    private function rules(): array
    {
        return [
            'unlock_mode' => (string) admin_setting('agent_center_unlock_mode', 'balance_threshold'),
            'unlock_balance' => (int) admin_setting('agent_center_unlock_balance', 0),
            'discount_percent' => (float) admin_setting('agent_center_discount_percent', 100),
            'user_limit' => $this->agentUserLimit(),
            'daily_create_limit' => $this->agentUserLimit(),
            'allow_traffic_reset' => $this->boolSetting('agent_center_allow_traffic_reset', true),
            'allowed_plan_ids' => trim((string) admin_setting('agent_center_allowed_plan_ids', '')),
            'bonus_day_price' => $this->bonusDayPrice(),
        ];
    }

    private function profileSnapshot(AgentProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'user_id' => (int) $profile->user_id,
            'status' => (string) $profile->status,
            'level' => (string) $profile->level,
            'remark' => $profile->remark,
            'enabled_at' => $profile->enabled_at ? (int) $profile->enabled_at : null,
            'disabled_at' => $profile->disabled_at ? (int) $profile->disabled_at : null,
        ];
    }

    private function ownedUserSnapshot(AgentUser $ownership): array
    {
        $user = $ownership->subordinate;
        return [
            'id' => (int) $ownership->sub_user_id,
            'email' => (string) ($user?->email ?? ''),
            'remark' => $ownership->remark,
            'plan_id' => $user?->plan_id !== null ? (int) $user->plan_id : null,
            'plan_name' => $user?->plan?->name,
            'expired_at' => $user?->expired_at !== null ? (int) $user->expired_at : null,
            'transfer_enable' => (int) ($user?->transfer_enable ?? 0),
            'u' => (int) ($user?->u ?? 0),
            'd' => (int) ($user?->d ?? 0),
            'banned' => (bool) ($user?->banned ?? false),
            'created_at' => $ownership->created_at ? (int) $ownership->created_at : null,
        ];
    }

    private function planSnapshot(Plan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'prices' => $plan->prices ?? [],
            'transfer_enable' => (int) $plan->transfer_enable,
            'device_limit' => $plan->device_limit !== null ? (int) $plan->device_limit : null,
            'speed_limit' => $plan->speed_limit !== null ? (int) $plan->speed_limit : null,
        ];
    }

    private function ledgerSnapshot(AgentLedger $ledger): array
    {
        return [
            'id' => (int) $ledger->id,
            'agent_user_id' => (int) $ledger->agent_user_id,
            'target_user_id' => $ledger->target_user_id !== null ? (int) $ledger->target_user_id : null,
            'type' => (string) $ledger->type,
            'amount' => (int) $ledger->amount,
            'balance_before' => (int) $ledger->balance_before,
            'balance_after' => (int) $ledger->balance_after,
            'plan_id' => $ledger->plan_id !== null ? (int) $ledger->plan_id : null,
            'period' => $ledger->period,
            'metadata' => $ledger->metadata ?? [],
            'created_at' => $ledger->created_at ? (int) $ledger->created_at : null,
        ];
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = admin_setting($key, $default ? 1 : 0);
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function cleanNullableString(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, $max);
    }

    private function randomToken(int $length): string
    {
        return substr(bin2hex(random_bytes(max(16, (int) ceil($length / 2)))), 0, $length);
    }
}
