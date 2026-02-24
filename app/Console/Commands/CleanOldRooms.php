<?php

namespace App\Console\Commands;

use App\Models\Room;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanOldRooms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rooms:clean {days=30 : Удалять комнаты старше N дней}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удаляет старые завершенные комнаты и связанные с ними данные';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->argument('days');
        $date = now()->subDays($days);
        
        $this->info("Поиск завершенных комнат старше {$days} дней (до {$date->format('d.m.Y H:i')})");
        
        // Находим комнаты для удаления
        $rooms = Room::where('status', 'finished')
            ->where('updated_at', '<', $date)
            ->get();
        
        $count = $rooms->count();
        
        if ($count === 0) {
            $this->info("Нет комнат для удаления");
            Log::info("CleanOldRooms: нет комнат для удаления");
            return 0;
        }
        
        $this->info("Найдено {$count} старых комнат");
        
        // Счетчики для статистики
        $deletedRooms = 0;
        $deletedMessages = 0;
        $deletedOocMessages = 0;
        $deletedUsers = 0;
        
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        
        foreach ($rooms as $room) {
            try {
                DB::transaction(function () use ($room, &$deletedMessages, &$deletedOocMessages, &$deletedUsers, &$deletedRooms) {
                    // Считаем сообщения для статистики
                    $messageCount = $room->gameMessages()->count();
                    $oocCount = $room->oocMessages()->count();
                    $usersCount = $room->users()->count();
                    
                    // Удаляем связанные данные
                    $room->gameMessages()->delete();
                    $room->oocMessages()->delete();
                    $room->users()->detach();
                    
                    // Удаляем комнату
                    $room->delete();
                    
                    // Обновляем статистику
                    $deletedMessages += $messageCount;
                    $deletedOocMessages += $oocCount;
                    $deletedUsers += $usersCount;
                    $deletedRooms++;
                });
                
                $bar->advance();
                
            } catch (\Exception $e) {
                $this->error("Ошибка при удалении комнаты ID {$room->id}: " . $e->getMessage());
                Log::error("CleanOldRooms: ошибка при удалении комнаты {$room->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $bar->finish();
        $this->newLine();
        
        // Выводим статистику
        $this->info("Удаление завершено:");
        $this->table(
            ['Комнат', 'Игровых сообщений', 'OOC сообщений', 'Участников'],
            [[$deletedRooms, $deletedMessages, $deletedOocMessages, $deletedUsers]]
        );
        
        // Логируем результат
        Log::info("CleanOldRooms: удалено {$deletedRooms} комнат", [
            'messages' => $deletedMessages,
            'ooc_messages' => $deletedOocMessages,
            'users' => $deletedUsers
        ]);
        
        return 0;
    }
}