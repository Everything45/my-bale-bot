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
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        error_log('[BaleAPI] invalid JSON from ' . $method . ': ' . substr($res, 0, 300));
        return null;
    }
    return $decoded;
}

function apiOk(?array $res): bool {
    return $res !== null && !empty($res['ok']);
}

/** متن بلند را به تکه‌های کمتر از حد مجاز تقسیم می‌کند */
function splitLongText(string $text, int $limit = 3800): array {
    if (mb_strlen($text) <= $limit) {
        return [$text];
    }
    $chunks = [];
    $current = '';
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        if ($current !== '' && mb_strlen($current) + mb_strlen($line) + 1 > $limit) {
            $chunks[] = $current;
            $current = '';
        }
        if (mb_strlen($line) > $limit) {
            foreach (mb_str_split($line, $limit) as $part) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = $part;
            }
            continue;
        }
        $current .= ($current === '' ? '' : "\n") . $line;
    }
    if ($current !== '') {
        $chunks[] = $current;
    }
    return $chunks ?: [$text];
}

/**
 * ارسال پیام جدید (متن ساده، بدون parse_mode). هیچ پیامی ویرایش نمی‌شود.
 * شناسه‌ی آخرین پیام ارسال‌شده را برمی‌گرداند.
 */
function sendMessageWithKeyboard($chatId, string $text, ?array $replyMarkup = null): ?int {
    if (!$chatId) {
        return null;
    }
    $chunks = splitLongText($text);
    $lastId = null;
    $lastIndex = count($chunks) - 1;

    foreach ($chunks as $i => $chunk) {
        $payload = ['chat_id' => $chatId, 'text' => $chunk];
        if ($replyMarkup !== null && $i === $lastIndex) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $res = apiPost('sendMessage', $payload);
        if (!apiOk($res)) {
            usleep(200000);
            $res = apiPost('sendMessage', $payload);
        }
        if (apiOk($res) && isset($res['result']['message_id'])) {
            $lastId = (int)$res['result']['message_id'];
        }
    }
    return $lastId;
}

function sendPlainText($chatId, string $text): bool {
    if (!$chatId) {
        return false;
    }
    return sendMessageWithKeyboard($chatId, $text) !== null;
}

function deleteMessageSafe($chatId, $messageId): bool {
    if (!$chatId || !$messageId) {
        return false;
    }
    return apiOk(apiPost('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]));
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