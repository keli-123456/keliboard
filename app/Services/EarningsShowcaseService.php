<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EarningsShowcaseService
{
    // Personal earnings require a new opt-in; the former aggregate switch must not enable them.
    public const SETTING = 'agent_earnings_showcase_enabled';
    public const MIN_RECIPIENTS = 5;
    public const DISPLAY_LIMIT = 3;

    private function emptyGroup(): array
    {
        return ['ready' => false, 'eligible' => false, 'entries' => [], 'currency' => 'CNY',
            'as_of' => Carbon::now(config('app.timezone'))->subDay()->format('Y-m-d')];
    }

    private function supported(array $tables): bool
    {
        if (admin_setting('currency', 'CNY') !== 'CNY' || !config('app.key')) return false;
        $schema = DB::getSchemaBuilder();
        foreach ($tables as $table => $columns) {
            if (!$schema->hasTable($table) || !$schema->hasColumns($table, $columns)) return false;
        }
        return true;
    }

    private function individuals(Builder $query, string $recipient, string $amount, string $time, string $kind): array
    {
        $group = $this->emptyGroup();
        // Bound the candidate pool, not each person's lifetime credits. No amount-based ranking.
        $rows = $query->selectRaw("$recipient AS recipient, SUM($amount) AS amount")
            ->groupBy($recipient)->havingRaw("SUM($amount) > 0 AND SUM($amount) <= 9007199254740991")
            ->orderByRaw("MAX($time) DESC")->orderBy($recipient)->limit(50)->get();
        $group['ready'] = true;
        $group['eligible'] = $rows->count() >= self::MIN_RECIPIENTS;
        if (!$group['eligible']) return $group;
        $key = (string) config('app.key');
        $scope = $kind . ':' . $group['as_of'] . ':';
        $hash = fn ($row) => hash_hmac('sha256', $scope . $row->recipient, $key);
        $group['entries'] = $rows->sortBy($hash)->take(self::DISPLAY_LIMIT)->values()->map(fn ($row) => [
            'alias' => strtoupper(substr($hash($row), 0, 8)), 'amount' => (int) $row->amount,
        ])->all();
        return $group;
    }

    public function referralEarnings(): array
    {
        if (!$this->supported([
            'v2_commission_log' => ['invite_user_id', 'get_amount', 'created_at', 'trade_no', 'reversed_at'],
            'v2_order' => ['trade_no', 'commission_status', 'status', 'refund_disposed_at', 'refund_amount', 'site_id'],
        ])) return $this->emptyGroup();
        $schema = DB::getSchemaBuilder();
        $cutoff = Carbon::now(config('app.timezone'))->startOfDay()->timestamp;
        // EXISTS avoids multiplying a credited row when imported trade numbers are duplicated.
        $query = DB::table('v2_commission_log as cl')->whereNull('cl.reversed_at')->where('cl.get_amount', '>', 0)
            ->where('cl.invite_user_id', '>', 0)->where('cl.created_at', '<', $cutoff)
            ->whereExists(function ($q) use ($schema) {
                $q->selectRaw('1')->from('v2_order as o')->whereColumn('o.trade_no', 'cl.trade_no')
                    ->where('o.commission_status', Order::COMMISSION_STATUS_VALID)
                    ->whereIn('o.status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
                    ->whereNull('o.site_id')->whereNull('o.refund_disposed_at')
                    ->where(fn ($refund) => $refund->whereNull('o.refund_amount')->orWhere('o.refund_amount', 0));
                foreach (['v2_agent_order_context', 'v2_site_order_context'] as $table) {
                    if ($schema->hasTable($table)) $q->whereNotExists(fn ($other) => $other->selectRaw('1')->from($table . ' as c')->whereColumn('c.order_id', 'o.id'));
                }
            });
        return $this->individuals($query, 'cl.invite_user_id', 'cl.get_amount', 'cl.created_at', 'referral');
    }

    public function agentEarnings(): array
    {
        if (!$this->supported([
            'v2_agent_profit' => ['agent_user_id', 'order_id', 'trade_no', 'amount', 'sale_amount', 'cost_amount', 'fee_amount', 'status', 'available_at', 'created_at', 'updated_at'],
            'v2_agent_order_context' => ['order_id', 'agent_user_id', 'status', 'pricing_snapshot'],
            'v2_order' => ['id', 'trade_no', 'status', 'paid_at', 'plan_id', 'refund_disposed_at', 'refund_amount', 'site_id'],
        ])) return $this->emptyGroup();
        $cutoff = Carbon::now(config('app.timezone'))->startOfDay()->timestamp;
        $query = DB::table('v2_agent_profit as p')->where('p.status', 'available')->where('p.amount', '>', 0)
            ->where('p.agent_user_id', '>', 0)->where('p.created_at', '<', $cutoff)
            ->where('p.available_at', '<', $cutoff)->where('p.updated_at', '<', $cutoff)
            ->whereRaw('p.amount + p.cost_amount + p.fee_amount = p.sale_amount')
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('v2_order as o')->whereColumn('o.id', 'p.order_id')->whereColumn('o.trade_no', 'p.trade_no')
                    ->where('o.status', Order::STATUS_COMPLETED)->where('o.paid_at', '>', 0)->where('o.plan_id', '>', 0)
                    ->whereNull('o.site_id')->whereNull('o.refund_disposed_at')
                    ->where(fn ($refund) => $refund->whereNull('o.refund_amount')->orWhere('o.refund_amount', 0));
            })->whereExists(function ($q) {
                $q->selectRaw('1')->from('v2_agent_order_context as ac')->whereColumn('ac.order_id', 'p.order_id')
                    ->whereColumn('ac.agent_user_id', 'p.agent_user_id')->where('ac.status', 'paid')
                    ->where('ac.pricing_snapshot->collection->mode', 'platform');
            });
        if (DB::getSchemaBuilder()->hasTable('v2_site_order_context')) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('v2_site_order_context as sc')->whereColumn('sc.order_id', 'p.order_id'));
        }
        return $this->individuals($query, 'p.agent_user_id', 'p.amount', 'p.updated_at', 'agent');
    }

    public function adminView(): array
    {
        return ['enabled' => (bool) admin_setting(self::SETTING, false), 'minimum_recipients' => self::MIN_RECIPIENTS,
            'referral_earnings' => $this->referralEarnings(), 'agent_earnings' => $this->agentEarnings()];
    }

    public function forUser(Request $request): array
    {
        $schema = DB::getSchemaBuilder();
        $user = $request->user();
        if (!$user || (int) $user->id <= 0 || !$schema->hasTable('v2_agent_user')) {
            return ['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null];
        }
        // Entry visibility follows account ownership, not the current site or domain.
        if (app(ReferralEligibilityService::class)->isAgentUser((int) $user->id)) {
            return ['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null];
        }
        $enabled = (bool) admin_setting('agent_center_enable', false);
        $active = $enabled && DB::getSchemaBuilder()->hasTable('v2_agent_profile')
            && AgentProfile::where('user_id', $user->id)->where('status', 'active')->exists();
        $result = ['referral' => true, 'agent' => $enabled ? ($active ? 'active' : 'available') : 'hidden',
            'referral_earnings' => null, 'agent_earnings' => null];
        if ((bool) admin_setting(self::SETTING, false)) {
            $key = 'earnings:individuals:v2:' . Carbon::now(config('app.timezone'))->format('Y-m-d') . ':' . admin_setting('currency', 'CNY')
                . ':' . substr(hash('sha256', (string) config('app.key')), 0, 16);
            $groups = Cache::remember($key, 300, fn () => ['referral_earnings' => $this->referralEarnings(), 'agent_earnings' => $this->agentEarnings()]);
            foreach ($groups as $kind => $group) {
                if ($kind === 'agent_earnings' && !$enabled) continue;
                if ($group['ready'] && $group['eligible']) $result[$kind] = array_intersect_key($group, array_flip(['entries', 'currency', 'as_of']));
            }
        }
        return $result;
    }
}
