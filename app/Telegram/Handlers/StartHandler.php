<?php

namespace App\Telegram\Handlers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\TelegramService;

class StartHandler
{
    public function __construct(protected TelegramService $telegram) {}

    public function handle(User $user): void
    {
        $chatId = $user->telegram_id;

        cache()->forget("user_state_{$chatId}");
        cache()->forget("user_data_{$chatId}");

        $name = $user->name ?: "do'st";

        if ($user->is_admin) {
            $this->handleAdmin($user, $name);
        } else {
            $this->handleUser($user, $name);
        }
    }

    private function handleUser(User $user, string $name): void
    {
        $chatId = $user->telegram_id;

        $cartCount     = Cart::where('user_id', $user->id)->sum('quantity');
        $pendingOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'pending_payment', 'accepted'])
            ->count();

        $text  = "👋 Assalomu alaykum, <b>{$name}</b>!\n\n";
        $text .= "🏪 <b>Ibrohim Savdo</b> do'koniga xush kelibsiz!\n\n";

        if ($cartCount > 0) {
            $text .= "🛒 Savatchingizda: <b>{$cartCount} ta mahsulot</b>\n";
        }
        if ($pendingOrders > 0) {
            $text .= "📦 Faol buyurtmalar: <b>{$pendingOrders} ta</b>\n";
        }

        $text .= "\nQuyidagi bo'limlardan birini tanlang:";

        // Inline tugmalar — websayt + biz haqimizda + buyurtmalarim
        $websiteUrl = config('app.url', 'https://ibrohimsavdo.uz');

        $inlineButtons = $this->telegram->inlineKeyboard([
            [
                ['text' => '🌐 Veb-sayt', 'url' => $websiteUrl],
                ['text' => 'ℹ️ Biz haqimizda', 'callback_data' => 'info_about'],
            ],
            [
                ['text' => '📦 Buyurtmalarim', 'callback_data' => 'user_orders'],
            ],
        ]);

        $this->telegram->sendMessage(
            $chatId,
            $text,
            $this->telegram->mainMenuKeyboard()
        );

        // Inline tugmalar alohida
        $this->telegram->sendMessage(
            $chatId,
            "🔗 Qo'shimcha imkoniyatlar:",
            $inlineButtons
        );
    }

    private function handleAdmin(User $user, string $name): void
    {
        $chatId = $user->telegram_id;

        $pendingOrders  = Order::whereIn('status', ['pending', 'pending_payment'])->count();
        $acceptedOrders = Order::where('status', 'accepted')->count();
        $deliveredToday = Order::where('status', 'delivered')
            ->whereDate('updated_at', today())
            ->count();

        $text  = "👋 Xush kelibsiz, admin <b>{$name}</b>!\n\n";
        $text .= "📊 <b>Bugungi holat:</b>\n";
        $text .= "⏳ Yangi buyurtmalar: <b>{$pendingOrders} ta</b>\n";
        $text .= "🚚 Qabul qilingan: <b>{$acceptedOrders} ta</b>\n";
        $text .= "✅ Bugun yetkazildi: <b>{$deliveredToday} ta</b>\n\n";
        $text .= "Quyidagi bo'limlardan birini tanlang:";

        $this->telegram->sendMessage(
            $chatId,
            $text,
            $this->telegram->adminMenuKeyboard()
        );
        $adminUrl = config('app.url') . '/admin';
        $this->telegram->sendMessage(
            $chatId,
            "🌐 Admin boshqaruv paneli:",
            $this->telegram->inlineKeyboard([[
                ['text' => '🖥 Admin saytga kirish', 'url' => $adminUrl],
            ]])
        );
    }

    // -------------------------------------------------------
    // Biz haqimizda callback
    // -------------------------------------------------------
    public function handleAbout(User $user, int $messageId): void
    {
        $address   = \App\Models\SiteSetting::get('shop_address', 'Toshkent, O\'zbekiston');
        $phone     = \App\Models\SiteSetting::get('shop_phone', '+998 90 000 00 00');
        $hours     = \App\Models\SiteSetting::get('shop_hours', '08:00–22:00');
        $card      = \App\Models\SiteSetting::get('shop_card', '8600 0000 0000 0000');
        $cardOwner = \App\Models\SiteSetting::get('shop_card_owner', 'Ibrohim Savdo');
        $about     = \App\Models\SiteSetting::get('shop_about', 'Mahalliy oziq-ovqat va kundalik ehtiyoj mahsulotlarini yetkazib beramiz.');
        $lat       = \App\Models\SiteSetting::get('shop_lat', '');
        $lng       = \App\Models\SiteSetting::get('shop_lng', '');

        $text  = "ℹ️ <b>Ibrohim Savdo haqida</b>\n\n";
        $text .= "🏪 {$about}\n\n";
        $text .= "⏰ <b>Ish vaqti:</b> {$hours}\n";
        $text .= "📍 <b>Manzil:</b> {$address}\n";
        $text .= "📞 <b>Telefon:</b> {$phone}\n";
        $text .= "💳 <b>Karta:</b> <code>{$card}</code> ({$cardOwner})\n\n";
        $text .= "🚚 <b>Yetkazib berish:</b> 10-30 daqiqa\n";
        $text .= "🛒 Xarid qilish uchun <b>🛍 Mahsulotlar</b> ni bosing!";

        $buttons = [[['text' => '🔙 Ortga', 'callback_data' => 'info_back']]];
        if ($lat && $lng) {
            $buttons[] = [['text' => '🗺 Xaritada ko\'rish', 'url' => "https://maps.google.com/?q={$lat},{$lng}"]];
        }

        $this->telegram->editMessage(
            $user->telegram_id, $messageId, $text,
            $this->telegram->inlineKeyboard($buttons)
        );
    }
}
