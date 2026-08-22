<?php

namespace App\Services;

class DiceService
{
    /**
     * Классический бросок d20 (проверка навыка / плоский /roll без контекста атаки).
     */
    public function roll(?int $difficulty = null): array
    {
        $roll = random_int(1, 20);

        if ($difficulty !== null) {
            $success = $roll >= $difficulty;
            $level = $success ? '✅ Успех' : '❌ Провал';

            return [
                'roll' => $roll,
                'difficulty' => $difficulty,
                'success' => $success,
                'level' => $level,
                'message' => "🎲 **Бросок d20:** $roll (нужно $difficulty) — $level",
            ];
        }

        switch ($roll) {
            case 1:
                $level = '💀 Критический провал!';
                $success = false;
                break;
            case 20:
                $level = '🎯 Критический успех!';
                $success = true;
                break;
            default:
                if ($roll <= 5) {
                    $level = '❌ Провал';
                    $success = false;
                } elseif ($roll <= 10) {
                    $level = '⚠️ Частичный успех';
                    $success = true;
                } else {
                    $level = '✅ Успех';
                    $success = true;
                }
        }

        return [
            'roll' => $roll,
            'difficulty' => null,
            'success' => $success,
            'level' => $level,
            'message' => "🎲 **Бросок d20:** $roll — $level",
        ];
    }

    /**
     * Бросок урона произвольными костями, например "1d6", "2d8", "1d10".
     * Кости и их количество ограничены безопасными рамками — это единственная
     * защита от того, что ИИ пришлёт что-то абсурдное вроде "99d100".
     *
     * @return array{dice: string, rolls: int[], bonus: int, total: int}
     */
    public function rollDice(string $notation, int $flatBonus = 0): array
    {
        [$count, $sides] = $this->parseNotation($notation);

        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $sides);
        }

        $flatBonus = max(0, min(10, $flatBonus));

        return [
            'dice' => "{$count}d{$sides}",
            'rolls' => $rolls,
            'bonus' => $flatBonus,
            'total' => array_sum($rolls) + $flatBonus,
        ];
    }

    /**
     * @return array{0: int, 1: int} [количество костей, граней]
     */
    private function parseNotation(string $notation): array
    {
        $allowedSides = [4, 6, 8, 10, 12, 20, 100];

        if (!preg_match('/^\s*(\d+)\s*[dDдД]\s*(\d+)\s*$/u', $notation, $m)) {
            // Не смогли распарсить — безопасный фолбэк, чтобы не ронять запрос
            return [1, 6];
        }

        $count = max(1, min(10, (int) $m[1]));
        $sides = in_array((int) $m[2], $allowedSides, true) ? (int) $m[2] : 6;

        return [$count, $sides];
    }
}
