<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $maxImages = (int) config('tickets.attachments.max_images', 3);
        $maxKb = (int) config('tickets.attachments.max_kb', 5120);
        return [
            'subject' => 'required',
            'level' => 'required|in:0,1,2',
            'message' => 'required_without:images',
            'images' => 'nullable|array|max:' . $maxImages,
            'images.*' => 'file|image|mimes:jpg,jpeg,png,webp|max:' . $maxKb
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => __('Ticket subject cannot be empty'),
            'level.required' => __('Ticket level cannot be empty'),
            'level.in' => __('Incorrect ticket level format'),
            'message.required_without' => __('Message cannot be empty'),
            'images.array' => __('Invalid parameter'),
            'images.max' => __('Invalid parameter'),
            'images.*.file' => __('Invalid parameter'),
            'images.*.image' => __('Invalid parameter'),
            'images.*.mimes' => __('Invalid parameter'),
            'images.*.max' => __('Invalid parameter')
        ];
    }
}
