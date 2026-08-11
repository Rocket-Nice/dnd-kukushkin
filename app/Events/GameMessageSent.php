<?php

namespace App\Events;

use App\Models\GameMessage;
use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class GameMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public $message;
    public $room;

    public function __construct(GameMessage $message, Room $room)
    {
        $this->message = $message;
        $this->room = $room;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('room.' . $this->room->id);
    }

    public function broadcastWith()
    {
        $userName = 'System';

        if ($this->message->role === 'assistant') {
            $userName = 'Мастер';
        } elseif ($this->message->role === 'user' && $this->message->user_id) {
            $userName = $this->room->characterNameForUser($this->message->user_id)
                ?? $this->message->user?->name
                ?? 'Игрок';
        }

        return [
            'id' => $this->message->id,
            'role' => $this->message->role,
            'content' => $this->message->content,
            'user_name' => $userName,
            'user_id' => $this->message->user_id,
            'created_at' => $this->message->created_at->timestamp,
        ];
    }
}