@extends('layouts.app')

@section('title', 'Главная')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-gray-800 overflow-hidden shadow-xl rounded-lg border border-gray-700">
            <div class="p-6 lg:p-8">
                <div class="text-center">
                    <h1 class="text-4xl font-bold text-white mb-4">
                        Добро пожаловать в D&D Game!
                    </h1>
                    <p class="text-xl text-gray-300 mb-8">
                        Создайте комнату и начните свое приключение с ИИ-мастером
                    </p>
                    
                    @auth
                        <a href="{{ route('rooms.create') }}" 
                           class="inline-block bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-6 rounded-lg text-lg transition">
                            Создать комнату
                        </a>
                    @else
                        <div class="space-x-4">
                            <a href="{{ route('login') }}" 
                               class="inline-block bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-6 rounded-lg text-lg transition">
                                Войти
                            </a>
                            <a href="{{ route('register') }}" 
                               class="inline-block bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-6 rounded-lg text-lg transition">
                                Регистрация
                            </a>
                        </div>
                    @endauth
                </div>
                
                <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-gray-700 p-6 rounded-lg">
                        <div class="text-3xl mb-3">🎲</div>
                        <h3 class="text-lg font-semibold text-white mb-2">ИИ-мастер</h3>
                        <p class="text-gray-300">Умный мастер на основе DeepSeek, который ведет сюжет и реагирует на действия</p>
                    </div>
                    
                    <div class="bg-gray-700 p-6 rounded-lg">
                        <div class="text-3xl mb-3">👥</div>
                        <h3 class="text-lg font-semibold text-white mb-2">Комнаты до 4 игроков</h3>
                        <p class="text-gray-300">Играйте с друзьями в одной комнате, создавайте персонажей вместе</p>
                    </div>
                    
                    <div class="bg-gray-700 p-6 rounded-lg">
                        <div class="text-3xl mb-3">📜</div>
                        <h3 class="text-lg font-semibold text-white mb-2">Свой сюжет</h3>
                        <p class="text-gray-300">Задайте мастеру свой промт и получите уникальное приключение</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection