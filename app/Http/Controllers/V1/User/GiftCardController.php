<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\GiftCardCheckRequest;
use App\Http\Requests\User\GiftCardRedeemRequest;
use App\Models\GiftCardUsage;
use App\Services\GiftCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GiftCardController extends Controller
{
    /**
     * 查询兑换码信息
     */
    public function check(GiftCardCheckRequest $request)
    {
        try {
            $giftCardService = new GiftCardService($request->input('code'));
            $giftCardService->setUser($request->user());

            // 1. 验证礼品卡本身是否有效 (如不存在、已过期、已禁用)
            $giftCardService->validateIsActive();

            // 2. 检查用户是否满足使用条件，但不在此处抛出异常
            $eligibility = $giftCardService->checkUserEligibility();

            // 3. 获取卡片信息和奖励预览
            $codeInfo = $giftCardService->getCodeInfo();
            $rewardPreview = $giftCardService->previewRewards();
            
            // 4. 预判套餐操作（仅套餐礼品卡）
            $planOperation = null;
            if ($codeInfo['template']['type'] === \App\Models\GiftCardTemplate::TYPE_PLAN) {
                $planOperation = $giftCardService->predictPlanOperation();
            }

            return $this->success([
                'code_info' => $codeInfo, // 这里面已经包含 plan_info
                'reward_preview' => $rewardPreview,
                'can_redeem' => $eligibility['can_redeem'],
                'reason' => $eligibility['reason'],
                'reason_code' => $eligibility['reason_code'] ?? null,
                'plan_operation' => $planOperation, // 套餐操作预判信息
            ]);

        } catch (ApiException $e) {
            // 这里只捕获 validateIsActive 抛出的异常
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('礼品卡查询失败', [
                'code' => $request->input('code'),
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '查询失败，请稍后重试']);
        }
    }

    /**
     * 使用兑换码
     */
    public function redeem(GiftCardRedeemRequest $request)
    {
        try {
            $giftCardService = new GiftCardService($request->input('code'));
            $giftCardService->setUser($request->user());
            $giftCardService->validate();

            // 使用礼品卡
            $result = $giftCardService->redeem([
                // 'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('礼品卡使用成功', [
                'code' => $request->input('code'),
                'user_id' => $request->user()->id,
                'rewards' => $result['rewards'],
            ]);

            // 根据操作信息生成更具体的成功消息
            $message = '兑换成功！';
            if (isset($result['operation_info']['message'])) {
                $message = '兑换成功！' . $result['operation_info']['message'];
            }

            return $this->success([
                'message' => $message,
                'rewards' => $result['rewards'],
                'invite_rewards' => $result['invite_rewards'],
                'template_name' => $result['template_name'],
                'operation_info' => $result['operation_info'] ?? null,
            ]);

        } catch (ApiException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('礼品卡使用失败', [
                'code' => $request->input('code'),
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail([500, '兑换失败，请稍后重试']);
        }
    }

    /**
     * 获取用户兑换记录
     */
    public function history(Request $request)
    {
        $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'template_type' => 'nullable|integer|in:1,2,3',
            'start_time' => 'nullable|integer|min:0',
            'end_time' => 'nullable|integer|min:0',
        ]);

        $perPage = $request->input('per_page', 15);

        $query = GiftCardUsage::with(['template', 'code'])
            ->where('user_id', $request->user()->id);
        
        // 按模板类型筛选
        if ($request->has('template_type')) {
            $query->whereHas('template', function ($q) use ($request) {
                $q->where('type', $request->input('template_type'));
            });
        }
        
        // 按时间范围筛选
        if ($request->has('start_time')) {
            $query->where('created_at', '>=', $request->input('start_time'));
        }
        if ($request->has('end_time')) {
            $query->where('created_at', '<=', $request->input('end_time'));
        }

        $usages = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $usages->getCollection()->map(function (GiftCardUsage $usage) {
            // 格式化奖励展示
            $formattedRewards = [];
            $rewards = $usage->rewards_given ?? [];
            
            if (isset($rewards['balance']) && $rewards['balance'] > 0) {
                $formattedRewards[] = '余额 ' . number_format($rewards['balance'] / 100, 2) . ' 元';
            }
            if (isset($rewards['transfer_enable']) && $rewards['transfer_enable'] > 0) {
                $gb = round($rewards['transfer_enable'] / (1024 * 1024 * 1024), 2);
                $formattedRewards[] = '流量 ' . ($gb >= 1 ? $gb . ' GB' : round($rewards['transfer_enable'] / (1024 * 1024), 2) . ' MB');
            }
            if (isset($rewards['device_limit']) && $rewards['device_limit'] > 0) {
                $formattedRewards[] = '设备数 +' . $rewards['device_limit'];
            }
            if (isset($rewards['expire_days']) && $rewards['expire_days'] > 0) {
                $formattedRewards[] = '有效期 +' . $rewards['expire_days'] . ' 天';
            }
            if (isset($rewards['plan_id'])) {
                $formattedRewards[] = '套餐奖励';
            }
            if (isset($rewards['reset_package']) && $rewards['reset_package']) {
                $formattedRewards[] = '流量已重置';
            }
            
            return [
                'id' => $usage->id,
                'code' => ($usage->code instanceof \App\Models\GiftCardCode && $usage->code->code)
                    ? (substr($usage->code->code, 0, 8) . '****')
                    : '[已删除]',
                'template_name' => $usage->template->name ?? '[模板已删除]',
                'template_type' => $usage->template->type ?? 0,
                'template_type_name' => $usage->template->type_name ?? '未知',
                'rewards_given' => $usage->rewards_given,
                'rewards_summary' => implode('、', $formattedRewards) ?: '无奖励',
                'invite_rewards' => $usage->invite_rewards,
                'multiplier_applied' => $usage->multiplier_applied,
                'created_at' => $usage->created_at,
            ];
        })->values();
        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $usages->currentPage(),
                'last_page' => $usages->lastPage(),
                'per_page' => $usages->perPage(),
                'total' => $usages->total(),
            ],
        ]);
    }

    /**
     * 获取兑换记录详情
     */
    public function detail(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_usage,id',
        ]);

        $usage = GiftCardUsage::with(['template', 'code', 'inviteUser'])
            ->where('user_id', $request->user()->id)
            ->where('id', $request->input('id'))
            ->first();

        if (!$usage) {
            return $this->fail([404, '记录不存在']);
        }

        return $this->success([
            'id' => $usage->id,
            'code' => $usage->code->code ?? '',
            'template' => [
                'name' => $usage->template->name ?? '',
                'description' => $usage->template->description ?? '',
                'type' => $usage->template->type ?? '',
                'type_name' => $usage->template->type_name ?? '',
                'icon' => $usage->template->icon ?? '',
                'theme_color' => $usage->template->theme_color ?? '',
            ],
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'invite_user' => $usage->inviteUser ? [
                'id' => $usage->inviteUser->id ?? '',
                'email' => isset($usage->inviteUser->email) ? (substr($usage->inviteUser->email, 0, 3) . '***@***') : '',
            ] : null,
            'user_level_at_use' => $usage->user_level_at_use,
            'plan_id_at_use' => $usage->plan_id_at_use,
            'multiplier_applied' => $usage->multiplier_applied,
            // 'ip_address' => $usage->ip_address,
            'notes' => $usage->notes,
            'created_at' => $usage->created_at,
        ]);
    }

    /**
     * 获取可用的礼品卡类型
     */
    public function types(Request $request)
    {
        return $this->success([
            'types' => \App\Models\GiftCardTemplate::getTypeMap(),
        ]);
    }
}
