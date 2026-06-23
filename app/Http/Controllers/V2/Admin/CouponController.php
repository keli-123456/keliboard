<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponGenerate;
use App\Http\Requests\Admin\CouponSave;
use App\Models\Coupon;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    private const COUPON_FILTER_FIELDS = [
        'id' => 'id',
        'name' => 'name',
        'code' => 'code',
        'type' => 'type',
        'scope_type' => 'scope_type',
        'site_id' => 'site_id',
        'agent_user_id' => 'agent_user_id',
        'agent_domain_id' => 'agent_domain_id',
        'value' => 'value',
        'show' => 'show',
        'limit_use' => 'limit_use',
        'limit_use_with_user' => 'limit_use_with_user',
        'started_at' => 'started_at',
        'ended_at' => 'ended_at',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    private const COUPON_SORT_FIELDS = [
        'id' => 'id',
        'name' => 'name',
        'code' => 'code',
        'type' => 'type',
        'scope_type' => 'scope_type',
        'site_id' => 'site_id',
        'agent_user_id' => 'agent_user_id',
        'value' => 'value',
        'show' => 'show',
        'started_at' => 'started_at',
        'ended_at' => 'ended_at',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    private function applyFiltersAndSorts(Request $request, $builder)
    {
        $filters = $request->input('filter');
        if (is_array($filters)) {
            collect($filters)->each(function ($filter) use ($builder) {
                if (!is_array($filter) || !array_key_exists('id', $filter)) {
                    return;
                }

                $key = $this->resolveCouponFilterField(trim((string) $filter['id']));
                if ($key === null) {
                    return;
                }

                $value = $filter['value'] ?? null;
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        $sorts = $request->input('sort');
        if (is_array($sorts)) {
            collect($sorts)->each(function ($sort) use ($builder) {
                if (!is_array($sort) || !array_key_exists('id', $sort)) {
                    return;
                }

                $key = $this->resolveCouponSortField(trim((string) $sort['id']));
                if ($key === null) {
                    return;
                }

                $value = !empty($sort['desc']) ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }

    private function resolveCouponFilterField(string $field): ?string
    {
        return self::COUPON_FILTER_FIELDS[$field] ?? null;
    }

    private function resolveCouponSortField(string $field): ?string
    {
        return self::COUPON_SORT_FIELDS[$field] ?? null;
    }

    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);
        $builder = Coupon::query();
        $this->applyFiltersAndSorts($request, $builder);
        $coupons = $builder
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ["*"], 'page', $current);
        return $this->paginate($coupons);
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|numeric',
            'show' => 'nullable|boolean'
        ], [
            'id.required' => '优惠券ID不能为空',
            'id.numeric' => '优惠券ID必须为数字'
        ]);
        try {
            DB::beginTransaction();
            $coupon = Coupon::find($request->input('id'));
            if (!$coupon) {
                throw new ApiException(400201, '优惠券不存在');
            }
            $coupon->update($params);
            DB::commit();
        } catch (\Exception $e) {
            \Log::error($e);
            return $this->fail([500, '保存失败']);
        }
    }

    public function show(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '优惠券ID不能为空',
            'id.numeric' => '优惠券ID必须为数字'
        ]);
        $coupon = Coupon::find($request->input('id'));
        if (!$coupon) {
            return $this->fail([400202, '优惠券不存在']);
        }
        $coupon->show = !$coupon->show;
        if (!$coupon->save()) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function generate(CouponGenerate $request)
    {
        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }

        $params = $request->validated();
        $params = array_merge($params, $this->scopePayload($request));
        if (!$request->input('id')) {
            if (!isset($params['code'])) {
                $params['code'] = Helper::randomChar(8);
            }
            if (!Coupon::create($params)) {
                return $this->fail([500, '创建失败']);
            }
        } else {
            try {
                $coupon = Coupon::find($request->input('id'));
                if (!$coupon) {
                    return $this->fail([400202, '优惠券不存在']);
                }
                $coupon->update($params);
            } catch (\Exception $e) {
                \Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        return $this->success(true);
    }

    private function multiGenerate(CouponGenerate $request)
    {
        $coupons = [];
        $coupon = array_merge($request->validated(), $this->scopePayload($request));
        $coupon['created_at'] = $coupon['updated_at'] = time();
        $coupon['show'] = 1;
        unset($coupon['generate_count']);
        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $coupon['code'] = Helper::randomChar(8);
            array_push($coupons, $coupon);
        }
        try {
            DB::beginTransaction();
            if (
                !Coupon::insert(array_map(function ($item) use ($coupon) {
                    // format data
                    if (isset($item['limit_plan_ids']) && is_array($item['limit_plan_ids'])) {
                        $item['limit_plan_ids'] = json_encode($coupon['limit_plan_ids']);
                    }
                    if (isset($item['limit_period']) && is_array($item['limit_period'])) {
                        $item['limit_period'] = json_encode($coupon['limit_period']);
                    }
                    return $item;
                }, $coupons))
            ) {
                throw new \Exception();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        $data = "名称,类型,金额或比例,开始时间,结束时间,可用次数,可用于订阅,归属类型,站点ID,代理ID,代理域名ID,券码,生成时间\r\n";
        foreach ($coupons as $coupon) {
            $type = ['', '金额', '比例'][$coupon['type']];
            $value = ['', ($coupon['value'] / 100), $coupon['value']][$coupon['type']];
            $startTime = date('Y-m-d H:i:s', $coupon['started_at']);
            $endTime = date('Y-m-d H:i:s', $coupon['ended_at']);
            $limitUse = $coupon['limit_use'] ?? '不限制';
            $createTime = date('Y-m-d H:i:s', $coupon['created_at']);
            $limitPlanIds = isset($coupon['limit_plan_ids']) ? implode("/", $coupon['limit_plan_ids']) : '不限制';
            $scopeType = $coupon['scope_type'] ?? Coupon::SCOPE_GLOBAL;
            $siteId = $coupon['site_id'] ?? '';
            $agentUserId = $coupon['agent_user_id'] ?? '';
            $agentDomainId = $coupon['agent_domain_id'] ?? '';
            $data .= "{$coupon['name']},{$type},{$value},{$startTime},{$endTime},{$limitUse},{$limitPlanIds},{$scopeType},{$siteId},{$agentUserId},{$agentDomainId},{$coupon['code']},{$createTime}\r\n";
        }

        $fileName = 'coupons_' . date('YmdHis') . '.csv';
        return response($data, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '优惠券ID不能为空',
            'id.numeric' => '优惠券ID必须为数字'
        ]);
        $coupon = Coupon::find($request->input('id'));
        if (!$coupon) {
            return $this->fail([400202, '优惠券不存在']);
        }
        if (!$coupon->delete()) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    private function scopePayload(Request $request): array
    {
        $scopeType = Coupon::normalizeScopeType($request->input('scope_type', Coupon::SCOPE_GLOBAL));
        $siteId = $this->positiveIntOrNull($request->input('site_id'));
        $agentUserId = $this->positiveIntOrNull($request->input('agent_user_id'));
        $agentDomainId = $this->positiveIntOrNull($request->input('agent_domain_id'));

        if ($scopeType === Coupon::SCOPE_SITE && !$siteId) {
            throw new ApiException(400203, '请选择优惠券所属站点');
        }
        if ($scopeType === Coupon::SCOPE_AGENT && !$agentUserId) {
            throw new ApiException(400204, '请选择优惠券所属代理');
        }

        return [
            'scope_type' => $scopeType,
            'site_id' => $scopeType === Coupon::SCOPE_SITE ? $siteId : null,
            'agent_user_id' => $scopeType === Coupon::SCOPE_AGENT ? $agentUserId : null,
            'agent_domain_id' => $scopeType === Coupon::SCOPE_AGENT ? $agentDomainId : null,
        ];
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
