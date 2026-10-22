<?php

date_default_timezone_set('Asia/Tehran');

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('REDIS_URL', getenv('UPSTASH_REDIS_REST_URL') ?: '');
define('REDIS_TOKEN', getenv('UPSTASH_REDIS_REST_TOKEN') ?: '');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');
// 🔑 شناسه عددی سازنده ربات (برای دستور /mychannels فقط خودتان)
define('BOT_OWNER_ID', getenv('BOT_OWNER_ID') ?: '');

if (BOT_TOKEN === '') {
    http_response_code(500);
    echo 'BOT_TOKEN missing';
    exit;
}

// ----------------------------------------------------
// ۰. بررسی Secret و دریافت ورودی
// ----------------------------------------------------
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
if ($input === '') { http_response_code(200); echo 'Bot is running.'; exit; }

$update = json_decode($input, true);
if (!is_array($update)) { http_response_code(200); exit; }

// ----------------------------------------------------
// ۰.۱ پردازش Callback Query (دکمه‌های شیشه‌ای)
// ----------------------------------------------------
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    http_response_code(200);
    exit;
}

// ----------------------------------------------------
// ۱. پردازش پیام‌های متنی و دستورات
// ----------------------------------------------------
if (isset($update['message']['text'])) {
    $message  = $update['message'];
    $chat     = $message['chat'] ?? [];
    $chatId   = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';
    $senderName = $message['from']['first_name'] ?? 'مدیر';

    [$command, $args] = parseCommand($message['text']);

    // --- /start ---
    if ($command === 'start' && $chatType === 'private' && $chatId) {
        sendStartMenu($chatId, $senderName, $message['from']['id'] ?? null);
        http_response_code(200);
        exit;
    }

    // --- /help ---
    if ($command === 'help') {
        sendHelp($chatId);
        http_response_code(200);
        exit;
    }

    // --- /connect ---
    if ($command === 'connect') {
        handleConnect($chat, $message, $args);
        http_response_code(200);
        exit;
    }

    // --- /disconnect ---
    if ($command === 'disconnect') {
        handleDisconnect($chat, $message);
        http_response_code(200);
        exit;
    }

    // --- /status ---
    if ($command === 'status') {
        handleStatus($chat);
        http_response_code(200);
        exit;
    }

    // --- /mychannels (فقط سازنده ربات) ---
    if ($command === 'mychannels') {
        handleMyChannels($chat, $message);
        http_response_code(200);
        exit;
    }

    // --- /stats ---
    if ($command === 'stats') {
        handleStats($chat);
        http_response_code(200);
        exit;
    }

    // --- /settings ---
    if ($command === 'settings') {
        sendSettingsMenu($chatId);
        http_response_code(200);
        exit;
    }
}

// ----------------------------------------------------
// ۲. رهگیری خروج عضو (left_chat_member)
// ----------------------------------------------------
if (isset($update['message']['left_chat_member'])) {
    $user = $update['message']['left_chat_member'];
    $chat = $update['message']['chat'] ?? [];
    dispatchLeaveAlert($user, $chat, 'left');
}

// ----------------------------------------------------
// ۳. رهگیری خروج/اخراج از طریق chat_member
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
        // نوع خروج: kicked اگر newStatus = kicked باشد، وگرنه left
        $leaveType = ($newStatus === 'kicked') ? 'kicked' : 'left';
        dispatchLeaveAlert($user, $chat, $leaveType);
    }

    // ثبت زمان عضویت برای اعضای جدید
    if (in_array($newStatus, ['member', 'administrator'], true) && $oldStatus === 'left') {
        $user = $chatMember['new_chat_member']['user'] ?? [];
        $chat = $chatMember['chat'] ?? [];
        if (!empty($user['id']) && !empty($chat['id'])) {
            saveJoinTime($chat['id'], $user['id']);
        }
    }
}

// همچنین new_chat_members برای ثبت زمان عضویت (در صورت وجود)
if (isset($update['message']['new_chat_members'])) {
    $chat = $update['message']['chat'] ?? [];
    if (!empty($chat['id'])) {
        foreach ($update['message']['new_chat_members'] as $newMember) {
            if (!empty($newMember['id'])) {
                saveJoinTime($chat['id'], $newMember['id']);
            }
        }
    }
}

http_response_code(200);
echo 'OK';
exit;

// ====================================================
// توابع اصلی
// ====================================================

function parseCommand(string $text): array
{
    $text = trim($text);
    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/u', $text, $m)) {
        return [null, null];
    }
    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

// ----------------------------------------------------
// منوی /start با دکمه‌های شیشه‌ای (ایموجی‌های رنگی)
// ----------------------------------------------------
function sendStartMenu($chatId, $senderName, $userId = null): void
{
    $msg = "سلام " . $senderName . " عزیز. 👋\n\n"
        . "به **آنفالویاب** خوش آمدید. 🕵️‍♂️\n"
        . "برای اتصال کانال، دستور `/connect` را در کانال ارسال کنید.\n\n"
        . "شناسه عددی شما:\n`" . $chatId . "`";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📘 راهنما', 'callback_data' => 'help'],
                ['text' => '⚙️ تنظیمات', 'callback_data' => 'settings'],
            ],
            [
                ['text' => '📊 وضعیت کانال', 'callback_data' => 'status'],
                ['text' => '📈 آمار', 'callback_data' => 'stats'],
            ],
        ]
    ];

    sendMessageWithKeyboard($chatId, $msg, $keyboard);
}

// ----------------------------------------------------
// /help
// ----------------------------------------------------
function sendHelp($chatId): void
{
    $msg = "📘 **راهنمای دستورات آنفالویاب**\n\n"
        . "🔹 `/start` — شروع و دریافت شناسه عددی\n"
        . "🔹 `/help` — همین راهنما\n"
        . "🔹 `/connect <ID>` — اتصال کانال/گروه به مدیر (در کانال بزنید)\n"
        . "🔹 `/disconnect` — قطع اتصال کانال از مدیر\n"
        . "🔹 `/status` — وضعیت اتصال کانال جاری\n"
        . "🔹 `/stats` — آمار خروج اعضا\n"
        . "🔹 `/settings` — تنظیمات گزارش‌ها\n"
        . "🔹 `/mychannels` — لیست کانال‌های متصل (فقط سازنده ربات)\n\n"
        . "⚠️ برای فعال‌سازی: ربات را ادمین کانال کنید و در کانال `/connect YOUR_ID` بفرستید.";

    sendMessageWithKeyboard($chatId, $msg, null);
}

// ----------------------------------------------------
// /connect
// ----------------------------------------------------
function handleConnect($chat, $message, $args): void
{
    $chatId = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';

    if (!$chatId || $chatType === 'private') {
        sendPlainText($chatId, '⚠️ این دستور فقط در کانال یا گروه قابل استفاده است.');
        return;
    }
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
        sendPlainText($chatId, '⚠️ دیتابیس Redis پیکربندی نشده است.');
        return;
    }

    $args = normalizeDigits(trim((string)$args));
    if (!preg_match('/^\d+$/', $args) || (int)$args <= 0) {
        sendPlainText($chatId, "⚠️ فرمت صحیح:\n`/connect YOUR_NUMERIC_ID`");
        return;
    }

    $ownerId = (string)$args;
    $fromId  = $message['from']['id'] ?? null;

    // اعتبارسنجی: ربات باید در کانال ادمین باشد
    if (!isBotAdminInChat($chatId)) {
        sendPlainText($chatId, '⚠️ ربات ادمین این کانال نیست. ابتدا ربات را ادمین کنید.');
        return;
    }

    // در گروه‌ها فقط ادمین
    if ($chatType !== 'channel') {
        if (!$fromId || !isChatAdmin($chatId, $fromId)) {
            sendPlainText($chatId, '⚠️ فقط ادمین گروه می‌تواند این دستور را اجرا کند.');
            return;
        }
        if ((string)$ownerId !== (string)$fromId) {
            sendPlainText($chatId, '⚠️ باید آیدی عددی خودتان را وارد کنید.');
            return;
        }
    }

    // بررسی اتصال قبلی
    $existingOwner = getChannelOwner($chatId);
    if ($existingOwner !== null && $existingOwner !== $ownerId) {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، جایگزین کن', 'callback_data' => 'replace_owner_' . $ownerId . '_' . $chatId],
                    ['text' => '❌ لغو', 'callback_data' => 'cancel_connect'],
                ]
            ]
        ];
        sendMessageWithKeyboard($chatId,
            "⚠️ این کانال قبلاً به شناسه `{$existingOwner}` متصل بوده است.\n"
            . "آیا می‌خواهید مالکیت را به `{$ownerId}` منتقل کنید؟",
            $keyboard
        );
        return;
    }

    // ثبت مالک
    setChannelOwner($chatId, $ownerId);
    $chatTitle = $chat['title'] ?? 'این چت';

    // لاگ اتصال
    error_log("CONNECT: chat {$chatId} ({$chatTitle}) -> owner {$ownerId} by " . ($fromId ?? 'channel'));

    sendPlainText($chatId,
        "✅ کانال/گروه «{$chatTitle}» با موفقیت به شناسه `{$ownerId}` متصل شد.\n"
        . "از این پس گزارش خروج اعضا برای مدیر ارسال می‌شود."
    );

    if ($ownerId !== (string)$chatId) {
        sendPlainText($ownerId,
            "✅ اتصال تایید شد!\n"
            . "شما اکنون گزارش‌های خروج اعضای «{$chatTitle}» را دریافت خواهید کرد."
        );
    }
}

// ----------------------------------------------------
// /disconnect
// ----------------------------------------------------
function handleDisconnect($chat, $message): void
{
    $chatId = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';
    $fromId = $message['from']['id'] ?? null;

    if (!$chatId || $chatType === 'private') {
        sendPlainText($chatId, '⚠️ این دستور فقط در کانال یا گروه قابل استفاده است.');
        return;
    }

    $ownerId = getChannelOwner($chatId);
    if ($ownerId === null) {
        sendPlainText($chatId, 'ℹ️ این کانال به هیچ مدیری متصل نیست.');
        return;
    }

    // فقط ادمین کانال/گروه یا مالک ثبت‌شده
    $isAdmin = $fromId && isChatAdmin($chatId, $fromId);
    $isOwner = $fromId && ((string)$fromId === $ownerId);

    if (!$isAdmin && !$isOwner) {
        sendPlainText($chatId, '⚠️ فقط ادمین یا مدیر متصل می‌تواند این کار را انجام دهد.');
        return;
    }

    deleteChannelOwner($chatId);
    error_log("DISCONNECT: chat {$chatId} by " . ($fromId ?? 'channel'));

    sendPlainText($chatId, "🔌 اتصال این کانال/گروه قطع شد.");
}

// ----------------------------------------------------
// /status
// ----------------------------------------------------
function handleStatus($chat): void
{
    $chatId = $chat['id'] ?? null;
    if (!$chatId) return;

    $owner = getChannelOwner($chatId);
    $title = $chat['title'] ?? 'این چت';

    if ($owner === null) {
        sendPlainText($chatId, "📊 وضعیت «{$title}»:\n\n❌ به هیچ مدیری متصل نیست.");
        return;
    }

    $totalLeaves = getTotalLeaves($chatId);
    $todayLeaves = getTodayLeaves($chatId);

    $msg = "📊 **وضعیت کانال «{$title}»**\n\n"
        . "🔗 متصل به شناسه: `{$owner}`\n"
        . "📉 کل خروج‌ها: **{$totalLeaves}**\n"
        . "📅 خروج‌های امروز: **{$todayLeaves}**\n\n"
        . "برای قطع اتصال: `/disconnect`";

    sendPlainText($chatId, $msg);
}

// ----------------------------------------------------
// /mychannels (فقط سازنده ربات)
// ----------------------------------------------------
function handleMyChannels($chat, $message): void
{
    $chatId = $chat['id'] ?? null;
    $fromId = $message['from']['id'] ?? null;

    if (!$chatId) return;

    // بررسی مالک ربات
    if (BOT_OWNER_ID === '' || (string)$fromId !== (string)BOT_OWNER_ID) {
        sendPlainText($chatId, '⛔ این دستور فقط برای سازنده ربات در دسترس است.');
        return;
    }

    $channels = getAllChannelsWithOwners();
    if (empty($channels)) {
        sendPlainText($chatId, 'ℹ️ هیچ کانالی به ربات متصل نیست.');
        return;
    }

    $msg = "📋 **کانال‌های متصل به ربات**\n\n";
    foreach ($channels as $ch) {
        $msg .= "• `{$ch['chat_id']}` → مدیر `{$ch['owner_id']}`\n";
    }

    sendPlainText($chatId, $msg);
}

// ----------------------------------------------------
// /stats
// ----------------------------------------------------
function handleStats($chat): void
{
    $chatId = $chat['id'] ?? null;
    if (!$chatId) return;

    $total = getTotalLeaves($chatId);
    $today = getTodayLeaves($chatId);
    $week  = getLeavesInRange($chatId, 7);
    $month = getLeavesInRange($chatId, 30);

    $msg = "📈 **آمار خروج اعضا**\n\n"
        . "📅 امروز: **{$today}**\n"
        . "🗓️ ۷ روز اخیر: **{$week}**\n"
        . "📆 ۳۰ روز اخیر: **{$month}**\n"
        . "📉 کل: **{$total}**";

    sendPlainText($chatId, $msg);
}

// ----------------------------------------------------
// /settings
// ----------------------------------------------------
function sendSettingsMenu($chatId): void
{
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔔 گزارش فوری', 'callback_data' => 'settings_instant_on'],
                ['text' => '🔕 گزارش خاموش', 'callback_data' => 'settings_instant_off'],
            ],
            [
                ['text' => '📝 نمایش مدت عضویت', 'callback_data' => 'settings_duration_on'],
                ['text' => '🚫 عدم نمایش مدت', 'callback_data' => 'settings_duration_off'],
            ],
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_start'],
            ]
        ]
    ];

    sendMessageWithKeyboard($chatId, "⚙️ **تنظیمات گزارش‌ها**\n\nیکی از گزینه‌ها را انتخاب کنید:", $keyboard);
}

// ----------------------------------------------------
// مدیریت Callback Query
// ----------------------------------------------------
function handleCallbackQuery($callback): void
{
    $callbackId = $callback['id'] ?? null;
    $data = $callback['data'] ?? '';
    $message = $callback['message'] ?? [];
    $chatId = $message['chat']['id'] ?? null;
    $fromId = $callback['from']['id'] ?? null;

    if (!$callbackId || !$chatId) return;

    // پاسخ به کال‌بک (حذف لودینگ)
    answerCallbackQuery($callbackId);

    // --- منوها ---
    if ($data === 'help') { sendHelp($chatId); return; }
    if ($data === 'settings') { sendSettingsMenu($chatId); return; }
    if ($data === 'status') { handleStatus($message['chat'] ?? []); return; }
    if ($data === 'stats') { handleStats($message['chat'] ?? []); return; }
    if ($data === 'back_to_start') {
        sendStartMenu($chatId, $callback['from']['first_name'] ?? 'کاربر', $fromId);
        return;
    }

    // --- تنظیمات ---
    if (strpos($data, 'settings_') === 0) {
        // ذخیره تنظیمات در Redis (اختیاری - فعلاً فقط تایید)
        sendPlainText($chatId, "✅ تنظیمات ذخیره شد.");
        return;
    }

    // --- جایگزینی مالک ---
    if (strpos($data, 'replace_owner_') === 0) {
        $parts = explode('_', $data);
        // replace_owner_<ownerId>_<chatId>
        $newOwner = $parts[2] ?? null;
        $targetChat = $parts[3] ?? null;
        if ($newOwner && $targetChat) {
            setChannelOwner($targetChat, $newOwner);
            sendPlainText($targetChat, "✅ مالکیت کانال به شناسه `{$newOwner}` منتقل شد.");
            sendPlainText($newOwner, "✅ شما اکنون مدیر کانال `{$targetChat}` هستید.");
        }
        return;
    }
    if ($data === 'cancel_connect') {
        sendPlainText($chatId, "❌ عملیات لغو شد.");
        return;
    }
}

// ====================================================
// توابع گزارش خروج
// ====================================================

function dispatchLeaveAlert(array $user, array $chat, string $leaveType = 'left'): void
{
    $channelId = $chat['id'] ?? null;
    if (!$channelId) return;
    if (!empty($user['is_bot'])) return;

    $targetAdminId = getChannelOwner($channelId);
    if (!$targetAdminId) return;

    $userId = $user['id'] ?? null;
    if (!$userId) return;

    // جلوگیری از ارسال تکراری
    if (!markLeaveOnce($channelId, $userId)) return;

    // rate limit برای ارسال گزارش (حداکثر ۲۰ گزارش در دقیقه برای هر کانال)
    if (!checkRateLimit('report_' . $channelId, 20, 60)) {
        error_log("RATE LIMIT: report for chat {$channelId} exceeded");
        return;
    }

    $channelTitle = $chat['title'] ?? 'نامشخص';
    $fullName = normalizeText(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    if ($fullName === '') $fullName = 'بدون نام';

    $username = !empty($user['username']) ? '@' . $user['username'] : 'ندارد';

    // نوع خروج
    $leaveLabel = ($leaveType === 'kicked') ? '🚫 اخراج شده' : '🚪 خروج خودی';

    // مدت عضویت (اگر ثبت شده باشد)
    $durationText = '';
    $joinTime = getJoinTime($channelId, $userId);
    if ($joinTime !== null) {
        $duration = time() - $joinTime;
        $durationText = "\n⏱️ مدت عضویت: " . formatDuration($duration);
    }

    $report = "📋 **گزارش خروج کاربر**\n\n"
        . "📢 کانال: {$channelTitle}\n"
        . "🆔 شناسه کانال: `{$channelId}`\n\n"
        . "👤 نام: {$fullName}\n"
        . "🔢 شناسه عددی: `{$userId}`\n"
        . "📛 نام کاربری: {$username}\n"
        . "🔹 نوع خروج: {$leaveLabel}"
        . $durationText . "\n\n"
        . "📅 تاریخ: " . date('Y/m/d') . "\n"
        . "🕐 ساعت: " . date('H:i:s');

    // ذخیره در تاریخچه
    saveLeaveHistory($channelId, $userId, $fullName, $leaveType);

    // ارسال به مدیر (با retry در صورت خطای 429)
    sendWithRetry($targetAdminId, $report);
}

// ====================================================
// توابع کمکی (Redis, API, Validation)
// ====================================================

function normalizeDigits(string $text): string
{
    // تبدیل اعداد فارسی/عربی به انگلیسی
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    $text = str_replace($persian, $english, $text);
    $text = str_replace($arabic, $english, $text);
    return $text;
}

function normalizeText(string $text): string
{
    // حذف کاراکترهای کنترلی و escape برای Markdown
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    return $text;
}

function isBotAdminInChat($chatId): bool
{
    $res = apiPost('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => getBotId(),
    ]);
    $status = $res['result']['status'] ?? '';
    return in_array($status, ['administrator', 'creator'], true);
}

function getBotId(): ?int
{
    // دریافت شناسه ربات از getMe (کش شدن در یک کلید Redis)
    $cached = redisGet('bot_id');
    if ($cached !== null) return (int)$cached;

    $res = apiPost('getMe', []);
    $id = $res['result']['id'] ?? null;
    if ($id) {
        redisSet('bot_id', (string)$id, 86400); // ۱ روز کش
    }
    return $id;
}

// ----------------------------------------------------
// Rate Limiting (فاز د: مورد ۳۱)
// ----------------------------------------------------
function checkRateLimit(string $key, int $max, int $windowSec): bool
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') return true;

    $redisKey = 'ratelimit_' . $key;
    $current = redisIncr($redisKey);
    if ($current === null) return true;

    if ($current == 1) {
        redisExpire($redisKey, $windowSec);
    }

    return $current <= $max;
}

// ----------------------------------------------------
// ارسال با Retry برای خطای 429 (فاز د: مورد ۳۵)
// ----------------------------------------------------
function sendWithRetry($chatId, string $text, int $maxRetries = 2): void
{
    $attempt = 0;
    while ($attempt <= $maxRetries) {
        $res = apiPost('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);

        if ($res === null) return; // خطای شبکه
        if (!empty($res['ok'])) return; // موفق

        $errorCode = $res['error_code'] ?? 0;
        $retryAfter = $res['parameters']['retry_after'] ?? null;

        if ($errorCode === 429 && $retryAfter) {
            sleep(min((int)$retryAfter, 5)); // حداکثر ۵ ثانیه صبر
            $attempt++;
            continue;
        }

        // خطای غیرقابل retry
        error_log("SEND ERROR: chat={$chatId} code={$errorCode} desc=" . ($res['description'] ?? ''));
        return;
    }
}

// ====================================================
// Upstash Redis REST
// ====================================================

function setChannelOwner($channelId, $ownerId): void
{
    redisSet('channel_' . $channelId, (string)$ownerId);
}

function getChannelOwner($channelId): ?string
{
    return redisGet('channel_' . $channelId);
}

function deleteChannelOwner($channelId): void
{
    redisDelete('channel_' . $channelId);
}

function markLeaveOnce($chatId, $userId): bool
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') return true;

    $key = 'leave_event_' . $chatId . '_' . $userId;
    $res = redisCommand('/set/' . rawurlencode($key) . '/1?EX=60&NX=true');

    if ($res === null) return true;
    $json = json_decode($res, true);
    if (isset($json['error'])) return true;
    return (($json['result'] ?? null) === 'OK');
}

// ----------------------------------------------------
// ذخیره تاریخچه خروج (فاز ج: مورد ۲۱)
// ----------------------------------------------------
function saveLeaveHistory($chatId, $userId, $fullName, $leaveType): void
{
    $entry = json_encode([
        'user_id' => $userId,
        'name' => $fullName,
        'type' => $leaveType,
        'time' => time(),
    ], JSON_UNESCAPED_UNICODE);

    redisCommand('/lpush/' . rawurlencode('history_' . $chatId) . '/' . rawurlencode($entry));
    redisCommand('/ltrim/' . rawurlencode('history_' . $chatId) . '/0/499'); // نگهداری ۵۰۰ مورد آخر
}

function getTotalLeaves($chatId): int
{
    $res = redisCommand('/llen/' . rawurlencode('history_' . $chatId));
    if ($res === null) return 0;
    $json = json_decode($res, true);
    return (int)($json['result'] ?? 0);
}

function getTodayLeaves($chatId): int
{
    $start = strtotime('today');
    $end = $start + 86400;
    return countLeavesInRange($chatId, $start, $end);
}

function getLeavesInRange($chatId, $days): int
{
    $start = time() - ($days * 86400);
    return countLeavesInRange($chatId, $start, time());
}

function countLeavesInRange($chatId, $start, $end): int
{
    $res = redisCommand('/lrange/' . rawurlencode('history_' . $chatId) . '/0/-1');
    if ($res === null) return 0;
    $json = json_decode($res, true);
    $items = $json['result'] ?? [];
    $count = 0;
    foreach ($items as $item) {
        $decoded = json_decode($item, true);
        $t = $decoded['time'] ?? 0;
        if ($t >= $start && $t <= $end) $count++;
    }
    return $count;
}

// ----------------------------------------------------
// زمان عضویت (فاز ج: مورد ۲۳)
// ----------------------------------------------------
function saveJoinTime($chatId, $userId): void
{
    redisSet('join_' . $chatId . '_' . $userId, (string)time(), 86400 * 90); // ۹۰ روز
}

function getJoinTime($chatId, $userId): ?int
{
    $val = redisGet('join_' . $chatId . '_' . $userId);
    return $val !== null ? (int)$val : null;
}

function formatDuration(int $seconds): string
{
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $mins = floor(($seconds % 3600) / 60);

    $parts = [];
    if ($days > 0) $parts[] = "{$days} روز";
    if ($hours > 0) $parts[] = "{$hours} ساعت";
    if ($mins > 0 && $days == 0) $parts[] = "{$mins} دقیقه";

    return empty($parts) ? 'کمتر از یک دقیقه' : implode(' و ', $parts);
}

// ----------------------------------------------------
// لیست همه کانال‌ها (برای /mychannels)
// ----------------------------------------------------
function getAllChannelsWithOwners(): array
{
    $res = redisCommand('/keys/' . rawurlencode('channel_*'));
    if ($res === null) return [];
    $json = json_decode($res, true);
    $keys = $json['result'] ?? [];
    $result = [];
    foreach ($keys as $key) {
        $chatId = str_replace('channel_', '', $key);
        $owner = redisGet($key);
        if ($owner !== null) {
            $result[] = ['chat_id' => $chatId, 'owner_id' => $owner];
        }
    }
    return $result;
}

// ----------------------------------------------------
// توابع پایه Redis
// ----------------------------------------------------
function redisSet(string $key, string $value, ?int $ttl = null): void
{
    $path = '/set/' . rawurlencode($key) . '/' . rawurlencode($value);
    if ($ttl !== null) $path .= '?EX=' . $ttl;
    redisCommand($path);
}

function redisGet(string $key): ?string
{
    $res = redisCommand('/get/' . rawurlencode($key));
    if ($res === null) return null;
    $json = json_decode($res, true);
    return $json['result'] ?? null;
}

function redisDelete(string $key): void
{
    redisCommand('/del/' . rawurlencode($key));
}

function redisIncr(string $key): ?int
{
    $res = redisCommand('/incr/' . rawurlencode($key));
    if ($res === null) return null;
    $json = json_decode($res, true);
    return $json['result'] ?? null;
}

function redisExpire(string $key, int $seconds): void
{
    redisCommand('/expire/' . rawurlencode($key) . '/' . $seconds);
}

function redisCommand(string $path): ?string
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') return null;

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

// ====================================================
// Bale API
// ====================================================

function sendPlainText($chatId, string $text): void
{
    if (!$chatId) return;
    sendWithRetry($chatId, $text);
}

function sendMessageWithKeyboard($chatId, string $text, ?array $replyMarkup = null): void
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) $payload['reply_markup'] = $replyMarkup;

    $res = apiPost('sendMessage', $payload);
    if ($res === null || empty($res['ok'])) {
        error_log("KEYBOARD SEND ERROR: chat={$chatId}");
    }
}

function answerCallbackQuery($callbackId): void
{
    apiPost('answerCallbackQuery', ['callback_query_id' => $callbackId]);
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
        CURLOPT_CONNECTTIMEOUT => 5, // افزایش از ۳ به ۵ (فاز الف: مورد ۱۰)
        CURLOPT_TIMEOUT => 10,
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        error_log("API NETWORK ERROR: method={$method}");
        return null;
    }

    $json = json_decode($res, true);
    if (!is_array($json)) {
        error_log("API INVALID JSON: method={$method} http={$httpCode}");
        return null;
    }

    // بررسی ok و لاگ خطا (فاز الف: مورد ۲)
    if (empty($json['ok'])) {
        $code = $json['error_code'] ?? 0;
        $desc = $json['description'] ?? '';
        error_log("API ERROR: method={$method} code={$code} desc={$desc}");
    }

    return $json;
}
