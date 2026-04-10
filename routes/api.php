<?php


use App\Http\Controllers\TelegramController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telegram Bot API Routes
|--------------------------------------------------------------------------
|
| Barcha Telegram bilan bog'liq route'lar shu yerda.
|
| Webhook URL ko'rinishi:
|   POST https://yourdomain.com/api/webhook/{secret_token}
|
| {secret_token} — .env da TELEGRAM_WEBHOOK_TOKEN
| Middleware bu tokenni tekshiradi, noto'g'ri bo'lsa 401 qaytaradi.
|
*/

// ── Telegram Webhook (asosiy) ─────────────────────────────
Route::post('/webhook/{token}', [TelegramController::class, 'webhook'])
    ->middleware('telegram.webhook')
    ->name('telegram.webhook');

// ── Webhook boshqaruv (faqat local/dev da foydalaning) ────
// Ishlab chiqarishda (production) bu route'larni o'chiring!
Route::prefix('webhook')->name('webhook.')->group(function () {

    // GET /api/webhook/set   — Webhookni o'rnatish
    Route::get('/set', [WebhookController::class, 'set'])
        ->name('set');

    // GET /api/webhook/info  — Webhook holati
    Route::get('/info', [WebhookController::class, 'info'])
        ->name('info');

    // GET /api/webhook/delete — Webhookni o'chirish
    Route::get('/delete', [WebhookController::class, 'delete'])
        ->name('delete');
});
