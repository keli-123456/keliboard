<?php

namespace App\Http\Requests\Admin;

use App\Models\OrderUpgradeQuote;
use Illuminate\Foundation\Http\FormRequest;

class OrderUpgradeQuoteFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'user_id' => 'nullable|integer|min:1',
            'token' => 'nullable|string|max:64',
            'status' => 'nullable|in:' . implode(',', [
                OrderUpgradeQuote::STATUS_PENDING,
                OrderUpgradeQuote::STATUS_CONSUMED,
                OrderUpgradeQuote::STATUS_EXPIRED,
                OrderUpgradeQuote::STATUS_CANCELLED,
            ]),
            'source_order_id' => 'nullable|integer|min:1',
            'source_plan_id' => 'nullable|integer|min:1',
            'target_plan_id' => 'nullable|integer|min:1',
            'has_upgrade_order' => 'nullable|boolean',
            'created_from' => 'nullable',
            'created_to' => 'nullable',
            'expires_from' => 'nullable',
            'expires_to' => 'nullable',
            'sort' => 'nullable|array',
            'sort.*.id' => 'nullable|in:id,user_id,status,source_order_id,source_plan_id,target_plan_id,target_price,upgrade_credit_amount,final_pay_amount,expires_at,created_at',
            'sort.*.desc' => 'nullable|boolean',
        ];
    }
}
