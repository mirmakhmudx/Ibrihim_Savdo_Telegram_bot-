<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopController extends Controller
{
    public function __construct(protected TelegramService $telegram) {}

    // ── Mahsulotlarni olish ───────────────────────────────
    public function getProducts(): JsonResponse
    {
        $products = Product::where('is_active', true)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($p) => [
                'id'          => $p->id,
                'title'       => $p->title,
                'price'       => (float) $p->price,
                'category'    => $p->category,
                'description' => $p->description,
                'image_url'   => $p->image
                    ? (str_starts_with($p->image, 'http')
                        ? $p->image
                        : (str_starts_with($p->image, 'product_')
                            ? url('storage/products/' . $p->image)
                            : url('shop/products/' . $p->id . '/image')))
                    : null,
                'is_active'   => $p->is_active,
            ]);

        return response()->json(['products' => $products]);
    }

    // ── Buyurtmalarni olish (telefon orqali) ──────────────
    public function getOrders(Request $request): JsonResponse
    {
        $phone = $request->query('phone');
        if (!$phone) return response()->json(['orders' => []]);

        $user = User::where('phone', $phone)->first();
        if (!$user) return response()->json(['orders' => []]);

        $orders = Order::with(['items.product'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($o) => [
                'id'             => $o->id,
                'status'         => $o->status,
                'total'          => (float) $o->total,
                'address'        => $o->address,
                'payment_method' => $o->payment_method,
                'screenshot_url' => $o->screenshot ? (strlen($o->screenshot) <= 100 ? url('storage/screenshots/' . $o->screenshot) : url('admin/orders/' . $o->id . '/screenshot')) : null,
                'created_at'     => $o->created_at->format('d.m.Y H:i'),
                'items'          => $o->items->map(fn($i) => [
                    'name'     => $i->product?->title ?? 'Mahsulot',
                    'category' => $i->product?->category ?? '',
                    'quantity' => $i->quantity,
                    'price'    => (float) $i->price,
                ]),
            ]);

        return response()->json([
            'orders'    => $orders,
            'user_name' => $user->name,
        ]);
    }

    // ── Buyurtmani bekor qilish (user) ────────────────────
    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $phone = $request->input('phone');
        $order = Order::with('user')->find($id);

        if (!$order) {
            return response()->json(['ok' => false, 'message' => 'Buyurtma topilmadi']);
        }

        // Faqat o'z buyurtmasini bekor qila oladi
        if ($phone && $order->user && $order->user->phone !== $phone) {
            return response()->json(['ok' => false, 'message' => 'Ruxsat yo\'q']);
        }

        if (!in_array($order->status, ['pending', 'pending_payment'])) {
            $msg = match($order->status) {
                'accepted'  => 'Buyurtma allaqachon qabul qilingan, bekor qilib bo\'lmaydi',
                'delivered' => 'Buyurtma yetkazilgan',
                'cancelled' => 'Buyurtma allaqachon bekor qilingan',
                default     => 'Bu buyurtmani bekor qilib bo\'lmaydi',
            };
            return response()->json(['ok' => false, 'message' => $msg]);
        }

        $order->update(['status' => 'cancelled']);

        // Adminga xabar
        $adminIds = array_filter(array_map('trim', explode(',', config('telegram.admin_ids', ''))));
        $userName = $order->user?->name ?? 'Noma\'lum';
        $adminMsg = "❌ <b>Buyurtma #{$order->id} bekor qilindi!</b>\n\n"
            . "👤 Mijoz: {$userName}\n"
            . "📞 Tel: {$order->phone}\n"
            . "💰 Summa: " . number_format($order->total, 0, '.', ' ') . " so'm\n\n"
            . "ℹ️ User o'zi bekor qildi (sayt orqali)";

        foreach ($adminIds as $adminId) {
            if ($adminId) $this->telegram->sendMessage($adminId, $adminMsg);
        }

        // Userga bot xabari
        if ($order->user && $order->user->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "✅ <b>Buyurtma #{$order->id} bekor qilindi.</b>\n\nSayt orqali o'zingiz bekor qildingiz."
            );
        }

        return response()->json(['ok' => true]);
    }

    // ── Profil yangilash (sayt → bot sync) ───────────────
    public function updateProfile(Request $request): JsonResponse
    {
        $phone      = trim($request->input('phone', ''));
        $name       = trim($request->input('name', ''));
        $telegramId = $request->input('telegram_id'); // optional

        if (!$phone) return response()->json(['ok' => false, 'message' => 'Telefon kerak']);

        // 1. Avval phone bilan qidiramiz
        $userByPhone = User::where('phone', $phone)->first();

        // 2. telegram_id bilan ham qidiramiz (agar kelgan bo'lsa)
        $userByTg = $telegramId
            ? User::where('telegram_id', $telegramId)->first()
            : null;

        if ($userByPhone && $userByTg && $userByPhone->id !== $userByTg->id) {
            // Ikki xil user — Telegram userni phone + name bilan yangilaymiz, web userni o'chiramiz
            $userByTg->update(['phone' => $phone, 'name' => $name ?: $userByTg->name]);
            // Web userni buyurtmalarini Telegram userga ko'chiramiz
            \App\Models\Order::where('user_id', $userByPhone->id)->update(['user_id' => $userByTg->id]);
            \App\Models\Favorite::where('user_id', $userByPhone->id)->update(['user_id' => $userByTg->id]);
            $userByPhone->delete();
            $user = $userByTg->fresh();
        } elseif ($userByTg) {
            // Faqat Telegram user bor — phone va name qo'shamiz
            $updates = [];
            if ($phone) $updates['phone'] = $phone;
            if ($name && $name !== $userByTg->name) $updates['name'] = $name;
            if (!empty($updates)) $userByTg->update($updates);
            $user = $userByTg->fresh();
        } elseif ($userByPhone) {
            // Faqat phone user bor — update
            $updates = [];
            if ($name && $name !== $userByPhone->name) $updates['name'] = $name;
            if ($telegramId && !$userByPhone->telegram_id) $updates['telegram_id'] = $telegramId;
            if (!empty($updates)) $userByPhone->update($updates);
            $user = $userByPhone->fresh();
        } else {
            // Hech kim topilmadi — yangi yaratamiz
            $user = User::create([
                'phone'       => $phone,
                'name'        => $name,
                'telegram_id' => $telegramId,
                'is_admin'    => false,
            ]);
        }

        if ($user->telegram_id) {
            try {
                $this->telegram->sendMessage(
                    $user->telegram_id,
                    "✅ <b>Profilingiz yangilandi!</b>\n\n"
                    . "👤 Ism: <b>{$user->name}</b>\n"
                    . "📞 Telefon: <b>{$user->phone}</b>"
                );
            } catch (\Exception $e) {}
        }

        return response()->json(['ok' => true, 'user_id' => $user->id]);
    }

    // ── Buyurtma berish ───────────────────────────────────
    public function placeOrder(Request $request): JsonResponse
    {
        $name      = trim($request->input('name', ''));
        $phone     = trim($request->input('phone', ''));
        $address   = trim($request->input('address', ''));
        $payMethod = $request->input('payment_method', 'cash');
        $total     = (float) $request->input('total', 0);
        $lat       = $request->input('lat');
        $lng       = $request->input('lng');
        $items     = $request->input('items', []);

        // Validatsiya
        if (!$name) return response()->json(['ok' => false, 'message' => 'Ism kiriting']);
        if (!$phone) return response()->json(['ok' => false, 'message' => 'Telefon kiriting']);
        if (!$address) return response()->json(['ok' => false, 'message' => 'Manzil kiriting']);
        if (empty($items)) return response()->json(['ok' => false, 'message' => 'Savatcha bo\'sh']);

        DB::beginTransaction();
        try {
            // User topish: avval telegram_id bo'lgan userni, keyin phone bo'lganini
            $user = User::where('phone', $phone)->whereNotNull('telegram_id')->first()
                ?? User::where('phone', $phone)->first();

            if (!$user) {
                // Yangi web user yaratish — telegram_id NULL
                $user = User::create([
                    'phone'       => $phone,
                    'name'        => $name,
                    'telegram_id' => null,  // ← NULL, 0 emas!
                    'is_admin'    => false,
                ]);
            } else {
                if ($user->name !== $name) {
                    $user->update(['name' => $name]);
                }
            }

            $order = Order::create([
                'user_id'        => $user->id,
                'phone'          => $phone,
                'status'         => $payMethod === 'card' ? 'pending_payment' : 'pending',
                'payment_method' => $payMethod,
                'total'          => $total,
                'address'        => $address,
                'location_lat'   => $lat ?: null,
                'location_lng'   => $lng ?: null,
            ]);

            $orderItems = [];
            foreach ($items as $item) {
                $pid     = (int)($item['pid'] ?? 0);
                $qty     = (int)($item['qty'] ?? 1);
                $product = Product::find($pid);
                if ($product && $qty > 0) {
                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $pid,
                        'quantity'   => $qty,
                        'price'      => $product->price,
                    ]);
                    $orderItems[] = "• {$product->title} × {$qty} ta";
                }
            }

            DB::commit();

            // Adminga Telegram xabari
            $adminIds  = array_filter(array_map('trim', explode(',', config('telegram.admin_ids', ''))));
            $itemsText = implode("\n", $orderItems);
            $mapLink   = ($lat && $lng) ? "\n🗺 <a href=\"https://maps.google.com/?q={$lat},{$lng}\">Xaritada ko'rish</a>" : '';
            $payLabel  = $payMethod === 'card' ? '💳 Karta (skrinshot kutilmoqda)' : '💵 Naqd pul';

            $adminMsg = "🌐 <b>YANGI WEB BUYURTMA #{$order->id}</b>\n\n"
                . "👤 Mijoz: <b>{$name}</b>\n"
                . "📞 Telefon: <b>{$phone}</b>\n"
                . "📍 Manzil: <b>{$address}</b>{$mapLink}\n"
                . "💳 To'lov: {$payLabel}\n\n"
                . "🛍 <b>Mahsulotlar:</b>\n{$itemsText}\n\n"
                . "💰 <b>Jami: " . number_format($total, 0, '.', ' ') . " so'm</b>\n\n"
                . "🔗 " . url('admin');

            $buttons = ['inline_keyboard' => [[
                ['text' => '✅ Qabul qilish',  'callback_data' => "admin_accept_{$order->id}"],
                ['text' => '❌ Bekor qilish',  'callback_data' => "admin_cancel_{$order->id}"],
            ]]];

            foreach ($adminIds as $adminId) {
                if ($adminId) $this->telegram->sendMessage($adminId, $adminMsg, $buttons);
            }

            // Userga bot xabari (agar telegram_id bo'lsa)
            if ($user->telegram_id) {
                $this->telegram->sendMessage(
                    $user->telegram_id,
                    "✅ <b>Buyurtmangiz qabul qilindi!</b>\n\n"
                    . "🆔 Buyurtma #{$order->id}\n"
                    . "💰 Jami: " . number_format($total, 0, '.', ' ') . " so'm\n"
                    . "📍 {$address}\n\n"
                    . "⏳ Tez orada yetkazib beramiz!"
                );
            }

            return response()->json(['ok' => true, 'order_id' => $order->id]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Web order failed: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['ok' => false, 'message' => 'Server xatosi: ' . $e->getMessage()]);
        }
    }

    // ── Buyurtmani tarixdan o'chirish (faqat user o'zi) ──
    public function deleteOrder(Request $request, int $id): JsonResponse
    {
        $phone = $request->input('phone');
        $order = Order::with('user')->find($id);

        if (!$order) {
            return response()->json(['ok' => false, 'message' => 'Buyurtma topilmadi']);
        }

        if ($phone && $order->user && $order->user->phone !== $phone) {
            return response()->json(['ok' => false, 'message' => 'Ruxsat yo\'q']);
        }

        // Faqat yakunlangan yoki bekor qilingan buyurtmalarni o'chirish mumkin
        if (!in_array($order->status, ['delivered', 'cancelled'])) {
            return response()->json(['ok' => false, 'message' => 'Aktiv buyurtmani o\'chirib bo\'lmaydi. Avval bekor qiling.']);
        }

        $order->items()->delete();
        $order->delete();

        return response()->json(['ok' => true]);
    }


    public function getSettings(): JsonResponse
    {
        try {
            $settings = \App\Models\SiteSetting::all_settings();
            return response()->json(['settings' => $settings]);
        } catch (\Exception $e) {
            return response()->json(['settings' => []]);
        }
    }

    // ── Sevimlilar sync (cross-device) ───────────────────
    public function getFavorites(Request $request): JsonResponse
    {
        $phone = $request->query('phone');
        if (!$phone) return response()->json(['favs' => []]);
        $user = User::where('phone', $phone)->first();
        if (!$user) return response()->json(['favs' => []]);

        $favs = \App\Models\Favorite::where('user_id', $user->id)
            ->pluck('product_id')->toArray();
        return response()->json(['favs' => $favs]);
    }

    public function syncFavorites(Request $request): JsonResponse
    {
        $phone  = $request->input('phone');
        $favIds = array_map('intval', array_filter($request->input('favs', [])));
        if (!$phone) return response()->json(['ok' => false]);

        $user = User::where('phone', $phone)->first();
        if (!$user) return response()->json(['ok' => false]);

        // REPLACE: avvalgilarni o'chirib yangilarini yozamiz
        \App\Models\Favorite::where('user_id', $user->id)->delete();
        foreach (array_unique($favIds) as $pid) {
            if ($pid > 0) {
                \App\Models\Favorite::create(['user_id' => $user->id, 'product_id' => $pid]);
            }
        }
        return response()->json(['ok' => true, 'favs' => $favIds]);
    }

    public function getNotifications(): \Illuminate\Http\JsonResponse
    {
        try {
            $last = \Illuminate\Support\Facades\DB::table('site_settings')
                ->where('key', 'last_broadcast')
                ->value('value');
            if ($last) {
                $notif = json_decode($last, true);
                return response()->json(['notifications' => [$notif]]);
            }
        } catch (\Exception $e) {}
        return response()->json(['notifications' => []]);
    }

    public function getProductImage(int $id): mixed
    {
        $product = \App\Models\Product::find($id);
        if (!$product || !$product->image) abort(404);

        // Storage faylmi?
        if (strlen($product->image) <= 100 || str_contains($product->image, 'product_')) {
            $paths = [
                storage_path('app/public/products/' . basename($product->image)),
                public_path('storage/products/' . basename($product->image)),
            ];
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    return response()->file($path, ['Cache-Control' => 'public, max-age=86400']);
                }
            }
            \Illuminate\Support\Facades\Log::warning('Product image not found: ' . $product->image);
            abort(404);
        }

        // Telegram file_id — cache qilamiz
        $cacheKey = 'product_img_' . $id;
        $fileUrl = cache()->remember($cacheKey, now()->addHours(6), function () use ($product) {
            return app(\App\Services\TelegramService::class)->getFileUrl($product->image);
        });

        if (!$fileUrl) abort(404);

        $content = @file_get_contents($fileUrl);
        if (!$content) {
            cache()->forget($cacheKey);
            abort(404);
        }
        return response($content, 200)->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }

}
