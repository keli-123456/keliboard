<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class GiftCardCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => 'required|string|min:8|max:32|regex:/^[A-Z0-9]+$/',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'code.required' => '请输入兑换码',
            'code.min' => '兑换码长度不能少于8位',
            'code.max' => '兑换码长度不能超过32位',
            'code.regex' => '兑换码格式不正确，只能包含大写字母和数字',
        ];
    }
}
