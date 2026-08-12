<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Support\Facades\Log;

/**
 * Применяет изменения характеристик персонажей (урон/лечение/левел-ап),
 * полученные из служебного STATE-блока ответа AI-мастера, к реальным
 * строкам room_user. Все дельты жёстко ограничены — это единственная
 * защита от галлюцинаций/некорректного вывода модели.
 */
class CombatService
{
    private const HP_MIN_DELTA = -50;
    private const HP_MAX_DELTA = 50;
    private const AC_MIN_DELTA = -10;
    private const AC_MAX_DELTA = 10;
    private const MAX_HP_MIN_DELTA = 0;   // левел-ап только увеличивает максимум
    private const MAX_HP_MAX_DELTA = 50;

    /**
     * @param  array<int, array{character?: string, hp_delta?: int, ac_delta?: int, max_hp_delta?: int}>  $changes
     * @return array<int, array{user_id:int, character_name:string, hp_delta:int, current_hp:int, max_hp:int, armor_class:int, leveled_up:bool}>
     */
    public function applyStateChanges(Room $room, array $changes): array
    {
        if (empty($changes)) {
            return [];
        }

        $nameToUserId = $this->buildCharacterNameMap($room);
        $applied = [];

        foreach ($changes as $change) {
            if (!is_array($change) || empty($change['character'])) {
                continue;
            }

            $characterName = (string) $change['character'];
            $userId = $nameToUserId[$characterName] ?? null;

            if ($userId === null) {
                Log::warning('CombatService: неизвестный персонаж в STATE-блоке', [
                    'room_id' => $room->id,
                    'character' => $characterName,
                ]);
                continue;
            }

            $pivot = $room->users()->where('user_id', $userId)->first()?->pivot;
            if (!$pivot) {
                continue;
            }

            $hpDelta = $this->clamp((int) ($change['hp_delta'] ?? 0), self::HP_MIN_DELTA, self::HP_MAX_DELTA);
            $acDelta = $this->clamp((int) ($change['ac_delta'] ?? 0), self::AC_MIN_DELTA, self::AC_MAX_DELTA);
            $maxHpDelta = $this->clamp((int) ($change['max_hp_delta'] ?? 0), self::MAX_HP_MIN_DELTA, self::MAX_HP_MAX_DELTA);

            if ($hpDelta === 0 && $acDelta === 0 && $maxHpDelta === 0) {
                continue;
            }

            $oldCurrentHp = (int) $pivot->current_hp;
            $oldMaxHp = (int) $pivot->max_hp;
            $oldAc = (int) $pivot->armor_class;

            $newMaxHp = max(1, $oldMaxHp + $maxHpDelta);
            // Левел-ап дополнительно "долечивает" на прирост максимума — честный прирост здоровья
            $newCurrentHp = max(0, min($newMaxHp, $oldCurrentHp + $hpDelta + $maxHpDelta));
            $newAc = max(0, $oldAc + $acDelta);

            if ($newCurrentHp === $oldCurrentHp && $newMaxHp === $oldMaxHp && $newAc === $oldAc) {
                continue;
            }

            $room->users()->updateExistingPivot($userId, [
                'current_hp' => $newCurrentHp,
                'max_hp' => $newMaxHp,
                'armor_class' => $newAc,
            ]);

            $applied[] = [
                'user_id' => $userId,
                'character_name' => $characterName,
                'hp_delta' => $newCurrentHp - $oldCurrentHp,
                'current_hp' => $newCurrentHp,
                'max_hp' => $newMaxHp,
                'armor_class' => $newAc,
                'leveled_up' => $maxHpDelta > 0,
            ];
        }

        return $applied;
    }

    /** @return array<string, int> имя персонажа => user_id */
    private function buildCharacterNameMap(Room $room): array
    {
        $map = [];

        foreach ($room->users()->wherePivot('is_ready', true)->get() as $user) {
            if ($user->pivot->character_name) {
                $map[$user->pivot->character_name] = $user->id;
            }
        }

        return $map;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}