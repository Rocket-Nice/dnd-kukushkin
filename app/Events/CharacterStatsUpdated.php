<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Транслирует точечные изменения HP/AC/max_hp персонажей (урон, лечение,
 * левел-ап), применённые CombatService. Отдельно от RoomStatusUpdated,
 * чтобы фронт мог показать "-8 HP" тост именно тому, кому нанесли урон,
 * а не просто перерисовать весь список игроков.
 */
class CharacterStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public Room $room;
    public array $changes;

    public function __construct(Room $room, array $changes)
    {
        $this->room = $room;
        $this->changes = $changes;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('room.' . $this->room->id);
    }

    public function broadcastWith()
    {
        return ['changes' => $this->changes];
    }
}