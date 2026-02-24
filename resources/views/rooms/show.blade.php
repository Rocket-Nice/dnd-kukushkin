@extends('layouts.app')

@section('title', $room->name)

@section('content')
<div data-room-id="{{ $room->id }}" 
     data-user-id="{{ Auth::id() }}" 
     data-creator-id="{{ $room->created_by }}" 
     class="min-h-screen bg-gray-900">
    <!-- Шапка комнаты с кнопками управления -->
    <div class="bg-gray-800 border-b border-gray-700 px-4 sm:px-6 py-4">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-white truncate max-w-[200px] sm:max-w-none">{{ $room->name }}</h1>
                <p class="text-xs sm:text-sm text-gray-400">Статус: 
                    <span class="status-badge px-2 py-1 rounded text-xs 
                        @if($room->status === 'waiting') bg-yellow-600 
                        @elseif($room->status === 'playing') bg-green-600 
                        @else bg-gray-600 @endif">
                        {{ $room->status === 'waiting' ? 'Ожидание' : ($room->status === 'playing' ? 'В игре' : 'Завершена') }}
                    </span>
                </p>
            </div>
            
            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                @if($room->created_by === Auth::id() && $room->status === 'waiting')
                    <form action="{{ route('rooms.start', $room) }}" method="POST" class="inline flex-1 sm:flex-none">
                        @csrf
                        <button type="submit" 
                            class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-3 sm:px-4 rounded-lg text-sm sm:text-base transition"
                            {{ $room->users()->wherePivot('is_ready', true)->count() < 2 ? 'disabled' : '' }}>
                            Начать игру
                        </button>
                    </form>
                @endif
                
                <!-- Кнопка выхода из комнаты (для всех, кроме создателя) -->
                @if($room->status === 'waiting' && $room->created_by !== Auth::id())
                    <form action="{{ route('rooms.leave', $room) }}" method="POST" class="inline flex-1 sm:flex-none">
                        @csrf
                        <button type="submit" 
                                class="w-full sm:w-auto bg-yellow-600 hover:bg-yellow-700 text-white font-bold py-2 px-3 sm:px-4 rounded-lg text-sm sm:text-base transition"
                                onclick="return confirm('Вы уверены, что хотите выйти из комнаты?')">
                            Выйти
                        </button>
                    </form>
                @endif
                
                <!-- Кнопка удаления комнаты (только для создателя) -->
                @if($room->created_by === Auth::id())
                    <a href="{{ route('rooms.destroy.confirm', $room) }}" 
                       class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-3 sm:px-4 rounded-lg text-sm sm:text-base text-center transition">
                        Удалить
                    </a>
                @endif
            </div>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="max-w-7xl mx-auto p-4 sm:p-6">
        <div class="flex flex-col lg:grid lg:grid-cols-4 gap-4 sm:gap-6">
            <!-- Левая колонка: Игровой чат (на мобиле сверху, на планшете/десктопе слева) -->
            <div class="lg:col-span-3 order-1">
                <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 h-[50vh] sm:h-[60vh] lg:h-[calc(100vh-200px)] flex flex-col">
                    <!-- Заголовок игрового чата -->
                    <div class="bg-gray-700 px-3 sm:px-4 py-2 sm:py-3 rounded-t-lg border-b border-gray-600">
                        <h2 class="text-base sm:text-lg font-semibold flex items-center">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            Игровой чат
                        </h2>
                    </div>
                    
                    <!-- Сообщения игрового чата -->
                    <div id="game-chat" class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-3 sm:space-y-4">
                        @forelse($room->gameMessages as $msg)
                            <div class="flex {{ $msg->role === 'assistant' ? 'justify-start' : 'justify-end' }}" data-message-id="{{ $msg->id }}" data-timestamp="{{ $msg->created_at->timestamp }}">
                                <div class="max-w-[90%] sm:max-w-[80%] {{ $msg->role === 'assistant' 
                                    ? ($msg->role === 'system' ? 'bg-yellow-600 bg-opacity-20 text-yellow-200 border border-yellow-700' : 'bg-gray-700 text-gray-100')
                                    : 'bg-purple-600 text-white' }} rounded-lg px-3 sm:px-4 py-2 shadow">
                                    <div class="text-xs {{ $msg->role === 'assistant' ? 'text-gray-400' : 'text-purple-200' }} mb-1">
                                        {{ $msg->role === 'assistant' ? '🎲 Мастер' : ($msg->user?->pivot?->character_name ?? 'System') }}
                                    </div>
                                    <div class="text-xs sm:text-sm break-words">{{ $msg->content }}</div>
                                    <div class="text-xs {{ $msg->role === 'assistant' ? 'text-gray-500' : 'text-purple-300' }} text-right mt-1">
                                        {{ $msg->created_at->format('H:i') }}
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div id="game-empty-message" class="text-center text-gray-500 py-8">
                                Пока нет сообщений. Начните игру!
                            </div>
                        @endforelse
                    </div>
                    
                    <!-- Форма ввода игрового чата -->
                    <div class="bg-gray-700 px-3 sm:px-4 py-2 sm:py-3 rounded-b-lg border-t border-gray-600">
                        <form id="game-message-form" class="flex flex-col sm:flex-row gap-2">
                            @csrf
                            <div class="flex flex-1 gap-2">
                                <input type="text" 
                                    name="message" 
                                    id="game-message-input"
                                    placeholder="Ваше действие..." 
                                    class="flex-1 bg-gray-600 text-white placeholder-gray-400 rounded-lg px-3 sm:px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-500"
                                    {{ $room->status !== 'playing' ? 'disabled' : '' }}>
                                <button type="submit" 
                                    class="bg-purple-600 hover:bg-purple-700 text-white px-3 sm:px-4 py-2 rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    {{ $room->status !== 'playing' ? 'disabled' : '' }}>
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                </button>
                            </div>
                            <button type="button" 
                                id="roll-dice"
                                class="w-full sm:w-auto bg-yellow-600 hover:bg-yellow-700 text-white px-3 sm:px-4 py-2 rounded-lg transition flex items-center justify-center space-x-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                {{ $room->status !== 'playing' ? 'disabled' : '' }}>
                                <span>🎲</span>
                                <span class="sm:hidden">Бросок кубика</span>
                            </button>
                        </form>
                        <p class="text-xs text-gray-500 mt-2 hidden sm:block">
                            💡 Для броска кубика нажмите 🎲 или введите /roll [сложность]
                        </p>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: OOC чат и игроки (на мобиле снизу, на планшете/десктопе справа) -->
            <div class="lg:col-span-1 order-2 space-y-4 sm:space-y-6">
                <!-- Блок игроков -->
                <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700">
                    <div class="bg-gray-700 px-3 sm:px-4 py-2 sm:py-3 rounded-t-lg border-b border-gray-600">
                        <h2 class="text-base sm:text-lg font-semibold flex items-center">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <span class="truncate players-header" data-max="{{ $room->max_players }}">Участники <span class="players-count">{{ $room->users->count() }}</span>/{{ $room->max_players }}</span>
                        </h2>
                    </div>
                    <div class="players-container p-3 sm:p-4 space-y-2 sm:space-y-3 max-h-[200px] sm:max-h-[300px] overflow-y-auto">
                        @forelse($room->users as $user)
                            @php
                                $characterClass = $user?->pivot->character_class ?? '';
                                $className = match($characterClass) {
                                    'fighter' => 'Воин',
                                    'wizard' => 'Волшебник',
                                    'rogue' => 'Плут',
                                    'cleric' => 'Жрец',
                                    'ranger' => 'Следопыт',
                                    'paladin' => 'Паладин',
                                    'bard' => 'Бард',
                                    'barbarian' => 'Варвар',
                                    default => $characterClass,
                                };
                            @endphp
                            <div class="flex items-center justify-between p-2 {{ $user?->pivot->is_ready ? 'bg-green-900 bg-opacity-20' : 'bg-gray-700' }} rounded-lg" data-user-id="{{ $user->id }}">
                                <div class="flex items-center space-x-2 min-w-0 flex-1">
                                    <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full bg-gray-600 flex items-center justify-center text-xs sm:text-sm font-bold flex-shrink-0">
                                        {{ substr($user?->pivot->character_name ?? $user->name, 0, 1) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium text-xs sm:text-sm truncate">
                                            {{ $user?->pivot->character_name ?? $user->name }}
                                            @if($user?->pivot->is_ready)
                                                <span class="ml-1 text-xs text-green-400">✅</span>
                                            @endif
                                        </div>
                                        @if($user?->pivot->character_name)
                                            <div class="text-xs text-gray-400 truncate">
                                                @if($className)
                                                    <span class="text-purple-400">{{ $className }}</span> | 
                                                @endif
                                                HP: {{ $user?->pivot->current_hp }}/{{ $user?->pivot->max_hp }} | AC: {{ $user?->pivot->armor_class }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @if($user->id === $room->created_by)
                                    <span class="text-xs bg-yellow-600 px-2 py-1 rounded ml-2 flex-shrink-0">Мастер</span>
                                @endif
                            </div>
                        @empty
                            <div class="text-center text-gray-500 py-4">
                                Нет участников
                            </div>
                        @endforelse
                    </div>
                </div>

                <!-- OOC чат -->
                <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 h-[30vh] sm:h-[40vh] lg:h-[calc(100vh-550px)] min-h-[200px] sm:min-h-[300px] flex flex-col">
                    <div class="bg-gray-700 px-3 sm:px-4 py-2 sm:py-3 rounded-t-lg border-b border-gray-600">
                        <h2 class="text-base sm:text-lg font-semibold flex items-center">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-2 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                            </svg>
                            OOC чат
                        </h2>
                    </div>
                    
                    <div id="ooc-chat" class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-2 sm:space-y-3">
                        @forelse($room->oocMessages as $msg)
                            <div class="text-xs sm:text-sm ooc-message" data-message-id="{{ $msg->id }}" data-timestamp="{{ $msg->created_at->timestamp }}">
                                <span class="font-medium text-blue-400">{{ $msg->user?->name ?? 'System' }}:</span>
                                <span class="text-gray-300 ml-1 break-words">{{ $msg->content }}</span>
                                <span class="text-xs text-gray-500 ml-2">{{ $msg->created_at->format('H:i') }}</span>
                            </div>
                        @empty
                            <div id="ooc-empty-message" class="text-center text-gray-500 py-4">
                                Нет сообщений
                            </div>
                        @endforelse
                    </div>
                    
                    <div class="bg-gray-700 px-3 sm:px-4 py-2 sm:py-3 rounded-b-lg border-t border-gray-600">
                        <form id="ooc-message-form" class="flex space-x-2">
                            @csrf
                            <input type="text" 
                                name="message" 
                                id="ooc-message-input"
                                placeholder="Обсуждение..." 
                                class="flex-1 bg-gray-600 text-white placeholder-gray-400 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 border border-gray-500">
                            <button type="submit" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Информация о комнате (скрываем на мобиле, показываем на планшете+) -->
                <div class="hidden sm:block bg-gray-800 rounded-lg shadow-xl border border-gray-700 p-3 sm:p-4">
                    <h3 class="text-xs sm:text-sm font-semibold text-gray-400 mb-2">О комнате</h3>
                    <div class="space-y-1 sm:space-y-2 text-xs">
                        <p><span class="text-gray-500">Создатель:</span> <span class="text-gray-300">{{ $room->creator->name }}</span></p>
                        <p><span class="text-gray-500">Создана:</span> <span class="text-gray-300">{{ $room->created_at->format('d.m.Y H:i') }}</span></p>
                        @if($room->master_prompt)
                            <p><span class="text-gray-500">Промт мастера:</span></p>
                            <p class="text-gray-400 bg-gray-700 p-2 rounded text-xs break-words">{{ $room->master_prompt }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- МОДАЛЬНОЕ ОКНО СОЗДАНИЯ ПЕРСОНАЖА (адаптивное) -->
@php
    $showModal = false;
    if ($room->status === 'waiting') {
        if (!$character) {
            $showModal = true;
        } elseif (empty($character->character_name)) {
            $showModal = true;
        } elseif (!$character->is_ready) {
            $showModal = true;
        }
    }
@endphp

@if($showModal)
<div id="character-modal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center p-2 sm:p-4 z-50">
    <div class="bg-gray-800 rounded-lg shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto border border-gray-700">
        <div class="bg-gray-700 px-4 sm:px-6 py-3 sm:py-4 rounded-t-lg border-b border-gray-600">
            <h2 class="text-lg sm:text-xl font-bold text-white">Создание персонажа</h2>
            <p class="text-xs sm:text-sm text-gray-400 mt-1">Заполните информацию о вашем герое</p>
        </div>
        
        <form action="{{ route('rooms.character.save', $room) }}" method="POST" class="p-4 sm:p-6 space-y-4 sm:space-y-6">
            @csrf
            
            <!-- Основная информация -->
            <div class="space-y-3 sm:space-y-4">
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-300 mb-1 sm:mb-2">Имя персонажа *</label>
                    <input type="text" name="character_name" required 
                        class="w-full bg-gray-700 text-white rounded-lg px-3 sm:px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600"
                        placeholder="Например: Арагорн"
                        value="{{ old('character_name', $character->character_name ?? '') }}">
                </div>
                
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-300 mb-1 sm:mb-2">Класс *</label>
                    <select name="character_class" required 
                        class="w-full bg-gray-700 text-white rounded-lg px-3 sm:px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <option value="">Выберите класс</option>
                        <option value="fighter" {{ old('character_class', $character->character_class ?? '') == 'fighter' ? 'selected' : '' }}>Воин</option>
                        <option value="wizard" {{ old('character_class', $character->character_class ?? '') == 'wizard' ? 'selected' : '' }}>Волшебник</option>
                        <option value="rogue" {{ old('character_class', $character->character_class ?? '') == 'rogue' ? 'selected' : '' }}>Плут</option>
                        <option value="cleric" {{ old('character_class', $character->character_class ?? '') == 'cleric' ? 'selected' : '' }}>Жрец</option>
                        <option value="ranger" {{ old('character_class', $character->character_class ?? '') == 'ranger' ? 'selected' : '' }}>Следопыт</option>
                        <option value="paladin" {{ old('character_class', $character->character_class ?? '') == 'paladin' ? 'selected' : '' }}>Паладин</option>
                        <option value="bard" {{ old('character_class', $character->character_class ?? '') == 'bard' ? 'selected' : '' }}>Бард</option>
                        <option value="barbarian" {{ old('character_class', $character->character_class ?? '') == 'barbarian' ? 'selected' : '' }}>Варвар</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-300 mb-1 sm:mb-2">История персонажа</label>
                    <textarea name="character_description" rows="3"
                        class="w-full bg-gray-700 text-white rounded-lg px-3 sm:px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600"
                        placeholder="Опишите прошлое вашего героя...">{{ old('character_description', $character->character_description ?? '') }}</textarea>
                </div>
            </div>
            
            <!-- Характеристики -->
            <div>
                <h3 class="text-sm sm:text-md font-semibold text-white mb-2 sm:mb-3">Характеристики (3-20)</h3>
                <p class="text-xs text-gray-400 mb-2 sm:mb-3">Распределите очки характеристик. Каждая характеристика влияет на навыки персонажа.</p>
                <div class="grid grid-cols-2 sm:grid-cols-2 gap-3 sm:gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Сила 💪</label>
                        <input type="number" name="strength" min="3" max="20" value="{{ old('strength', $character->strength ?? 10) }}" required
                            class="w-full bg-gray-700 text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <p class="text-xs text-gray-500 mt-1 hidden sm:block">Влияет на ближний бой</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Ловкость 🏃</label>
                        <input type="number" name="dexterity" min="3" max="20" value="{{ old('dexterity', $character->dexterity ?? 10) }}" required
                            class="w-full bg-gray-700 text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <p class="text-xs text-gray-500 mt-1 hidden sm:block">Влияет на уклонение</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Выносливость 🛡️</label>
                        <input type="number" name="constitution" min="3" max="20" value="{{ old('constitution', $character->constitution ?? 10) }}" required
                            class="w-full bg-gray-700 text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <p class="text-xs text-gray-500 mt-1 hidden sm:block">Влияет на здоровье</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Интеллект 🧠</label>
                        <input type="number" name="intelligence" min="3" max="20" value="{{ old('intelligence', $character->intelligence ?? 10) }}" required
                            class="w-full bg-gray-700 text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <p class="text-xs text-gray-500 mt-1 hidden sm:block">Влияет на магию</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Мудрость 👁️</label>
                        <input type="number" name="wisdom" min="3" max="20" value="{{ old('wisdom', $character->wisdom ?? 10) }}" required
                            class="w-full bg-gray-700 text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <p class="text-xs text-gray-500 mt-1 hidden sm:block">Влияет на восприятие</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Харизма 💬</label>
                        <input type="number" name="charisma" min="3" max="20" value="{{ old('charisma', $character->charisma ?? 10) }}" required
                            class="w-full bg-gray-700 text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 border border-gray-600">
                        <p class="text-xs text-gray-500 mt-1 hidden sm:block">Влияет на переговоры</p>
                    </div>
                </div>
            </div>
            
            <!-- Кнопки -->
            <div class="flex justify-end space-x-3 pt-3 sm:pt-4 border-t border-gray-700">
                <button type="submit" 
                    class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 sm:px-6 rounded-lg text-sm sm:text-base transition w-full sm:w-auto">
                    Создать персонажа
                </button>
            </div>
        </form>
    </div>
</div>
@endif

@endsection

{{-- @push('scripts')
    @vite(['resources/js/room.js'])
@endpush --}}