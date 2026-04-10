<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    public function __construct(protected TelegramService $telegram) {}

    public function set(): JsonResponse
    {
        $token      = config('telegram.webhook_token');
        $appUrl     = config('app.url');
        $webhookUrl = "{$appUrl}/webhook/{$token}";

        $result = $this->telegram->setWebhook($webhookUrl);

        $this->telegram->setMyCommands([
            ['command' => 'start', 'description' => "🏪 Do'konni ochish"],
        ]);

        return response()->json([
            'webhook_url' => $webhookUrl,
            'result'      => $result,
            'commands'    => 'set',
        ]);
    }

    public function info(): JsonResponse
    {
        $result = $this->telegram->getWebhookInfo();
        return response()->json($result);
    }

    public function delete(): JsonResponse
    {
        $result = $this->telegram->deleteWebhook();
        return response()->json($result);
    }
}
