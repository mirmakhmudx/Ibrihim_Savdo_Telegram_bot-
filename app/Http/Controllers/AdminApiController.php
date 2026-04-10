<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\SiteSetting;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminApiController extends Controller
{
    public function __construct(protected TelegramService $telegram) {}

    // ── Buyurtmalar ───────────────────────────────────────
    public function orders(): JsonResponse
    {
        try {
            $orders = Order::with(['user', 'items.product'])
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($order) {
                    return [
                        'id'             => $order->id,
                        'status'         => $order->status,
                        'total'          => (float)($order->total ?? 0),
                        'address'        => $order->address ?? '',
                        'location_lat'   => $order->location_lat,
                        'location_lng'   => $order->location_lng,
                        'payment_method' => $order->payment_method ?? 'cash',
                        'screenshot_url' => $order->screenshot
                            ? url("/admin/orders/{$order->id}/screenshot")
                            : null,
                        'created_at'     => $order->created_at?->toIso8601String() ?? '',
                        'user_name'      => $order->user?->name ?? "Noma'lum",
                        'user_phone'     => $order->phone ?? $order->user?->phone ?? '—',
                        'user_telegram'  => $order->user?->telegram_id,
                        'items'          => $order->items->map(fn($i) => [
                            'id'       => $i->product_id,
                            'name'     => $i->product?->title ?? 'Mahsulot',
                            'category' => $i->product?->category ?? '',
                            'quantity' => $i->quantity,
                            'price'    => (float)($i->price ?? 0),
                        ])->toArray(),
                    ];
                });

            return response()->json(['orders' => $orders]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('orders() xato: '.$e->getMessage().' '.$e->getFile().':'.$e->getLine());
            return response()->json(['orders' => [], 'error' => $e->getMessage()]);
        }
    }

    // Qabul qilish
    private function notifyAdmins(string $text): void
    {
        $adminIds = array_map('intval', array_filter(explode(',', config('telegram.admin_ids', ''))));
        foreach ($adminIds as $adminId) {
            try { $this->telegram->sendMessage($adminId, $text); } catch (\Exception $e) {}
        }
    }

    private function orderNum(int $id): string { return 'N' . str_pad($id, 5, '0', STR_PAD_LEFT); }

    public function acceptOrder(int $id): JsonResponse
    {
        $order = Order::with(['user', 'items.product'])->find($id);
        if (!$order) return response()->json(['ok' => false, 'message' => 'Topilmadi'], 404);

        $order->update(['status' => 'accepted']);
        $itemsList = $order->items->map(fn($i) => "• ".($i->product?->title ?? 'Mahsulot')." — {$i->quantity} ta")->join("\n");

        // Userga xabar
        if ($order->user?->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "✅ <b>Buyurtmangiz qabul qilindi!</b>\n\n"
                . "🆔 Buyurtma " . $this->orderNum($order->id) . "\n\n"
                . "🛍 <b>Mahsulotlar:</b>\n{$itemsList}\n\n"
                . "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n\n"
                . "🚚 Tez orada yetkazib beramiz!\n🙏 Rahmat!"
            );
        }

        // Admin Telegram ga ham xabar (saytdan bajarilib)
        $this->notifyAdmins(
            "🌐 <b>Saytdan qabul qilindi</b>\n"
            . "📦 Buyurtma " . $this->orderNum($order->id) . "\n"
            . "👤 " . ($order->user?->name ?? '—') . " | 📞 " . ($order->phone ?? '—') . "\n"
            . "💰 " . number_format($order->total, 0, '.', ' ') . " so'm\n"
            . "🛍 {$itemsList}"
        );

        return response()->json(['ok' => true]);
    }

    // Yetkazildi
    public function deliveredOrder(int $id): JsonResponse
    {
        $order = Order::with(['user', 'items.product'])->find($id);
        if (!$order) return response()->json(['ok' => false, 'message' => 'Topilmadi'], 404);

        $order->update(['status' => 'delivered']);
        $itemsList = $order->items->map(fn($i) => "• ".($i->product?->title ?? 'Mahsulot')." — {$i->quantity} ta")->join("\n");

        // Userga xabar
        if ($order->user?->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "🎉 <b>Buyurtmangiz yetkazildi!</b>\n\n"
                . "🆔 Buyurtma " . $this->orderNum($order->id) . "\n"
                . "💰 Jami: <b>" . number_format($order->total, 0, '.', ' ') . " so'm</b>\n\n"
                . "🙏 Xaridingiz uchun katta rahmat!\nQayta buyurtma bering: /start"
            );
        }

        // Admin Telegram ga ham xabar
        $this->notifyAdmins(
            "🌐 <b>Saytdan yetkazildi deb belgilandi</b>\n"
            . "✅ Buyurtma " . $this->orderNum($order->id) . "\n"
            . "👤 " . ($order->user?->name ?? '—') . " | 📞 " . ($order->phone ?? '—') . "\n"
            . "💰 " . number_format($order->total, 0, '.', ' ') . " so'm\n"
            . "🛍 {$itemsList}"
        );

        return response()->json(['ok' => true]);
    }

    // Bekor qilish (POST)
    public function cancelOrderPost(int $id): JsonResponse
    {
        return $this->cancelOrder($id);
    }

    // Bekor qilish (DELETE)
    public function cancelOrder(int $id): JsonResponse
    {
        $order = Order::with('user')->find($id);
        if (!$order) return response()->json(['ok' => false, 'message' => 'Topilmadi'], 404);

        $order->update(['status' => 'cancelled']);

        if ($order->user?->telegram_id) {
            $this->telegram->sendMessage(
                $order->user->telegram_id,
                "❌ <b>Buyurtmangiz bekor qilindi.</b>\n\n"
                . "🆔 Buyurtma #" . str_pad($order->id, 5, '0', STR_PAD_LEFT) . "\n\n"
                . "Savollar uchun admin bilan bog'laning."
            );
        }

        return response()->json(['ok' => true]);
    }

    // Skrinshot ko'rsatish (proxy)
    public function showScreenshot(int $orderId): mixed
    {
        $order = Order::find($orderId);
        if (!$order || !$order->screenshot) abort(404);

        // Storage faylmi? (qisqa nom)
        if (strlen($order->screenshot) <= 100) {
            $path = storage_path('app/public/screenshots/' . $order->screenshot);
            if (file_exists($path)) {
                return response()->file($path);
            }
            abort(404);
        }

        // Telegram file_id — cache bilan
        $cacheKey = 'screenshot_url_' . $orderId;
        $fileUrl  = cache()->remember($cacheKey, now()->addHours(6), function () use ($order) {
            return $this->telegram->getFileUrl($order->screenshot);
        });

        if (!$fileUrl) { cache()->forget($cacheKey); abort(404); }

        $content = @file_get_contents($fileUrl);
        if (!$content) { cache()->forget($cacheKey); abort(404); }

        return response($content, 200)
            ->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    // Skrinshot yuklash (web dan)
    public function uploadScreenshot(Request $request, int $orderId): JsonResponse
    {
        $order = Order::find($orderId);
        if (!$order) return response()->json(['ok' => false, 'message' => 'Buyurtma topilmadi'], 404);

        if (!$request->hasFile('screenshot')) {
            return response()->json(['ok' => false, 'message' => 'Fayl yuklanmadi'], 400);
        }

        $file     = $request->file('screenshot');
        $filename = 'order_' . $orderId . '_' . time() . '.' . $file->getClientOriginalExtension();
        $file->storeAs('screenshots', $filename, 'public');
        $order->update(['screenshot' => $filename]);

        $adminIds = array_filter(array_map('trim', explode(',', config('telegram.admin_ids', ''))));
        foreach ($adminIds as $adminId) {
            $this->telegram->sendMessage($adminId,
                "📷 <b>Karta to'lov skrinshotи yuklandi!</b>\n\n"
                . "📦 Buyurtma #" . str_pad($order->id, 5, '0', STR_PAD_LEFT) . "\n"
                . "💰 Summa: " . number_format($order->total, 0, '.', ' ') . " so'm\n\n"
                . "🌐 Admin panelda tekshiring."
            );
        }

        return response()->json(['ok' => true, 'url' => url("admin/orders/{$orderId}/screenshot")]);
    }

    // ── Mahsulotlar ───────────────────────────────────────
    public function products(): JsonResponse
    {
        $products = Product::orderByDesc('created_at')->get();
        return response()->json(['products' => $products]);
    }

    public function addProduct(Request $request): JsonResponse
    {
        $product = Product::create([
            'title'       => $request->input('title'),
            'price'       => $request->input('price'),
            'category'    => $request->input('category'),
            'description' => $request->input('description'),
            'is_active'   => $request->boolean('is_active', true),
        ]);
        return response()->json(['ok' => true, 'id' => $product->id]);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) return response()->json(['ok' => false, 'message' => 'Topilmadi'], 404);

        $product->update([
            'title'       => $request->input('title', $product->title),
            'price'       => $request->input('price', $product->price),
            'category'    => $request->input('category', $product->category),
            'description' => $request->input('description', $product->description),
            'is_active'   => $request->boolean('is_active', $product->is_active),
        ]);

        return response()->json(['ok' => true, 'id' => $product->id]);
    }

    public function deleteProduct(int $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) return response()->json(['ok' => false]);

        // Rasmni o'chiramiz (agar local fayl bo'lsa)
        if ($product->image && str_starts_with($product->image, 'product_')) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $product->image);
        }

        $product->delete();
        return response()->json(['ok' => true]);
    }

    public function uploadProductImage(Request $request, int $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (!$product) return response()->json(['ok' => false, 'message' => 'Topilmadi'], 404);

        if (!$request->hasFile('image')) {
            return response()->json(['ok' => false, 'message' => 'Rasm yuklanmadi'], 400);
        }

        $file     = $request->file('image');
        $filename = 'product_' . $productId . '_' . time() . '.' . $file->getClientOriginalExtension();
        $file->storeAs('products', $filename, 'public');
        $product->update(['image' => $filename]);

        return response()->json(['ok' => true, 'url' => url('storage/products/' . $filename)]);
    }

    // ── Foydalanuvchilar ──────────────────────────────────
    public function users(): JsonResponse
    {
        $users = User::withCount('orders')
            ->withSum('orders as total_spent', 'total')
            ->where('is_admin', false)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($u) => [
                'id'           => $u->id,
                'name'         => $u->name,
                'username'     => $u->username,
                'phone'        => $u->phone,
                'telegram_id'  => $u->telegram_id,
                'orders_count' => $u->orders_count,
                'total_spent'  => $u->total_spent ?? 0,
                'created_at'   => $u->created_at?->format('Y-m-d'),
            ]);

        return response()->json(['users' => $users]);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) return response()->json(['ok' => false]);

        $user->update([
            'name'  => $request->input('name', $user->name),
            'phone' => $request->input('phone', $user->phone),
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Statistika ────────────────────────────────────────
    public function stats(): JsonResponse
    {
        return response()->json([
            'total_orders'     => Order::count(),
            'today_orders'     => Order::whereDate('created_at', today())->count(),
            'total_revenue'    => Order::where('status', 'delivered')->sum('total'),
            'today_revenue'    => Order::where('status', 'delivered')->whereDate('created_at', today())->sum('total'),
            'total_users'      => User::where('is_admin', false)->count(),
            'pending_orders'   => Order::whereIn('status', ['pending', 'pending_payment'])->count(),
            'accepted_orders'  => Order::where('status', 'accepted')->count(),
            'delivered_orders' => Order::where('status', 'delivered')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
        ]);
    }

    // ── Sozlamalar ────────────────────────────────────────
    public function getSettings(): JsonResponse
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('site_settings')) {
                return response()->json(['settings' => []]);
            }
            $settings = \Illuminate\Support\Facades\DB::table('site_settings')->pluck('value', 'key');
            return response()->json(['settings' => $settings]);
        } catch (\Exception $e) {
            return response()->json(['settings' => []]);
        }
    }

    public function saveSettings(Request $request): JsonResponse
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('site_settings')) {
                \Illuminate\Support\Facades\DB::statement("CREATE TABLE IF NOT EXISTS site_settings (
                    id integer primary key autoincrement,
                    `key` varchar(255) not null unique,
                    `value` text,
                    created_at timestamp,
                    updated_at timestamp
                )");
            }
            $keys = ['shop_name','shop_address','shop_phone','shop_hours','shop_card','shop_card_owner','shop_lat','shop_lng','shop_about'];
            foreach ($keys as $key) {
                $val = $request->input($key);
                if ($val !== null) {
                    \Illuminate\Support\Facades\DB::table('site_settings')
                        ->updateOrInsert(['key' => $key], ['value' => $val, 'updated_at' => now()]);
                }
            }
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Broadcast ─────────────────────────────────────────
    public function broadcast(Request $request): JsonResponse
    {
        $message = $request->input('message');
        $title   = $request->input('title', '📢 Ibrohim Savdo yangiligi');
        if (!$message) return response()->json(['ok' => false]);

        // Rasm bor bo'lsa saqlaymiz
        $imageFileId = null;
        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'bc_' . time() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('broadcasts', $filename, 'public');
            $imageFileId = $filename;
        }

        // Notifications jadvaliga saqlash (sayt bildirishnomalari uchun)
        $title = $request->input('title', '📢 Ibrohim Savdo yangiligi');
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('notifications')) {
                \App\Models\Notification::create([
                    'title' => $title ?: '📢 Yangilik',
                    'text'  => $message,
                    'image' => $imageFileId,
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Notification save: '.$e->getMessage());
        }

        $users = User::where('is_admin', false)->get();
        $sent  = 0;
        $caption = "📢 <b>" . ($title ?: 'Ibrohim Savdo xabari') . "</b>\n\n" . $message;

        foreach ($users as $user) {
            if (!$user->telegram_id) continue;
            if ($imageFileId) {
                // Rasm bilan yuborish - fayl yo'li bilan (multipart)
                $filePath = storage_path('app/public/broadcasts/' . $imageFileId);
                $result = $this->telegram->sendPhoto(
                    $user->telegram_id,
                    $filePath,
                    $caption
                );
            } else {
                $result = $this->telegram->sendMessage(
                    $user->telegram_id,
                    $caption
                );
            }
            if ($result) $sent++;
        }
        try {
            \Illuminate\Support\Facades\DB::table('site_settings')
                ->updateOrInsert(
                    ['key' => 'last_broadcast'],
                    ['value' => json_encode([
                        'title' => $title,
                        'text'  => $message,
                        'time'  => now()->format('d.m.Y H:i'),
                        'image' => $imageFileId ? url('storage/broadcasts/' . $imageFileId) : null,
                    ]), 'updated_at' => now()]
                );
        } catch (\Exception $e) {}

        return response()->json(['ok' => true, 'sent' => $sent]);
    }
}
