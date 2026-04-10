<?php


namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->token = config('telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}";
    }

    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $extra = []): ?array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra);

        if (!empty($replyMarkup)) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('sendMessage', $params);
    }

    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $replyMarkup = []): ?array
    {
        try {
            $req = Http::timeout(8);
            // Agar mahalliy fayl yo'li bo'lsa - multipart yuboramiz
            if (file_exists($photo)) {
                $attach = $req->attach('photo', file_get_contents($photo), basename($photo));
                $body = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
                if (!empty($replyMarkup)) $body['reply_markup'] = json_encode($replyMarkup);
                $response = $attach->post("{$this->baseUrl}/sendPhoto", $body);
            } else {
                // URL sifatida yubor
                $params = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML'];
                if (!empty($replyMarkup)) $params['reply_markup'] = json_encode($replyMarkup);
                $response = $req->post("{$this->baseUrl}/sendPhoto", $params);
            }
            if ($response->successful()) return $response->json();
            Log::warning("Telegram API [sendPhoto] xato: " . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error("Telegram sendPhoto failed: " . $e->getMessage());
            return null;
        }
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, array $replyMarkup = []): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if (!empty($replyMarkup)) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('editMessageText', $params);
    }

    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, array $replyMarkup = []): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if (!empty($replyMarkup)) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('editMessageCaption', $params);
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, array $replyMarkup): ?array
    {
        return $this->request('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($replyMarkup),
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): ?array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): ?array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public function sendDocument(int|string $chatId, string $document, string $caption = ''): ?array
    {
        return $this->request('sendDocument', [
            'chat_id' => $chatId,
            'document' => $document,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    public function forwardMessage(int|string $toChatId, int|string $fromChatId, int $messageId): ?array
    {
        return $this->request('forwardMessage', [
            'chat_id' => $toChatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ]);
    }

    public function setWebhook(string $url): ?array
    {
        return $this->request('setWebhook', ['url' => $url]);
    }

    public function setMyCommands(array $commands, string $scope = 'default'): ?array
    {
        return $this->request('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }

    public function setChatMenuButton(int|string $chatId, string $type = 'commands'): ?array
    {
        return $this->request('setChatMenuButton', [
            'chat_id'     => $chatId,
            'menu_button' => json_encode(['type' => $type]),
        ]);
    }

    public function deleteWebhook(): ?array
    {
        return $this->request('deleteWebhook', []);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->request('getWebhookInfo', []);
    }

    // ── Keyboard builders ──────────────────────────────────

    public function mainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                ["🌐 Saytga o'tish", '🆘 Yordam', '📢 Yangiliklar'],
            ],
            'resize_keyboard' => true,
            'persistent' => true,
        ];
    }

    public function adminMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                ['📦 Buyurtmalar', "➕ Mahsulot qo'shish"],
                ['📢 Hammaga xabar', '📊 Statistika'],
                ['👥 Foydalanuvchilar', '🖥 Admin boshqaruv paneli'],
            ],
            'resize_keyboard' => true,
            'persistent' => true,
        ];
    }

    public function cancelKeyboard(): array
    {
        return [
            'keyboard' => [['❌ Bekor qilish']],
            'resize_keyboard' => true,
        ];
    }

    public function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    public function inlineKeyboard(array $buttons): array
    {
        return ['inline_keyboard' => $buttons];
    }

    // ── Telegram file URL olish ───────────────────────────
    public function getFileUrl(string $fileId): ?string
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/getFile", ['file_id' => $fileId]);
            if ($response->successful()) {
                $filePath = $response->json('result.file_path');
                if ($filePath) {
                    return "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
                }
            }
        } catch (\Exception $e) {
            Log::warning("getFileUrl failed for {$fileId}: " . $e->getMessage());
        }
        return null;
    }

    // ── Internal HTTP request ──────────────────────────────

    protected function request(string $method, array $params): ?array
    {
        try {
            $response = Http::timeout(8)->post("{$this->baseUrl}/{$method}", $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("Telegram API [{$method}] xato: " . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error("Telegram request [{$method}] failed: " . $e->getMessage());
            return null;
        }
    }
}
