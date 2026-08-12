<?php

namespace App\Services;

use App\Models\Room;
use App\Models\OocMessage;
use App\Events\OocMessageSent;

class OocChatService
{
    public function sendMessage(Room $room, int $userId, string $rawMessage): OocMessage
    {
        $message = OocMessage::create([
            'room_id' => $room->id,
            'user_id' => $userId,
            'content' => trim($rawMessage),
        ]);

        $message->load('user');

        broadcast(new OocMessageSent($message, $room))->toOthers();

        return $message;
    }

    public function formatMessage(OocMessage $msg): array
    {
        return [
            'id' => $msg->id,
            'content' => $msg->content,
            'user_name' => $msg->user->name,
            'user_id' => $msg->user_id,
            'created_at' => $msg->created_at->timestamp,
        ];
    }
}