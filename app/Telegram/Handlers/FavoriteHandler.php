<?php

namespace App\Telegram\Handlers;

use App\Models\Cart;
use App\Models\Favorite;
use App\Models\User;
use App\Services\TelegramService;

class FavoriteHandler
{
    public function __construct(protected TelegramService $telegram) {}

    // -------------------------------------------------------
    // Sevimlilar ro'yxatini ko'rsatish
    // -------------------------------------------------------
    public function showFavorites(User $user): void
    {
        $chatId    = $user->telegram_id;
        $favorites = Favorite::with('product')
            ->where('user_id', $user->id)
            ->get();

        if ($favorites->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "❤️ <b>Sevimlilar ro'yxati bo'sh</b>\n\n"
                ."Mahsulotlarni ko'rib, ❤️ tugmasini bosib sevimlilarga qo'shing:",
                $this->telegram->inlineKeyboard([[
                    ['text' => '🛒 Saytga o\'tish', 'url' => config('app.url')],
                ]])
            );
            return;
        }

        $count = $favorites->count();
        $text  = "❤️ <b>Sevimli mahsulotlaringiz ({$count} ta):</b>\n\n";

        $buttons = [];

        foreach ($favorites as $index => $fav) {
            $price = number_format($fav->product->price, 0, '.', ' ');

            // Savatchada bormi?
            $inCart = Cart::where('user_id', $user->id)
                ->where('product_id', $fav->product_id)
                ->exists();

            $cartLabel = $inCart ? '✅ Savatchada' : '🛒 Savatchaga';

            $text .= ($index + 1) . ". <b>{$fav->product->title}</b>\n";
            $text .= "   💰 {$price} so'm\n\n";

            $buttons[] = [
                ['text' => "🛒 {$fav->product->title}", 'callback_data' => "cart_add_{$fav->product_id}"],
                ['text' => '💔 O\'chirish',              'callback_data' => "fav_remove_{$fav->product_id}"],
            ];
        }

        // Hammasini tozalash tugmasi
        $buttons[] = [
            ['text' => '🗑 Hammasini o\'chirish', 'callback_data' => 'fav_clear'],
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
        $parts     = explode('_', $data, 3);
        $action    = $parts[1] ?? '';
        $productId = (int) ($parts[2] ?? 0);

        match ($action) {
            'toggle' => $this->toggleFavorite($user, $productId),
            'remove' => $this->removeFavorite($user, $productId),
            'clear'  => $this->clearFavorites($user, $messageId),
            'list'   => $this->showFavorites($user),
            default  => $this->showFavorites($user),
        };
    }

    // -------------------------------------------------------
    // Sevimlilarga qo'shish / olib tashlash (toggle)
    // -------------------------------------------------------
    private function toggleFavorite(User $user, int $productId): void
    {
        $existing = Favorite::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($existing) {
            $existing->delete();
            $this->telegram->sendMessage(
                $user->telegram_id,
                "💔 Sevimlilardan olib tashlandi.",
                $this->telegram->inlineKeyboard([[
                    ['text' => '❤️ Sevimlilarni ko\'rish', 'callback_data' => 'fav_list'],
                ]])
            );
        } else {
            Favorite::create([
                'user_id'    => $user->id,
                'product_id' => $productId,
            ]);
            $this->telegram->sendMessage(
                $user->telegram_id,
                "❤️ Sevimlilarga qo'shildi!\n\nSevimlilar ro'yxatini ko'rish uchun <b>❤️ Sevimlilar</b> bo'limiga o'ting.",
                $this->telegram->inlineKeyboard([[
                    ['text' => '❤️ Sevimlilarni ko\'rish', 'callback_data' => 'fav_list'],
                    ['text' => '🛍 Davom etish',           'callback_data' => 'product_continue'],
                ]])
            );
        }
    }

    // -------------------------------------------------------
    // Bitta mahsulotni sevimlilardan o'chirish
    // -------------------------------------------------------
    private function removeFavorite(User $user, int $productId): void
    {
        Favorite::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->delete();

        // Yangilangan ro'yxatni ko'rsatish
        $this->showFavorites($user);
    }

    // -------------------------------------------------------
    // Barcha sevimlilarni tozalash
    // -------------------------------------------------------
    private function clearFavorites(User $user, int $messageId): void
    {
        Favorite::where('user_id', $user->id)->delete();

        $this->telegram->editMessage(
            $user->telegram_id,
            $messageId,
            "🗑 Sevimlilar ro'yxati tozalandi.",
            $this->telegram->inlineKeyboard([[
                ['text' => '🛍 Mahsulotlarga o\'tish', 'callback_data' => 'product_continue'],
            ]])
        );
    }
}
