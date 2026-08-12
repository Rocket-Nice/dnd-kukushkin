<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use App\Models\GameMessage;
use App\Events\GameMessageSent;
use App\Events\CharacterStatsUpdated;
use App\Events\RoomStatusUpdated;
use RuntimeException;

class GameChatService
{
    public function __construct(
        private GameMasterService $gameMaster,
        private DiceService $dice,
    ) {
    }

    /**
     * @return array{
     *     user_message: ?GameMessage,
     *     system_message: ?GameMessage,
     *     roll: ?array,
     *     ai_message: GameMessage,
     *     stat_changes: array,
     * }
     *
     * @throws RuntimeException если у пользователя нет персонажа
     */
    public function sendMessage(Room $room, User $user, string $rawMessage): array
    {
        $characterPivot = $room->users()->where('user_id', $user->id)->first()?->pivot;
        if (!$characterPivot || !$characterPivot->character_name) {
            throw new RuntimeException('Сначала создайте персонажа');
        }

        $message = trim($rawMessage);
        $isRoll = str_starts_with($message, '/roll');

        $userMessageModel = null;
        $systemMessageModel = null;
        $rollData = null;

        if (!$isRoll) {
            $userMessageModel = GameMessage::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'role' => 'user',
                'content' => $message,
            ]);

            broadcast(new GameMessageSent($userMessageModel, $room))->toOthers();
        } else {
            preg_match('/\/roll\s*(\d+)?/', $message, $matches);
            $difficulty = isset($matches[1]) ? (int) $matches[1] : null;

            $rollData = $this->dice->roll($difficulty);

            $systemMessageModel = GameMessage::create([
                'room_id' => $room->id,
                'role' => 'system',
                'content' => $rollData['message'],
            ]);

            broadcast(new GameMessageSent($systemMessageModel, $room))->toOthers();
        }

        $result = $this->gameMaster->processMessage(
            $room,
            $user,
            $message,
            $rollData['message'] ?? null
        );

        $aiMessageModel = $result['message'];
        $statChanges = $result['stat_changes'];

        broadcast(new GameMessageSent($aiMessageModel, $room))->toOthers();

        if (!empty($statChanges)) {
            broadcast(new CharacterStatsUpdated($room, $statChanges));
            broadcast(new RoomStatusUpdated($room));
        }

        return [
            'user_message' => $userMessageModel,
            'system_message' => $systemMessageModel,
            'roll' => $rollData,
            'ai_message' => $aiMessageModel,
            'stat_changes' => $statChanges,
        ];
    }

    public function formatMessage(GameMessage $message, Room $room): array
    {
        $userName = match (true) {
            $message->role === 'assistant' => 'Мастер',
            $message->role === 'system' => 'System',
            (bool) $message->user_id => $room->characterNameForUser($message->user_id)
                ?? $message->user?->name
                ?? 'Игрок',
            default => 'System',
        };

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'user_name' => $userName,
            'user_id' => $message->user_id,
            'created_at' => $message->created_at->timestamp,
        ];
    }
}