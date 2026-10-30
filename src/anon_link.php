<?php
// src/anon_link.php
declare(strict_types=1);

const ANON_RATE_INTERVAL = 30;
const ANON_DAILY_LIMIT   = 20;
const ANON_MAX_TEXT      = 1000;

function getOrCreateAnonLink($userId): string {
    $userIdStr = (string)$userId;
    $existing = redisGet("anon_owner_code:{$userIdStr}");
    if ($existing !== null && $existing !== '') return $existing;

    $code = bin2hex(random_bytes(5));
    redisSet("anon_code:{$code}", $userIdStr);
    redisSet("anon_owner_code:{$userIdStr}", $code);
    return $code;
}

function getAnonOwnerByCode(string $code): ?string {
    $code = preg_replace('/[^a-z0-9]/i', '', $code) ?? '';
    if ($code === '') return null;
    $owner = redisGet("anon_code:{$code}");
    return ($owner !== null && $owner !== '') ? $owner : null;
}

/**
 * ⭐ شناسه عددی پایدار برای هر فرستنده نسبت به هر مالک
 * اولین پیام #1، دومین #2، ...
 */
function getAnonTag(string $ownerId, string $senderId): int {
    $key = "anon_tag:{$ownerId}:{$senderId}";
    $v = redisGet($key);
    if ($v !== null && $v !== '') return (int)$v;
    $n = redisIncrBy($key, 1);
    return $n > 0 ? $n : 1;
}

/** ارسال پیام با پشتیبانی از ریپلای */
function sendAnonBubble($chatId, string $text, array $keyboard, ?int $replyTo = null): ?int {
    $payload = ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => $keyboard];
    if ($replyTo !== null && $replyTo > 0) $payload['reply_to_message_id'] = $replyTo;
    $res = apiPost('sendMessage', $payload);
    if (apiOk($res) && isset($res['result']['message_id'])) return (int)$res['result']['message_id'];
    return null;
}

function sendAnonMenu($chatId, $userId, $messageId = null): void {
    $code = getOrCreateAnonLink($userId);
    $botUsername = getBotUsername();
    if (!$botUsername) {
        editOrSend($chatId, $messageId, "❌ خطا. بعداً تلاش کنید.");
        return;
    }
    $link = "https://ble.ir/{$botUsername}?start=anon_{$code}";
    $text = "🔗 لینک ناشناس شما:\n\n{$link}\n\n"
          . "این لینک را هر جا بفرستید. هر کسی می‌تواند ناشناس پیام بدهد.\n\n"
          . "💡 به هر پیام دکمه «📝 پاسخ» چسبیده است.\n"
          . "⚠️ هر فرستنده: " . ANON_RATE_INTERVAL . " ثانیه بین پیام‌ها، " . ANON_DAILY_LIMIT . " پیام در روز.";

    editOrSend($chatId, $messageId, $text, [
        'inline_keyboard' => [
            [['text' => '🔄 لینک جدید', 'callback_data' => 'anon_regen']],
            [['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']],
        ]
    ]);
}

function handleAnonCallback(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $messageId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
    $userId = $cb['from']['id'] ?? null;

    if (!$chatId || !$userId) { answerCallbackQuery($cbId); return; }
    $userIdStr = (string)$userId;

    if ($data === 'anon_link') {
        answerCallbackQuery($cbId);
        sendAnonMenu($chatId, $userId, $messageId);
        return;
    }

    if ($data === 'anon_regen') {
        $oldCode = redisGet("anon_owner_code:{$userIdStr}");
        if ($oldCode !== null && $oldCode !== '') redisDel("anon_code:{$oldCode}");
        redisDel("anon_owner_code:{$userIdStr}");
        getOrCreateAnonLink($userId);
        answerCallbackQuery($cbId, '✅ لینک جدید ساخته شد.');
        sendAnonMenu($chatId, $userId, $messageId);
        return;
    }

    // ─── ⭐ دکمه پاسخ — ذخیره messageId پیام اصلی برای ریپلای ───
    if (strpos($data, 'anon_reply:') === 0) {
        $targetId = substr($data, strlen('anon_reply:'));
        if (!preg_match('/^\d+$/', $targetId) || (string)$targetId === $userIdStr) {
            answerCallbackQuery($cbId, '❌ مقصد نامعتبر.', true);
            return;
        }
        setUserState($userIdStr, 'anon_reply', [
            'target_id' => $targetId,
            'reply_to'  => (string)$messageId,   // ⭐ پیام اصلی در چت خودم
        ]);
        answerCallbackQuery($cbId, '📝 پاسخ خود را بنویسید...');
        sendMessageWithKeyboard(
            $chatId,
            "📝 پاسخ خود را بنویسید. ناشناس ارسال می‌شود.",
            ['inline_keyboard' => [[['text' => '❌ لغو', 'callback_data' => 'anon_reply_cancel']]]]
        );
        return;
    }

    if ($data === 'anon_reply_cancel') {
        clearUserState($userIdStr);
        answerCallbackQuery($cbId, 'لغو شد.');
        sendStartMenu($chatId);
        return;
    }

    answerCallbackQuery($cbId);
}

function handleAnonStart($chatId, $senderId, string $code): void {
    $ownerId = getAnonOwnerByCode($code);
    if ($ownerId === null) {
        sendMessageWithKeyboard($chatId, "❌ این لینک معتبر نیست یا منقضی شده.");
        return;
    }
    if ((string)$senderId === (string)$ownerId) {
        sendMessageWithKeyboard($chatId, "🔗 این لینک خودتان است.");
        return;
    }
    setUserState((string)$senderId, 'anon_send', ['owner_id' => $ownerId]);
    sendMessageWithKeyboard(
        $chatId,
        "🕵️ حالت ناشناس فعال.\n\nپیام خود را بفرستید. هویت شما فاش نمی‌شود.\n\n"
        . "⚠️ " . ANON_RATE_INTERVAL . " ثانیه بین پیام‌ها، " . ANON_DAILY_LIMIT . " پیام در روز.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

/**
 * ⭐ فرستنده پیام می‌فرستد — شناسه عددی می‌گیرد
 * $userMsgId = message_id پیام user (برای ریپلای پاسخ‌های بعدی)
 */
function handleAnonSend($chatId, $senderId, string $text, int $userMsgId = 0): void {
    $senderIdStr = (string)$senderId;
    $data = getUserStateData($senderIdStr);
    $ownerId = (string)($data['owner_id'] ?? '');

    if ($ownerId === '') {
        clearUserState($senderIdStr);
        sendMessageWithKeyboard($chatId, "❌ خطا. از لینک ناشناس دوباره شروع کنید.");
        return;
    }

    $text = trim($text);
    if ($text === '') {
        sendMessageWithKeyboard($chatId, "❌ پیام خالی است.");
        return;
    }
    if (mb_strlen($text) > ANON_MAX_TEXT) {
        sendMessageWithKeyboard($chatId, "❌ حداکثر " . ANON_MAX_TEXT . " کاراکتر.");
        return;
    }

    $rlKey = "anon_rl:{$senderIdStr}:{$ownerId}";
    if (redisGet($rlKey) !== null) {
        sendMessageWithKeyboard($chatId, "⏳ " . ANON_RATE_INTERVAL . " ثانیه صبر کنید.");
        return;
    }

    $dailyKey = "anon_daily:{$senderIdStr}:{$ownerId}";
    $dailyRaw = redisGet($dailyKey);
    $dailyCount = $dailyRaw !== null ? (int)$dailyRaw : 0;
    if ($dailyCount >= ANON_DAILY_LIMIT) {
        sendMessageWithKeyboard($chatId, "⚠️ سهمیه روزانه تمام شد. فردا تلاش کنید.");
        return;
    }

    // ⭐ شناسه عددی پایدار
    $tag = getAnonTag($ownerId, $senderIdStr);

    // ⭐ ذخیره messageId آخرین پیام user برای ریپلای در چت خودش
    if ($userMsgId > 0) {
        redisSet("anon_lastusermsg:{$ownerId}:{$senderIdStr}", (string)$userMsgId, 604800);
    }

    // ⭐ ارسال به مالک با شناسه عددی + دکمه پاسخ
    $sent = sendAnonBubble(
        $ownerId,
        "📩 پیام ناشناس #{$tag}\n\n{$text}",
        ['inline_keyboard' => [[['text' => '📝 پاسخ', 'callback_data' => "anon_reply:{$senderIdStr}"]]]]
    );
    if ($sent === null) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق. دوباره تلاش کنید.");
        return;
    }

    redisSet($rlKey, '1', ANON_RATE_INTERVAL);
    redisSet($dailyKey, (string)($dailyCount + 1), 86400);

    sendMessageWithKeyboard(
        $chatId,
        "✅ ارسال شد. پیام دیگری بفرستید یا خارج شوید.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

/**
 * ⭐ مالک پاسخ می‌دهد — با ریپلای واقعی به پیام اصلی در هر دو طرف
 */
function handleAnonReply($chatId, $userId, string $text): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);
    $targetId = (string)($data['target_id'] ?? '');
    $replyToOwner = (int)($data['reply_to'] ?? 0);

    if ($targetId === '') {
        clearUserState($userIdStr);
        sendMessageWithKeyboard($chatId, "❌ خطا. از دکمه «📝 پاسخ» دوباره شروع کنید.");
        return;
    }

    $text = trim($text);
    if ($text === '') {
        sendMessageWithKeyboard($chatId, "❌ پاسخ خالی است.");
        return;
    }
    if (mb_strlen($text) > ANON_MAX_TEXT) {
        sendMessageWithKeyboard($chatId, "❌ حداکثر " . ANON_MAX_TEXT . " کاراکتر.");
        return;
    }

    // ⭐ شناسه عددی همان فرستنده (همان #N که در پیام اصلی دیده)
    $tag = getAnonTag($userIdStr, $targetId);

    // ⭐ messageId آخرین پیام user در چت خودش (برای ریپلای در سمت sender)
    $replyToSender = (int)(redisGet("anon_lastusermsg:{$userIdStr}:{$targetId}") ?? 0);

    // ⭐ ارسال پاسخ به sender با ریپلای به پیام خودش
    $sent = sendAnonBubble(
        $targetId,
        "📬 پاسخ #{$tag}\n\n{$text}",
        ['inline_keyboard' => [[['text' => '📝 پاسخ', 'callback_data' => "anon_reply:{$userIdStr}"]]]],
        $replyToSender > 0 ? $replyToSender : null
    );
    if ($sent === null) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق. دوباره تلاش کنید.");
        return;
    }

    // ⭐ ثبت پاسخ در چت خود مالک با ریپلای به پیام اصلی
    sendAnonBubble(
        $chatId,
        "✅ پاسخ #{$tag} ارسال شد.",
        ['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']]]],
        $replyToOwner > 0 ? $replyToOwner : null
    );

    clearUserState($userIdStr);
}
