<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCollection;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class AgentCollectionService
{
    public function settings(int $agentId): AgentCollection
    {
        $defaults = new AgentCollection([
            'agent_user_id' => $agentId, 'mode' => 'self', 'platform_enabled' => false,
            'payment_ids' => [], 'fee_bps' => 0, 'fee_fixed' => 0,
            'settlement_days' => 7, 'minimum_withdrawal' => 1000,
        ]);
        if (!DB::getSchemaBuilder()->hasTable('v2_agent_collection')) {
            return $defaults;
        }
        return AgentCollection::find($agentId) ?? $defaults;
    }

    public function snapshot(int $agentId, bool $prepaidOnly = false): array
    {
        $settings = $this->settings($agentId);
        if ($settings->mode !== 'platform') {
            return ['mode' => 'self'];
        }
        if (!$prepaidOnly && (!$settings->platform_enabled || empty($settings->payment_ids)
            || !Payment::where('owner_type', Payment::OWNER_PLATFORM)->where('payment', '!=', 'balance')
                ->whereIn('id', $settings->payment_ids)->where('enable', true)->exists())) {
            throw new ApiException('平台代收暂不可用，请联系站点客服');
        }
        return [
            'mode' => 'platform', 'payment_ids' => $settings->payment_ids,
            'fee_bps' => (int) $settings->fee_bps, 'fee_fixed' => (int) $settings->fee_fixed,
            'settlement_days' => (int) $settings->settlement_days,
        ];
    }

    public function forOrder(?AgentOrderContext $context): array
    {
        return $context?->pricing_snapshot['collection'] ?? ['mode' => 'self'];
    }

    public function isPlatform(?AgentOrderContext $context): bool
    {
        return $this->forOrder($context)['mode'] === 'platform';
    }

    public function fee(int $sale, array $snapshot): int
    {
        return intdiv($sale * (int) ($snapshot['fee_bps'] ?? 0) + 9999, 10000)
            + (int) ($snapshot['fee_fixed'] ?? 0);
    }

    public function assertActive(int $agentId): void
    {
        if (!AgentProfile::where('user_id', $agentId)->where('status', 'active')->exists()
            || !\App\Models\User::whereKey($agentId)->where('banned', false)->exists()) {
            throw new ApiException('代理账户未启用');
        }
    }

    public function setMode(int $agentId, string $mode): array
    {
        return DB::transaction(function () use ($agentId, $mode) {
            AgentProfile::where('user_id', $agentId)->lockForUpdate()->firstOrFail();
            $this->assertActive($agentId);
            $settings = $this->settings($agentId);
            if (!in_array($mode, ['self', 'platform'], true)) {
                throw new ApiException('收款模式无效');
            }
            if ($mode === 'platform' && (!$settings->platform_enabled || empty($settings->payment_ids))) {
                throw new ApiException('请先由管理员开通平台代收渠道');
            }
            if ($mode === 'self' && $settings->mode === 'platform' && app(AgentPrepaidService::class)->hasLiabilities($agentId)) {
                throw new ApiException('仍有代收余额或未完成订单，请先处理完毕再切换收款模式');
            }
            $settings->mode = $mode;
            $settings->updated_at = time();
            $settings->save();
            if ($mode === 'platform') {
                $this->snapshot($agentId);
            }
            return $this->view($agentId);
        });
    }

    public function configure(int $agentId, array $data, int $adminId): array
    {
        return DB::transaction(function () use ($agentId, $data, $adminId) {
            AgentProfile::where('user_id', $agentId)->lockForUpdate()->firstOrFail();
            $ids = array_values(array_unique(array_map('intval', $data['payment_ids'])));
            if (Payment::where('owner_type', Payment::OWNER_PLATFORM)->where('payment', '!=', 'balance')->whereIn('id', $ids)->count() !== count($ids)) {
                throw new ApiException('只能选择有效的官方支付渠道');
            }
            if ($data['platform_enabled'] && !$ids) {
                throw new ApiException('请至少选择一个官方支付渠道');
            }
            $settings = $this->settings($agentId);
            $settings->fill(array_merge($data, ['payment_ids' => $ids, 'updated_by' => $adminId, 'updated_at' => time()]));
            $settings->save();
            return $this->view($agentId);
        });
    }

    public function view(int $agentId): array
    {
        $settings = $this->settings($agentId);
        return array_merge($settings->only([
            'mode', 'platform_enabled', 'payment_ids', 'fee_bps', 'fee_fixed', 'settlement_days', 'minimum_withdrawal',
        ]), ['channels' => Payment::where('owner_type', Payment::OWNER_PLATFORM)
            ->whereIn('id', $settings->payment_ids ?? [])->orderBy('sort')->get(['id', 'name', 'enable'])->toArray()]);
    }
}
