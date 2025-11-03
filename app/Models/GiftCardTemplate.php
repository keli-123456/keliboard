<?php

namespace App\Models;

use Dflydev\DotAccessData\Data;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\GiftCardTemplate
 *
 * @property int $id
 * @property string $name 礼品卡名称
 * @property string|null $description 礼品卡描述
 * @property int $type 卡片类型
 * @property boolean $status 状态
 * @property array|null $conditions 使用条件配置
 * @property array $rewards 奖励配置
 * @property array|null $limits 限制条件
 * @property array|null $special_config 特殊配置
 * @property string|null $icon 卡片图标
 * @property string $theme_color 主题色
 * @property int $sort 排序
 * @property int $admin_id 创建管理员ID
 * @property int $created_at
 * @property int $updated_at
 */
class GiftCardTemplate extends Model
{
    protected $table = 'v2_gift_card_template';
    protected $dateFormat = 'U';

    // 卡片类型常量
    const TYPE_GENERAL = 1;         // 通用礼品卡
    const TYPE_PLAN = 2;            // 套餐礼品卡
    const TYPE_MYSTERY = 3;         // 盲盒礼品卡

    protected $fillable = [
        'name',
        'description',
        'type',
        'status',
        'conditions',
        'rewards',
        'limits',
        'special_config',
        'icon',
        'background_image',
        'theme_color',
        'sort',
        'admin_id'
    ];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'conditions' => 'array',
        'rewards' => 'array',
        'limits' => 'array',
        'special_config' => 'array',
        'status' => 'boolean'
    ];

    /**
     * 获取卡片类型映射
     */
    public static function getTypeMap(): array
    {
        return [
            self::TYPE_GENERAL => '通用礼品卡',
            self::TYPE_PLAN => '套餐礼品卡',
            self::TYPE_MYSTERY => '盲盒礼品卡',
        ];
    }

    /**
     * 获取类型名称
     */
    public function getTypeNameAttribute(): string
    {
        return self::getTypeMap()[$this->type] ?? '未知类型';
    }

    /**
     * 关联兑换码
     */
    public function codes(): HasMany
    {
        return $this->hasMany(GiftCardCode::class, 'template_id');
    }

    /**
     * 关联使用记录
     */
    public function usages(): HasMany
    {
        return $this->hasMany(GiftCardUsage::class, 'template_id');
    }

    /**
     * 关联统计数据
     */
    public function stats(): HasMany
    {
        return $this->hasMany(GiftCardUsage::class, 'template_id');
    }

    /**
     * 检查是否可用
     */
    public function isAvailable(): bool
    {
        return $this->status;
    }

    /**
     * 检查用户是否满足使用条件（返回详细信息）
     * 
     * @param User $user
     * @return array ['can_use' => bool, 'reason' => string|null, 'reason_code' => string|null]
     */
    public function checkUserConditionsWithReason(User $user): array
    {
        switch ($this->type) {
            case self::TYPE_GENERAL:
                // 通用礼品卡：所有用户都可以兑换，不限制是否有套餐
                // 移除了原来对有套餐用户的限制逻辑
                break;
            case self::TYPE_PLAN:
                // 套餐礼品卡：允许所有用户兑换（包括有活跃套餐的用户）
                // 移除了原来对活跃用户的限制，允许升级或续费套餐
                break;
        }

        $conditions = $this->conditions ?? [];

        // 检查新用户条件
        if (isset($conditions['new_user_only']) && $conditions['new_user_only']) {
            $maxDays = $conditions['new_user_max_days'] ?? 7;
            $userDays = floor((time() - $user->created_at) / 86400);
            // 修复：使用 > 而不是 >=，让第7天的用户仍可使用
            if ($userDays > $maxDays) {
                return [
                    'can_use' => false,
                    'reason' => "此礼品卡仅限注册 {$maxDays} 天内的新用户使用，您的账号已注册 {$userDays} 天",
                    'reason_code' => 'new_user_only'
                ];
            }
        }

        // 检查付费用户条件
        if (isset($conditions['paid_user_only']) && $conditions['paid_user_only']) {
            $paidOrderExists = $user->orders()->where('status', Order::STATUS_COMPLETED)->exists();
            if (!$paidOrderExists) {
                return [
                    'can_use' => false,
                    'reason' => '此礼品卡仅限已付费用户使用，请先购买套餐后再来兑换',
                    'reason_code' => 'paid_user_only'
                ];
            }
        }

        // 检查允许的套餐
        if (isset($conditions['allowed_plans']) && !empty($conditions['allowed_plans'])) {
            // 如果设置了允许的套餐列表，则必须有套餐且在列表中
            if (!$user->plan_id) {
                return [
                    'can_use' => false,
                    'reason' => '此礼品卡仅限特定套餐用户使用，您当前没有套餐',
                    'reason_code' => 'no_plan'
                ];
            }
            
            if (!in_array($user->plan_id, $conditions['allowed_plans'])) {
                $userPlanName = $user->plan ? $user->plan->name : '当前套餐';
                return [
                    'can_use' => false,
                    'reason' => "此礼品卡仅限特定套餐用户使用，您的「{$userPlanName}」不在允许列表中",
                    'reason_code' => 'plan_not_allowed'
                ];
            }
        }

        // 检查是否需要邀请人
        if (isset($conditions['require_invite']) && $conditions['require_invite']) {
            if (!$user->invite_user_id) {
                return [
                    'can_use' => false,
                    'reason' => '此礼品卡需要通过邀请链接注册的用户才能使用',
                    'reason_code' => 'require_invite'
                ];
            }
        }

        return ['can_use' => true, 'reason' => null, 'reason_code' => null];
    }

    /**
     * 检查用户是否满足使用条件
     */
    public function checkUserConditions(User $user): bool
    {
        $result = $this->checkUserConditionsWithReason($user);
        return $result['can_use'];
    }

    /**
     * 计算实际奖励
     */
    public function calculateActualRewards(User $user): array
    {
        $baseRewards = $this->rewards ?? [];
        $actualRewards = $baseRewards;

        // 处理盲盒随机奖励
        if ($this->type === self::TYPE_MYSTERY && isset($this->rewards['random_rewards'])) {
            $randomRewards = $this->rewards['random_rewards'];
            
            // 验证盲盒配置
            if (!is_array($randomRewards) || empty($randomRewards)) {
                \Log::error('盲盒配置错误：random_rewards必须是非空数组', [
                    'template_id' => $this->id,
                    'rewards' => $this->rewards
                ]);
                throw new \Exception('盲盒配置错误');
            }
            
            // 验证每个奖励项都有weight字段
            foreach ($randomRewards as $index => $reward) {
                if (!isset($reward['weight']) || !is_numeric($reward['weight']) || $reward['weight'] <= 0) {
                    \Log::error('盲盒配置错误：缺少或无效的weight字段', [
                        'template_id' => $this->id,
                        'reward_index' => $index,
                        'reward' => $reward
                    ]);
                    throw new \Exception('盲盒配置错误：第' . ($index + 1) . '项奖励缺少有效的权重');
                }
            }
            
            $totalWeight = array_sum(array_column($randomRewards, 'weight'));
            if ($totalWeight <= 0) {
                throw new \Exception('盲盒配置错误：总权重必须大于0');
            }
            
            $random = mt_rand(1, $totalWeight);
            $currentWeight = 0;

            foreach ($randomRewards as $reward) {
                $currentWeight += $reward['weight'];
                if ($random <= $currentWeight) {
                    // 移除random_rewards和weight，避免污染实际奖励
                    $selectedReward = $reward;
                    unset($selectedReward['weight']);
                    
                    // 合并奖励（排除一些元数据字段）
                    unset($actualRewards['random_rewards']);
                    $actualRewards = array_merge($actualRewards, $selectedReward);
                    break;
                }
            }
        }

        // 处理节日等特殊奖励(通用逻辑)
        if (isset($this->special_config['festival_bonus'])) {
            $now = time();
            $festivalConfig = $this->special_config;

            if (isset($festivalConfig['start_time']) && isset($festivalConfig['end_time'])) {
                if ($now >= $festivalConfig['start_time'] && $now <= $festivalConfig['end_time']) {
                    $bonus = data_get($festivalConfig, 'festival_bonus', 1.0);
                    if ($bonus > 1.0) {
                        foreach ($actualRewards as $key => &$value) {
                            if (is_numeric($value)) {
                                $value = intval($value * $bonus);
                            }
                        }
                        unset($value); // 解除引用
                    }
                }
            }
        }

        return $actualRewards;
    }

    /**
     * 检查使用频率限制（返回详细信息）
     * 
     * @param User $user
     * @return array ['can_use' => bool, 'reason' => string|null, 'reason_code' => string|null, 'used_count' => int|null, 'last_usage' => GiftCardUsage|null]
     */
    public function checkUsageLimitWithReason(User $user): array
    {
        $limits = $this->limits ?? [];
        
        // 一次性查询使用记录（避免重复查询）
        $usedCount = 0;
        $lastUsage = null;
        
        if (isset($limits['max_use_per_user']) || isset($limits['cooldown_hours'])) {
            $usedCount = $this->usages()->where('user_id', $user->id)->count();
            
            if ($usedCount > 0 && isset($limits['cooldown_hours'])) {
                $lastUsage = $this->usages()
                    ->where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->first();
            }
        }

        // 检查每用户最大使用次数
        if (isset($limits['max_use_per_user']) && $usedCount >= $limits['max_use_per_user']) {
            return [
                'can_use' => false,
                'reason' => "您已达到此礼品卡的最大使用次数限制（已使用 {$usedCount}/{$limits['max_use_per_user']} 次）",
                'reason_code' => 'max_use_reached',
                'used_count' => $usedCount,
                'last_usage' => $lastUsage
            ];
        }

        // 检查冷却时间
        if (isset($limits['cooldown_hours']) && $lastUsage && isset($lastUsage->created_at)) {
            $cooldownTime = $lastUsage->created_at + ($limits['cooldown_hours'] * 3600);
            $remainingSeconds = $cooldownTime - time();
            
            if ($remainingSeconds > 0) {
                $remainingHours = ceil($remainingSeconds / 3600);
                $remainingMinutes = ceil(($remainingSeconds % 3600) / 60);
                return [
                    'can_use' => false,
                    'reason' => "此礼品卡有冷却时间限制，请在 {$remainingHours} 小时 {$remainingMinutes} 分钟后再试",
                    'reason_code' => 'cooldown_active',
                    'used_count' => $usedCount,
                    'last_usage' => $lastUsage
                ];
            }
        }

        return [
            'can_use' => true,
            'reason' => null,
            'reason_code' => null,
            'used_count' => $usedCount,
            'last_usage' => $lastUsage
        ];
    }

    /**
     * 检查使用频率限制（兼容旧方法）
     */
    public function checkUsageLimit(User $user): bool
    {
        $result = $this->checkUsageLimitWithReason($user);
        return $result['can_use'];
    }
}
