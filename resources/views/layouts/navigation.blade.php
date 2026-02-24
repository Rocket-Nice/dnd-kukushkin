<nav class="bg-gray-800 border-b border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="{{ route('home') }}" class="flex items-center space-x-2">
                    <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <span class="font-bold text-xl text-white">D&D Game</span>
                </a>
            </div>
            
            <div class="flex items-center space-x-4">
                @auth
                    <a href="{{ route('rooms.index') }}" class="text-gray-300 hover:text-white transition">
                        Комнаты
                    </a>
                    <span class="text-gray-300">{{ Auth::user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-400 hover:text-white transition">
                            Выйти
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="text-gray-300 hover:text-white transition">
                        Войти
                    </a>
                    <a href="{{ route('register') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                        Регистрация
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>