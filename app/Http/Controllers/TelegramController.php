<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\TelegramService;
use App\Telegram\Handlers\AdminHandler;
use App\Telegram\Handlers\CartHandler;
use App\Telegram\Handlers\FavoriteHandler;
use App\Telegram\Handlers\OrderHandler;
use App\Telegram\Handlers\ProductHandler;
use App\Telegram\Handlers\StartHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    protected TelegramService  $telegram;
    protected StartHandler     $startHandler;
    protected ProductHandler   $productHandler;
    protected CartHandler      $cartHandler;
    protected OrderHandler     $orderHandler;
    protected AdminHandler     $adminHandler;
    protected FavoriteHandler  $favoriteHandler;

    public function __construct()
    {
        $this->telegram        = new TelegramService();
        $this->startHandler    = new StartHandler($this->telegram);
        $this->productHandler  = new ProductHandler($this->telegram);
        $this->cartHandler     = new CartHandler($this->telegram);
        $this->orderHandler    = new OrderHandler($this->telegram);
        $this->adminHandler    = new AdminHandler($this->telegram);
        $this->favoriteHandler = new FavoriteHandler($this->telegram);
    }

    public function webhook(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $update = $request->all();
            Log::info('WEBHOOK', ['type' => array_keys($update)]);

            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
            }
        } catch (\Throwable $e) {
            Log::error('WEBHOOK XATO: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $from   = $message['from'];
        $text   = $message['text'] ?? '';
        $user   = $this->findOrCreateUser($from, $chatId);
        $state  = cache()->get("user_state_{$chatId}");

        Log::info('MESSAGE', ['text' => $text, 'state' => $state, 'is_admin' => $user->is_admin]);

        if ($text === '/start') {
            $this->startHandler->handle($user);
            return;
        }

        if ($text === '❌ Bekor qilish') {
            cache()->forget("user_state_{$chatId}");
            cache()->forget("user_data_{$chatId}");
            $this->startHandler->handle($user);
            return;
        }

        // Admin rasm yuklash
        if ($state === 'admin_add_product_image' && $user->is_admin) {
            $this->adminHandler->handleAddProductStep($user, $message, $state);
            return;
        }

        // Karta to'lov screenshoti
        if ($state === 'checkout_awaiting_screenshot' && isset($message['photo'])) {
            $this->orderHandler->handlePaymentScreenshot($user, $message);
            return;
        }

        // Lokatsiya
        if (isset($message['location'])) {
            if ($state === 'checkout_awaiting_location') {
                $this->orderHandler->handleState($user, $message, $state);
            }
            return;
        }

        // Kontakt
        if (isset($message['contact'])) {
            if ($state === 'checkout_awaiting_phone') {
                $this->orderHandler->handleState($user, $message, $state);
            }
            return;
        }

        // Admin holatlari
        if ($state && str_starts_with($state, 'admin_') && $user->is_admin) {
            $this->adminHandler->handleState($user, $message, $state);
            return;
        }

        // Checkout holatlari
        if (in_array($state, ['checkout_awaiting_phone', 'checkout_awaiting_location'])) {
            $this->orderHandler->handleState($user, $message, $state);
            return;
        }
        if ($state === 'checkout_awaiting_address') {
            $this->orderHandler->handleAddressInput($user, $message, $state);
            return;
        }

        if ($state === 'search') {
            $this->productHandler->handleSearch($user, $message);
            return;
        }

        // Admin menu
        if ($user->is_admin && $this->adminHandler->handleMessage($user, $message)) return;

        // User menu
        switch ($text) {
            case "🌐 Saytga o'tish":
                $this->showWebsiteLink($user); break;
            case '🆘 Yordam':
                $this->showHelp($user); break;
            case '📢 Yangiliklar':
                $this->showNews($user); break;
            case '👤 Profil':
                $this->showProfile($user); break;
            default:
                $this->telegram->sendMessage(
                    $chatId,
                    "❓ Menyu tugmalaridan foydalaning.",
                    $user->is_admin ? $this->telegram->adminMenuKeyboard() : $this->telegram->mainMenuKeyboard()
                );
        }
    }

    private function showWebsiteLink(User $user): void
    {
        $websiteUrl = config('app.url');
        $this->telegram->sendMessage(
            $user->telegram_id,
            "🌐 <b>Mahsulotlarni ko'rish uchun veb-saytimizga o'ting!</b>\n\n"
            . "✅ Barcha mahsulotlar kategoriyalar bo'yicha\n"
            . "✅ Qidiruv, narx filtri\n"
            . "✅ Sevimlilar va buyurtmalar tarixi\n\n"
            . "👇 Bosing:",
            $this->telegram->inlineKeyboard([[
                ['text' => '🛒 Saytga o\'tish', 'url' => $websiteUrl],
            ]])
        );
    }

    private function showHelp(User $user): void
    {
        $phone   = \App\Models\SiteSetting::get('shop_phone', '+998 90 000 00 00');
        $address = \App\Models\SiteSetting::get('shop_address', 'Toshkent');
        $hours   = \App\Models\SiteSetting::get('shop_hours', '08:00-22:00');
        $about   = \App\Models\SiteSetting::get('shop_about', 'Sifatli mahsulotlar.');
        $lat     = \App\Models\SiteSetting::get('shop_lat', '');
        $lng     = \App\Models\SiteSetting::get('shop_lng', '');

        $text = "🆘 <b>Yordam va ma'lumot</b>\n\n"
            . "🏪 <b>Do'kon haqida:</b>\n"
            . "{$about}\n\n"
            . "📋 <b>Qanday buyurtma berish:</b>\n"
            . "1️⃣ 🌐 <b>Saytga o'tish</b> — mahsulotlarni ko'ring\n"
            . "2️⃣ Mahsulotni savatga qo'shing\n"
            . "3️⃣ Buyurtma bering — ism, telefon, manzil kiriting\n"
            . "4️⃣ To'lov usulini tanlang (naqd yoki karta)\n"
            . "5️⃣ Admin tasdiqlaydi → yetkazib beradi\n\n"
            . "📦 <b>Buyurtma holatlari:</b>\n"
            . "⏳ Kutilmoqda → ✅ Qabul qilindi → 🚚 Yetkazildi\n\n"
            . "⏰ <b>Ish vaqti:</b> {$hours}\n"
            . "📍 <b>Manzil:</b> {$address}\n"
            . "📞 <b>Telefon:</b> {$phone}";

        $rows = [[['text' => "🌐 Saytga o'tish", 'url' => config('app.url')]]];
        if ($lat && $lng) {
            $rows[] = [['text' => "🗺 Xaritada ko'rish", 'url' => "https://maps.google.com/?q={$lat},{$lng}"]];
        }
        $this->telegram->sendMessage($user->telegram_id, $text, $this->telegram->inlineKeyboard($rows));
    }

    private function showNews(User $user): void
    {
        try {
            $last = \Illuminate\Support\Facades\DB::table('site_settings')
                ->where('key', 'last_broadcast')->value('value');
            if ($last) {
                $notif = json_decode($last, true);
                $title = $notif['title'] ?? '📢 Yangilik';
                $text  = $notif['text']  ?? '';
                $time  = $notif['time']  ?? '';
                $msg = "📢 <b>So'nggi yangilik</b>\n\n"
                    . "<b>{$title}</b>\n\n"
                    . $text
                    . ($time ? "\n\n⏰ <i>{$time}</i>" : "");
                $this->telegram->sendMessage(
                    $user->telegram_id, $msg,
                    $this->telegram->inlineKeyboard([[['text' => "🌐 Saytga o'tish", 'url' => config('app.url')]]])
                );
                return;
            }
        } catch (\Exception $e) {}
        $this->telegram->sendMessage(
            $user->telegram_id,
            "📢 <b>Yangiliklar</b>\n\nHozircha yangilik yo'q. Tez orada yangiliklar e'lon qilinadi!",
            $this->telegram->inlineKeyboard([[['text' => "🌐 Saytga o'tish", 'url' => config('app.url')]]])
        );
    }

    private function showProfile(User $user): void
    {
        $ordersCount = Order::where('user_id', $user->id)->count();
        $activeOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'pending_payment', 'accepted'])
            ->count();
        $totalSpent = Order::where('user_id', $user->id)
            ->where('status', 'delivered')
            ->sum('total');

        $text  = "👤 <b>Profilingiz</b>\n\n";
        $text .= "📛 Ism: <b>{$user->name}</b>\n";
        if ($user->username) $text .= "🔗 @{$user->username}\n";
        $text .= "📞 Telefon: <b>" . ($user->phone ?? "Kiritilmagan") . "</b>\n\n";
        $text .= "📦 Jami buyurtmalar: <b>{$ordersCount} ta</b>\n";
        if ($activeOrders > 0) {
            $text .= "🚚 Faol buyurtmalar: <b>{$activeOrders} ta</b>\n";
        }
        $text .= "💰 Jami xarid: <b>" . number_format($totalSpent, 0, '.', ' ') . " so'm</b>\n";
        $text .= "📅 Ro'yxatdan: <b>{$user->created_at->format('d.m.Y')}</b>\n\n";
        $text .= "🔗 Saytdagi profilingiz orqali ma'lumotlaringizni yangilashingiz mumkin.";

        $this->telegram->sendMessage(
            $user->telegram_id,
            $text,
            $this->telegram->inlineKeyboard([[
                ['text' => '📦 Buyurtmalarim', 'callback_data' => 'user_orders'],
                ['text' => '❤️ Sevimlilar', 'callback_data' => 'fav_list'],
            ]])
        );
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $chatId    = $callbackQuery['from']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $data      = $callbackQuery['data'];
        $user      = $this->findOrCreateUser($callbackQuery['from'], $chatId);

        $this->telegram->answerCallbackQuery($callbackQuery['id']);

        Log::info('CALLBACK', ['data' => $data, 'is_admin' => $user->is_admin]);

        if (str_starts_with($data, 'admin_category_'))   { $this->adminHandler->handleCategorySelect($user, $data, $messageId); return; }
        if ($data === 'admin_cancel_product')             { cache()->forget("user_state_{$user->telegram_id}"); cache()->forget("user_data_{$user->telegram_id}"); $this->telegram->editMessage($chatId, $messageId, "❌ Bekor qilindi."); $this->startHandler->handle($user); return; }
        if (str_starts_with($data, 'admin_'))             { $this->adminHandler->handleCallback($user, $data, $messageId); return; }
        if (str_starts_with($data, 'cat_'))               { $data === 'cat_list' ? $this->productHandler->showCategories($user) : $this->productHandler->handleCategoryBrowse($user, $data); return; }
        if ($data === 'product_search')                   { $this->productHandler->promptSearch($user); return; }
        if (str_starts_with($data, 'pay_'))               { $this->orderHandler->handlePaymentMethod($user, $data, $messageId); return; }
        if (str_starts_with($data, 'order_'))             { $this->orderHandler->handleCallback($user, $data, $messageId); return; }
        if (str_starts_with($data, 'cart_'))              { $this->cartHandler->handleCallback($user, $data, $messageId); return; }
        if (str_starts_with($data, 'fav_'))               { $this->favoriteHandler->handleCallback($user, $data, $messageId); return; }
        if (str_starts_with($data, 'product_'))           { $this->productHandler->handleCallback($user, $data, $messageId); return; }
        if (str_starts_with($data, 'page_'))              { $this->productHandler->handlePagination($user, $data, $messageId); return; }
        if ($data === 'info_about')                       { $this->startHandler->handleAbout($user, $messageId); return; }
        if ($data === 'info_back')                        { $this->startHandler->handle($user); return; }
        if ($data === 'user_orders')                      { $this->orderHandler->showOrders($user); return; }
        if ($data === 'fav_list' || str_starts_with($data, 'fav_'))  { $this->favoriteHandler->handleCallback($user, $data, $messageId); return; }
    }

    private function findOrCreateUser(array $from, int $chatId): User
    {
        $adminIds = array_map('intval', array_filter(explode(',', config('telegram.admin_ids', ''))));
        $isAdmin  = in_array($chatId, $adminIds);

        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $user = User::firstOrCreate(
            ['telegram_id' => $chatId],
            ['name' => $name ?: 'Foydalanuvchi', 'username' => $from['username'] ?? null, 'is_admin' => $isAdmin]
        );

        if ($isAdmin && !$user->is_admin) {
            $user->update(['is_admin' => true]);
        }

        return $user;
    }
}
