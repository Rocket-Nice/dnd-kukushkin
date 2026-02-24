<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\GameMessageController;
use App\Http\Controllers\OocMessageController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TestController;

Route::get('/test-ai', [TestController::class, 'testAI'])->name('test.ai');

Route::get('/', function () {
    return view('home');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    Route::resource('rooms', RoomController::class);
    Route::post('/rooms/{room}/join', [RoomController::class, 'join'])->name('rooms.join');
    Route::post('/rooms/{room}/character', [RoomController::class, 'saveCharacter'])->name('rooms.character.save');
    Route::post('/rooms/{room}/start', [RoomController::class, 'start'])->name('rooms.start');
    
    Route::get('/rooms/{room}/game-messages', [GameMessageController::class, 'index'])->name('game-messages.index');
    Route::post('/rooms/{room}/game-messages', [GameMessageController::class, 'store'])->name('game-messages.store');
    
    Route::get('/rooms/{room}/ooc-messages', [OocMessageController::class, 'index'])->name('ooc-messages.index');
    Route::post('/rooms/{room}/ooc-messages', [OocMessageController::class, 'store'])->name('ooc-messages.store');

    // Выход из комнаты
    Route::post('/rooms/{room}/leave', [RoomController::class, 'leave'])->name('rooms.leave');

    // Подтверждение удаления комнаты
    Route::get('/rooms/{room}/confirm-destroy', [RoomController::class, 'confirmDestroy'])->name('rooms.destroy.confirm');

    // Удаление комнаты
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

    // Кик всех игроков (опционально)
    Route::post('/rooms/{room}/kick-all', [RoomController::class, 'kickAll'])->name('rooms.kick.all');

    // Статус комнаты (ДОБАВЬТЕ ЭТОТ МАРШРУТ)
    Route::get('/rooms/{room}/status', [RoomController::class, 'status'])->name('rooms.status');
});

require __DIR__.'/auth.php';