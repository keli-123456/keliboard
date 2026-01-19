<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthLogin extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'password' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty')
        ];
    }
}
