<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use App\Models\GameMessage;
use Illuminate\Support\Facades\Log;

class GameMasterService
{
    protected DeepSeekService $deepSeek;
    protected CombatService $combat;

    public function __construct(DeepSeekService $deepSeek, CombatService $combat)
    {
        $this->deepSeek = $deepSeek;
        $this->combat = $combat;
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

    /**
     * @return array{message: GameMessage, stat_changes: array}
     */
    public function processMessage(Room $room, User $user, string $userMessage, ?string $rollResult = null): array
    {
        Log::info('GameMasterService::processMessage', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => $userMessage
        ]);

        $userRoomData = $room->users()->where('user_id', $user->id)->first();

        if (!$userRoomData) {
            throw new \Exception('Пользователь не в комнате');
        }

        $character = $userRoomData->pivot;

        if (!$character || !$character->character_name) {
            throw new \Exception('У пользователя нет персонажа');
        }

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

        $system = $this->buildSystemPrompt($room, $character, $allCharacters, $user);

        $history = GameMessage::where('room_id', $room->id)
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->map(function ($msg) use ($room) {
                $role = $msg->role === 'assistant' ? 'assistant' : 'user';

                if ($msg->role === 'user' && $msg->user_id) {
                    $characterName = $room->characterNameForUser($msg->user_id)
                        ?? 'Игрок ' . $msg->user_id;

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

        if ($userMessage && !str_starts_with($userMessage, '/roll')) {
            $messages[] = ['role' => 'user', 'content' => "{$character->character_name}: $userMessage"];
        }

        $rawResponse = $this->deepSeek->chat($messages);
        $parsed = $this->extractStateChanges($rawResponse);

        $aiMessage = GameMessage::create([
            'room_id' => $room->id,
            'role' => 'assistant',
            'content' => $parsed['content'] !== '' ? $parsed['content'] : $rawResponse,
        ]);

        $statChanges = $this->combat->applyStateChanges($room, $parsed['changes']);

        return [
            'message' => $aiMessage,
            'stat_changes' => $statChanges,
        ];
    }

    /**
     * Вырезает служебный [[STATE:{...}]] блок из ответа модели и парсит его.
     * Если блока нет или JSON битый — просто нет изменений, чат не ломается.
     *
     * @return array{content: string, changes: array}
     */
    private function extractStateChanges(string $raw): array
    {
        $trimmed = trim($raw);

        if (!preg_match('/\[\[STATE:(\{.*\})\]\]\s*$/s', $trimmed, $matches)) {
            return ['content' => $trimmed, 'changes' => []];
        }

        $content = trim(substr($trimmed, 0, -strlen($matches[0])));
        $changes = [];

        try {
            $decoded = json_decode($matches[1], true, 8, JSON_THROW_ON_ERROR);
            if (is_array($decoded['changes'] ?? null)) {
                $changes = $decoded['changes'];
            }
        } catch (\JsonException $e) {
            Log::warning('GameMasterService: не удалось распарсить STATE-блок', [
                'raw' => $matches[1],
                'error' => $e->getMessage(),
            ]);
        }

        return ['content' => $content, 'changes' => $changes];
    }

    protected function buildSystemPrompt(Room $room, $currentCharacter, $allCharacters, $currentUser): string
    {
        $playersList = collect($allCharacters)->map(function ($c) {
            return "- Персонаж: {$c['character_name']} ({$c['character_class']}), HP: {$c['hp']}, AC: {$c['ac']}";
        })->join("\n");

        $currentPlayerInfo = "Сейчас действует игрок, управляющий персонажем **{$currentCharacter->character_name}**.";

        $basePrompt = $room->master_prompt ?? "Ты мастер игры D&D. Твоя задача - вести сюжет, описывать мир и NPC. НИКОГДА не отвечай за персонажей игроков - только игроки управляют своими персонажами. Ты управляешь NPC. Кидай кубики за действия NPC.";

        return <<<PROMPT
Ты — Мастер Подземелий в Dungeons & Dragons. Ты ведёшь игру для группы.

**ВАЖНО: Различай игроков!**
В игре участвуют несколько игроков, каждый управляет своим персонажем. Ты должен обращаться к ним по именам их персонажей и понимать, что за каждым персонажем стоит реальный игрок.

Персонажи в игре (с текущим HP и AC):
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

ИЗМЕНЕНИЯ ХАРАКТЕРИСТИК (ОБЯЗАТЕЛЬНО, СТРОГОЕ ПРАВИЛО):
Если в ТЕКСТЕ твоего ответа персонаж хоть как-то получил урон, вылечился, выпил зелье/применил лечение,
использовал магию с эффектом на HP/AC, или произошёл левел-ап — ты ОБЯЗАН отразить это числом в STATE-блоке.
Нельзя писать "рана начинает затягиваться" / "ты чувствуешь себя лучше" / "эликсир исцеляет тебя" и НЕ дать
положительный hp_delta. Нельзя писать "меч пронзает плечо" / "ты получаешь удар" и НЕ дать отрицательный hp_delta.
Текст без цифры в STATE — это ошибка, а не стиль повествования.

Ориентиры по величине (если не задано иное по сюжету):
- Лёгкий урон (царапина, слабый удар NPC): -3..-8
- Серьёзный урон (удар оружием, попадание заклинания): -8..-15
- Тяжёлый/критический урон: -15..-30
- Обычное лечебное зелье/заклинание: +10..+20
- Долгий отдых/сильное исцеление: +20..+35
- Левел-ап: max_hp_delta +5..+15 (используется редко, только при явном сюжетном триггере)

В САМОМ КОНЦЕ ответа, отдельной последней строкой, добавь служебный блок в точном формате:

[[STATE:{"changes":[{"character":"Точное имя персонажа из списка выше","hp_delta":-8}]}]]

Примеры (только для понимания формата, не копируй содержание):
- Игрок пишет "бью гоблина мечом", в тексте гоблин бьёт в ответ и ранит игрока:
  [[STATE:{"changes":[{"character":"Арагорн","hp_delta":-10}]}]]
- Игрок пишет "пью лечебное зелье", в тексте описано исцеление:
  [[STATE:{"changes":[{"character":"Арагорн","hp_delta":15}]}]]
- Ничего механически не изменилось (просто диалог, осмотр комнаты):
  [[STATE:{"changes":[]}]]

Правила блока:
- "character" — точное имя персонажа, как указано в списке персонажей выше
- "hp_delta" — целое число, на сколько меняется ТЕКУЩЕЕ HP (отрицательное = урон, положительное = лечение), диапазон от -50 до 50
- "ac_delta" — опционально, изменение брони (например, эффект заклинания), диапазон от -10 до 10
- "max_hp_delta" — опционально, ТОЛЬКО для левел-апа/постоянного усиления, положительное число, диапазон от 0 до 50
- Можно указать несколько персонажей в массиве "changes", если урон/эффект затронул нескольких
- Если за этот ход ничего механически не изменилось — всё равно добавь блок с пустым массивом: [[STATE:{"changes":[]}]]
- Эта строка служебная — никогда не пересказывай и не объясняй её в тексте сцены, она не видна игрокам

Мастер-промт: {$basePrompt}
PROMPT;
    }
}