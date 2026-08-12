<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    /** Может ли пользователь видеть комнату (страница show, статус, список сообщений) */
    public function view(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id);
    }

    /** Может ли присоединиться */
    public function join(User $user, Room $room): bool
    {
        return !$room->isFull() && !$room->isUserInRoom($user->id);
    }

    /** Может ли создавать/редактировать своего персонажа */
    public function manageCharacter(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id) && $room->status === 'waiting';
    }

    /** Может ли выйти из комнаты */
    public function leave(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id) && $room->status !== 'playing';
    }

    /** Может ли отправлять игровые сообщения */
    public function sendMessage(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id) && $room->status === 'playing';
    }

    /** Может ли начать игру */
    public function start(User $user, Room $room): bool
    {
        return $room->created_by === $user->id;
    }

    /** Может ли удалить комнату */
    public function destroy(User $user, Room $room): bool
    {
        return $room->created_by === $user->id;
    }

    /** Может ли кикнуть всех игроков */
    public function kickAll(User $user, Room $room): bool
    {
        return $room->created_by === $user->id;
    }
}