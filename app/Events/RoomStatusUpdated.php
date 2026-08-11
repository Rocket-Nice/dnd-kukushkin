<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $room;

    public function __construct(Room $room)
    {
        $this->room = $room;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('room.' . $this->room->id);
    }

    public function broadcastWith()
    {
        return [
            'status' => $this->room->status,
            'users' => $this->room->users()->get()->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'character_name' => $user->pivot->character_name,
                    'character_class' => $user->pivot->character_class,
                    'is_ready' => $user->pivot->is_ready,
                    'current_hp' => $user->pivot->current_hp,
                    'max_hp' => $user->pivot->max_hp,
                    'armor_class' => $user->pivot->armor_class,
                ];
            }),
            'users_count' => $this->room->users()->count(),
            'ready_count' => $this->room->users()->wherePivot('is_ready', true)->count(),
        ];
    }
}