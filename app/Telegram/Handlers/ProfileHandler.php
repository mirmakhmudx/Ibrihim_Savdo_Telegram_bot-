<?php

namespace App\Telegram\Handlers;

use App\Models\Favorite;
use App\Models\Order;
use App\Models\User;
use App\Services\TelegramService;

class ProfileHandler
{
    public function __construct(protected TelegramService $telegram) {}

    // -------------------------------------------------------
    // Profilni ko'rsatish
    // -------------------------------------------------------
    public function showProfile(User $user): void
    {
        $chatId = $user->telegram_id;

        // Statistika
        $totalOrders     = Order::where('user_id', $user->id)->count();
        $deliveredOrders = Order::where('user_id', $user->id)
            ->where('status', 'delivered')
            ->count();
        $pendingOrders   = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'pending_payment'])
            ->count();
        $totalSpent      = Order::where('user_id', $user->id)
            ->where('status', 'delivered')
            ->sum('total');
        $favCount        = Favorite::where('user_id', $user->id)->count();

        $text  = "👤 <b>Sizning profilingiz</b>\n\n";

        // Shaxsiy ma'lumotlar
        $text .= "👤 Ism: <b>{$user->name}</b>\n";

        if ($user->username) {
            $text .= "🔗 Username: @{$user->username}\n";
        }

        $text .= "📞 Telefon: <b>" . ($user->phone ?? 'Kiritilmagan') . "</b>\n";
        $text .= "📅 Ro'yxatdan o'tilgan: <b>{$user->created_at->format('d.m.Y')}</b>\n\n";

        // Xarid statistikasi
        $text .= "📊 <b>Statistikangiz:</b>\n";
        $text .= "📦 Jami buyurtmalar: <b>{$totalOrders} ta</b>\n";
        $text .= "✅ Yetkazilgan: <b>{$deliveredOrders} ta</b>\n";

        if ($pendingOrders > 0) {
            $text .= "⏳ Kutilmoqda: <b>{$pendingOrders} ta</b>\n";
        }

        $text .= "❤️ Sevimli mahsulotlar: <b>{$favCount} ta</b>\n";
        $text .= "💰 Jami xarid qilingan: <b>" . number_format($totalSpent, 0, '.', ' ') . " so'm</b>";

        // Tugmalar
        $buttons = [];

        if ($user->phone) {
            $buttons[] = [
                ['text' => '📞 Telefon raqamni o\'zgartirish', 'callback_data' => 'profile_change_phone'],
            ];
        } else {
            $buttons[] = [
                ['text' => '📞 Telefon raqam qo\'shish', 'callback_data' => 'profile_add_phone'],
            ];
        }

        $buttons[] = [
            ['text' => '📦 Buyurtmalarim', 'callback_data' => 'profile_orders'],
        ];

        $this->telegram->sendMessage(
            $chatId,
            $text,
            $this->telegram->inlineKeyboard($buttons)
        );
    }

    // -------------------------------------------------------
    // Callback query'larni qayta ishlash
    // -------------------------------------------------------
    public function handleCallback(User $user, string $data, int $messageId): void
    {
        $parts  = explode('_', $data, 3);
        $action = $parts[1] ?? '';

        match ($action) {
            'change', 'add' => $this->startChangePhone($user, $messageId),
            'orders'        => (new OrderHandler($this->telegram))->showOrders($user),
            default         => null,
        };
    }

    // -------------------------------------------------------
    // Telefon raqam o'zgartirish
    // -------------------------------------------------------
    private function startChangePhone(User $user, int $messageId): void
    {
        $chatId = $user->telegram_id;

        cache()->put("user_state_{$chatId}", 'checkout_awaiting_phone', now()->addMinutes(10));

        $this->telegram->editMessage(
            $chatId,
            $messageId,
            "📞 Yangi telefon raqamingizni kiriting:\n(Masalan: +998901234567)"
        );

        $this->telegram->sendMessage(
            $chatId,
            "Raqamni yuboring:",
            [
                'keyboard' => [
                    [['text' => '📱 Kontaktni yuborish', 'request_contact' => true]],
                    [['text' => '❌ Bekor qilish']],
                ],
                'resize_keyboard' => true,
            ]
        );
    }
}
