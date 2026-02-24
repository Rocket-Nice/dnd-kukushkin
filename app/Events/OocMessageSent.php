<?php

namespace App\Events;

use App\Models\OocMessage;
use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class OocMessageSent implements ShouldBroadcast
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
        return new Channel('room.' . $this->room->id);
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