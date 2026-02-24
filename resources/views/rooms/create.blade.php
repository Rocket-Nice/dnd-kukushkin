@extends('layouts.app')

@section('title', 'Создание комнаты')

@section('content')
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-gray-800 overflow-hidden shadow-xl rounded-lg border border-gray-700">
            <div class="p-6">
                <h2 class="text-2xl font-bold text-white mb-6">Создание новой комнаты</h2>
                
                <form action="{{ route('rooms.store') }}" method="POST">
                    @csrf
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Название комнаты *</label>
                            <input type="text" name="name" required 
                                class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600 @error('name') border-red-500 @enderror"
                                value="{{ old('name') }}">
                            @error('name')
                                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Максимум игроков</label>
                            <select name="max_players" 
                                class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                                <option value="2">2 игрока</option>
                                <option value="3">3 игрока</option>
                                <option value="4" selected>4 игрока</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Промт для мастера (опционально)</label>
                            <textarea name="master_prompt" rows="5"
                                class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600"
                                placeholder="Опишите сюжет, мир или особенности приключения...">{{ old('master_prompt') }}</textarea>
                            <p class="text-xs text-gray-400 mt-1">
                                Если оставить пустым, будет использован стандартный промт
                            </p>
                        </div>
                        
                        <div class="flex justify-end space-x-3 pt-4">
                            <a href="{{ route('rooms.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg transition">
                                Отмена
                            </a>
                            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg transition">
                                Создать
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection