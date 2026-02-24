<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <title>@yield('title', 'D&D Game')</title>
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
    <div class="min-h-screen bg-gray-900">
        @include('layouts.navigation')
        
        @if(session('success'))
            <div class="fixed bottom-4 right-4 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg animate-fade-in z-50">
                {{ session('success') }}
            </div>
        @endif
        
        @if(session('error'))
            <div class="fixed bottom-4 right-4 bg-red-600 text-white px-6 py-3 rounded-lg shadow-lg animate-fade-in z-50">
                {{ session('error') }}
            </div>
        @endif
        
        <main>
            @yield('content')
        </main>
    </div>
    
    @stack('scripts')
</body>
</html>