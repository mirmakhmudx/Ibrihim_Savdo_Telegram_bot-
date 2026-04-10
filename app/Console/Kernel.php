<?php


namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    // Barcha artisan commandlar shu yerda ro'yxatdan o'tadi
    protected $commands = [
        Commands\SetTelegramWebhook::class,
        Commands\DeleteTelegramWebhook::class,
        Commands\TelegramWebhookInfo::class,
        Commands\BroadcastMessage::class,
    ];

    // Rejalashtirilgan vazifalar (cron)
    protected function schedule(Schedule $schedule): void
    {
        // Har kuni yarim tunda — eski cancelled buyurtmalarni tozalash (ixtiyoriy)
        // $schedule->command('orders:cleanup')->dailyAt('00:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
