<?php

return [
    'bot_token'    => env('TELEGRAM_BOT_TOKEN', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
    'webhook_token' => env('TELEGRAM_WEBHOOK_TOKEN', ''),
    'admin_ids'    => env('TELEGRAM_ADMIN_IDS', ''),
    'card_number'  => env('TELEGRAM_CARD_NUMBER', ''),
    'card_owner'   => env('TELEGRAM_CARD_OWNER', ''),
];
