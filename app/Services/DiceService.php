<?php

namespace App\Services;

class DiceService
{
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
}