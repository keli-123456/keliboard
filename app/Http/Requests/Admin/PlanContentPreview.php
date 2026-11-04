<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanContentPreview extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => 'nullable|string|max:200000',
            'transfer_enable' => 'required|integer|min:1',
            'speed_limit' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
            'reset_traffic_method' => 'nullable|integer|in:0,1,2,3,4',
        ];
    }
}
