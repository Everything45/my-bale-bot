<?php
// src/helpers.php
declare(strict_types=1);

function finishRequest(): void {
    if (!headers_sent()) http_response_code(200);
    echo 'OK';
    exit;
}

function flushResponseEarly(): void {
    if (function_exists('fastcgi_finish_request')) {
        if (!headers_sent()) http_response_code(200);
        echo 'OK';
        fastcgi_finish_request();
    }
}

function isBotOwner($userId): bool {
    if (!$userId || BOT_OWNER_ID === '') return false;
    return (string)$userId === (string)BOT_OWNER_ID;
}

function getBotId(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $key = 'bot_self_id:' . substr(md5(BOT_TOKEN), 0, 8);
    $stored = redisGet($key);
    if ($stored !== null && $stored !== '') return $cached = $stored;
    $res = apiPost('getMe', []);
    if (!apiOk($res) || empty($res['result']['id'])) return null;
    $id = (string)$res['result']['id'];
    redisSet($key, $id, 86400);
    return $cached = $id;
}

function getBotUsername(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $key = 'bot_self_username:' . substr(md5(BOT_TOKEN), 0, 8);
    $stored = redisGet($key);
    if ($stored !== null && $stored !== '') return $cached = $stored;
    $res = apiPost('getMe', []);
    if (!apiOk($res) || empty($res['result']['username'])) return null;
    $username = (string)$res['result']['username'];
    redisSet($key, $username, 86400);
    return $cached = $username;
}

function parseCommand(string $text): array {
    $text = trim($text);
    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/us', $text, $m)) return [null, null];
    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

function normalizeDigits(string $s): string {
    return strtr($s, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

function normalizeChannelRef(string $arg): ?string {
    $arg = trim(normalizeDigits($arg));
    if ($arg === '') return null;
    $arg = preg_replace('~^https?://(www\.)?(ble\.ir|bale\.ai|t\.me|telegram\.me)/~i', '', $arg) ?? $arg;
    $arg = explode('?', $arg)[0];
    $arg = trim($arg, "/ \t\n\r@");
    if ($arg === '') return null;
    if (preg_match('/^-?\d+$/', $arg)) return $arg;
    if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $arg)) return null;
    return '@' . $arg;
}

function editOrSend($chatId, $messageId, string $text, ?array $replyMarkup = null): void {
    if ($messageId) {
        $payload = ['chat_id'=>$chatId,'message_id'=>$messageId,'text'=>$text];
        if ($replyMarkup !== null) $payload['reply_markup'] = $replyMarkup;
        $res = apiPost('editMessageText', $payload);
        if (apiOk($res)) return;
    }
    sendMessageWithKeyboard($chatId, $text, $replyMarkup);
}