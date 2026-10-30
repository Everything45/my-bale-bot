<?php declare(strict_types=1);
// api/index.php

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/redis_core.php';
require_once __DIR__ . '/../src/redis_state.php';
require_once __DIR__ . '/../src/redis_force.php';
require_once __DIR__ . '/../src/redis_channels.php';
require_once __DIR__ . '/../src/redis_khatm.php';
require_once __DIR__ . '/../src/bale_api.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/force_join.php';
require_once __DIR__ . '/../src/ai_chat.php';
require_once __DIR__ . '/../src/anon_link.php';       // ⭐ جدید
require_once __DIR__ . '/../src/menus.php';
require_once __DIR__ . '/../src/channel_helpers.php';
require_once __DIR__ . '/../src/channel_handlers.php';
require_once __DIR__ . '/../src/khatm_helpers.php';
require_once __DIR__ . '/../src/khatm_flow.php';
require_once __DIR__ . '/../src/khatm_render.php';
require_once __DIR__ . '/../src/khatm_manage.php';
require_once __DIR__ . '/../src/khatm_callbacks.php';
require_once __DIR__ . '/../src/callbacks.php';
require_once __DIR__ . '/../src/router.php';
require_once __DIR__ . '/../src/events.php';

$input = file_get_contents('php://input');

if ($input === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (isset($_GET['setwebhook'])) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $webhookUrl = 'https://' . $host . '/api/index.php';
        $payload = [
            'url' => $webhookUrl,
            'allowed_updates' => ['message', 'channel_post', 'callback_query', 'chat_member', 'my_chat_member'],
        ];
        if (WEBHOOK_SECRET !== '') $payload['secret_token'] = WEBHOOK_SECRET;

        $res = apiPost('setWebhook', $payload);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'Webhook Setup Executed',
            'webhook_url' => $webhookUrl,
            'secret_enabled' => WEBHOOK_SECRET !== '',
            'bale_response' => $res,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    echo '✅ Bale Leave Tracker Bot is running! (Modular Version)';
    exit;
}

if (WEBHOOK_SECRET !== '') {
    $received = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(WEBHOOK_SECRET, $received)) {
        if (!headers_sent()) http_response_code(403);
        exit('Forbidden');
    }
}

$update = json_decode($input, true);
if (!is_array($update)) finishRequest();

if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    finishRequest();
}
if (isset($update['my_chat_member']) && is_array($update['my_chat_member'])) {
    handleMyChatMember($update['my_chat_member']);
    finishRequest();
}
if (isset($update['chat_member']) && is_array($update['chat_member'])) {
    handleChatMemberUpdate($update['chat_member']);
    finishRequest();
}

$message = $update['message'] ?? ($update['channel_post'] ?? null);
if (is_array($message)) {
    if (isset($message['left_chat_member']) && is_array($message['left_chat_member'])) {
        processMemberLeft($message['chat'] ?? [], $message['left_chat_member'], $message['from'] ?? null);
        finishRequest();
    }
    if (isset($message['text']) || isset($message['photo'])) {
        handleTextMessage($message);
        finishRequest();
    }
}
finishRequest();