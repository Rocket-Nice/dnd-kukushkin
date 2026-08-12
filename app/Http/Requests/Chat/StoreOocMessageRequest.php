<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreOocMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Для OOC-чата достаточно просто состоять в комнате (не обязательно в игре)
        return $this->user()->can('view', $this->route('room'));
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:1000',
        ];
    }
}