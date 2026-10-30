<?php
// src/anon_link.php
declare(strict_types=1);

const ANON_RATE_INTERVAL = 30;   // ثانیه بین هر پیام از یک فرستنده
const ANON_DAILY_LIMIT   = 20;   // حداکثر پیام روزانه از یک فرستنده به یک مالک
const ANON_MAX_TEXT      = 1000; // حداکثر طول متن

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

function sendAnonMenu($chatId, $userId, $messageId = null): void {
    $code = getOrCreateAnonLink($userId);
    $botUsername = getBotUsername();
    if (!$botUsername) {
        editOrSend($chatId, $messageId, "❌ خطا در شناسایی ربات. بعداً دوباره تلاش کنید.");
        return;
    }
    $link = "https://ble.ir/{$botUsername}?start=anon_{$code}";
    $text = "🔗 لینک ناشناس شما:\n\n{$link}\n\n"
          . "این لینک را هر جا خواستید بفرستید. هر کسی آن را باز کند، می‌تواند بدون فاش شدن هویت به شما پیام بدهد.\n\n"
          . "💡 به هر پیام دریافتی، دکمه «📝 پاسخ» زیر آن نمایش داده می‌شود. با زدن آن، می‌توانید ناشناس پاسخ دهید.\n\n"
          . "⚠️ محدودیت فرستنده: هر " . ANON_RATE_INTERVAL . " ثانیه یک پیام، حداکثر " . ANON_DAILY_LIMIT . " پیام در روز.";

    editOrSend($chatId, $messageId, $text, [
        'inline_keyboard' => [
            [['text' => '🔄 ساخت لینک جدید', 'callback_data' => 'anon_regen']],
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']],
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

    // ─── ⭐ دکمه پاسخ: کاربر وارد حالت پاسخ می‌شود ───
    if (strpos($data, 'anon_reply:') === 0) {
        $targetId = substr($data, strlen('anon_reply:'));
        if (!preg_match('/^\d+$/', $targetId) || (string)$targetId === $userIdStr) {
            answerCallbackQuery($cbId, '❌ مقصد نامعتبر.', true);
            return;
        }
        setUserState($userIdStr, 'anon_reply', ['target_id' => $targetId]);
        answerCallbackQuery($cbId, '📝 پاسخ خود را بنویسید...');
        sendMessageWithKeyboard(
            $chatId,
            "📝 حالت پاسخ فعال شد.\n\nمتن پاسخ خود را بنویسید. پاسخ شما به صورت ناشناس ارسال می‌شود.\n\n💡 برای لغو /start را بزنید.",
            ['inline_keyboard' => [[['text' => '❌ لغو پاسخ', 'callback_data' => 'anon_reply_cancel']]]]
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

/**
 * پردازش /start anon_XXX
 */
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
        "🕵️ شما در حالت پیام ناشناس هستید.\n\nپیام خود را بفرستید؛ هویت شما برای مالک لینک فاش نمی‌شود.\n\n⚠️ محدودیت: هر " . ANON_RATE_INTERVAL . " ثانیه یک پیام، حداکثر " . ANON_DAILY_LIMIT . " پیام در روز.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

/**
 * فرستنده پیام می‌فرستد → به مالک با دکمه پاسخ می‌رسد
 */
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

    // ⭐ ارسال به مالک + دکمه شیشه‌ای «📝 پاسخ»
    $sent = sendMessageWithKeyboard(
        $ownerId,
        "📩 پیام ناشناس\n\n" . $text,
        ['inline_keyboard' => [[['text' => '📝 پاسخ', 'callback_data' => "anon_reply:{$senderIdStr}"]]]]
    );
    if ($sent === null) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق بود. کمی بعد دوباره تلاش کنید.");
        return;
    }

    redisSet($rlKey, '1', ANON_RATE_INTERVAL);
    redisSet($dailyKey, (string)($dailyCount + 1), 86400);

    sendMessageWithKeyboard(
        $chatId,
        "✅ پیام شما به صورت ناشناس ارسال شد.\n\nپیام دیگری بفرستید یا خارج شوید.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

/**
 * مالک پاسخ می‌دهد → به فرستنده با دکمه پاسخ می‌رسد
 */
function handleAnonReply($chatId, $userId, string $text): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);
    $targetId = (string)($data['target_id'] ?? '');

    if ($targetId === '') {
        clearUserState($userIdStr);
        sendMessageWithKeyboard($chatId, "❌ خطا. لطفاً دوباره از دکمه «📝 پاسخ» استفاده کنید.");
        return;
    }

    $text = trim($text);
    if ($text === '') {
        sendMessageWithKeyboard($chatId, "❌ پاسخ خالی است. متن پاسخ را بنویسید.");
        return;
    }
    if (mb_strlen($text) > ANON_MAX_TEXT) {
        sendMessageWithKeyboard($chatId, "❌ پاسخ طولانی است (حداکثر " . ANON_MAX_TEXT . " کاراکتر).");
        return;
    }

    // ⭐ ارسال پاسخ به فرستنده + دکمه «📝 پاسخ» برای ادامه
    $sent = sendMessageWithKeyboard(
        $targetId,
        "📬 پاسخ ناشناس\n\n" . $text,
        ['inline_keyboard' => [[['text' => '📝 پاسخ', 'callback_data' => "anon_reply:{$userIdStr}"]]]]
    );
    if ($sent === null) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق بود. کمی بعد دوباره تلاش کنید.");
        return;
    }

    clearUserState($userIdStr);
    sendMessageWithKeyboard(
        $chatId,
        "✅ پاسخ شما به صورت ناشناس ارسال شد.\n\n💡 اگر طرف مقابل پاسخ دهد، پیامش با دکمه «📝 پاسخ» برای شما می‌آید.",
        ['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']]]]
    );
}
