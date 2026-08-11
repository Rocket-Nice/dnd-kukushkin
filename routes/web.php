<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
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

    // throttle: не даём спамить платные AI-запросы
    Route::get('/rooms/{room}/game-messages', [GameMessageController::class, 'index'])->name('game-messages.index');
    Route::post('/rooms/{room}/game-messages', [GameMessageController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('game-messages.store');

    Route::get('/rooms/{room}/ooc-messages', [OocMessageController::class, 'index'])->name('ooc-messages.index');
    Route::post('/rooms/{room}/ooc-messages', [OocMessageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('ooc-messages.store');

    Route::post('/rooms/{room}/leave', [RoomController::class, 'leave'])->name('rooms.leave');
    Route::get('/rooms/{room}/confirm-destroy', [RoomController::class, 'confirmDestroy'])->name('rooms.destroy.confirm');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
    Route::post('/rooms/{room}/kick-all', [RoomController::class, 'kickAll'])->name('rooms.kick.all');
    Route::get('/rooms/{room}/status', [RoomController::class, 'status'])->name('rooms.status');
});

require __DIR__.'/auth.php';