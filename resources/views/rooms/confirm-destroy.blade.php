@extends('layouts.app')

@section('title', 'Удаление комнаты')

@section('content')
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-gray-800 overflow-hidden shadow-xl rounded-lg border border-gray-700">
            <div class="p-6">
                <div class="text-center mb-6">
                    <svg class="w-16 h-16 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    
                    <h2 class="text-2xl font-bold text-white mb-2">Удаление комнаты</h2>
                    <p class="text-gray-400">
                        Вы уверены, что хотите удалить комнату <span class="text-white font-semibold">"{{ $room->name }}"</span>?
                    </p>
                </div>

                <div class="bg-gray-700 rounded-lg p-4 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-2">При удалении:</h3>
                    <ul class="space-y-2 text-sm text-gray-300">
                        <li class="flex items-center">
                            <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Все игроки будут удалены из комнаты
                        </li>
                        <li class="flex items-center">
                            <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Все персонажи будут безвозвратно удалены
                        </li>
                        <li class="flex items-center">
                            <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            История чата будет полностью очищена
                        </li>
                        <li class="flex items-center">
                            <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Кэш комнаты будет очищен
                        </li>
                        <li class="flex items-center text-green-400 mt-2">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Действие необратимо
                        </li>
                    </ul>
                </div>

                <div class="flex justify-center space-x-4">
                    <a href="{{ route('rooms.show', $room) }}" 
                       class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-lg transition">
                        Отмена
                    </a>
                    
                    <form action="{{ route('rooms.destroy', $room) }}" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="submit" 
                                class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg transition"
                                onclick="return confirm('Последнее подтверждение: удалить комнату?')">
                            Удалить комнату
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection