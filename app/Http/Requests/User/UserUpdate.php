<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'remind_expire' => 'in:0,1',
            'remind_traffic' => 'in:0,1',
            'auto_renew_enable' => 'in:0,1',
            'auto_renew_period' => 'nullable|in:' . implode(',', \App\Models\User::getAutoRenewPeriods()),
        ];
    }

    public function messages()
    {
        return [
            'remind_expire.in' => __('Incorrect format of expiration reminder'),
            'remind_traffic.in' => __('Incorrect traffic alert format'),
            'auto_renew_enable.in' => __('Incorrect auto renewal setting'),
            'auto_renew_period.in' => __('Incorrect auto renewal period'),
        ];
    }
}
