<?php
// src/anon_link.php
declare(strict_types=1);

// محدودیت‌ها
const ANON_RATE_INTERVAL = 30;
const ANON_DAILY_LIMIT   = 20;
const ANON_MAX_TEXT      = 1000;
const ANON_INBOX_MAX     = 100;

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

function saveAnonReceivedMessage($ownerId, string $text): void {
    $record = [
        'text' => $text,
        'time' => date('Y/m/d - H:i'),
    ];
    redisLPushTrim("anon_inbox:{$ownerId}", json_encode($record, JSON_UNESCAPED_UNICODE), ANON_INBOX_MAX);
}

function getAnonInbox($ownerId): array {
    $raw = redisLRange("anon_inbox:{$ownerId}", 0, ANON_INBOX_MAX - 1);
    $result = [];
    foreach ($raw as $item) {
        if (is_array($item)) { $result[] = $item; continue; }
        $decoded = json_decode((string)$item, true);
        if (is_array($decoded)) $result[] = $decoded;
    }
    return $result;
}

function sendAnonMenu($chatId, $userId, $messageId = null): void {
    $code = getOrCreateAnonLink($userId);
    $botUsername = getBotUsername();
    if (!$botUsername) {
        editOrSend($chatId, $messageId, "❌ خطا در شناسایی ربات. بعداً دوباره تلاش کنید.");
        return;
    }
    $link = "https://ble.ir/{$botUsername}?start=anon_{$code}";
    $inboxCount = count(getAnonInbox($userId));

    $text = "🔗 لینک ناشناس شما آماده است!\n\n"
          . "این لینک را هر جا خواستید بفرستید. هر کسی آن را باز کند، می‌تواند بدون فاش شدن هویت به شما پیام بدهد.\n\n"
          . "📎 لینک شما:\n{$link}\n\n"
          . "📬 پیام‌های دریافتی: {$inboxCount}\n\n"
          . "⚠️ محدودیت‌ها برای هر فرستنده:\n"
          . "• حداقل " . ANON_RATE_INTERVAL . " ثانیه بین هر پیام\n"
          . "• حداکثر " . ANON_DAILY_LIMIT . " پیام در روز\n\n"
          . "💡 با دکمه «ساخت لینک جدید» می‌توانید لینک قبلی را غیرفعال و لینک تازه بسازید:";

    editOrSend($chatId, $messageId, $text, [
        'inline_keyboard' => [
            [['text' => '📬 پیام‌های دریافتی', 'callback_data' => 'anon_inbox']],
            [['text' => '🔄 ساخت لینک جدید', 'callback_data' => 'anon_regen']],
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']],
        ]
    ]);
}

function showAnonInbox($chatId, $userId, $messageId = null): void {
    $inbox = getAnonInbox($userId);
    if (empty($inbox)) {
        editOrSend($chatId, $messageId, "📭 هنوز هیچ پیام ناشناسی دریافت نکرده‌اید.",
            ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'anon_link']]]]);
        return;
    }

    $total = count($inbox);
    $shown = array_slice($inbox, 0, 15);
    $text = "📬 صندوق پیام‌های ناشناس شما\n\n"
          . "تعداد کل: {$total} پیام\n"
          . "نمایش آخرین " . count($shown) . " پیام:\n\n";

    foreach ($shown as $idx => $item) {
        $num = $idx + 1;
        $txt = mb_substr((string)($item['text'] ?? ''), 0, 300);
        $text .= "{$num}. {$txt}\n🕒 " . ($item['time'] ?? '') . "\n\n";
    }

    editOrSend($chatId, $messageId, $text, [
        'inline_keyboard' => [
            [['text' => '🔄 بروزرسانی', 'callback_data' => 'anon_inbox']],
            [['text' => '🗑 پاک کردن صندوق', 'callback_data' => 'anon_inbox_clear']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'anon_link']],
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

    if ($data === 'anon_link') {
        answerCallbackQuery($cbId);
        sendAnonMenu($chatId, $userId, $messageId);
        return;
    }

    if ($data === 'anon_regen') {
        $userIdStr = (string)$userId;
        $oldCode = redisGet("anon_owner_code:{$userIdStr}");
        if ($oldCode !== null && $oldCode !== '') redisDel("anon_code:{$oldCode}");
        redisDel("anon_owner_code:{$userIdStr}");
        getOrCreateAnonLink($userId);
        answerCallbackQuery($cbId, '✅ لینک جدید ساخته شد.');
        sendAnonMenu($chatId, $userId, $messageId);
        return;
    }

    if ($data === 'anon_inbox') {
        answerCallbackQuery($cbId);
        showAnonInbox($chatId, $userId, $messageId);
        return;
    }

    if ($data === 'anon_inbox_clear') {
        redisDel("anon_inbox:{$userId}");
        answerCallbackQuery($cbId, '🗑 صندوق پاک شد.');
        showAnonInbox($chatId, $userId, $messageId);
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
        sendMessageWithKeyboard($chatId, "🔗 این لینک خودتان است.\n\nبرای دیدن/ساخت لینک، از «🔗 لینک ناشناس من» استفاده کنید.");
        return;
    }

    setUserState((string)$senderId, 'anon_send', ['owner_id' => $ownerId]);
    sendMessageWithKeyboard(
        $chatId,
        "🕵️ شما در حالت پیام ناشناس هستید.\n\n"
        . "پیام خود را بفرستید؛ هویت شما برای مالک لینک فاش نمی‌شود.\n\n"
        . "⚠️ محدودیت: هر " . ANON_RATE_INTERVAL . " ثانیه یک پیام، حداکثر " . ANON_DAILY_LIMIT . " پیام در روز.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

function handleAnonSend($chatId, $senderId, string $text): void {
    $senderIdStr = (string)$senderId;
    $data = getUserStateData($senderIdStr);
    $ownerId = (string)($data['owner_id'] ?? '');

    if ($ownerId === '') {
        clearUserState($senderIdStr);
        sendMessageWithKeyboard($chatId, "❌ خطا. لطفاً دوباره از لینک ناشناس شروع کنید.");
        return;
    }

    $text = trim($text);
    if ($text === '') {
        sendMessageWithKeyboard($chatId, "❌ پیام خالی است. متن خود را بفرستید.");
        return;
    }
    if (mb_strlen($text) > ANON_MAX_TEXT) {
        sendMessageWithKeyboard($chatId, "❌ پیام طولانی است (حداکثر " . ANON_MAX_TEXT . " کاراکتر).");
        return;
    }

    $rlKey = "anon_rl:{$senderIdStr}:{$ownerId}";
    if (redisGet($rlKey) !== null) {
        sendMessageWithKeyboard($chatId, "⏳ لطفاً " . ANON_RATE_INTERVAL . " ثانیه صبر کنید و بعد پیام بعدی را بفرستید.");
        return;
    }

    $dailyKey = "anon_daily:{$senderIdStr}:{$ownerId}";
    $dailyRaw = redisGet($dailyKey);
    $dailyCount = $dailyRaw !== null ? (int)$dailyRaw : 0;
    if ($dailyCount >= ANON_DAILY_LIMIT) {
        sendMessageWithKeyboard($chatId, "⚠️ سهمیه پیام روزانه شما به پایان رسیده است. فردا دوباره تلاش کنید.");
        return;
    }

    $ok = sendPlainText($ownerId, "📩 پیام ناشناس\n\n" . $text);
    if (!$ok) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق بود. کمی بعد دوباره تلاش کنید.");
        return;
    }

    saveAnonReceivedMessage($ownerId, $text);

    redisSet($rlKey, '1', ANON_RATE_INTERVAL);
    redisSet($dailyKey, (string)($dailyCount + 1), 86400);

    sendMessageWithKeyboard(
        $chatId,
        "✅ پیام شما به صورت ناشناس ارسال شد.\n\n"
        . "پیام دیگری بفرستید یا خارج شوید.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}