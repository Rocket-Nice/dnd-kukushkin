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
    protected AttackService $attack;

    public function __construct(DeepSeekService $deepSeek, CombatService $combat, AttackService $attack)
    {
        $this->deepSeek = $deepSeek;
        $this->combat = $combat;
        $this->attack = $attack;
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
     * @return array{message: GameMessage, stat_changes: array, combat_messages: GameMessage[]}
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
        $parsed = $this->extractDirectives($rawResponse);

        $aiMessage = GameMessage::create([
            'room_id' => $room->id,
            'role' => 'assistant',
            'content' => $parsed['content'] !== '' ? $parsed['content'] : $rawResponse,
        ]);

        $statChanges = $this->combat->applyStateChanges($room, $parsed['directives']['STATE']);
        $combatMessages = [];

        foreach ($parsed['directives']['ATTACK'] as $atk) {
            if (empty($atk['attacker']) || empty($atk['target'])) {
                continue;
            }

            $result = $this->attack->resolveNpcAttackOnCharacter(
                $room,
                (string) $atk['attacker'],
                (string) $atk['target'],
                (int) ($atk['attack_bonus'] ?? 3),
                (string) ($atk['damage_dice'] ?? '1d6'),
                (int) ($atk['damage_bonus'] ?? 0)
            );

            if ($result === null) {
                Log::warning('GameMasterService: неизвестная цель в ATTACK-блоке', [
                    'room_id' => $room->id,
                    'target' => $atk['target'],
                ]);
                continue;
            }

            $combatMessages[] = GameMessage::create([
                'room_id' => $room->id,
                'role' => 'system',
                'content' => $this->attack->formatMessage($result, (string) $atk['target']),
            ]);

            if ($result['hit'] && $result['damage'] > 0) {
                $hpChanges = $this->combat->applyStateChanges($room, [
                    ['character' => (string) $atk['target'], 'hp_delta' => -$result['damage']],
                ]);
                $statChanges = [...$statChanges, ...$hpChanges];
            }
        }

        if ($parsed['directives']['PENDING_ATTACK']) {
            $this->attack->storePendingAttack($room, $user->id, $parsed['directives']['PENDING_ATTACK']);
        }

        return [
            'message' => $aiMessage,
            'stat_changes' => $statChanges,
            'combat_messages' => $combatMessages,
        ];
    }

    /**
     * Вырезает служебные блоки [[STATE:{...}]] / [[ATTACK:{...}]] / [[PENDING_ATTACK:{...}]]
     * с конца ответа модели (может быть несколько подряд, по одному на строку).
     * Битый JSON или отсутствие блока — не ошибка, просто нет соответствующих изменений.
     *
     * @return array{content: string, directives: array{STATE: array, ATTACK: array, PENDING_ATTACK: ?array}}
     */
    private function extractDirectives(string $raw): array
    {
        $content = trim($raw);
        $directives = ['STATE' => [], 'ATTACK' => [], 'PENDING_ATTACK' => null];

        while (preg_match('/\n?\[\[(STATE|ATTACK|PENDING_ATTACK):(\{.*\})\]\]\s*$/s', $content, $m)) {
            $type = $m[1];
            $json = $m[2];
            $content = trim(substr($content, 0, -strlen($m[0])));

            try {
                $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Log::warning("GameMasterService: не удалось распарсить {$type}-блок", [
                    'raw' => $json,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($type === 'STATE' && is_array($decoded['changes'] ?? null)) {
                $directives['STATE'] = $decoded['changes'];
            } elseif ($type === 'ATTACK' && is_array($decoded['attacks'] ?? null)) {
                $directives['ATTACK'] = $decoded['attacks'];
            } elseif ($type === 'PENDING_ATTACK' && is_array($decoded)) {
                $directives['PENDING_ATTACK'] = $decoded;
            }
        }

        return ['content' => $content, 'directives' => $directives];
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
3. Когда нужна проверка навыка (НЕ атака), скажи: "Для этого нужна проверка [навыка]. Сложность: X" (X от 5 до 20)
4. Давай игрокам выбор (2-3 варианта)
5. НИКОГДА не отвечай за персонажей игроков. Только описывай мир, NPC и последствия
6. Ты управляешь NPC
7. Учитывай текущее состояние HP и AC в описаниях
8. Обращайся к персонажам по их именам, указанным выше
9. Помни, что **{$currentCharacter->character_name}** - это текущий действующий игрок

БОЕВАЯ МЕХАНИКА (ОБЯЗАТЕЛЬНО, ТРИ ТИПА СЛУЖЕБНЫХ БЛОКОВ):

Броски кубиков ты НИКОГДА не считаешь и не придумываешь сам — их бросает сервер.
Твоя задача — только описать намерение (кто атакует, с каким бонусом, какими костями
урона) в служебном блоке. Сервер сам бросит кубики и покажет игрокам честный результат.
Никогда не пиши в тексте сцены готовый результат броска ("ты попадаешь и наносишь 8 урона") —
опиши только сам момент атаки, а числа появятся из блока.

1) STATE — для изменений HP/AC/max_hp БЕЗ броска (лечебное зелье, эффект окружения,
   левел-ап). ВСЕГДА добавляй этот блок последней строкой, даже если менять нечего:
   [[STATE:{"changes":[{"character":"Имя","hp_delta":15}]}]]
   Если ничего не изменилось: [[STATE:{"changes":[]}]]
   ВАЖНО: если урон/лечение уже относится к блоку ATTACK или PENDING_ATTACK ниже —
   НЕ дублируй его здесь, сервер сам применит урон от боевых бросков.

2) ATTACK — когда NPC атакует персонажа игрока в этот ход. Добавляй, только когда
   по сюжету NPC ДЕЙСТВИТЕЛЬНО атакует прямо сейчас:
   [[ATTACK:{"attacks":[{"attacker":"Гоблин","target":"Точное имя персонажа из списка выше","attack_bonus":3,"damage_dice":"1d6","damage_bonus":1}]}]]
   - "attack_bonus": бонус атаки NPC (слабый враг 1-3, средний 3-6, опасный 6-10)
   - "damage_dice": кости урона в формате "XdY" (1d4, 1d6, 1d8, 2d6 и т.п.), под оружие/природу NPC
   - "damage_bonus": плоский бонус к урону (обычно 0-3)
   AC цели указывать не нужно — сервер возьмёт актуальный AC персонажа сам.
   Если в этот ход ни один NPC не атаковал — просто не добавляй этот блок вообще.

3) PENDING_ATTACK — когда игрок пытается атаковать NPC (написал "бью мечом",
   "стреляю из лука", "атакую" и т.п.) и ты даёшь ему возможность бросить атаку:
   [[PENDING_ATTACK:{"npc_name":"Гоблин","target_ac":13,"damage_dice":"1d8","damage_bonus":2}]]
   Сервер сохранит это и, когда игрок нажмёт бросок кубика, автоматически бросит
   d20 + боевой бонус персонажа против target_ac, а при попадании — урон по этим
   костям. Тебе не нужно ничего считать самому — просто укажи AC цели (подбери
   разумное значение под противника, обычно 10-18) и кости урона под оружие/способность
   персонажа. Добавляй этот блок, только когда игрок реально пытается атаковать NPC.

Можно добавить STATE (обязательно) и при необходимости ATTACK и/или PENDING_ATTACK —
каждый отдельной строкой в конце ответа, в любом порядке между собой, но STATE
должен идти самым последним.

Мастер-промт: {$basePrompt}
PROMPT;
    }
}
