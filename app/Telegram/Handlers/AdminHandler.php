<?php

namespace App\Telegram\Handlers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\TelegramService;

class AdminHandler
{
    // Buyurtma raqamini chiroyli ko'rsatish
    private function orderNum(int $id): string { return '#' . str_pad($id, 5, '0', STR_PAD_LEFT); }

    // Mahsulot kategoriyalari
    const CATEGORIES = [
        'drinks'     => '🥤 Ichimliklar',
        'vegetables' => '🥦 Sabzavotlar',
        'fruits'     => '🍎 Mevalar',
        'bread'      => '🍞 Non mahsulotlari',
        'dairy'      => '🥛 Sut mahsulotlari',
        'meat'       => '🍗 Go\'sht mahsulotlari',
        'fish'       => '🐟 Baliq mahsulotlari',
        'sweets'     => '🍫 Shirinliklar',
        'grains'     => '🍚 Don mahsulotlari',
        'spices'     => '🧂 Ziravorlar',
        'canned'     => '🥫 Konserva mahsulotlari',
        'kids'       => '🧃 Bolalar uchun mahsulotlar',
        'household'  => '🧻 Uy-ro\'zg\'or buyumlari',
        'hygiene'    => '🧼 Gigiyena mahsulotlari',
    ];

    public function __construct(protected TelegramService $telegram) {}

    public function handleMessage(User $user, array $message): bool
    {
        if (!$user->is_admin) return false;

        $text = $message['text'] ?? '';

        $handled = match ($text) {
            '📦 Buyurtmalar'          => $this->showOrders($user, 'pending'),
            '➕ Mahsulot qo\'shish'   => $this->startAddProduct($user),
            '📢 Hammaga xabar'        => $this->startBroadcast($user),
            '📊 Statistika'           => $this->showStats($user),
            '👥 Foydalanuvchilar'     => $this->showUsers($user),
            '🖥 Admin boshqaruv paneli' => $this->sendAdminPanelLink($user),
            default                   => false,
        };

        return $handled !== false;
    }

    public function handleState(User $user, array $message, string $state): void
    {
        match (true) {
            str_starts_with($state, 'admin_add_product') => $this->handleAddProductStep($user, $message, $state),
            $state === 'admin_broadcast'                  => $this->executeBroadcast($user, $message),
            default                                       => null,
        };
    }

    public function handleCallback(User $user, string $data, int $messageId): void
    {
        if (!$user->is_admin) return;

        // admin_accept_5 / admin_delivered_5 / admin_cancel_5 / admin_orders_pending
        $parts  = explode('_', $data, 3);
        $action = $parts[1] ?? '';
        $id     = (int) ($parts[2] ?? 0);

        match ($action) {
            'accept'    => $this->acceptOrder($user, $id, $messageId),
            'delivered' => $this->markDelivered($user, $id, $messageId),
            'cancel'    => $this->cancelOrder($user, $id, $messageId),
            'orders'    => $this->handleOrdersTab($user, $data),
            'delete'    => $this->deleteProduct($user, $id, $messageId),
            default     => null,
        };
    }

    // -------------------------------------------------------
    // Buyurtmalar ro'yxati
    // -------------------------------------------------------
    public function showOrders(User $user, string $filter = 'pending'): void
    {
        $chatId = $user->telegram_id;

        $pendingCount   = Order::whereIn('status', ['pending', 'pending_payment'])->count();
        $acceptedCount  = Order::where('status', 'accepted')->count();
        $deliveredCount = Order::where('status', 'delivered')->count();

        if ($filter === 'pending') {
            $orders = Order::with(['user', 'items.product'])
                ->whereIn('status', ['pending', 'pending_payment'])
                ->orderBy('created_at', 'desc')
                ->take(20)
                ->get();
        } elseif ($filter === 'accepted') {
            $orders = Order::with(['user', 'items.product'])
                ->where('status', 'accepted')
                ->orderBy('created_at', 'desc')
                ->take(20)
                ->get();
        } else {
            $orders = Order::with(['user', 'items.product'])
                ->where('status', 'delivered')
                ->orderBy('updated_at', 'desc')
                ->take(20)
                ->get();
        }

        $filterLabel = match ($filter) {
            'pending'   => "⏳ Yangi ({$pendingCount} ta)",
            'accepted'  => "🚚 Qabul qilingan ({$acceptedCount} ta)",
            default     => "✅ Yetkazilgan ({$deliveredCount} ta)",
        };

        $text = "📦 <b>Buyurtmalar — {$filterLabel}</b>\n\n";

        if ($orders->isEmpty()) {
            $text .= "Hozircha buyurtma yo'q.";
        } else {
            foreach ($orders as $order) {
                $statusEmoji = match ($order->status) {
                    'pending_payment' => '💳',
                    'accepted'        => '🚚',
                    'delivered'       => '✅',
                    default           => '🆕',
                };

                $text .= "{$statusEmoji} <b>$this->orderNum($order->id)</b> — {$order->user->name}\n";
                $text .= "   📞 {$order->phone}";
                if ($order->user->username) {
                    $text .= " | @{$order->user->username}";
                }
                $text .= "\n   💰 " . number_format($order->total, 0, '.', ' ') . " so'm";
                $text .= " | {$order->created_at->format('d.m H:i')}\n";

                // Mahsulot nomlari
                $itemNames = $order->items->map(fn($i) => "{$i->product->title} ({$i->quantity}x)")->join(', ');
                $text .= "   🛍 {$itemNames}\n\n";
            }
        }

        // Tab tugmalari
        $tabs = [[
            ['text' => "⏳ Yangi ({$pendingCount})",      'callback_data' => 'admin_orders_pending'],
            ['text' => "🚚 Qabul ({$acceptedCount})",     'callback_data' => 'admin_orders_accepted'],
            ['text' => "✅ Yetkazilgan ({$deliveredCount})", 'callback_data' => 'admin_orders_delivered'],
        ]];

        $this->telegram->sendMessage($chatId, $text, $this->telegram->inlineKeyboard($tabs));
    }

    // -------------------------------------------------------
    // Buyurtmani "Qabul qilish" — usserga xabar ketadi
    // -------------------------------------------------------
    private function acceptOrder(User $adminUser, int $orderId, int $messageId): void
    {
        $order = Order::with(['user', 'items.product'])->find($orderId);

        if (!$order) {
            $this->telegram->sendMessage($adminUser->telegram_id, "❌ Buyurtma " . $this->orderNum($orderId) . " topilmadi.");
            return;
        }

        if (in_array($order->status, ['delivered', 'cancelled'])) {
            $this->telegram->sendMessage($adminUser->telegram_id, "ℹ️ Bu buyurtma allaqachon yakunlangan.");
            return;
        }

        $order->update(['status' => 'accepted']);

        // Mahsulot nomlari
        $itemsList = $order->items->map(fn($i) => "• {$i->product->title} — {$i->quantity} ta")->join("\n");

        // Mijozga bildirishnoma (faqat telegram_id bo'lsa)
        if ($order->user && $order->user->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "✅ <b>Buyurtmangiz qabul qilindi!</b>\n\n"
                . "🆔 Buyurtma " . $this->orderNum($order->id) . "\n\n"
                . "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n"
                . "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n\n"
                . "🚚 Tez orada yetkazib beramiz!"
            );
        }

        // Admin xabarini yangilash — endi Yetkazildi + Bekor qilish tugmalari
        $newText = "🚚 <b>Buyurtma " . $this->orderNum($orderId) . " qabul qilindi</b>\n\n"
            . "👤 Mijoz: <b>{$order->user->name}</b>\n"
            . "📞 Tel: <b>{$order->phone}</b>\n";

        if ($order->user->username) {
            $newText .= "🔗 @{$order->user->username}\n";
        }

        if ($order->address) {
            $newText .= "📍 Manzil: {$order->address}\n";
        }
        if ($order->location_lat && $order->location_lng) {
            $newText .= "🗺 Lokatsiya: <a href=\"https://maps.google.com/?q={$order->location_lat},{$order->location_lng}\">Xaritada ko'rish</a>\n";
        }

        $newText .= "\n🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n";
        $newText .= "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n";
        $newText .= "💳 To'lov: <b>{$order->payment_label}</b>";

        $buttons = $this->telegram->inlineKeyboard([[
            ['text' => '✅ Yetkazildi',    'callback_data' => "admin_delivered_{$order->id}"],
            ['text' => '❌ Bekor qilish',  'callback_data' => "admin_cancel_{$order->id}"],
        ]]);

        $this->telegram->editMessage($adminUser->telegram_id, $messageId, $newText, $buttons);
    }

    // -------------------------------------------------------
    // Buyurtmani "Yetkazildi" deb belgilash
    // -------------------------------------------------------
    private function markDelivered(User $adminUser, int $orderId, int $messageId): void
    {
        $order = Order::with(['user', 'items.product'])->find($orderId);

        if (!$order) {
            $this->telegram->sendMessage($adminUser->telegram_id, "❌ Buyurtma " . $this->orderNum($orderId) . " topilmadi.");
            return;
        }

        if ($order->status === 'delivered') {
            $this->telegram->sendMessage($adminUser->telegram_id, "ℹ️ Bu buyurtma allaqachon yetkazilgan.");
            return;
        }

        $order->update(['status' => 'delivered']);

        // Mahsulot nomlari
        $itemsList = $order->items->map(fn($i) => "• {$i->product->title} — {$i->quantity} ta")->join("\n");

        // Mijozga bildirishnoma (faqat telegram_id bo'lsa)
        if ($order->user && $order->user->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "🎉 <b>Buyurtmangiz yetkazildi!</b>\n\n"
                . "🆔 Buyurtma " . $this->orderNum($order->id) . "\n\n"
                . "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n"
                . "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n\n"
                . "🙏 Xaridingiz uchun katta rahmat!\n"
                . "Yana buyurtma berish uchun /start bosing."
            );
        }

        // Admin xabarini yangilash
        $this->telegram->editMessage(
            $adminUser->telegram_id,
            $messageId,
            "✅ <b>Buyurtma " . $this->orderNum($orderId) . " yetkazildi!</b>\n\n"
            . "👤 Mijoz: <b>{$order->user->name}</b>\n"
            . "📞 Tel: <b>{$order->phone}</b>\n\n"
            . "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n"
            . "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n\n"
            . "✅ Mijozga avtomatik xabar yuborildi."
        );
    }

    // -------------------------------------------------------
    // Buyurtmani bekor qilish
    // -------------------------------------------------------
    private function cancelOrder(User $adminUser, int $orderId, int $messageId): void
    {
        $order = Order::with(['user', 'items.product'])->find($orderId);

        if (!$order) {
            $this->telegram->sendMessage($adminUser->telegram_id, "❌ Buyurtma topilmadi.");
            return;
        }

        if (in_array($order->status, ['delivered', 'cancelled'])) {
            $this->telegram->sendMessage($adminUser->telegram_id, "ℹ️ Bu buyurtma allaqachon yakunlangan.");
            return;
        }

        $order->update(['status' => 'cancelled']);

        $itemsList = $order->items->map(fn($i) => "• {$i->product->title} — {$i->quantity} ta")->join("\n");

        // Mijozga bildirishnoma (faqat telegram_id bo'lsa)
        if ($order->user && $order->user->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "❌ <b>Buyurtmangiz bekor qilindi.</b>\n\n"
                . "🆔 Buyurtma " . $this->orderNum($order->id) . "\n\n"
                . "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n"
                . "Muammo bo'lsa, admin bilan bog'laning."
            );
        }

        $this->telegram->editMessage(
            $adminUser->telegram_id,
            $messageId,
            "❌ <b>Buyurtma " . $this->orderNum($orderId) . " bekor qilindi.</b>\n\n"
            . "👤 Mijoz: <b>{$order->user->name}</b>\n"
            . "📞 Tel: <b>{$order->phone}</b>\n\n"
            . "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n"
            . "Mijozga xabar yuborildi."
        );
    }

    // -------------------------------------------------------
    // Buyurtmalar tab callback'i
    // -------------------------------------------------------
    private function handleOrdersTab(User $user, string $data): void
    {
        $parts  = explode('_', $data);
        $filter = end($parts);
        $this->showOrders($user, $filter);
    }

    // -------------------------------------------------------
    // Mahsulot qo'shish — boshlash (kategoriya tanlash)
    // -------------------------------------------------------
    private function startAddProduct(User $user): void
    {
        $chatId = $user->telegram_id;

        cache()->put("user_state_{$chatId}", 'admin_add_product_category', now()->addMinutes(30));
        cache()->put("user_data_{$chatId}", [], now()->addMinutes(30));

        $text = "➕ <b>Yangi mahsulot qo'shish</b>\n\n1️⃣ / 5️⃣ — Kategoriyani tanlang:";

        $buttons = [];
        $row = [];
        foreach (self::CATEGORIES as $key => $label) {
            $row[] = ['text' => $label, 'callback_data' => "admin_category_{$key}"];
            if (count($row) === 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if ($row) $buttons[] = $row;
        $buttons[] = [['text' => '❌ Bekor qilish', 'callback_data' => 'admin_cancel_product']];

        $this->telegram->sendMessage($chatId, $text, $this->telegram->inlineKeyboard($buttons));
    }

    // -------------------------------------------------------
    // Kategoriya tanlandi callback
    // -------------------------------------------------------
    public function handleCategorySelect(User $user, string $data, int $messageId): void
    {
        if (!$user->is_admin) return;

        $category = str_replace('admin_category_', '', $data);
        $chatId = $user->telegram_id;

        if (!isset(self::CATEGORIES[$category])) return;

        $data_cached = cache()->get("user_data_{$chatId}", []);
        $data_cached['category'] = $category;
        cache()->put("user_data_{$chatId}", $data_cached, now()->addMinutes(30));
        cache()->put("user_state_{$chatId}", 'admin_add_product_name', now()->addMinutes(30));

        $catLabel = self::CATEGORIES[$category];

        $this->telegram->editMessage(
            $chatId,
            $messageId,
            "✅ Kategoriya: <b>{$catLabel}</b>\n\n2️⃣ / 5️⃣ — Mahsulot nomini kiriting:",
            $this->telegram->inlineKeyboard([[['text' => '❌ Bekor qilish', 'callback_data' => 'admin_cancel_product']]])
        );
    }

    // -------------------------------------------------------
    // Mahsulot qo'shish — har bir qadam
    // -------------------------------------------------------
    public function handleAddProductStep(User $user, array $message, string $state): void
    {
        $chatId = $user->telegram_id;
        $data   = cache()->get("user_data_{$chatId}", []);
        $text   = $message['text'] ?? '';

        match ($state) {
            'admin_add_product_name'        => $this->stepProductName($user, $text, $data),
            'admin_add_product_price'       => $this->stepProductPrice($user, $text, $data),
            'admin_add_product_description' => $this->stepProductDescription($user, $text, $data),
            'admin_add_product_image'       => $this->stepProductImage($user, $message, $data),
            default                         => null,
        };
    }

    private function stepProductName(User $user, string $text, array $data): void
    {
        $chatId = $user->telegram_id;

        if (empty(trim($text))) {
            $this->telegram->sendMessage($chatId, "❌ Nom bo'sh bo'lmasin. Qaytadan kiriting:");
            return;
        }

        $data['title'] = trim($text);
        cache()->put("user_data_{$chatId}", $data, now()->addMinutes(30));
        cache()->put("user_state_{$chatId}", 'admin_add_product_price', now()->addMinutes(30));

        $this->telegram->sendMessage(
            $chatId,
            "✅ Nom: <b>{$data['title']}</b>\n\n3️⃣ / 5️⃣ — Narxini kiriting (so'mda):\n<i>Masalan: 25000</i>"
        );
    }

    private function stepProductPrice(User $user, string $text, array $data): void
    {
        $chatId = $user->telegram_id;
        $price  = preg_replace('/[\s,.]/', '', $text);

        if (!is_numeric($price) || (float)$price <= 0) {
            $this->telegram->sendMessage($chatId, "❌ Noto'g'ri narx. Faqat musbat raqam kiriting:");
            return;
        }

        $data['price'] = (float) $price;
        cache()->put("user_data_{$chatId}", $data, now()->addMinutes(30));
        cache()->put("user_state_{$chatId}", 'admin_add_product_description', now()->addMinutes(30));

        $this->telegram->sendMessage(
            $chatId,
            "✅ Narx: <b>" . number_format($data['price'], 0, '.', ' ') . " so'm</b>\n\n"
            . "4️⃣ / 5️⃣ — Tavsif kiriting:\n<i>O'tkazib yuborish uchun /skip yuboring</i>"
        );
    }

    private function stepProductDescription(User $user, string $text, array $data): void
    {
        $chatId = $user->telegram_id;

        $data['description'] = $text === '/skip' ? null : trim($text);
        cache()->put("user_data_{$chatId}", $data, now()->addMinutes(30));
        cache()->put("user_state_{$chatId}", 'admin_add_product_image', now()->addMinutes(30));

        $this->telegram->sendMessage(
            $chatId,
            "✅ Tavsif saqlandi.\n\n5️⃣ / 5️⃣ — Mahsulot rasmini yuboring:\n<i>O'tkazib yuborish uchun /skip yuboring</i>"
        );
    }

    private function stepProductImage(User $user, array $message, array $data): void
    {
        $chatId      = $user->telegram_id;
        $imageFileId = null;
        $text        = $message['text'] ?? '';

        if (isset($message['photo'])) {
            $photos      = $message['photo'];
            $photo       = end($photos);
            $imageFileId = $photo['file_id'];
        } elseif ($text !== '/skip') {
            $this->telegram->sendMessage($chatId, "❌ Rasm yuboring yoki /skip yozing.");
            return;
        }

        $catLabel = self::CATEGORIES[$data['category']] ?? 'Noma\'lum';

        $product = Product::create([
            'title'       => $data['title'],
            'price'       => $data['price'],
            'description' => $data['description'] ?? null,
            'image'       => $imageFileId,
            'category'    => $data['category'],
            'is_active'   => true,
        ]);

        cache()->forget("user_state_{$chatId}");
        cache()->forget("user_data_{$chatId}");

        $preview  = "✅ <b>Mahsulot muvaffaqiyatli qo'shildi!</b>\n\n";
        $preview .= "📂 Kategoriya: <b>{$catLabel}</b>\n";
        $preview .= "🏷 Nom: <b>{$product->title}</b>\n";
        $preview .= "💰 Narx: <b>" . number_format($product->price, 0, '.', ' ') . " so'm</b>\n";
        if ($product->description) {
            $preview .= "📝 Tavsif: {$product->description}\n";
        }
        $preview .= "🖼 Rasm: " . ($imageFileId ? "Qo'shildi ✅" : "Yo'q") . "\n";
        $preview .= "🆔 ID: #{$product->id}";

        if ($imageFileId) {
            $this->telegram->sendPhoto(
                $chatId,
                $imageFileId,
                $preview,
                $this->telegram->inlineKeyboard([[
                    ['text' => '🗑 O\'chirish', 'callback_data' => "admin_delete_{$product->id}"],
                ]])
            );
        } else {
            $this->telegram->sendMessage($chatId, $preview);
        }

        $this->telegram->sendMessage($chatId, "Boshqa amal tanlang:", $this->telegram->adminMenuKeyboard());
    }

    // -------------------------------------------------------
    // Mahsulot o'chirish
    // -------------------------------------------------------
    private function deleteProduct(User $user, int $productId, int $messageId): void
    {
        $product = Product::find($productId);

        if (!$product) {
            $this->telegram->sendMessage($user->telegram_id, "❌ Mahsulot topilmadi.");
            return;
        }

        $title = $product->title;
        $product->delete();

        $this->telegram->editMessage(
            $user->telegram_id,
            $messageId,
            "🗑 <b>{$title}</b> mahsuloti o'chirildi."
        );
    }

    // -------------------------------------------------------
    // Broadcast
    // -------------------------------------------------------
    private function startBroadcast(User $user): void
    {
        $chatId    = $user->telegram_id;
        $userCount = User::where('is_admin', false)->count();

        cache()->put("user_state_{$chatId}", 'admin_broadcast', now()->addMinutes(30));

        $this->telegram->sendMessage(
            $chatId,
            "📢 <b>Hammaga xabar yuborish</b>\n\n"
            . "Foydalanuvchilar soni: <b>{$userCount} ta</b>\n\n"
            . "Yuboriladigan xabar matnini kiriting:\n"
            . "<i>Bekor qilish uchun ❌ Bekor qilish bosing</i>",
            $this->telegram->cancelKeyboard()
        );
    }

    private function executeBroadcast(User $adminUser, array $message): void
    {
        $chatId  = $adminUser->telegram_id;
        $text    = $message['text'] ?? '';

        cache()->forget("user_state_{$chatId}");

        $users = User::where('is_admin', false)->get();
        $total = $users->count();

        $this->telegram->sendMessage($chatId, "⏳ Xabar yuborilmoqda...\n{$total} ta foydalanuvchiga");

        $sent   = 0;
        $failed = 0;

        $broadcastText = "📢 <b>Ibrohim Savdo:</b>\n\n" . $text;

        foreach ($users as $usr) {
            try {
                $result = $this->telegram->sendMessage($usr->telegram_id, $broadcastText);
                if ($result && ($result['ok'] ?? false)) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $this->telegram->sendMessage(
            $chatId,
            "✅ <b>Broadcast yakunlandi!</b>\n\n"
            . "✅ Muvaffaqiyatli: <b>{$sent} ta</b>\n"
            . "❌ Muvaffaqiyatsiz: <b>{$failed} ta</b>\n\n"
            . "<i>Ba'zi foydalanuvchilar botni bloklagan bo'lishi mumkin.</i>",
            $this->telegram->adminMenuKeyboard()
        );
    }

    // -------------------------------------------------------
    // Statistika
    // -------------------------------------------------------
    private function showStats(User $user): void
    {
        $chatId = $user->telegram_id;

        $totalUsers      = User::where('is_admin', false)->count();
        $newUsersToday   = User::where('is_admin', false)->whereDate('created_at', today())->count();
        $totalProducts   = Product::where('is_active', true)->count();
        $totalOrders     = Order::count();
        $pendingOrders   = Order::whereIn('status', ['pending', 'pending_payment'])->count();
        $acceptedOrders  = Order::where('status', 'accepted')->count();
        $deliveredOrders = Order::where('status', 'delivered')->count();
        $cancelledOrders = Order::where('status', 'cancelled')->count();
        $totalRevenue    = Order::where('status', 'delivered')->sum('total');
        $todayOrders     = Order::whereDate('created_at', today())->count();
        $todayRevenue    = Order::where('status', 'delivered')->whereDate('updated_at', today())->sum('total');
        $weekRevenue     = Order::where('status', 'delivered')
            ->whereBetween('updated_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('total');

        $text  = "📊 <b>Statistika</b>\n\n";
        $text .= "👥 <b>Foydalanuvchilar:</b>\n";
        $text .= "   Jami: <b>{$totalUsers} ta</b>\n";
        $text .= "   Bugun yangi: <b>{$newUsersToday} ta</b>\n\n";
        $text .= "🛍 <b>Mahsulotlar:</b>\n";
        $text .= "   Faol: <b>{$totalProducts} ta</b>\n\n";
        $text .= "📦 <b>Buyurtmalar:</b>\n";
        $text .= "   Jami: <b>{$totalOrders} ta</b>\n";
        $text .= "   Kutilmoqda: <b>{$pendingOrders} ta</b>\n";
        $text .= "   Qabul qilingan: <b>{$acceptedOrders} ta</b>\n";
        $text .= "   Yetkazildi: <b>{$deliveredOrders} ta</b>\n";
        $text .= "   Bekor qilindi: <b>{$cancelledOrders} ta</b>\n\n";
        $text .= "💰 <b>Daromad:</b>\n";
        $text .= "   Bugun: <b>" . number_format($todayRevenue, 0, '.', ' ') . " so'm</b> ({$todayOrders} buyurtma)\n";
        $text .= "   Bu hafta: <b>" . number_format($weekRevenue, 0, '.', ' ') . " so'm</b>\n";
        $text .= "   Jami: <b>" . number_format($totalRevenue, 0, '.', ' ') . " so'm</b>";

        $this->telegram->sendMessage($chatId, $text, $this->telegram->adminMenuKeyboard());
    }

    // -------------------------------------------------------
    // Foydalanuvchilar ro'yxati
    // -------------------------------------------------------
    private function showUsers(User $user): void
    {
        $chatId = $user->telegram_id;
        $total  = User::where('is_admin', false)->count();
        $users  = User::where('is_admin', false)->orderBy('created_at', 'desc')->take(20)->get();

        $text = "👥 <b>Foydalanuvchilar (so'nggi 20 ta)</b>\n";
        $text .= "Jami: <b>{$total} ta</b>\n\n";

        foreach ($users as $u) {
            $text .= "• <b>{$u->name}</b>";
            if ($u->username) {
                $text .= " (@{$u->username})";
            }
            $text .= "\n";
            $text .= "  📞 " . ($u->phone ?? 'Raqam yo\'q');
            $text .= " | 📅 {$u->created_at->format('d.m.Y')}\n\n";
        }

        $this->telegram->sendMessage($chatId, $text, $this->telegram->adminMenuKeyboard());
    }

    // -------------------------------------------------------
    // Admin boshqaruv paneli havolasi
    // -------------------------------------------------------
    private function sendAdminPanelLink(User $user): bool
    {
        $adminUrl = config('app.url') . '/admin';
        $this->telegram->sendMessage(
            $user->telegram_id,
            "🖥 <b>Admin Boshqaruv Paneli</b>\n\nBarcha buyurtmalar, mahsulotlar va foydalanuvchilarni boshqaring.",
            $this->telegram->inlineKeyboard([[
                ['text' => '🖥 Panelni ochish', 'url' => $adminUrl],
            ]])
        );
        return true;
    }
}
