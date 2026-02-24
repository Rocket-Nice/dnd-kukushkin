<?php

namespace App\Events;

use App\Models\GameMessage;
use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

class GameMessageSent implements ShouldBroadcast
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
        return new Channel('room.' . $this->room->id);
    }

    public function broadcastWith()
    {
        $userName = 'System';
        
        if ($this->message->role === 'assistant') {
            $userName = 'Мастер';
        } elseif ($this->message->user) {
            $pivotData = \DB::table('room_user')
                ->where('room_id', $this->room->id)
                ->where('user_id', $this->message->user_id)
                ->first();
            
            $userName = $pivotData && !empty($pivotData->character_name) 
                ? $pivotData->character_name 
                : $this->message->user->name;
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