<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\PlanResource;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GiftCardService
{
    protected readonly GiftCardCode $code;
    protected readonly GiftCardTemplate $template;
    protected ?User $user = null;

    public function __construct(string $code)
    {
        $this->code = GiftCardCode::where('code', $code)->first()
            ?? throw new ApiException('兑换码不存在');

        $this->template = $this->code->template;
    }

    /**
     * 设置使用用户
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * 验证兑换码
     */
    public function validate(): self
    {
        $this->validateIsActive();

        $eligibility = $this->checkUserEligibility();
        if (!$eligibility['can_redeem']) {
            throw new ApiException($eligibility['reason']);
        }

        return $this;
    }

    /**
     * 验证礼品卡本身是否可用 (不检查用户条件)
     * @throws ApiException
     */
    public function validateIsActive(): self
    {
        if (!$this->template->isAvailable()) {
            throw new ApiException('该礼品卡类型已停用');
        }

        if (!$this->code->isAvailable()) {
            throw new ApiException('兑换码不可用：' . $this->code->status_name);
        }
        return $this;
    }

    /**
     * 检查用户是否满足兑换条件 (不抛出异常)
     */
    public function checkUserEligibility(): array
    {
        if (!$this->user) {
            return [
                'can_redeem' => false,
                'reason' => '用户信息未提供',
                'reason_code' => 'user_not_set'
            ];
        }

        // 使用新的详细检查方法
        $conditionsCheck = $this->template->checkUserConditionsWithReason($this->user);
        if (!$conditionsCheck['can_use']) {
            return [
                'can_redeem' => false,
                'reason' => $conditionsCheck['reason'],
                'reason_code' => $conditionsCheck['reason_code'] ?? 'condition_not_met'
            ];
        }

        // 检查使用频率限制（使用新的优化方法）
        $usageLimitCheck = $this->template->checkUsageLimitWithReason($this->user);
        if (!$usageLimitCheck['can_use']) {
            return [
                'can_redeem' => false,
                'reason' => $usageLimitCheck['reason'],
                'reason_code' => $usageLimitCheck['reason_code']
            ];
        }

        return ['can_redeem' => true, 'reason' => null, 'reason_code' => null];
    }

    /**
     * 使用礼品卡
     */
    public function redeem(array $options = []): array
    {
        if (!$this->user) {
            throw new ApiException('未设置使用用户');
        }

        return DB::transaction(function () use ($options) {
            // 在事务内再次验证，防止并发问题
            // 重新加载兑换码和用户数据（带行锁）
            $this->code->refresh();
            $this->user->refresh();
            
            // 再次检查兑换码是否可用
            if (!$this->code->isAvailable()) {
                throw new ApiException('兑换码已被使用或不可用');
            }
            
            // 再次检查用户资格（防止check和redeem之间状态变化）
            $eligibility = $this->checkUserEligibility();
            if (!$eligibility['can_redeem']) {
                throw new ApiException($eligibility['reason']);
            }
            
            $actualRewards = $this->template->calculateActualRewards($this->user);

            if ($this->template->type === GiftCardTemplate::TYPE_MYSTERY) {
                $this->code->setActualRewards($actualRewards);
            }

            $rewardResult = $this->giveRewards($actualRewards);

            $inviteRewards = null;
            if ($this->user->invite_user_id && isset($actualRewards['invite_reward_rate'])) {
                $inviteRewards = $this->giveInviteRewards($actualRewards);
            }

            $this->code->markAsUsed($this->user);

            GiftCardUsage::createRecord(
                $this->code,
                $this->user,
                $actualRewards,
                array_merge($options, [
                    'invite_rewards' => $inviteRewards,
                    'multiplier' => $this->calculateMultiplier(),
                ])
            );

            return [
                'rewards' => $actualRewards,
                'invite_rewards' => $inviteRewards,
                'code' => $this->code->code,
                'template_name' => $this->template->name,
                'operation_info' => $rewardResult['operation_info'] ?? null,
            ];
        });
    }

    /**
     * 发放奖励
     */
    protected function giveRewards(array $rewards): array
    {
        $userService = app(UserService::class);

        if (isset($rewards['balance']) && $rewards['balance'] > 0) {
            if (!$userService->addBalance($this->user->id, $rewards['balance'])) {
                throw new ApiException('余额发放失败');
            }
        }

        if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0) {
            $this->user->transfer_enable = ($this->user->transfer_enable ?? 0) + $rewards['transfer_enable'];
        }

        if (isset($rewards['device_limit']) && $rewards['device_limit'] > 0) {
            $this->user->device_limit = ($this->user->device_limit ?? 0) + $rewards['device_limit'];
        }

        if (isset($rewards['reset_package']) && $rewards['reset_package']) {
            // 修复：允许有套餐的用户（包括过期套餐）重置流量
            if ($this->user->plan_id) {
                $this->performGiftCardTrafficReset();
            }
        }

        $operationInfo = [];

        if (isset($rewards['plan_id'])) {
            $plan = Plan::find($rewards['plan_id']);
            if (!$plan) {
                Log::error('礼品卡套餐不存在', [
                    'plan_id' => $rewards['plan_id'],
                    'template_id' => $this->template->id,
                    'code' => $this->code->code,
                ]);
                throw new ApiException('礼品卡配置的套餐不存在，请联系管理员');
            }
            
            if ($plan) {
                $validityDays = $rewards['plan_validity_days'] ?? 0;
                
                // 智能套餐处理逻辑
                if ($this->user->plan_id && $this->user->plan_id === $plan->id) {
                    // 相同套餐：只延长有效期，不重置流量
                    if ($validityDays > 0) {
                        $userService->extendSubscription($this->user, $validityDays);
                        $operationInfo['plan_action'] = 'extend';
                        $operationInfo['plan_name'] = $plan->name;
                        $operationInfo['extended_days'] = $validityDays;
                        $operationInfo['message'] = "套餐「{$plan->name}」有效期已延长 {$validityDays} 天";
                    }
                } else {
                    // 不同套餐或无套餐：分配新套餐并重置流量
                    $oldPlanName = $this->user->plan ? $this->user->plan->name : '无套餐';
                    $hadPlan = (bool) $this->user->plan_id;
                    
                    // 只有当用户有套餐时才重置流量
                    if ($hadPlan) {
                        $this->performGiftCardTrafficReset();
                        $operationInfo['traffic_reset'] = true;
                    }
                    
                    $userService->assignPlan($this->user, $plan, $validityDays);
                    
                    $operationInfo['plan_action'] = $hadPlan ? 'replace' : 'assign';
                    $operationInfo['old_plan_name'] = $oldPlanName;
                    $operationInfo['new_plan_name'] = $plan->name;
                    $operationInfo['validity_days'] = $validityDays;
                    
                    if ($hadPlan) {
                        $operationInfo['message'] = "套餐已从「{$oldPlanName}」更换为「{$plan->name}」，流量已重置";
                    } else {
                        $operationInfo['message'] = "已分配套餐「{$plan->name}」";
                    }
                }
            }
        } else {
            // 只有在不是套餐卡的情况下，才处理独立的有效期奖励
            if (isset($rewards['expire_days']) && $rewards['expire_days'] > 0) {
                $userService->extendSubscription($this->user, $rewards['expire_days']);
            }
        }

        // 保存用户更改
        if (!$this->user->save()) {
            throw new ApiException('用户信息更新失败');
        }

        return [
            'operation_info' => $operationInfo
        ];
    }

    /**
     * 发放邀请人奖励
     */
    protected function giveInviteRewards(array $rewards): ?array
    {
        if (!$this->user->invite_user_id) {
            return null;
        }

        $inviteUser = User::find($this->user->invite_user_id);
        if (!$inviteUser) {
            Log::warning('邀请人不存在', [
                'user_id' => $this->user->id,
                'invite_user_id' => $this->user->invite_user_id,
            ]);
            return null;
        }

        // 检查邀请人状态（如果被禁用则不发放奖励）
        if (isset($inviteUser->banned) && $inviteUser->banned) {
            Log::info('邀请人已被禁用，跳过奖励发放', [
                'user_id' => $this->user->id,
                'invite_user_id' => $inviteUser->id,
            ]);
            return null;
        }

        $rate = $rewards['invite_reward_rate'] ?? 0.2;
        $inviteRewards = [];

        $userService = app(UserService::class);

        // 邀请人余额奖励
        if (isset($rewards['balance']) && $rewards['balance'] > 0) {
            $inviteBalance = intval($rewards['balance'] * $rate);
            if ($inviteBalance > 0) {
                if ($userService->addBalance($inviteUser->id, $inviteBalance)) {
                    $inviteRewards['balance'] = $inviteBalance;
                    Log::info('邀请人获得余额奖励', [
                        'invite_user_id' => $inviteUser->id,
                        'balance' => $inviteBalance,
                    ]);
                }
            }
        }

        // 邀请人流量奖励（统一使用模型操作确保一致性）
        if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0) {
            $inviteTransfer = intval($rewards['transfer_enable'] * $rate);
            if ($inviteTransfer > 0) {
                $inviteUser->transfer_enable = ($inviteUser->transfer_enable ?? 0) + $inviteTransfer;
                if ($inviteUser->save()) {
                    $inviteRewards['transfer_enable'] = $inviteTransfer;
                    Log::info('邀请人获得流量奖励', [
                        'invite_user_id' => $inviteUser->id,
                        'transfer_enable' => $inviteTransfer,
                    ]);
                }
            }
        }

        return $inviteRewards ?: null;
    }

    /**
     * 计算倍率
     */
    protected function calculateMultiplier(): float
    {
        return $this->getFestivalBonus();
    }

    /**
     * 获取节日加成倍率
     */
    private function getFestivalBonus(): float
    {
        $festivalConfig = $this->template->special_config ?? [];
        $now = time();

        if (
            isset($festivalConfig['start_time'], $festivalConfig['end_time']) &&
            $now >= $festivalConfig['start_time'] &&
            $now <= $festivalConfig['end_time']
        ) {
            return $festivalConfig['festival_bonus'] ?? 1.0;
        }

        return 1.0;
    }

    /**
     * 获取兑换码信息（不包含敏感信息）
     */
    public function getCodeInfo(): array
    {
        $info = [
            'code' => $this->code->code,
            'template' => [
                'name' => $this->template->name,
                'description' => $this->template->description,
                'type' => $this->template->type,
                'type_name' => $this->template->type_name,
                'icon' => $this->template->icon,
                'background_image' => $this->template->background_image,
                'theme_color' => $this->template->theme_color,
            ],
            'status' => $this->code->status,
            'status_name' => $this->code->status_name,
            'expires_at' => $this->code->expires_at,
            'usage_count' => $this->code->usage_count,
            'max_usage' => $this->code->max_usage,
        ];
        if ($this->template->type === GiftCardTemplate::TYPE_PLAN) {
            $plan = Plan::find($this->code->template->rewards['plan_id']);
            if ($plan) {
                $info['plan_info'] = PlanResource::make($plan)->toArray(request());
            }
        }
        return $info;
    }

    /**
     * 预览奖励（不实际发放）
     */
    public function previewRewards(): array
    {
        if (!$this->user) {
            throw new ApiException('未设置使用用户');
        }

        $rewards = $this->template->calculateActualRewards($this->user);
        
        // 格式化奖励信息，使其更友好
        $formatted = [];
        
        if (isset($rewards['balance']) && $rewards['balance'] > 0) {
            $formatted['balance'] = [
                'raw' => $rewards['balance'],
                'formatted' => number_format($rewards['balance'] / 100, 2) . ' 元',
                'description' => '余额奖励'
            ];
        }
        
        if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0) {
            $gb = round($rewards['transfer_enable'] / (1024 * 1024 * 1024), 2);
            $formatted['transfer_enable'] = [
                'raw' => $rewards['transfer_enable'],
                'formatted' => $gb >= 1 ? number_format($gb, 2) . ' GB' : number_format($rewards['transfer_enable'] / (1024 * 1024), 2) . ' MB',
                'description' => '流量奖励'
            ];
        }
        
        if (isset($rewards['device_limit']) && $rewards['device_limit'] > 0) {
            $formatted['device_limit'] = [
                'raw' => $rewards['device_limit'],
                'formatted' => $rewards['device_limit'] . ' 个设备',
                'description' => '设备数奖励'
            ];
        }
        
        if (isset($rewards['expire_days']) && $rewards['expire_days'] > 0) {
            $formatted['expire_days'] = [
                'raw' => $rewards['expire_days'],
                'formatted' => $rewards['expire_days'] . ' 天',
                'description' => '有效期延长'
            ];
        }
        
        if (isset($rewards['reset_package']) && $rewards['reset_package']) {
            $formatted['reset_package'] = [
                'raw' => true,
                'formatted' => '是',
                'description' => '重置流量'
            ];
        }
        
        if (isset($rewards['plan_id'])) {
            $plan = Plan::find($rewards['plan_id']);
            $formatted['plan'] = [
                'id' => $rewards['plan_id'],
                'name' => $plan ? $plan->name : '未知套餐',
                'description' => '套餐奖励',
                'validity_days' => $rewards['plan_validity_days'] ?? null,
            ];
        }
        
        return [
            'raw' => $rewards,
            'formatted' => $formatted
        ];
    }

    /**
     * 预判套餐操作（用于check接口）
     */
    public function predictPlanOperation(): ?array
    {
        if (!$this->user) {
            return null;
        }
        
        $rewards = $this->template->rewards ?? [];
        if (!isset($rewards['plan_id'])) {
            return null;
        }
        
        $plan = Plan::find($rewards['plan_id']);
        if (!$plan) {
            return null;
        }
        
        $validityDays = $rewards['plan_validity_days'] ?? 0;
        
        // 判断操作类型
        if ($this->user->plan_id && $this->user->plan_id === $plan->id) {
            // 相同套餐：延长有效期
            $currentPlan = $this->user->plan;
            return [
                'operation_type' => 'extend',
                'current_plan_id' => $this->user->plan_id,
                'current_plan_name' => $currentPlan ? $currentPlan->name : '当前套餐',
                'new_plan_id' => $plan->id,
                'new_plan_name' => $plan->name,
                'validity_days' => $validityDays,
                'traffic_reset' => false,
                'warning' => null,
                'message' => "将延长套餐「{$plan->name}」的有效期 {$validityDays} 天，不重置流量"
            ];
        } else {
            // 不同套餐或无套餐：覆盖套餐
            $currentPlan = $this->user->plan;
            $currentPlanName = $currentPlan ? $currentPlan->name : '无套餐';
            $hasPlan = (bool) $this->user->plan_id;
            
            $warning = null;
            if ($hasPlan) {
                $warning = "⚠️ 兑换后将替换您当前的套餐「{$currentPlanName}」，流量将被重置";
            }
            
            return [
                'operation_type' => $hasPlan ? 'replace' : 'assign',
                'current_plan_id' => $this->user->plan_id,
                'current_plan_name' => $currentPlanName,
                'new_plan_id' => $plan->id,
                'new_plan_name' => $plan->name,
                'validity_days' => $validityDays,
                'traffic_reset' => $hasPlan,
                'warning' => $warning,
                'message' => $hasPlan 
                    ? "将把套餐从「{$currentPlanName}」更换为「{$plan->name}」，流量将被重置"
                    : "将分配套餐「{$plan->name}」给您"
            ];
        }
    }

    /**
     * 获取兑换码
     */
    public function getCode(): GiftCardCode
    {
        return $this->code;
    }

    /**
     * 获取模板
     */
    public function getTemplate(): GiftCardTemplate
    {
        return $this->template;
    }

    /**
     * 执行礼品卡流量重置（允许过期用户）
     */
    protected function performGiftCardTrafficReset(): bool
    {
        try {
            return DB::transaction(function () {
                $oldUpload = $this->user->u ?? 0;
                $oldDownload = $this->user->d ?? 0;
                $oldTotal = $oldUpload + $oldDownload;

                // 重置流量
                $this->user->update([
                    'u' => 0,
                    'd' => 0,
                    'last_reset_at' => time(),
                    'reset_count' => $this->user->reset_count + 1,
                ]);

                // 记录重置日志
                TrafficResetLog::create([
                    'user_id' => $this->user->id,
                    'reset_time' => time(),
                    'reset_type' => 'gift_card',
                    'trigger_source' => TrafficResetLog::SOURCE_GIFT_CARD,
                    'old_upload' => $oldUpload,
                    'old_download' => $oldDownload,
                    'old_total' => $oldTotal,
                    'new_upload' => 0,
                    'new_download' => 0,
                    'new_total' => 0,
                    'metadata' => [
                        'gift_card_code' => $this->code->code,
                        'template_name' => $this->template->name,
                    ],
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('礼品卡流量重置失败', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'code' => $this->code->code,
            ]);
            return false;
        }
    }

    /**
     * 记录日志
     */
    protected function logUsage(string $action, array $data = []): void
    {
        Log::info('礼品卡使用记录', [
            'action' => $action,
            'code' => $this->code->code,
            'template_id' => $this->template->id,
            'user_id' => $this->user?->id,
            'data' => $data,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
