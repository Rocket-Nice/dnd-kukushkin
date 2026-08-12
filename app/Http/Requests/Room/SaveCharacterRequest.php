<?php

namespace App\Http\Requests\Room;

use Illuminate\Foundation\Http\FormRequest;

class SaveCharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageCharacter', $this->route('room'));
    }

    public function rules(): array
    {
        return [
            'character_name' => 'required|string|max:255',
            'character_description' => 'nullable|string|max:2000',
            'character_class' => 'required|string|in:fighter,wizard,rogue,cleric,ranger,paladin,bard,barbarian',
            'strength' => 'required|integer|min:3|max:20',
            'dexterity' => 'required|integer|min:3|max:20',
            'constitution' => 'required|integer|min:3|max:20',
            'intelligence' => 'required|integer|min:3|max:20',
            'wisdom' => 'required|integer|min:3|max:20',
            'charisma' => 'required|integer|min:3|max:20',
        ];
    }
}