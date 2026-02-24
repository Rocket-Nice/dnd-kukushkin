@extends('layouts.app')

@section('title', 'Комнаты')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-white">Комнаты</h1>
            <a href="{{ route('rooms.create') }}" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg transition">
                Создать комнату
            </a>
        </div>
        
        @if(session('success'))
            <div class="bg-green-600 text-white p-4 rounded-lg mb-4">
                {{ session('success') }}
            </div>
        @endif
        
        @if(session('error'))
            <div class="bg-red-600 text-white p-4 rounded-lg mb-4">
                {{ session('error') }}
            </div>
        @endif
        
        <div class="bg-gray-800 overflow-hidden shadow-xl rounded-lg border border-gray-700">
            <div class="p-6">
                @if($rooms->count() > 0)
                    <div class="space-y-4">
                        @foreach($rooms as $room)
                            @php
                                $isUserInRoom = in_array($room->id, $userRooms ?? []);
                            @endphp
                            
                            <div class="bg-gray-700 rounded-lg p-4 flex justify-between items-center">
                                <div>
                                    <h3 class="text-lg font-semibold text-white">{{ $room->name }}</h3>
                                    <p class="text-sm text-gray-400">
                                        Создатель: {{ $room->creator->name }} | 
                                        Игроков: {{ $room->users_count }}/{{ $room->max_players }} | 
                                        Статус: 
                                        <span class="px-2 py-1 rounded text-xs 
                                            @if($room->status === 'waiting') bg-yellow-600 
                                            @elseif($room->status === 'playing') bg-green-600 
                                            @else bg-gray-600 @endif">
                                            {{ $room->status === 'waiting' ? 'Ожидание' : ($room->status === 'playing' ? 'В игре' : 'Завершена') }}
                                        </span>
                                    </p>
                                </div>
                                
                                @auth
                                    @if($isUserInRoom)
                                        <!-- Если пользователь уже в комнате -->
                                        <a href="{{ route('rooms.show', $room) }}" 
                                           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                                            Войти
                                        </a>
                                    @elseif($room->status === 'waiting' && !$room->isFull())
                                        <!-- Если комната в ожидании и не полная -->
                                        <form action="{{ route('rooms.join', $room) }}" method="POST">
                                            @csrf
                                            <button type="submit" 
                                                    class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                                                Присоединиться
                                            </button>
                                        </form>
                                    @else
                                        <!-- Если нельзя присоединиться -->
                                        <button disabled 
                                                class="bg-gray-600 text-gray-400 px-4 py-2 rounded-lg cursor-not-allowed">
                                            @if($room->status !== 'waiting')
                                                Игра идет
                                            @elseif($room->isFull())
                                                Комната полна
                                            @endif
                                        </button>
                                    @endif
                                @endauth
                            </div>
                        @endforeach
                    </div>
                    
                    <div class="mt-6">
                        {{ $rooms->links() }}
                    </div>
                @else
                    <p class="text-gray-400 text-center py-8">Нет доступных комнат</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection