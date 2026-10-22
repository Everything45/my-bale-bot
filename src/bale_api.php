<?php
// src/bale_api.php
require_once __DIR__ . '/config.php';

function apiPost(string $method, array $payload): array {
    $url = BALE_API_BASE . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'http_code' => $http, 'curl_error' => $error];
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'http_code' => $http, 'raw' => $raw];
    }
    $decoded['http_code'] = $http;
    return $decoded;
}

function sendWithRetry(string $method, array $payload, int $maxAttempts = 2): array {
    $last = ['ok' => false];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $last = apiPost($method, $payload);
        if (!empty($last['ok'])) {
            return $last;
        }
        if ($attempt < $maxAttempts) {
            usleep(150000);
        }
    }
    return $last;
}

function sendPlainText($chatId, string $text, $replyToMessageId = null): array {
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown' // فعال‌سازی قابلیت بولد و کپی با کلیک
    ];
    if ($replyToMessageId !== null) {
        $payload['reply_to_message_id'] = $replyToMessageId;
    }
    $res = sendWithRetry('sendMessage', $payload);
    
    // اگر متنی مثل یوزرنیم دارای کاراکتر خاص (_) بود و خطا داد، به صورت ساده ارسال شود
    if (empty($res['ok'])) {
        unset($payload['parse_mode']);
        $res = sendWithRetry('sendMessage', $payload);
    }
    return $res;
}

function sendMessageWithKeyboard($chatId, string $text, array $inlineKeyboard): array {
    $payload = [
        'chat_id'      => $chatId,
        'text'         => $text,
        'parse_mode'   => 'Markdown', // فعال‌سازی قابلیت بولد و کپی با کلیک
        'reply_markup' => [
            'inline_keyboard' => $inlineKeyboard
        ]
    ];
    $res = sendWithRetry('sendMessage', $payload);
    
    if (empty($res['ok'])) {
        unset($payload['parse_mode']);
        $res = sendWithRetry('sendMessage', $payload);
    }
    return $res;
}

function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array {
    $payload = [
        'callback_query_id' => $callbackQueryId,
        'show_alert'        => $showAlert
    ];
    if ($text !== '') {
        $payload['text'] = $text;
    }
    return apiPost('answerCallbackQuery', $payload);
}
