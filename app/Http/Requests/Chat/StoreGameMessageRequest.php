<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreGameMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sendMessage', $this->route('room'));
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:2000',
        ];
    }
}