<?php

namespace App\Telegram\Handlers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\TelegramService;

class CartHandler
{
    public function __construct(protected TelegramService $telegram) {}

    // -------------------------------------------------------
    // Savatchani ko'rsatish
    // -------------------------------------------------------
    public function showCart(User $user): void
    {
        $chatId = $user->telegram_id;

        $items = Cart::with('product')
            ->where('user_id', $user->id)
            ->get();

        if ($items->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "🛒 Savatchingiz hozircha bo'sh.\n\n"
                . "Mahsulot qo'shish uchun <b>🛍 Mahsulotlar</b> bo'limiga o'ting.",
                $this->telegram->mainMenuKeyboard()
            );
            return;
        }

        // Savatcha matni
        $text  = "🛒 <b>Sizning savatchingiz:</b>\n\n";
        $total = 0;

        foreach ($items as $index => $item) {
            $subtotal  = $item->product->price * $item->quantity;
            $total    += $subtotal;

            $text .= ($index + 1) . ". <b>{$item->product->title}</b>\n";
            $text .= "   💰 " . number_format($item->product->price, 0, '.', ' ') . " so'm";
            $text .= " × {$item->quantity} ta";
            $text .= " = <b>" . number_format($subtotal, 0, '.', ' ') . " so'm</b>\n\n";
        }

        $text .= "━━━━━━━━━━━━━━━━\n";
        $text .= "💰 <b>Jami: " . number_format($total, 0, '.', ' ') . " so'm</b>\n";
        $text .= "📦 Mahsulotlar soni: <b>{$items->count()} xil</b>";

        // Har bir mahsulot uchun miqdor boshqarish tugmalari
        $buttons = [];
        foreach ($items as $item) {
            $buttons[] = [
                ['text' => "➖",                                             'callback_data' => "cart_dec_{$item->product_id}"],
                ['text' => "{$item->product->title} ({$item->quantity} ta)", 'callback_data' => "cart_noop"],
                ['text' => "➕",                                             'callback_data' => "cart_inc_{$item->product_id}"],
                ['text' => "🗑",                                             'callback_data' => "cart_remove_{$item->product_id}"],
            ];
        }

        // Umumiy tugmalar
        $buttons[] = [
            ['text' => '🗑 Savatchani tozalash', 'callback_data' => 'cart_clear'],
        ];
        $buttons[] = [
            ['text' => '✅ Buyurtma berish (' . number_format($total, 0, '.', ' ') . " so'm)", 'callback_data' => 'order_checkout'],
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
            'add'    => $this->addToCart($user, $productId),
            'inc'    => $this->increaseQty($user, $productId),
            'dec'    => $this->decreaseQty($user, $productId),
            'remove' => $this->removeFromCart($user, $productId),
            'clear'  => $this->clearCart($user, $messageId),
            'view'   => $this->showCart($user),
            'noop'   => null,
            default  => null,
        };
    }

    // -------------------------------------------------------
    // Savatchaga mahsulot qo'shish
    // -------------------------------------------------------
    private function addToCart(User $user, int $productId): void
    {
        $product = Product::find($productId);
        if (!$product) return;

        $cartItem = Cart::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($cartItem) {
            $cartItem->increment('quantity');
            $qty = $cartItem->fresh()->quantity;
        } else {
            Cart::create([
                'user_id'    => $user->id,
                'product_id' => $productId,
                'quantity'   => 1,
            ]);
            $qty = 1;
        }

        $totalItems = Cart::where('user_id', $user->id)->sum('quantity');

        $this->telegram->sendMessage(
            $user->telegram_id,
            "✅ <b>{$product->title}</b> savatchaga qo'shildi!\n\n"
            . "📦 Ushbu mahsulot: <b>{$qty} ta</b>\n"
            . "🛒 Savatchada jami: <b>{$totalItems} ta mahsulot</b>",
            $this->telegram->inlineKeyboard([[
                ['text' => '🛒 Savatchani ko\'rish', 'callback_data' => 'cart_view_0'],
                ['text' => '🛍 Davom etish',         'callback_data' => 'product_continue'],
            ]])
        );
    }

    // -------------------------------------------------------
    // Miqdorni oshirish
    // -------------------------------------------------------
    private function increaseQty(User $user, int $productId): void
    {
        $cartItem = Cart::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($cartItem) {
            $cartItem->increment('quantity');
        }

        $this->showCart($user);
    }

    // -------------------------------------------------------
    // Miqdorni kamaytirish (1 ta qolsa — o'chirish)
    // -------------------------------------------------------
    private function decreaseQty(User $user, int $productId): void
    {
        $cartItem = Cart::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$cartItem) return;

        if ($cartItem->quantity <= 1) {
            $cartItem->delete();
        } else {
            $cartItem->decrement('quantity');
        }

        $this->showCart($user);
    }

    // -------------------------------------------------------
    // Mahsulotni savatchadan o'chirish
    // -------------------------------------------------------
    private function removeFromCart(User $user, int $productId): void
    {
        Cart::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->delete();

        $this->showCart($user);
    }

    // -------------------------------------------------------
    // Savatchani to'liq tozalash
    // -------------------------------------------------------
    private function clearCart(User $user, int $messageId): void
    {
        Cart::where('user_id', $user->id)->delete();

        $this->telegram->editMessage(
            $user->telegram_id,
            $messageId,
            "🗑 Savatchingiz tozalandi.\n\nYangi xarid qilish uchun mahsulotlarga o'ting.",
            $this->telegram->inlineKeyboard([[
                ['text' => '🛍 Mahsulotlarga o\'tish', 'callback_data' => 'product_continue'],
            ]])
        );
    }
}
