<?php

namespace App\Telegram\Handlers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderHandler
{
    private function orderNum(int $id): string { return '#' . str_pad($id, 5, '0', STR_PAD_LEFT); }

    public function __construct(protected TelegramService $telegram) {}

    // -------------------------------------------------------
    // Foydalanuvchi buyurtmalarini ko'rsatish
    // -------------------------------------------------------
    public function showOrders(User $user): void
    {
        $chatId = $user->telegram_id;

        $orders = Order::with('items.product')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "📦 <b>Siz hali buyurtma bermagansiz.</b>\n\n"                ."Xarid qilish uchun saytimizga o'ting:",
                $this->telegram->inlineKeyboard([[
                    ['text' => '🛒 Saytga o\'tish', 'url' => config('app.url')],
                ]])
            );
            return;
        }

        $text = "📦 <b>Sizning buyurtmalaringiz (so'nggi 10 ta):</b>\n\n";

        foreach ($orders as $order) {
            $statusInfo = $this->getStatusInfo($order->status);

            $text .= "{$statusInfo['emoji']} <b>Buyurtma " . $this->orderNum($order->id) . "</b>\n";
            $text .= "📅 {$order->created_at->format('d.m.Y H:i')}\n";
            $text .= "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n";
            $text .= "💳 To'lov: <b>{$order->payment_label}</b>\n";
            $text .= "📌 Holat: <b>{$statusInfo['text']}</b>\n";

            $itemNames = $order->items->map(fn($i) => ($i->product?->title ?? 'Mahsulot').' ('.$i->quantity.' ta)')->join(', ');
            $text .= "🛍 {$itemNames}\n\n";
        }

        $this->telegram->sendMessage($chatId, $text, $this->telegram->mainMenuKeyboard());
    }

    // -------------------------------------------------------
    // Callback query'larni qayta ishlash
    // -------------------------------------------------------
    public function handleCallback(User $user, string $data, int $messageId): void
    {
        $parts  = explode('_', $data, 3);
        $action = $parts[1] ?? '';

        match ($action) {
            'checkout' => $this->startCheckout($user, $messageId),
            'confirm'  => $this->confirmOrder($user, $messageId),
            'cancel'   => $this->cancelCheckout($user, $messageId),
            default    => null,
        };
    }

    // -------------------------------------------------------
    // To'lov turini tanlash callback'i
    // -------------------------------------------------------
    public function handlePaymentMethod(User $user, string $data, int $messageId): void
    {
        $method = explode('_', $data)[1] ?? 'cash';
        $chatId = $user->telegram_id;

        cache()->put("user_data_{$chatId}.payment_method", $method, now()->addMinutes(30));

        if ($method === 'card') {
            cache()->put("user_state_{$chatId}", 'checkout_awaiting_screenshot', now()->addMinutes(30));

            $cardNumber = \App\Models\SiteSetting::get('shop_card', config('telegram.card_number', '8600 0000 0000 0000'));
            $cardOwner  = \App\Models\SiteSetting::get('shop_card_owner', 'Ibrohim');

            $this->telegram->editMessage(
                $chatId,
                $messageId,
                "💳 <b>Karta orqali to'lov</b>\n\n"
                . "Karta raqami: <code>{$cardNumber}</code>\n"
                . "Karta egasi: <b>{$cardOwner}</b>\n\n"
                . "Summani o'tkazib, <b>to'lov chekini (screenshot)</b> yuboring 👇\n\n"
                . "⚠️ Screenshot yubormaguningizcha buyurtma tasdiqlanmaydi.",
                $this->telegram->inlineKeyboard([[
                    ['text' => '❌ Bekor qilish', 'callback_data' => 'order_cancel'],
                ]])
            );
        } else {
            // Naqd — to'g'ridan-to'g'ri buyurtma yaratish
            $this->placeOrder($user, 'cash');
        }
    }

    // -------------------------------------------------------
    // To'lov screenshoti kelganda
    // -------------------------------------------------------
    public function handlePaymentScreenshot(User $user, array $message): void
    {
        $chatId = $user->telegram_id;
        $state  = cache()->get("user_state_{$chatId}");

        if ($state !== 'checkout_awaiting_screenshot') return;

        $photos = $message['photo'];
        $photo  = end($photos);
        $fileId = $photo['file_id'];

        cache()->put("user_data_{$chatId}.screenshot", $fileId, now()->addMinutes(30));

        $this->telegram->sendMessage($chatId, "✅ Screenshot qabul qilindi! Buyurtmangiz tasdiqlanmoqda...");

        $this->placeOrder($user, 'card', $fileId);
    }

    // -------------------------------------------------------
    // State boshqaruvi
    // -------------------------------------------------------
    public function handleState(User $user, array $message, string $state): void
    {
        match ($state) {
            'checkout_awaiting_phone'    => $this->processPhone($user, $message),
            'checkout_awaiting_location' => $this->processLocation($user, $message),
            default                      => null,
        };
    }

    // -------------------------------------------------------
    // Checkout boshlash
    // -------------------------------------------------------
    private function startCheckout(User $user, int $messageId): void
    {
        $chatId = $user->telegram_id;

        $items = Cart::with('product')->where('user_id', $user->id)->get();

        if ($items->isEmpty()) {
            $this->telegram->editMessage($chatId, $messageId, "🛒 Savatchingiz bo'sh! Avval mahsulot qo'shing.");
            return;
        }

        $total = $items->sum(fn($i) => $i->product->price * $i->quantity);

        // Telefon raqam yo'q bo'lsa — so'rash
        if (!$user->phone) {
            cache()->put("user_state_{$chatId}", 'checkout_awaiting_phone', now()->addMinutes(30));
            cache()->put("user_data_{$chatId}.checkout_total", $total, now()->addMinutes(30));

            $this->telegram->editMessage($chatId, $messageId, "📞 Yetkazib berish uchun telefon raqamingizni kiriting:");
            $this->telegram->sendMessage(
                $chatId,
                "Telefon raqamingizni yuboring:",
                [
                    'keyboard' => [
                        [['text' => '📱 Kontaktni yuborish', 'request_contact' => true]],
                        [['text' => '❌ Bekor qilish']],
                    ],
                    'resize_keyboard' => true,
                ]
            );
            return;
        }

        // Lokatsiya so'rash
        $this->askLocation($user, $total, $messageId);
    }

    // -------------------------------------------------------
    // Telefon raqamni qayta ishlash
    // -------------------------------------------------------
    private function processPhone(User $user, array $message): void
    {
        $chatId = $user->telegram_id;

        if (isset($message['contact'])) {
            $phone = $message['contact']['phone_number'];
            if (!str_starts_with($phone, '+')) {
                $phone = '+' . $phone;
            }
        } else {
            $phone = trim($message['text'] ?? '');
        }

        $phone = preg_replace('/\s+/', '', $phone);
        if (!preg_match('/^(\+998|998|0)\d{9}$/', $phone)) {
            $this->telegram->sendMessage($chatId, "❌ Noto'g'ri format. Qaytadan kiriting:\n<b>Masalan: +998901234567</b>");
            return;
        }

        $user->update(['phone' => $phone]);
        cache()->forget("user_state_{$chatId}");

        $items = Cart::with('product')->where('user_id', $user->id)->get();
        $total = $items->sum(fn($i) => $i->product->price * $i->quantity);

        $this->telegram->sendMessage($chatId, "✅ Telefon raqam saqlandi: <b>{$phone}</b>", $this->telegram->mainMenuKeyboard());

        $this->askLocation($user, $total);
    }

    // -------------------------------------------------------
    // Lokatsiya so'rash
    // -------------------------------------------------------
    private function askLocation(User $user, float $total, ?int $messageId = null): void
    {
        $chatId = $user->telegram_id;

        cache()->put("user_state_{$chatId}", 'checkout_awaiting_location', now()->addMinutes(30));
        cache()->put("user_data_{$chatId}.checkout_total", $total, now()->addMinutes(30));

        $text = "📍 <b>Yetkazib berish manzili</b>\n\n"
            . "Manzilingizni quyidagi usullardan biri bilan yuboring:";

        $keyboard = [
            'keyboard' => [
                [['text' => '📍 Lokatsiyamni yuborish', 'request_location' => true]],
                [['text' => '✍️ Manzilni matn bilan yozish']],
                [['text' => '❌ Bekor qilish']],
            ],
            'resize_keyboard' => true,
        ];

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    // -------------------------------------------------------
    // Lokatsiyani qayta ishlash
    // -------------------------------------------------------
    private function processLocation(User $user, array $message): void
    {
        $chatId = $user->telegram_id;

        if (isset($message['location'])) {
            // GPS lokatsiya
            $lat = $message['location']['latitude'];
            $lng = $message['location']['longitude'];

            cache()->put("user_data_{$chatId}.location_lat", $lat, now()->addMinutes(30));
            cache()->put("user_data_{$chatId}.location_lng", $lng, now()->addMinutes(30));
            cache()->put("user_data_{$chatId}.address", null, now()->addMinutes(30));
            cache()->forget("user_state_{$chatId}");

            $this->telegram->sendMessage($chatId, "✅ Lokatsiya qabul qilindi!", $this->telegram->mainMenuKeyboard());

            $total = cache()->get("user_data_{$chatId}.checkout_total", 0);
            $this->showPaymentOptions($user, $total);

        } elseif (isset($message['text'])) {
            $text = trim($message['text']);

            if ($text === '✍️ Manzilni matn bilan yozish') {
                // Matn bilan yozishga o'tish
                cache()->put("user_state_{$chatId}", 'checkout_awaiting_address', now()->addMinutes(30));
                $this->telegram->sendMessage(
                    $chatId,
                    "✍️ Manzilingizni yozing:\n<i>Masalan: Chilonzor 5-kvartal, 12-uy, 3-kvartira</i>",
                    $this->telegram->cancelKeyboard()
                );
                return;
            }

            // Bekor qilish
            if ($text === '❌ Bekor qilish') {
                cache()->forget("user_state_{$chatId}");
                (new StartHandler($this->telegram))->handle($user);
                return;
            }
        }
    }

    // -------------------------------------------------------
    // Matn manzil kiritilganda
    // -------------------------------------------------------
    public function handleAddressInput(User $user, array $message, string $state): void
    {
        if ($state !== 'checkout_awaiting_address') return;

        $chatId  = $user->telegram_id;
        $address = trim($message['text'] ?? '');

        if (empty($address)) {
            $this->telegram->sendMessage($chatId, "❌ Manzil bo'sh bo'lmasin. Qaytadan kiriting:");
            return;
        }

        cache()->put("user_data_{$chatId}.address", $address, now()->addMinutes(30));
        cache()->put("user_data_{$chatId}.location_lat", null, now()->addMinutes(30));
        cache()->put("user_data_{$chatId}.location_lng", null, now()->addMinutes(30));
        cache()->forget("user_state_{$chatId}");

        $this->telegram->sendMessage($chatId, "✅ Manzil saqlandi: <b>{$address}</b>", $this->telegram->mainMenuKeyboard());

        $total = cache()->get("user_data_{$chatId}.checkout_total", 0);
        $this->showPaymentOptions($user, $total);
    }

    // -------------------------------------------------------
    // To'lov turini tanlash sahifasi
    // -------------------------------------------------------
    private function showPaymentOptions(User $user, float $total, ?int $messageId = null): void
    {
        $chatId  = $user->telegram_id;
        $address = cache()->get("user_data_{$chatId}.address");
        $lat     = cache()->get("user_data_{$chatId}.location_lat");

        $locationText = $address ?: ($lat ? "📍 GPS lokatsiya yuborildi" : "Manzil ko'rsatilmagan");

        $text  = "✅ <b>Buyurtmani tasdiqlash</b>\n\n";
        $text .= "📞 Telefon: <b>{$user->phone}</b>\n";
        $text .= "📍 Manzil: <b>{$locationText}</b>\n";
        $text .= "💰 Jami summa: <b>" . number_format($total, 0, '.', ' ') . " so'm</b>\n\n";
        $text .= "💳 To'lov turini tanlang:";

        $buttons = $this->telegram->inlineKeyboard([
            [
                ['text' => '💵 Naqd pul', 'callback_data' => 'pay_cash'],
                ['text' => '💳 Karta',    'callback_data' => 'pay_card'],
            ],
            [
                ['text' => '❌ Bekor qilish', 'callback_data' => 'order_cancel'],
            ],
        ]);

        if ($messageId) {
            $this->telegram->editMessage($chatId, $messageId, $text, $buttons);
        } else {
            $this->telegram->sendMessage($chatId, $text, $buttons);
        }
    }

    // -------------------------------------------------------
    // Buyurtmani yaratish va saqlash
    // -------------------------------------------------------
    private function placeOrder(User $user, string $paymentMethod, ?string $screenshotFileId = null): void
    {
        $chatId = $user->telegram_id;

        DB::beginTransaction();
        try {
            $items = Cart::with('product')->where('user_id', $user->id)->get();

            if ($items->isEmpty()) {
                $this->telegram->sendMessage($chatId, "❌ Savatcha bo'sh.");
                DB::rollBack();
                return;
            }

            $total   = $items->sum(fn($i) => $i->product->price * $i->quantity);
            $address = cache()->get("user_data_{$chatId}.address");
            $lat     = cache()->get("user_data_{$chatId}.location_lat");
            $lng     = cache()->get("user_data_{$chatId}.location_lng");

            $order = Order::create([
                'user_id'        => $user->id,
                'phone'          => $user->phone,
                'status'         => $paymentMethod === 'card' ? 'pending_payment' : 'pending',
                'payment_method' => $paymentMethod,
                'total'          => $total,
                'screenshot'     => $screenshotFileId,
                'address'        => $address,
                'location_lat'   => $lat,
                'location_lng'   => $lng,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => $item->product->price,
                ]);
            }

            Cart::where('user_id', $user->id)->delete();

            cache()->forget("user_state_{$chatId}");
            cache()->forget("user_data_{$chatId}");
            cache()->forget("user_data_{$chatId}.payment_method");
            cache()->forget("user_data_{$chatId}.checkout_total");
            cache()->forget("user_data_{$chatId}.address");
            cache()->forget("user_data_{$chatId}.location_lat");
            cache()->forget("user_data_{$chatId}.location_lng");

            DB::commit();

            $this->sendConfirmationToUser($user, $order, $items);
            $this->notifyAdmins($user, $order, $items, $screenshotFileId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order placement failed: ' . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ Xatolik yuz berdi. Iltimos qaytadan urinib ko'ring.");
        }
    }

    // -------------------------------------------------------
    // Foydalanuvchiga tasdiqlash xabari
    // -------------------------------------------------------
    private function sendConfirmationToUser(User $user, Order $order, $items): void
    {
        $itemsList = $items->map(fn($i) => "• {$i->product->title} — {$i->quantity} ta × "
            . number_format($i->product->price, 0, '.', ' ') . " so'm")->join("\n");

        $text  = "✅ <b>Buyurtmangiz qabul qilindi!</b>\n\n";
        $text .= "🆔 Buyurtma raqami: <b>" . $this->orderNum($order->id) . "</b>\n\n";
        $text .= "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n";
        $text .= "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n";
        $text .= "💳 To'lov: <b>{$order->payment_label}</b>\n\n";

        if ($order->payment_method === 'card') {
            $text .= "⏳ To'lovingiz tekshirilmoqda. Admin tasdiqlagach buyurtma yo'lga qo'yiladi.\n\n";
        }

        $text .= "🙏 <b>Tanlovingiz uchun rahmat!</b>\n";
        $text .= "⏱ 10-30 daqiqada yetkazib beramiz.";

        $this->telegram->sendMessage($user->telegram_id, $text, $this->telegram->mainMenuKeyboard());
    }

    // -------------------------------------------------------
    // Adminlarga bildirishnoma
    // -------------------------------------------------------
    private function notifyAdmins(User $user, Order $order, $items, ?string $screenshotFileId): void
    {
        $adminIds = array_filter(
            array_map('intval', explode(',', config('telegram.admin_ids', '')))
        );

        $paymentLabel = $order->payment_method === 'card' ? '💳 Karta' : '💵 Naqd';
        $statusLabel  = $order->payment_method === 'card' ? '⏳ To\'lov kutilmoqda' : '🆕 Yangi buyurtma';

        $text  = "🔔 <b>YANGI BUYURTMA " . $this->orderNum($order->id) . "</b>\n\n";
        $text .= "👤 Mijoz: <b>{$user->name}</b>\n";
        $text .= "📞 Tel: <b>{$user->phone}</b>\n";

        if ($user->username) {
            $text .= "🔗 Telegram: @{$user->username}\n";
        }

        // Manzil / Lokatsiya
        if ($order->address) {
            $text .= "📍 Manzil: <b>{$order->address}</b>\n";
        }
        if ($order->location_lat && $order->location_lng) {
            $text .= "🗺 <a href=\"https://maps.google.com/?q={$order->location_lat},{$order->location_lng}\">Xaritada ko'rish</a>\n";
        }

        $text .= "💳 To'lov: {$paymentLabel}\n";
        $text .= "📌 Holat: {$statusLabel}\n\n";
        $text .= "🛍 <b>Buyurtma tarkibi:</b>\n";

        foreach ($items as $item) {
            $subtotal = number_format($item->product->price * $item->quantity, 0, '.', ' ');
            $text .= "• {$item->product->title} — {$item->quantity} ta × "
                . number_format($item->product->price, 0, '.', ' ')
                . " = <b>{$subtotal} so'm</b>\n";
        }

        $text .= "\n💰 <b>Jami: " . number_format($order->total, 0, '.', ' ') . " so'm</b>";

        // 3 ta tugma: Qabul qilish + Yetkazildi + Bekor qilish
        $buttons = $this->telegram->inlineKeyboard([[
            ['text' => '✅ Qabul qilish',  'callback_data' => "admin_accept_{$order->id}"],
            ['text' => '🚚 Yetkazildi',    'callback_data' => "admin_delivered_{$order->id}"],
            ['text' => '❌ Bekor qilish',  'callback_data' => "admin_cancel_{$order->id}"],
        ]]);

        foreach ($adminIds as $adminId) {
            if ($screenshotFileId) {
                $this->telegram->sendPhoto($adminId, $screenshotFileId, $text, $buttons);
            } else {
                $this->telegram->sendMessage($adminId, $text, $buttons);
            }
        }
    }

    // -------------------------------------------------------
    // Checkoutni bekor qilish
    // -------------------------------------------------------
    private function cancelCheckout(User $user, int $messageId): void
    {
        $chatId = $user->telegram_id;

        cache()->forget("user_state_{$chatId}");
        cache()->forget("user_data_{$chatId}");

        $this->telegram->editMessage(
            $chatId,
            $messageId,
            "❌ Buyurtma bekor qilindi.\n\nSavatchangiz saqlanib qoldi."
        );

        (new StartHandler($this->telegram))->handle($user);
    }

    private function confirmOrder(User $user, int $messageId): void {}

    // -------------------------------------------------------
    // Status info
    // -------------------------------------------------------
    private function getStatusInfo(string $status): array
    {
        return match ($status) {
            'pending'         => ['emoji' => '🆕', 'text' => 'Yangi'],
            'pending_payment' => ['emoji' => '💳', 'text' => 'To\'lov kutilmoqda'],
            'accepted'        => ['emoji' => '🚚', 'text' => 'Qabul qilindi'],
            'delivered'       => ['emoji' => '✅', 'text' => 'Yetkazildi'],
            'cancelled'       => ['emoji' => '❌', 'text' => 'Bekor qilindi'],
            default           => ['emoji' => '❓', 'text' => $status],
        };
    }
}
