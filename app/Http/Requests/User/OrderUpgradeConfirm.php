<?php

namespace App\Http\Requests\User;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class OrderUpgradeConfirm extends FormRequest
{
    public function rules()
    {
        return [
            'quote_token' => 'required|string|max:64',
            // Legacy themes send only the token; selection-aware themes must send both fields.
            'target_plan_id' => 'required_with:period|integer|min:1',
            'period' => 'required_with:target_plan_id|in:' . implode(',', array_unique(array_merge(
                array_keys(Plan::LEGACY_PERIOD_MAPPING), array_values(Plan::LEGACY_PERIOD_MAPPING)
            ))),
        ];
    }

    public function messages()
    {
        return [
            'quote_token.required' => __('Quote token cannot be empty'),
        ];
    }
}
