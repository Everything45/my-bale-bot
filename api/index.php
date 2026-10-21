<?php

date_default_timezone_set('Asia/Tehran');

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('REDIS_URL', getenv('UPSTASH_REDIS_REST_URL') ?: '');
define('REDIS_TOKEN', getenv('UPSTASH_REDIS_REST_TOKEN') ?: '');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');

if (BOT_TOKEN === '') {
    http_response_code(500);
    echo 'BOT_TOKEN missing';
    exit;
}

// اگر WEBHOOK_SECRET تنظیم شده باشد، درخواستهای جعلی رد میشوند.
// اگر بله هدر secret را ارسال نمیکند، این متغیر را خالی بگذارید.
if (WEBHOOK_SECRET !== '') {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']
        ?? $_SERVER['HTTP_X_BALE_BOT_API_SECRET_TOKEN']
        ?? '';

    if (!hash_equals(WEBHOOK_SECRET, $header)) {
        http_response_code(403);
        exit;
    }
}

$input = file_get_contents('php://input');

if ($input === '') {
    http_response_code(200);
    echo 'Bot is running.';
    exit;
}

$update = json_decode($input, true);

if (!is_array($update)) {
    http_response_code(200);
    exit;
}

// ----------------------------------------------------
// ۱. دستور /start در چت خصوصی
// ----------------------------------------------------
if (isset($update['message']['text'])) {
    $message  = $update['message'];
    $chat     = $message['chat'] ?? [];
    $chatId   = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';
    $senderName = $message['from']['first_name'] ?? 'مدیر';

    [$command, $args] = parseCommand($message['text']);

    if ($command === 'start' && $chatType === 'private' && $chatId) {
        $msg = "سلام " . $senderName . " عزیز.\n\n"
            . "شناسه عددی شما:\n"
            . $chatId . "\n\n"
            . "نحوه فعال‌سازی ربات برای کانال شما:\n"
            . "۱. ربات را به کانال خود اضافه کرده و ادمین کنید.\n"
            . "۲. در کانال، دستور زیر را دقیقاً بفرستید:\n\n"
            . "/connect " . $chatId . "\n\n"
            . "پس از این پیام، گزارش‌های خروج اعضای آن کانال فقط برای شما ارسال می‌شود.";

        sendPlainText($chatId, $msg);
        http_response_code(200);
        exit;
    }

    // ----------------------------------------------------
    // ۲. اتصال کانال/گروه به مدیر
    // ----------------------------------------------------
    if ($command === 'connect') {
        if (!$chatId || $chatType === 'private') {
            sendPlainText($chatId, 'این دستور فقط در کانال یا گروه قابل استفاده است.');
            http_response_code(200);
            exit;
        }

        if (REDIS_URL === '' || REDIS_TOKEN === '') {
            sendPlainText($chatId, 'دیتابیس Redis پیکربندی نشده است.');
            http_response_code(200);
            exit;
        }

        $args = trim((string)$args);

        if (!preg_match('/^\d+$/', $args) || (int)$args <= 0) {
            sendPlainText($chatId, 'فرمت صحیح: /connect YOUR_NUMERIC_ID');
            http_response_code(200);
            exit;
        }

        $ownerId = (string)$args;
        $fromId  = $message['from']['id'] ?? null;

        // در گروه‌ها فقط ادمین اجازه دارد و باید آیدی خودش را ثبت کند.
        if ($chatType !== 'channel') {
            if (!$fromId || !isChatAdmin($chatId, $fromId)) {
                sendPlainText($chatId, 'فقط ادمین گروه می‌تواند این دستور را اجرا کند.');
                http_response_code(200);
                exit;
            }

            if ((string)$ownerId !== (string)$fromId) {
                sendPlainText($chatId, 'باید آیدی عددی خودتان را وارد کنید.');
                http_response_code(200);
                exit;
            }
        }

        // در کانال، چون پستها از طرف کانال ارسال میشوند، from قابل اتکا نیست.
        setChannelOwner($chatId, $ownerId);

        $chatTitle = $chat['title'] ?? 'این چت';

        sendPlainText(
            $chatId,
            "کانال/گروه '" . $chatTitle . "' با موفقیت به شناسه " . $ownerId . " متصل شد."
        );

        sendPlainText(
            $ownerId,
            "اتصال تایید شد: شما اکنون گزارش‌های خروج اعضای '" . $chatTitle . "' را دریافت خواهید کرد."
        );

        http_response_code(200);
        exit;
    }
}

// ----------------------------------------------------
// ۳. رهگیری خروج عضو از طریق left_chat_member
// ----------------------------------------------------
if (isset($update['message']['left_chat_member'])) {
    $user = $update['message']['left_chat_member'];
    $chat = $update['message']['chat'] ?? [];
    dispatchLeaveAlert($user, $chat);
}

// ----------------------------------------------------
// ۴. رهگیری خروج عضو از طریق chat_member
// ----------------------------------------------------
if (isset($update['chat_member'])) {
    $chatMember = $update['chat_member'];
    $oldStatus  = $chatMember['old_chat_member']['status'] ?? '';
    $newStatus  = $chatMember['new_chat_member']['status'] ?? '';

    $oldActive = in_array($oldStatus, ['creator', 'member', 'administrator', 'restricted'], true);
    $newLeft   = in_array($newStatus, ['left', 'kicked'], true);

    if ($oldActive && $newLeft) {
        $user = $chatMember['old_chat_member']['user']
            ?? ($chatMember['new_chat_member']['user'] ?? []);
        $chat = $chatMember['chat'] ?? [];
        dispatchLeaveAlert($user, $chat);
    }
}

http_response_code(200);
echo 'OK';
exit;

// ----------------------------------------------------
// توابع
// ----------------------------------------------------

function parseCommand(string $text): array
{
    $text = trim($text);

    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/u', $text, $m)) {
        return [null, null];
    }

    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

function dispatchLeaveAlert(array $user, array $chat): void
{
    $channelId = $chat['id'] ?? null;
    if (!$channelId) {
        return;
    }

    // گزارش خروج ربات‌ها لازم نیست.
    if (!empty($user['is_bot'])) {
        return;
    }

    $targetAdminId = getChannelOwner($channelId);
    if (!$targetAdminId) {
        return;
    }

    $userId = $user['id'] ?? null;
    if (!$userId) {
        return;
    }

    // جلوگیری از ارسال تکراری برای left_chat_member و chat_member
    if (!markLeaveOnce($channelId, $userId)) {
        return;
    }

    $channelTitle = $chat['title'] ?? 'نامشخص';
    $firstName    = $user['first_name'] ?? '';
    $lastName     = $user['last_name'] ?? '';
    $fullName     = trim($firstName . ' ' . $lastName);

    if ($fullName === '') {
        $fullName = 'بدون نام';
    }

    $username = !empty($user['username']) ? '@' . $user['username'] : 'ندارد';
    $isBot    = !empty($user['is_bot']) ? 'بله' : 'خیر';

    $report = "[ گزارش خروج کاربر ]\n\n"
        . "کانال: " . $channelTitle . "\n"
        . "شناسه کانال: " . $channelId . "\n\n"
        . "نام کاربر: " . $fullName . "\n"
        . "شناسه عددی کاربر: " . $userId . "\n"
        . "نام کاربری: " . $username . "\n"
        . "ربات است: " . $isBot . "\n\n"
        . "تاریخ: " . date('Y/m/d') . "\n"
        . "ساعت: " . date('H:i:s');

    sendPlainText($targetAdminId, $report);
}

function isChatAdmin($chatId, $userId): bool
{
    $res = apiPost('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
    ]);

    $status = $res['result']['status'] ?? '';

    return in_array($status, ['creator', 'administrator'], true);
}

// ----------------------------------------------------
// Upstash Redis REST
// ----------------------------------------------------

function setChannelOwner($channelId, $ownerId): void
{
    $key = rawurlencode('channel_' . $channelId);
    $val = rawurlencode((string)$ownerId);

    redisCommand('/set/' . $key . '/' . $val);
}

function getChannelOwner($channelId): ?string
{
    $key = rawurlencode('channel_' . $channelId);
    $res = redisCommand('/get/' . $key);

    if ($res === null) {
        return null;
    }

    $json = json_decode($res, true);
    $owner = $json['result'] ?? null;

    return $owner !== null ? (string)$owner : null;
}

function markLeaveOnce($chatId, $userId): bool
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
        return true;
    }

    $key = rawurlencode('leave_event_' . $chatId . '_' . $userId);
    $res = redisCommand('/set/' . $key . '/1?EX=60&NX=true');

    if ($res === null) {
        return true;
    }

    $json = json_decode($res, true);

    if (isset($json['error'])) {
        return true;
    }

    return (($json['result'] ?? null) === 'OK');
}

function redisCommand(string $path): ?string
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
        return null;
    }

    $url = rtrim(REDIS_URL, '/') . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . REDIS_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    return $res === false ? null : $res;
}

// ----------------------------------------------------
// Bale API
// ----------------------------------------------------

function sendPlainText($chatId, string $text): void
{
    if (!$chatId) {
        return;
    }

    apiPost('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);
}

function apiPost(string $method, array $payload): ?array
{
    $url = 'https://tapi.bale.ai/bot' . BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    if ($res === false) {
        return null;
    }

    $json = json_decode($res, true);
    return is_array($json) ? $json : null;
}
