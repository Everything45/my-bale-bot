<?php
// src/anon_link.php
declare(strict_types=1);

const ANON_RATE_INTERVAL = 30;   // ثانیه بین هر پیام از یک فرستنده
const ANON_DAILY_LIMIT   = 20;   // حداکثر پیام روزانه از یک فرستنده به یک مالک
const ANON_MAX_TEXT      = 1000; // حداکثر طول متن
const ANON_INBOX_MAX     = 100;  // حداکثر پیام‌های ذخیره‌شده برای صندوق هر کاربر

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
 * ذخیره پیام دریافتی — با شناسه یکتا برای پاسخ امن (جلوگیری از race condition)
 */
function saveAnonReceivedMessage($ownerId, string $text, $senderId): void {
    $record = [
        'id' => bin2hex(random_bytes(4)),  // 8 char hex — شناسه‌ی پایدار
        'text' => $text,
        'time' => date('Y/m/d - H:i'),
        'sender_id' => (string)$senderId,
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

function findAnonInboxMessage($ownerId, string $msgId): ?array {
    foreach (getAnonInbox($ownerId) as $item) {
        if (($item['id'] ?? '') === $msgId) return $item;
    }
    return null;
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
    $shown = array_slice($inbox, 0, 10);
    $text = "📬 صندوق پیام‌های ناشناس شما\n\n"
          . "تعداد کل: {$total} پیام\n"
          . "نمایش آخرین " . count($shown) . " پیام:\n\n";

    $rows = [];
    foreach ($shown as $idx => $item) {
        $num = $idx + 1;
        $txt = mb_substr((string)($item['text'] ?? ''), 0, 120);
        $text .= "{$num}. {$txt}\n🕒 " . ($item['time'] ?? '') . "\n\n";
        $msgId = (string)($item['id'] ?? '');
        if ($msgId !== '') {
            $rows[] = [['text' => "📝 پاسخ به پیام {$num}", 'callback_data' => "anon_reply:{$msgId}"]];
        }
    }

    $rows[] = [['text' => '🔄 بروزرسانی', 'callback_data' => 'anon_inbox']];
    $rows[] = [['text' => '🗑 پاک کردن صندوق', 'callback_data' => 'anon_inbox_clear']];
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'anon_link']];

    editOrSend($chatId, $messageId, $text, ['inline_keyboard' => $rows]);
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

    // ─── ⭐ پاسخ به پیام ناشناس ───
    if (strpos($data, 'anon_reply:') === 0) {
        $msgId = substr($data, strlen('anon_reply:'));
        $item = findAnonInboxMessage($userId, $msgId);
        if ($item === null) {
            answerCallbackQuery($cbId, '❌ این پیام پیدا نشد.', true);
            return;
        }
        $targetId = (string)($item['sender_id'] ?? '');
        if ($targetId === '') {
            answerCallbackQuery($cbId, '❌ این پیام قابل پاسخ نیست.', true);
            return;
        }
        setUserState($userIdStr, 'anon_reply', ['target_id' => $targetId]);
        answerCallbackQuery($cbId);
        editOrSend($chatId, $messageId,
            "📝 حالت پاسخ فعال شد.\n\n"
            . "پاسخ شما به صورت ناشناس برای فرستنده ارسال می‌شود. متن پاسخ را بنویسید:\n\n"
            . "💡 برای لغو، دکمه زیر یا /start را بزنید.",
            ['inline_keyboard' => [[['text' => '❌ لغو پاسخ', 'callback_data' => 'anon_reply_cancel']]]]
        );
        return;
    }

    if ($data === 'anon_reply_cancel') {
        clearUserState($userIdStr);
        answerCallbackQuery($cbId, 'لغو شد.');
        sendAnonMenu($chatId, $userId, $messageId);
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
        "🕵️ شما در حالت پیام ناشناس هستید.\n\n"
        . "پیام خود را بفرستید؛ هویت شما برای مالک لینک فاش نمی‌شود.\n\n"
        . "⚠️ محدودیت: هر " . ANON_RATE_INTERVAL . " ثانیه یک پیام، حداکثر " . ANON_DAILY_LIMIT . " پیام در روز.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

/**
 * پردازش پیام متنی کاربر در حالت anon_send (فرستنده)
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

    $ok = sendPlainText($ownerId, "📩 پیام ناشناس\n\n" . $text);
    if (!$ok) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق بود. کمی بعد دوباره تلاش کنید.");
        return;
    }

    saveAnonReceivedMessage($ownerId, $text, $senderId);

    redisSet($rlKey, '1', ANON_RATE_INTERVAL);
    redisSet($dailyKey, (string)($dailyCount + 1), 86400);

    sendMessageWithKeyboard(
        $chatId,
        "✅ پیام شما به صورت ناشناس ارسال شد.\n\nپیام دیگری بفرستید یا خارج شوید.",
        ['inline_keyboard' => [[['text' => '❌ خروج', 'callback_data' => 'anon_exit']]]]
    );
}

/**
 * پردازش پیام متنی در حالت anon_reply (سمت مالک)
 */
function handleAnonReply($chatId, $userId, string $text): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);
    $targetId = (string)($data['target_id'] ?? '');

    if ($targetId === '') {
        clearUserState($userIdStr);
        sendMessageWithKeyboard($chatId, "❌ خطا. لطفاً دوباره از صندوق تلاش کنید.");
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

    // لینک بازگشت برای ادامه مکالمه توسط فرستنده
    $botUsername = getBotUsername();
    $ownerCode = getOrCreateAnonLink($userId);
    $returnLink = ($botUsername && $ownerCode)
        ? "\n\n—\n🔗 برای پاسخ، روی این لینک بزنید:\nhttps://ble.ir/{$botUsername}?start=anon_{$ownerCode}"
        : '';

    $ok = sendPlainText($targetId, "📬 پاسخ پیام ناشناس شما\n\n" . $text . $returnLink);
    if (!$ok) {
        sendMessageWithKeyboard($chatId, "❌ ارسال ناموفق بود. کمی بعد دوباره تلاش کنید.");
        return;
    }

    clearUserState($userIdStr);
    sendMessageWithKeyboard(
        $chatId,
        "✅ پاسخ شما به صورت ناشناس برای فرستنده ارسال شد.\n\nپاسخ‌های بعدی این فرستنده در صندوق شما ذخیره می‌شود.",
        ['inline_keyboard' => [[['text' => '🔙 بازگشت به لینک ناشناس', 'callback_data' => 'anon_link']]]]
    );
}
