<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use App\Models\GameMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GameMasterService
{
    protected DeepSeekService $deepSeek;

    public function __construct(DeepSeekService $deepSeek)
    {
        $this->deepSeek = $deepSeek;
    }

    public function generateIntro(Room $room): GameMessage
    {
        $characters = $room->users()
            ->wherePivot('is_ready', true)
            ->get()
            ->map(function ($user) {
                $pivot = $user->pivot;
                return "{$pivot->character_name} - {$pivot->character_description} (Класс: {$pivot->character_class})";
            })->join("\n");

        $systemPrompt = "Ты — опытный Мастер Подземелий в Dungeons & Dragons. Создай захватывающее начало приключения для этих персонажей:\n\n$characters\n\nСоздай вступительную сцену (3-5 предложений), которая объединит этих героев и начнёт их приключение. Будь драматичным и захватывающим!";

        $response = $this->deepSeek->chat([
            ['role' => 'system', 'content' => $systemPrompt]
        ]);

        return GameMessage::create([
            'room_id' => $room->id,
            'role' => 'assistant',
            'content' => $response,
        ]);
    }

    public function processMessage(Room $room, User $user, string $userMessage, ?string $rollResult = null): GameMessage
    {
        Log::info('GameMasterService::processMessage', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'message' => $userMessage
        ]);

        // ПОЛУЧАЕМ ПЕРСОНАЖА
        $userRoomData = $room->users()->where('user_id', $user->id)->first();
        
        if (!$userRoomData) {
            throw new \Exception('Пользователь не в комнате');
        }
        
        $character = $userRoomData->pivot;
        
        if (!$character || !$character->character_name) {
            throw new \Exception('У пользователя нет персонажа');
        }

        // Получаем ВСЕХ персонажей в комнате с их игроками
        $allCharacters = $room->users()
            ->wherePivot('is_ready', true)
            ->get()
            ->map(function ($u) {
                return [
                    'user_id' => $u->id,
                    'user_name' => $u->name,
                    'character_name' => $u->pivot->character_name,
                    'character_class' => $u->pivot->character_class,
                    'hp' => $u->pivot->current_hp . '/' . $u->pivot->max_hp,
                    'ac' => $u->pivot->armor_class
                ];
            });

        // Строим системный промпт с информацией о том, кто есть кто
        $system = $this->buildSystemPrompt($room, $character, $allCharacters, $user);

        // Получаем историю сообщений
        $history = GameMessage::where('room_id', $room->id)
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->map(function ($msg) use ($room) {
                $role = $msg->role === 'assistant' ? 'assistant' : 'user';
                
                if ($msg->role === 'user' && $msg->user_id) {
                    // Получаем имя персонажа для этого сообщения
                    $pivotData = DB::table('room_user')
                        ->where('room_id', $room->id)
                        ->where('user_id', $msg->user_id)
                        ->first();
                    
                    $characterName = $pivotData && $pivotData->character_name 
                        ? $pivotData->character_name 
                        : 'Игрок ' . $msg->user_id;
                    
                    $content = $characterName . ': ' . $msg->content;
                } else {
                    $content = $msg->content;
                }
                
                return ['role' => $role, 'content' => $content];
            })
            ->values()
            ->toArray();

        $messages = [
            ['role' => 'system', 'content' => $system],
            ...$history,
        ];

        if ($rollResult) {
            $messages[] = ['role' => 'system', 'content' => $rollResult];
        }

        // Добавляем текущее сообщение с правильной маркировкой
        if ($userMessage && !str_starts_with($userMessage, '/roll')) {
            $messages[] = ['role' => 'user', 'content' => "{$character->character_name}: $userMessage"];
        }

        $response = $this->deepSeek->chat($messages);

        return GameMessage::create([
            'room_id' => $room->id,
            'role' => 'assistant',
            'content' => $response,
        ]);
    }

    protected function buildSystemPrompt(Room $room, $currentCharacter, $allCharacters, $currentUser): string
    {
        // Строим список персонажей с указанием игроков
        $playersList = collect($allCharacters)->map(function ($c) {
            return "- Персонаж: {$c['character_name']} ({$c['character_class']})";
        })->join("\n");

        // Информация о текущем игроке
        $currentPlayerInfo = "Сейчас действует игрок, управляющий персонажем **{$currentCharacter->character_name}**.";

        $basePrompt = $room->master_prompt ?? "Ты мастер игры D&D. Твоя задача - вести сюжет, описывать мир и NPC. НИКОГДА не отвечай за персонажей игроков - только игроки управляют своими персонажами. Ты управляешь NPC. Кидай кубики за действия NPC.";

        return <<<PROMPT
Ты — Мастер Подземелий в Dungeons & Dragons. Ты ведёшь игру для группы.

**ВАЖНО: Различай игроков!**
В игре участвуют несколько игроков, каждый управляет своим персонажем. Ты должен обращаться к ним по именам их персонажей и понимать, что за каждым персонажем стоит реальный игрок.

Персонажи в игре:
{$playersList}

{$currentPlayerInfo}

ПРАВИЛА:
1. Отвечай КРАТКО (2-4 предложения)
2. Создавай интересные вызовы и развивай сюжет
3. Когда нужна проверка навыка, скажи: "Для этого нужна проверка [навыка]. Сложность: X" (X от 5 до 20)
4. Давай игрокам выбор (2-3 варианта)
5. НИКОГДА не отвечай за персонажей игроков. Только описывай мир, NPC и последствия
6. Ты управляешь NPC
7. Учитывай текущее состояние HP и AC в описаниях
8. Если игрок бросил кубик (ты увидишь системное сообщение с результатом), опиши результат в контексте происходящего
9. Обращайся к персонажам по их именам, указанным выше
10. Помни, что **{$currentCharacter->character_name}** - это текущий действующий игрок

Мастер-промт: {$basePrompt}
PROMPT;
    }
}