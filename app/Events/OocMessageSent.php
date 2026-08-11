<?php

namespace App\Events;

use App\Models\OocMessage;
use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OocMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public $message;
    public $room;

    public function __construct(OocMessage $message, Room $room)
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
        return [
            'id' => $this->message->id,
            'content' => $this->message->content,
            'user_name' => $this->message->user->name,
            'user_id' => $this->message->user_id,
            'created_at' => $this->message->created_at->timestamp,
        ];
    }
}