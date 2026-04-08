<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderUpgradeConfirm extends FormRequest
{
    public function rules()
    {
        return [
            'quote_token' => 'required|string|max:64',
        ];
    }

    public function messages()
    {
        return [
            'quote_token.required' => __('Quote token cannot be empty'),
        ];
    }
}
