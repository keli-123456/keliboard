<?php

namespace App\Http\Requests\User;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class OrderUpgradePreview extends FormRequest
{
    public function rules()
    {
        $periods = array_unique(array_merge(
            array_keys(Plan::LEGACY_PERIOD_MAPPING),
            array_values(Plan::LEGACY_PERIOD_MAPPING)
        ));

        return [
            'target_plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|in:' . implode(',', $periods),
        ];
    }

    public function messages()
    {
        return [
            'target_plan_id.required' => __('Plan ID cannot be empty'),
            'target_plan_id.exists' => __('Subscription plan does not exist'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period'),
        ];
    }
}
