<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\CleanOldRooms::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Очистка старых завершенных комнат (каждый день в 3 часа ночи)
        $schedule->command('rooms:clean 30')
                 ->daily()
                 ->at('03:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Log::info('Старые комнаты успешно очищены');
                 })
                 ->onFailure(function () {
                     \Log::error('Ошибка при очистке старых комнат');
                 });

        // Дополнительная очистка каждую неделю для более старых комнат (60 дней)
        $schedule->command('rooms:clean 60')
                 ->weekly()
                 ->sundays()
                 ->at('04:00')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Очистка кэша каждый час
        $schedule->command('cache:prune-stale-tags')->hourly();
        
        // Очистка сессий (если используете file/database драйвер)
        $schedule->command('session:gc')->daily();

        // Можно добавить другие команды по расписанию
        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone(): string
    {
        return 'Europe/Moscow'; // Установите свой часовой пояс
    }
}