<?php
// src/bale_api.php

declare(strict_types=1);

function apiPost(string $method, array $payload): ?array {
    $url = 'https://tapi.bale.ai/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('[BaleAPI] curl failed: ' . curl_error($ch) . ' | method=' . $method);
        return null;
    }
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        error_log('[BaleAPI] invalid JSON from ' . $method . ': ' . substr($res, 0, 300));
        return null;
    }
    return $decoded;
}

function sendWithRetry($chatId, string $text, int $maxRetries = 2): void {
    if (!$chatId) {
        return;
    }
    for ($i = 0; $i < $maxRetries; $i++) {
        $res = apiPost('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);
        if ($res !== null && !empty($res['ok'])) {
            return;
        }
        usleep(200000);
    }
    apiPost('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
    ]);
}

function sendPlainText($chatId, string $text): void {
    if (!$chatId) {
        return;
    }
    sendWithRetry($chatId, $text);
}

function sendMessageWithKeyboard($chatId, string $text, ?array $replyMarkup = null): void {
    if (!$chatId) {
        return;
    }
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }

    $res = apiPost('sendMessage', $payload);
    if ($res === null || empty($res['ok'])) {
        unset($payload['parse_mode']);
        apiPost('sendMessage', $payload);
    }
}

function answerCallbackQuery($callbackId, string $text = '', bool $showAlert = false): void {
    if (!$callbackId) {
        return;
    }
    $payload = ['callback_query_id' => $callbackId];
    if ($text !== '') {
        $payload['text'] = $text;
        $payload['show_alert'] = $showAlert;
    }
    apiPost('answerCallbackQuery', $payload);
}