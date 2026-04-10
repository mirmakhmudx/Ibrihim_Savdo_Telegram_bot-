<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SetTelegramWebhook extends Command
{
    protected $signature   = 'telegram:set-webhook';
    protected $description = 'Telegram webhook URL ni o\'rnatish';

    public function handle(TelegramService $telegram): void
    {
        $token      = config('telegram.webhook_token');
        $appUrl     = config('app.url');
        $botToken   = config('telegram.bot_token');

        if (empty($botToken)) {
            $this->error('❌ TELEGRAM_BOT_TOKEN .env da topilmadi!');
            return;
        }

        if (empty($token)) {
            $this->error('❌ TELEGRAM_WEBHOOK_TOKEN .env da topilmadi!');
            return;
        }

        $webhookUrl = "{$appUrl}/api/webhook/{$token}";

        $this->info("🔗 Webhook URL: {$webhookUrl}");
        $this->info("⏳ Telegram'ga yuborilmoqda...");

        $result = $telegram->setWebhook($webhookUrl);

        if ($result && ($result['ok'] ?? false)) {
            $this->info('✅ Webhook muvaffaqiyatli o\'rnatildi!');
        } else {
            $this->error('❌ Webhook o\'rnatishda xatolik:');
            $this->error(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
