<?php

namespace App\Telegram\Handlers;

use App\Models\Cart;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use App\Services\TelegramService;

class ProductHandler
{
    const PER_PAGE = 5;

    // Kategoriyalar — AdminHandler bilan bir xil
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

    // -------------------------------------------------------
    // Kategoriyalar ro'yxatini ko'rsatish
    // -------------------------------------------------------
    public function showCategories(User $user): void
    {
        $chatId = $user->telegram_id;

        $text = "🛍 <b>Mahsulotlar</b>\n\nKategoriyani tanlang:";

        $buttons = [];
        $row = [];
        foreach (self::CATEGORIES as $key => $label) {
            // Har kategoriyada nechta mahsulot borligini ko'rish
            $count = Product::where('is_active', true)->where('category', $key)->count();
            if ($count === 0) continue; // Bo'sh kategoriyani ko'rsatmaslik

            $row[] = ['text' => "{$label} ({$count})", 'callback_data' => "cat_browse_{$key}_1"];
            if (count($row) === 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if ($row) $buttons[] = $row;

        // Qidiruv tugmasi
        $buttons[] = [['text' => '🔍 Qidirish', 'callback_data' => 'product_search']];

        if (empty($buttons)) {
            $this->telegram->sendMessage(
                $chatId,
                "📭 Hozircha mahsulotlar mavjud emas.",
                $this->telegram->mainMenuKeyboard()
            );
            return;
        }

        $this->telegram->sendMessage($chatId, $text, $this->telegram->inlineKeyboard($buttons));
    }

    // -------------------------------------------------------
    // Kategoriya bo'yicha mahsulotlar
    // -------------------------------------------------------
    public function showByCategory(User $user, string $category, int $page = 1): void
    {
        $chatId    = $user->telegram_id;
        $catLabel  = self::CATEGORIES[$category] ?? 'Mahsulotlar';

        $query = Product::query()
            ->where('is_active', true)
            ->where('category', $category)
            ->orderBy('id', 'desc');

        $total      = $query->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));

        $products = $query->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "📭 {$catLabel} bo'limida hozircha mahsulot yo'q.",
                $this->telegram->mainMenuKeyboard()
            );
            return;
        }

        $header = "📂 <b>{$catLabel}</b> | Sahifa {$page}/{$totalPages} (jami {$total} ta)";
        $this->telegram->sendMessage($chatId, $header);

        foreach ($products as $product) {
            $this->sendProductCard($user, $product);
        }

        // Pagination + kategoriyalarga qaytish
        $navButtons = [];
        $navRow = [];

        if ($page > 1) {
            $navRow[] = ['text' => '⬅️ Oldingi', 'callback_data' => "cat_browse_{$category}_" . ($page - 1)];
        }
        $navRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'page_noop'];
        if ($page < $totalPages) {
            $navRow[] = ['text' => 'Keyingi ➡️', 'callback_data' => "cat_browse_{$category}_" . ($page + 1)];
        }

        $buttons = [$navRow];
        $buttons[] = [['text' => '🔙 Kategoriyalarga', 'callback_data' => 'cat_list']];

        $this->telegram->sendMessage(
            $chatId,
            "📄 Sahifalar:",
            $this->telegram->inlineKeyboard($buttons)
        );
    }

    // -------------------------------------------------------
    // Barcha mahsulotlar (qidiruv natijalari uchun)
    // -------------------------------------------------------
    public function showProducts(User $user, int $page = 1, ?string $search = null): void
    {
        $chatId = $user->telegram_id;

        $query = Product::query()->where('is_active', true)->orderBy('id', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $total      = $query->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $products   = $query->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get();

        if ($products->isEmpty()) {
            $msg = $search
                ? "🔍 <b>\"{$search}\"</b> bo'yicha hech narsa topilmadi.\n\nBoshqa so'z bilan qidiring."
                : "📭 Hozircha mahsulotlar mavjud emas.";

            $this->telegram->sendMessage($chatId, $msg, $this->telegram->mainMenuKeyboard());
            return;
        }

        $header = $search
            ? "🔍 <b>\"{$search}\"</b> — {$total} ta natija | Sahifa {$page}/{$totalPages}"
            : "🛍 <b>Mahsulotlar</b> | Sahifa {$page}/{$totalPages} (jami {$total} ta)";

        $this->telegram->sendMessage($chatId, $header);

        foreach ($products as $product) {
            $this->sendProductCard($user, $product);
        }

        if ($totalPages > 1) {
            $navButtons = [];
            if ($page > 1) {
                $prevData = $search ? "page_search_" . ($page - 1) . "_{$search}" : "page_list_" . ($page - 1);
                $navButtons[] = ['text' => '⬅️ Oldingi', 'callback_data' => $prevData];
            }
            $navButtons[] = ['text' => "{$page} / {$totalPages}", 'callback_data' => 'page_noop'];
            if ($page < $totalPages) {
                $nextData = $search ? "page_search_" . ($page + 1) . "_{$search}" : "page_list_" . ($page + 1);
                $navButtons[] = ['text' => 'Keyingi ➡️', 'callback_data' => $nextData];
            }

            $this->telegram->sendMessage($chatId, "📄 Sahifalar:", $this->telegram->inlineKeyboard([$navButtons]));
        }
    }

    // -------------------------------------------------------
    // Bitta mahsulot kartochkasini yuborish
    // -------------------------------------------------------
    public function sendProductCard(User $user, Product $product): void
    {
        $chatId = $user->telegram_id;

        $isFav   = Favorite::where('user_id', $user->id)->where('product_id', $product->id)->exists();
        $cartQty = Cart::where('user_id', $user->id)->where('product_id', $product->id)->value('quantity') ?? 0;

        $catLabel = self::CATEGORIES[$product->category] ?? '';

        $caption  = "🏷 <b>{$product->title}</b>\n";
        if ($catLabel) {
            $caption .= "📂 {$catLabel}\n";
        }
        $caption .= "💰 Narx: <b>" . number_format($product->price, 0, '.', ' ') . " so'm</b>\n";
        if ($product->description) {
            $caption .= "\n📝 {$product->description}";
        }

        $favLabel  = $isFav ? '❤️ Sevimlilardan olib tashlash' : '🤍 Sevimlilarga qo\'shish';
        $cartLabel = $cartQty > 0 ? "🛒 Savatchada ({$cartQty} ta)" : '🛒 Savatchaga qo\'shish';

        $buttons = [
            [['text' => $favLabel, 'callback_data' => "fav_toggle_{$product->id}"]],
            [
                ['text' => '➕ Qo\'shish', 'callback_data' => "cart_add_{$product->id}"],
                ['text' => $cartLabel, 'callback_data' => "cart_view_0"],
            ],
        ];

        $markup = $this->telegram->inlineKeyboard($buttons);

        if ($product->image) {
            $this->telegram->sendPhoto($chatId, $product->image, $caption, $markup);
        } else {
            $this->telegram->sendMessage($chatId, $caption, $markup);
        }
    }

    // -------------------------------------------------------
    // Qidiruv — foydalanuvchidan so'z so'rash
    // -------------------------------------------------------
    public function promptSearch(User $user): void
    {
        $chatId = $user->telegram_id;
        cache()->put("user_state_{$chatId}", 'search', now()->addMinutes(10));

        $this->telegram->sendMessage(
            $chatId,
            "🔍 Qidirmoqchi bo'lgan mahsulot nomini kiriting:",
            $this->telegram->cancelKeyboard()
        );
    }

    // -------------------------------------------------------
    // Qidiruv — foydalanuvchi so'z kiritgandan keyin
    // -------------------------------------------------------
    public function handleSearch(User $user, array $message): void
    {
        $chatId = $user->telegram_id;
        $search = trim($message['text'] ?? '');

        cache()->forget("user_state_{$chatId}");

        if (empty($search)) {
            $this->telegram->sendMessage($chatId, "❌ Bo'sh qidiruv. Iltimos, so'z kiriting.");
            return;
        }

        $this->showProducts($user, 1, $search);
    }

    // -------------------------------------------------------
    // Callback query'larni qayta ishlash
    // -------------------------------------------------------
    public function handleCallback(User $user, string $data, int $messageId): void
    {
        $parts     = explode('_', $data, 3);
        $action    = $parts[1] ?? '';
        $productId = (int) ($parts[2] ?? 0);

        if ($action === 'view' && $productId) {
            $product = Product::find($productId);
            if ($product) {
                $this->sendProductCard($user, $product);
            } else {
                $this->telegram->sendMessage($user->telegram_id, "❌ Mahsulot topilmadi.");
            }
        }

        if ($action === 'search') {
            $this->promptSearch($user);
        }

        if ($action === 'continue') {
            $this->showCategories($user);
        }
    }

    // -------------------------------------------------------
    // Kategoriya browsing callback
    // -------------------------------------------------------
    public function handleCategoryBrowse(User $user, string $data): void
    {
        // cat_browse_{category}_{page}
        $parts    = explode('_', $data, 4);
        $category = $parts[2] ?? '';
        $page     = (int) ($parts[3] ?? 1);

        if ($category === 'list') {
            $this->showCategories($user);
            return;
        }

        $this->showByCategory($user, $category, $page);
    }

    // -------------------------------------------------------
    // Pagination callback'larini qayta ishlash
    // -------------------------------------------------------
    public function handlePagination(User $user, string $data, int $messageId): void
    {
        $parts  = explode('_', $data, 4);
        $type   = $parts[1] ?? 'list';
        $page   = (int) ($parts[2] ?? 1);
        $search = $parts[3] ?? null;

        if ($type === 'noop') return;

        $this->showProducts($user, $page, $type === 'search' ? $search : null);
    }
}
