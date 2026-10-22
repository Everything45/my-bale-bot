<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/bale_api.php';
require_once __DIR__ . '/../src/redis_db.php';
require_once __DIR__ . '/../src/bot_logic.php';

$input = file_get_contents('php://input');

// پاسخ به درخواست‌های مرورگر (GET) و تنظیم وب‌هوک
if ($input === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (isset($_GET['setwebhook'])) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $webhookUrl = 'https://' . $host . '/api/index.php';
        $res = apiPost('setWebhook', ['url' => $webhookUrl]);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'Webhook Setup Executed',
            'webhook_url' => $webhookUrl,
            'bale_response' => $res,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo '✅ Bale Leave Tracker Bot is running! (Modular Version)';
    exit;
}

$update = json_decode($input, true);
if (!is_array($update)) {
    finishRequest();
}

// ۱. پردازش دکمه‌های شیشه‌ای
if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    finishRequest();
}

// ۲. پردازش رویدادهای chat_member (خروج یا اخراج عضو در کانال)
if (isset($update['chat_member']) && is_array($update['chat_member'])) {
    handleChatMemberUpdate($update['chat_member']);
    finishRequest();
}

// ۳. پردازش پیام‌های دریافتی یا سرویس‌مسیج خروج (left_chat_member)
$message = $update['message'] ?? ($update['channel_post'] ?? null);
if (is_array($message)) {
    if (isset($message['left_chat_member']) && is_array($message['left_chat_member'])) {
        $chat = $message['chat'] ?? [];
        $leftUser = $message['left_chat_member'];
        $actor = $message['from'] ?? null;
        processMemberLeft($chat, $leftUser, $actor);
        finishRequest();
    }

    if (isset($message['text'])) {
        handleTextMessage($message);
        finishRequest();
    }
}

finishRequest();
