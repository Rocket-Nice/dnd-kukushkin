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
        private AttackService $attack,
    ) {
    }

    /**
     * @return array{
     *     user_message: ?GameMessage,
     *     system_message: ?GameMessage,
     *     combat_messages: GameMessage[],
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
            $rollData = $this->resolveRoll($room, $user, $characterPivot, $message);

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
        $combatMessages = $result['combat_messages'] ?? [];

        broadcast(new GameMessageSent($aiMessageModel, $room))->toOthers();

        foreach ($combatMessages as $combatMessage) {
            broadcast(new GameMessageSent($combatMessage, $room))->toOthers();
        }

        if (!empty($statChanges)) {
            broadcast(new CharacterStatsUpdated($room, $statChanges));
            broadcast(new RoomStatusUpdated($room));
        }

        return [
            'user_message' => $userMessageModel,
            'system_message' => $systemMessageModel,
            'combat_messages' => $combatMessages,
            'roll' => $rollData,
            'ai_message' => $aiMessageModel,
            'stat_changes' => $statChanges,
        ];
    }

    /**
     * Резолвит /roll: если для этого игрока в этой комнате есть отложенная
     * атака на NPC (ИИ выставил её в предыдущем ходу через PENDING_ATTACK) —
     * бросает d20 + боевой бонус персонажа против AC цели и, при попадании,
     * урон по указанным костям. Иначе — обычный d20-чек навыка (как раньше).
     */
    private function resolveRoll(Room $room, User $user, object $characterPivot, string $message): array
    {
        $pendingAttack = $this->attack->consumePendingAttack($room, $user->id);

        if ($pendingAttack && isset($pendingAttack['target_ac'])) {
            $attackBonus = $this->attack->characterAttackBonus($characterPivot);
            $npcName = (string) ($pendingAttack['npc_name'] ?? 'противник');

            $result = $this->attack->resolveAttack(
                $characterPivot->character_name,
                $attackBonus,
                (int) $pendingAttack['target_ac'],
                (string) ($pendingAttack['damage_dice'] ?? '1d6'),
                (int) ($pendingAttack['damage_bonus'] ?? 0)
            );

            $messageText = $this->attack->formatMessage($result, $npcName);

            return [
                'roll' => $result['attack_roll'],
                'difficulty' => $result['target_ac'],
                'success' => $result['hit'],
                'level' => $result['fumble']
                    ? '💀 Критический промах!'
                    : ($result['critical']
                        ? '🎯 Критический удар!'
                        : ($result['hit'] ? '✅ Попадание' : '❌ Промах')),
                'message' => $messageText,
            ];
        }

        preg_match('/\/roll\s*(\d+)?/', $message, $matches);
        $difficulty = isset($matches[1]) ? (int) $matches[1] : null;

        return $this->dice->roll($difficulty);
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
