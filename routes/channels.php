<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Room;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Здесь регистрируются все каналы для broadcasting событий. Callback
| авторизации определяет, может ли аутентифицированный пользователь
| слушать канал.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Приватный канал комнаты: слушать может только тот, кто реально в ней состоит
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    $room = Room::find($roomId);

    if (! $room) {
        return false;
    }

    return $room->isUserInRoom($user->id);
});