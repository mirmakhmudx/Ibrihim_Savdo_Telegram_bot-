<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\AdminApiController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\WebhookController;

Route::get('/', fn() => file_get_contents(public_path('index-shop.html')));
Route::get('/admin', fn() => file_get_contents(public_path('admin.html')));

Route::post('/webhook/{token}', [TelegramController::class, 'webhook'])
    ->middleware('telegram.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::prefix('webhook')->group(function () {
    Route::get('/set',    [WebhookController::class, 'set']);
    Route::get('/info',   [WebhookController::class, 'info']);
    Route::get('/delete', [WebhookController::class, 'delete']);
});

Route::prefix('admin')->group(function () {
    Route::get('/orders',                         [AdminApiController::class, 'orders']);
    Route::post('/orders/{id}/accept',            [AdminApiController::class, 'acceptOrder']);
    Route::post('/orders/{id}/cancel',            [AdminApiController::class, 'cancelOrderPost']);
    Route::post('/orders/{id}/delivered',         [AdminApiController::class, 'deliveredOrder']);
    Route::delete('/orders/{id}',                 [AdminApiController::class, 'cancelOrder']);
    Route::get('/orders/{id}/screenshot',         [AdminApiController::class, 'showScreenshot']);
    Route::post('/orders/{id}/screenshot',        [AdminApiController::class, 'uploadScreenshot']);

    Route::get('/products',                       [AdminApiController::class, 'products']);
    Route::post('/products',                      [AdminApiController::class, 'addProduct']);
    Route::post('/products/{id}/update',          [AdminApiController::class, 'updateProduct']);
    Route::post('/products/{id}/image',           [AdminApiController::class, 'uploadProductImage']);
    Route::delete('/products/{id}',               [AdminApiController::class, 'deleteProduct']);

    Route::get('/users',                          [AdminApiController::class, 'users']);
    Route::post('/users/{id}/update',             [AdminApiController::class, 'updateUser']);

    Route::get('/stats',                          [AdminApiController::class, 'stats']);
    Route::post('/broadcast',                     [AdminApiController::class, 'broadcast']);
    Route::get('/settings',                       [AdminApiController::class, 'getSettings']);
    Route::post('/settings',                      [AdminApiController::class, 'saveSettings']);
});

// Shop API
Route::post('/shop/order',                        [ShopController::class, 'placeOrder']);
Route::delete('/shop/orders/{id}',                [ShopController::class, 'cancelOrder']);
Route::delete('/shop/orders/{id}/delete',         [ShopController::class, 'deleteOrder']);
Route::get('/shop/orders',                        [ShopController::class, 'getOrders']);
Route::get('/shop/settings',                      [ShopController::class, 'getSettings']);
Route::get('/shop/notifications',                 [ShopController::class, 'getNotifications']);
Route::get('/shop/products/{id}/image',           [ShopController::class, 'getProductImage']);
Route::get('/shop/products',                      [ShopController::class, 'getProducts']);
Route::post('/shop/profile',                      [ShopController::class, 'updateProfile']);
Route::get('/shop/favorites',                     [ShopController::class, 'getFavorites']);
Route::post('/shop/favorites',                    [ShopController::class, 'syncFavorites']);

// ══ DEV ONLY: baza tozalash ══
Route::get('/dev/reset-db', function () {
    if (app()->environment('production')) abort(403);
    try {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        \App\Models\Order::truncate();
        \App\Models\OrderItem::truncate();
        \App\Models\Favorite::truncate();
        \App\Models\User::truncate();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
        return response()->json(['ok'=>true,'message'=>'Users, orders, favorites tozalandi. Products va settings saqlab qolindi.']);
    } catch (\Exception $e) {
        return response()->json(['ok'=>false,'error'=>$e->getMessage()]);
    }
});

Route::get('/set-webhook', function () {
    $token   = env('TELEGRAM_BOT_TOKEN');
    $url     = env('APP_URL') . '/webhook/' . env('TELEGRAM_WEBHOOK_TOKEN');
    $api     = "https://api.telegram.org/bot{$token}";

    // 1. Webhook o'rnatamiz
    $wh = file_get_contents($api . '/setWebhook?url=' . urlencode($url));

    // 2. Bot klaviatura menyu - Profil olib tashlangan
    $commands = [
        ['command' => 'start', 'description' => "Botni boshlash"],
    ];
    $cmdRes = file_get_contents($api . '/setMyCommands', false,
        stream_context_create(['http'=>[
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => json_encode(['commands' => $commands]),
        ]])
    );

    return response()->json([
        'webhook' => json_decode($wh),
        'commands' => json_decode($cmdRes),
        'note' => 'Foydalanuvchilar /start bossa yangi menyu korinadi',
    ]);
});
