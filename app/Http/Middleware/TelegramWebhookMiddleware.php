<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $receivedToken  = $request->route('token');
        $expectedToken  = config('telegram.webhook_token');

        if (empty($expectedToken) || $receivedToken !== $expectedToken) {
            Log::warning('⚠️ Webhook: noto\'g\'ri token', [
                'ip'    => $request->ip(),
                'token' => $receivedToken,
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Faqat Telegram IP'laridan kelgan so'rovlarga ruxsat
        // (ixtiyoriy — ishlab chiqarishda yoqishingiz mumkin)
        // if (!$this->isTelegramIp($request->ip())) {
        //     return response()->json(['error' => 'Forbidden'], 403);
        // }

        return $next($request);
    }

    // Telegram rasmiy IP oralig'ini tekshirish (ixtiyoriy)
    private function isTelegramIp(string $ip): bool
    {
        $telegramRanges = [
            '149.154.160.0/20',
            '91.108.4.0/22',
        ];

        foreach ($telegramRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $ip     = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask   = -1 << (32 - (int)$bits);
        $subnet &= $mask;
        return ($ip & $mask) === $subnet;
    }
}
