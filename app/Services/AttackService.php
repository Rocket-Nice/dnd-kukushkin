<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Support\Facades\Cache;

/**
 * Резолвит боевые броски: d20 + бонус атаки против AC цели, при попадании —
 * бросок урона по указанным костям. Все кубики бросает сервер (random_int),
 * ИИ только описывает намерение (кто атакует, с каким бонусом, какие кости
 * урона) — числа никогда не берутся из текста модели напрямую.
 */
class AttackService
{
    private const ATTACK_BONUS_MIN = -2;
    private const ATTACK_BONUS_MAX = 10;
    private const DAMAGE_BONUS_MIN = 0;
    private const DAMAGE_BONUS_MAX = 10;
    private const PENDING_ATTACK_TTL_MINUTES = 5;

    public function __construct(private DiceService $dice)
    {
    }

    /**
     * Универсальный резолв: d20 + attackBonus против targetAc.
     * Натуральная 1 — всегда промах, натуральная 20 — всегда попадание + крит (двойные кости урона).
     */
    public function resolveAttack(
        string $attackerName,
        int $attackBonus,
        int $targetAc,
        string $damageDice,
        int $damageBonus
    ): array {
        $attackBonus = max(self::ATTACK_BONUS_MIN, min(self::ATTACK_BONUS_MAX, $attackBonus));
        $damageBonus = max(self::DAMAGE_BONUS_MIN, min(self::DAMAGE_BONUS_MAX, $damageBonus));

        $d20 = random_int(1, 20);
        $isFumble = $d20 === 1;
        $isCritical = $d20 === 20;
        $total = $d20 + $attackBonus;
        $hit = !$isFumble && ($isCritical || $total >= $targetAc);

        $damage = 0;
        $damageRoll = null;

        if ($hit) {
            $damageRoll = $this->dice->rollDice($damageDice, $damageBonus);
            $damage = $damageRoll['total'];

            if ($isCritical) {
                // Критический удар: удваиваем кости урона (без бонуса)
                $damage += array_sum($damageRoll['rolls']);
            }
        }

        return [
            'attacker' => $attackerName,
            'attack_roll' => $d20,
            'attack_bonus' => $attackBonus,
            'attack_total' => $total,
            'target_ac' => $targetAc,
            'hit' => $hit,
            'critical' => $isCritical,
            'fumble' => $isFumble,
            'damage' => $damage,
            'damage_dice' => $damageRoll['dice'] ?? $damageDice,
        ];
    }

    /**
     * Атака NPC на персонажа игрока: AC цели берётся из реальных данных БД,
     * а не из текста модели (модель не может «занизить» чужой AC).
     */
    public function resolveNpcAttackOnCharacter(
        Room $room,
        string $attackerName,
        string $targetCharacterName,
        int $attackBonus,
        string $damageDice,
        int $damageBonus
    ): ?array {
        $targetUserId = $room->userIdForCharacterName($targetCharacterName);
        if ($targetUserId === null) {
            return null;
        }

        $pivot = $room->users()->where('user_id', $targetUserId)->first()?->pivot;
        if (!$pivot) {
            return null;
        }

        $result = $this->resolveAttack($attackerName, $attackBonus, (int) $pivot->armor_class, $damageDice, $damageBonus);
        $result['target_user_id'] = $targetUserId;
        $result['target_character'] = $targetCharacterName;

        return $result;
    }

    public function formatMessage(array $result, string $targetName): string
    {
        $rollText = "🎲 {$result['attacker']} атакует {$targetName}: {$result['attack_roll']}+{$result['attack_bonus']}={$result['attack_total']} против AC {$result['target_ac']}";

        if ($result['fumble']) {
            return $rollText . ' — 💀 Критический промах!';
        }

        if (!$result['hit']) {
            return $rollText . ' — ❌ Промах';
        }

        $critText = $result['critical'] ? ' (🎯 Критический удар!)' : '';

        return $rollText . " — ✅ Попадание{$critText}! Урон: {$result['damage']} ({$result['damage_dice']})";
    }

    /**
     * Бонус атаки персонажа игрока: модификатор ключевой характеристики класса
     * + условный флэт-бонус мастерства (+2, как на низких уровнях D&D 5e).
     */
    public function characterAttackBonus(object $pivot): int
    {
        $classToStat = [
            'fighter' => 'strength',
            'barbarian' => 'strength',
            'paladin' => 'strength',
            'rogue' => 'dexterity',
            'ranger' => 'dexterity',
            'wizard' => 'intelligence',
            'cleric' => 'wisdom',
            'bard' => 'charisma',
        ];

        $stat = $classToStat[$pivot->character_class] ?? 'strength';
        $statValue = (int) ($pivot->{$stat} ?? 10);
        $modifier = (int) floor(($statValue - 10) / 2);

        return $modifier + 2;
    }

    /**
     * Сохраняет "предложенную" ИИ атаку игрока на NPC — резолвится при
     * следующем /roll этого игрока в этой комнате. Короткий TTL: если игрок
     * передумал и написал что-то другое вместо броска, запись просто истечёт.
     */
    public function storePendingAttack(Room $room, int $userId, array $directive): void
    {
        Cache::put(
            $this->pendingAttackKey($room->id, $userId),
            $directive,
            now()->addMinutes(self::PENDING_ATTACK_TTL_MINUTES)
        );
    }

    public function consumePendingAttack(Room $room, int $userId): ?array
    {
        return Cache::pull($this->pendingAttackKey($room->id, $userId));
    }

    private function pendingAttackKey(int $roomId, int $userId): string
    {
        return "pending_attack_room{$roomId}_user{$userId}";
    }
}
