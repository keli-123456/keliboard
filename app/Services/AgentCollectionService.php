<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCollection;
use App\Models\AgentCollectionPolicy;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class AgentCollectionService
{
    public const RULE_FIELDS = ['platform_enabled', 'payment_ids', 'fee_bps', 'fee_fixed', 'settlement_days', 'minimum_withdrawal'];

    public static function validationRules(): array
    {
        return [
            'platform_enabled' => 'required|boolean', 'payment_ids' => 'present|array|max:100',
            'payment_ids.*' => 'required|integer|min:1|distinct',
            'fee_bps' => 'required|integer|min:0|max:10000', 'fee_fixed' => 'required|integer|min:0|max:1000000',
            'settlement_days' => 'required|integer|min:1|max:90', 'minimum_withdrawal' => 'required|integer|min:1|max:100000000',
        ];
    }

    private function storedSettings(int $agentId): AgentCollection
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

    public function policy(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('v2_agent_collection_policy')) {
            return array_merge($this->storedSettings(0)->only(self::RULE_FIELDS), [
                'enabled' => true, 'use_global' => false, 'revision' => 0, 'ready' => false,
            ]);
        }
        $policy = AgentCollectionPolicy::find(1);
        if (!$policy) {
            throw new ApiException('统一代收配置缺失，请联系管理员');
        }
        return array_merge($policy->only(array_merge(self::RULE_FIELDS, ['enabled', 'use_global', 'revision'])), ['ready' => true]);
    }

    public function settings(int $agentId): AgentCollection
    {
        $settings = $this->storedSettings($agentId);
        $policy = $this->policy();
        if ($policy['use_global']) {
            $settings->fill(array_intersect_key($policy, array_flip(self::RULE_FIELDS)));
        }
        $settings->configured_platform_enabled = (bool) $settings->platform_enabled;
        $settings->global_enabled = $policy['enabled'];
        $settings->use_global = $policy['use_global'];
        $settings->platform_enabled = $settings->platform_enabled && $policy['enabled'];
        return $settings;
    }

    public function configurePolicy(array $data, int $adminId): array
    {
        if (!$this->policy()['ready']) {
            throw new ApiException('请先完成数据库升级，再保存统一代收设置');
        }
        return DB::transaction(function () use ($data, $adminId) {
            $policy = AgentCollectionPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            if ((int) $policy->revision !== (int) $data['revision']) {
                throw new ApiException('统一设置已被其他管理员修改，请刷新后重新确认');
            }
            // A removed channel must not prevent emergency closure of the master gate.
            $ids = $data['enabled'] && $data['use_global'] ? $this->validateChannels($data)
                : array_values(array_unique(array_map('intval', $data['payment_ids'])));
            $policy->fill(array_intersect_key($data, array_flip(array_merge(self::RULE_FIELDS, ['enabled', 'use_global']))));
            $policy->payment_ids = $ids;
            $policy->revision++;
            $policy->updated_by = $adminId;
            $policy->updated_at = time();
            $policy->save();
            return $this->policy();
        });
    }

    private function validateChannels(array $data): array
    {
        $ids = array_values(array_unique(array_map('intval', $data['payment_ids'])));
        if (Payment::where('owner_type', Payment::OWNER_PLATFORM)->where('payment', '!=', 'balance')->whereIn('id', $ids)->count() !== count($ids)) {
            throw new ApiException('只能选择有效的官方支付渠道');
        }
        if ($data['platform_enabled'] && !$ids) {
            throw new ApiException('请至少选择一个官方支付渠道');
        }
        return $ids;
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
            // Never persist effective global rules over the agent's independent configuration.
            $stored = $this->storedSettings($agentId);
            $stored->mode = $mode;
            $stored->updated_at = time();
            $stored->save();
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
            if ($this->policy()['use_global']) {
                throw new ApiException('当前使用统一代收规则，请在统一代收设置中修改');
            }
            $ids = $this->validateChannels($data);
            $settings = $this->storedSettings($agentId);
            $settings->fill(array_merge(array_intersect_key($data, array_flip(self::RULE_FIELDS)), ['payment_ids' => $ids, 'updated_by' => $adminId, 'updated_at' => time()]));
            $settings->save();
            return $this->view($agentId);
        });
    }

    public function view(int $agentId): array
    {
        $settings = $this->settings($agentId);
        return array_merge($settings->only([
            'mode', 'platform_enabled', 'payment_ids', 'fee_bps', 'fee_fixed', 'settlement_days', 'minimum_withdrawal',
            'configured_platform_enabled', 'global_enabled', 'use_global',
        ]), ['channels' => Payment::where('owner_type', Payment::OWNER_PLATFORM)
            ->whereIn('id', $settings->payment_ids ?? [])->orderBy('sort')->get(['id', 'name', 'enable'])->toArray()]);
    }
}
