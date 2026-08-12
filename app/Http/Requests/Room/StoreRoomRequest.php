<?php

namespace App\Http\Requests\Room;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Создать комнату может любой аутентифицированный пользователь
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'master_prompt' => 'nullable|string|max:5000',
            'max_players' => 'integer|min:2|max:4',
        ];
    }
}