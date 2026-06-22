<?php

namespace App\Http\Requests\User;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $periods = array_unique(array_merge(
            array_keys(Plan::LEGACY_PERIOD_MAPPING),
            array_values(Plan::LEGACY_PERIOD_MAPPING)
        ));

        return [
            'plan_id' => 'required',
            'period' => 'required|in:' . implode(',', $periods)
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
