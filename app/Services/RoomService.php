<?php

namespace App\Services;

use App\Models\Room;
use App\Models\GameMessage;
use App\Models\OocMessage;
use App\Events\RoomStatusUpdated;
use App\Events\GameMessageSent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RoomService
{
    public function __construct(private GameMasterService $gameMaster)
    {
    }

    public function create(array $data, int $userId): Room
    {
        $room = Room::create([
            ...$data,
            'created_by' => $userId,
        ]);

        $room->users()->attach($userId, ['joined_at' => now()]);

        return $room;
    }

    public function join(Room $room, int $userId): void
    {
        $room->users()->syncWithoutDetaching([$userId => ['joined_at' => now()]]);

        broadcast(new RoomStatusUpdated($room));
    }

    public function leave(Room $room, int $userId): void
    {
        DB::transaction(function () use ($room, $userId) {
            GameMessage::where('room_id', $room->id)
                ->where('user_id', $userId)
                ->delete();

            $room->users()->detach($userId);
        });

        broadcast(new RoomStatusUpdated($room));
    }

    public function destroy(Room $room): void
    {
        DB::transaction(function () use ($room) {
            GameMessage::where('room_id', $room->id)->delete();
            OocMessage::where('room_id', $room->id)->delete();
            $room->users()->detach();
            $room->delete();
        });
    }

    public function kickAll(Room $room): void
    {
        DB::transaction(function () use ($room) {
            $room->users()->where('user_id', '!=', $room->created_by)->detach();
            $room->update(['status' => 'waiting']);
            GameMessage::where('room_id', $room->id)->delete();
        });

        broadcast(new RoomStatusUpdated($room));
    }

    public function saveCharacter(Room $room, int $userId, array $data): void
    {
        $modCon = floor(($data['constitution'] - 10) / 2);
        $maxHp = 30 + $modCon * 2;

        $modDex = floor(($data['dexterity'] - 10) / 2);
        $armorClass = 10 + $modDex;

        $room->users()->updateExistingPivot($userId, [
            ...$data,
            'max_hp' => $maxHp,
            'current_hp' => $maxHp,
            'armor_class' => $armorClass,
            'is_ready' => true,
        ]);

        broadcast(new RoomStatusUpdated($room));
    }

    /**
     * @throws RuntimeException если недостаточно готовых игроков
     */
    public function start(Room $room): void
    {
        $readyCount = $room->users()->wherePivot('is_ready', true)->count();

        if ($readyCount < 2) {
            throw new RuntimeException('Нужно минимум 2 готовых игрока');
        }

        $room->update(['status' => 'playing']);
        broadcast(new RoomStatusUpdated($room));

        // Раньше intro-сообщение создавалось, но никогда не транслировалось —
        // игроки не видели старт игры без ручного обновления страницы.
        $intro = $this->gameMaster->generateIntro($room);
        broadcast(new GameMessageSent($intro, $room));
    }
}