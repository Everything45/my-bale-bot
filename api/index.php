
<?php

date_default_timezone_set('Asia/Tehran');

define('BOT_TOKEN', trim(getenv('BOT_TOKEN') ?: ''));
define('REDIS_URL', trim(getenv('UPSTASH_REDIS_REST_URL') ?: ''));
define('REDIS_TOKEN', trim(getenv('UPSTASH_REDIS_REST_TOKEN') ?: ''));
define('BOT_OWNER_ID', trim(getenv('BOT_OWNER_ID') ?: ''));

if (BOT_TOKEN === '') {
    http_response_code(500);
    echo 'Error: BOT_TOKEN environment variable is missing.';
    exit;
}

// ----------------------------------------------------
// ۰. دریافت ورودی و تست سلامت ربات در مرورگر
// ----------------------------------------------------
$input = file_get_contents('php://input');

if ($input === '' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    // امکان ست کردن خودکار وبهوک با فراخوانی ?setwebhook=1 در مرورگر
    if (isset($_GET['setwebhook'])) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https';
        $host  = $_SERVER['HTTP_HOST'] ?? '';
        $webhookUrl = $proto . '://' . $host . '/api/index.php';
        $res = apiPost('setWebhook', ['url' => $webhookUrl]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'Webhook Configuration Attempt',
            'webhook_url' => $webhookUrl,
            'bale_response' => $res
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(200);
    echo '✅ Bale Bot is running successfully!';
    exit;
}

$update = json_decode($input, true);
if (!is_array($update)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// ----------------------------------------------------
// ۰.۱ پردازش Callback Query (دکمه‌های شیشه‌ای)
// ----------------------------------------------------
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    http_response_code(200);
    echo 'OK';
    exit;
}

// ----------------------------------------------------
// ۱. پردازش پیام‌های متنی و دستورات
// ----------------------------------------------------
if (isset($update['message']['text'])) {
    $message    = $update['message'];
    $chat       = $message['chat'] ?? [];
    $chatId     = $chat['id'] ?? null;
    $chatType   = $chat['type'] ?? '';
    $senderName = $message['from']['first_name'] ?? 'کاربر';

    [$command, $args] = parseCommand($message['text']);

    if ($command === 'start' && $chatType === 'private' && $chatId) {
        sendStartMenu($chatId, $senderName);
        http_response_code(200);
        exit;
    }

    if ($command === 'help') {
        sendHelp($chatId);
        http_response_code(200);
        exit;
    }

    if ($command === 'connect') {
        handleConnect($chat, $message, $args);
        http_response_code(200);
        exit;
    }

    if ($command === 'disconnect') {
        handleDisconnect($chat, $message);
        http_response_code(200);
        exit;
    }

    if ($command === 'status') {
        handleStatus($chat);
        http_response_code(200);
        exit;
    }

    if ($command === 'mychannels') {
        handleMyChannels($chat, $message);
        http_response_code(200);
        exit;
    }

    if ($command === 'stats') {
        handleStats($chat);
        http_response_code(200);
        exit;
    }

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

// ثبت زمان عضویت از new_chat_members
if (isset($update['message']['new_chat_members'])) {
    $chat = $update['message']['chat'] ?? [];
    if (!empty($chat['id'])) {
        foreach ($update['message']['new_chat_members'] as $newMember) {
            if (!empty($newMember['id']) && empty($newMember['is_bot'])) {
                saveJoinTime($chat['id'], $newMember['id']);
            }
        }
    }
}

http_response_code(200);
echo 'OK';
exit;

// ====================================================
// توابع اصلی منوها و دستورات
// ====================================================

function parseCommand(string $text): array
{
    $text = trim($text);
    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/us', $text, $m)) {
        return [null, null];
    }
    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

function sendStartMenu($chatId, string $senderName): void
{
    $safeName = cleanText($senderName);
    $msg = "سلام {$safeName} عزیز 👋\n\n"
        . "به *آنفالویاب* خوش آمدید. 🕵️‍♂️\n"
        . "برای اتصال کانال یا گروه، ابتدا ربات را در آن *ادمین* کرده و سپس دستور زیر را در داخل کانال/گروه ارسال کنید:\n\n"
        . "`/connect {$chatId}`\n\n"
        . "شناسه عددی شما:\n`{$chatId}`";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📘 راهنما', 'callback_data' => 'help'],
                ['text' => '⚙️ تنظیمات', 'callback_data' => 'settings'],
            ],
            [
                ['text' => '📊 وضعیت کانال', 'callback_data' => 'status'],
                ['text' => '📈 آمار خروج', 'callback_data' => 'stats'],
            ],
        ]
    ];

    sendMessageWithKeyboard($chatId, $msg, $keyboard);
}

function sendHelp($chatId): void
{
    $msg = "📘 *راهنمای دستورات آنفالویاب*\n\n"
        . "🔹 `/start` — دریافت شناسه کاربری و منو\n"
        . "🔹 `/help` — مشاهده همین راهنما\n"
        . "🔹 `/connect ID` — اتصال کانال/گروه به مدیر\n"
        . "🔹 `/disconnect` — قطع اتصال ربات از کانال\n"
        . "🔹 `/status` — وضعیت اتصال کانال فعلی\n"
        . "🔹 `/stats` — مشاهده آمار خروج‌ها\n"
        . "🔹 `/settings` — تنظیمات اعلان‌ها\n"
        . "🔹 `/mychannels` — لیست کانال‌های فعال (ویژه سازنده)\n\n"
        . "⚠️ *نحوه اتصال:* ربات را در کانال یا گروه ادمین کرده و سپس دستور `/connect YOUR_ID` را داخل کانال ارسال کنید.";

    sendMessageWithKeyboard($chatId, $msg, null);
}

function handleConnect($chat, $message, $args): void
{
    $chatId   = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';

    if (!$chatId || $chatType === 'private') {
        sendPlainText($chatId, '⚠️ این دستور فقط باید داخل کانال یا گروه ارسال شود.');
        return;
    }
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
        sendPlainText($chatId, '⚠️ دیتابیس پایدار Redis در متغیرهای محیطی Vercel پیکربندی نشده است.');
        return;
    }

    $args = normalizeDigits(trim((string)$args));
    if (!preg_match('/^\d+$/', $args) || (int)$args <= 0) {
        sendPlainText($chatId, "⚠️ فرمت دستور صحیح نیست.\nنمونه صحیح:\n`/connect 12345678`");
        return;
    }

    $ownerId = (string)$args;
    $fromId  = $message['from']['id'] ?? null;

    if (!isBotAdminInChat($chatId)) {
        sendPlainText($chatId, '⚠️ ربات ادمین این کانال/گروه نیست. لطفاً ابتدا دسترسی ادمین به ربات بدهید.');
        return;
    }

    if ($chatType !== 'channel') {
        if (!$fromId || !isChatAdmin($chatId, $fromId)) {
            sendPlainText($chatId, '⚠️ فقط مدیران گروه مجاز به اتصال ربات هستند.');
            return;
        }
    }

    $existingOwner = getChannelOwner($chatId);
    if ($existingOwner !== null && $existingOwner !== $ownerId) {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، جایگزین شود', 'callback_data' => "replace_owner:{$ownerId}:{$chatId}"],
                    ['text' => '❌ لغو عملیات', 'callback_data' => 'cancel_connect'],
                ]
            ]
        ];
        sendMessageWithKeyboard(
            $chatId,
            "⚠️ این چت قبلاً به شناسه `{$existingOwner}` متصل شده است.\nآیا می‌خواهید شناسه جدید (`{$ownerId}`) جایگزین شود؟",
            $keyboard
        );
        return;
    }

    setChannelOwner($chatId, $ownerId);
    $chatTitle = cleanText($chat['title'] ?? 'این چت');

    sendPlainText($chatId, "✅ چت «{$chatTitle}» با موفقیت به مدیر با شناسه `{$ownerId}` متصل گردید.");

    if ($ownerId !== (string)$chatId) {
        sendPlainText($ownerId, "✅ اتصال تایید شد!\nشما از این پس گزارش‌های خروج چت «{$chatTitle}» را دریافت خواهید کرد.");
    }
}

function handleDisconnect($chat, $message): void
{
    $chatId   = $chat['id'] ?? null;
    $chatType = $chat['type'] ?? '';
    $fromId   = $message['from']['id'] ?? null;

    if (!$chatId || $chatType === 'private') {
        sendPlainText($chatId, '⚠️ این دستور فقط در داخل کانال یا گروه قابل استفاده است.');
        return;
    }

    $ownerId = getChannelOwner($chatId);
    if ($ownerId === null) {
        sendPlainText($chatId, 'ℹ️ این چت به هیچ مدیری متصل نیست.');
        return;
    }

    $isAdmin = $fromId && isChatAdmin($chatId, $fromId);
    $isOwner = $fromId && ((string)$fromId === (string)$ownerId);

    if ($chatType !== 'channel' && !$isAdmin && !$isOwner) {
        sendPlainText($chatId, '⚠️ فقط ادمین یا مدیر متصل اجازه قطع اتصال را دارد.');
        return;
    }

    deleteChannelOwner($chatId);
    sendPlainText($chatId, "🔌 اتصال ربات از این چت قطع شد.");
}

function handleStatus($chat): void
{
    $chatId = $chat['id'] ?? null;
    if (!$chatId) return;

    $owner = getChannelOwner($chatId);
    $title = cleanText($chat['title'] ?? 'این چت');

    if ($owner === null) {
        sendPlainText($chatId, "📊 وضعیت «{$title}»:\n\n❌ به هیچ مدیری متصل نیست.");
        return;
    }

    $totalLeaves = getTotalLeaves($chatId);
    $todayLeaves = getTodayLeaves($chatId);

    $msg = "📊 *وضعیت «{$title}»*\n\n"
        . "🔗 مدیر متصل: `{$owner}`\n"
        . "📉 کل خروج‌ها: *{$totalLeaves}*\n"
        . "📅 خروج‌های امروز: *{$todayLeaves}*\n\n"
        . "برای لغو اتصال: `/disconnect`";

    sendPlainText($chatId, $msg);
}

function handleMyChannels($chat, $message): void
{
    $chatId = $chat['id'] ?? null;
    $fromId = $message['from']['id'] ?? null;

    if (!$chatId) return;

    if (BOT_OWNER_ID === '' || (string)$fromId !== (string)BOT_OWNER_ID) {
        sendPlainText($chatId, '⛔ این دستور فقط مخصوص سازنده ربات است.');
        return;
    }

    $channels = getAllChannelsWithOwners();
    if (empty($channels)) {
        sendPlainText($chatId, 'ℹ️ در حال حاضر هیچ کانالی به سیستم متصل نیست.');
        return;
    }

    $msg = "📋 *کانال‌ها و گروه‌های متصل:*\n\n";
    foreach ($channels as $ch) {
        $msg .= "• چت: `{$ch['chat_id']}` ⬅️ مدیر: `{$ch['owner_id']}`\n";
    }

    sendPlainText($chatId, $msg);
}

function handleStats($chat): void
{
    $chatId = $chat['id'] ?? null;
    if (!$chatId) return;

    $total = getTotalLeaves($chatId);
    $today = getTodayLeaves($chatId);
    $week  = getLeavesInRange($chatId, 7);
    $month = getLeavesInRange($chatId, 30);

    $msg = "📈 *آمار خروج اعضا*\n\n"
        . "📅 امروز: *{$today}*\n"
        . "🗓️ ۷ روز گذشته: *{$week}*\n"
        . "📆 ۳۰ روز گذشته: *{$month}*\n"
        . "📉 کل آمار ثبت‌شده: *{$total}*";

    sendPlainText($chatId, $msg);
}

function sendSettingsMenu($chatId): void
{
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔔 اعلان آنی: فعال', 'callback_data' => 'settings_instant_on'],
                ['text' => '🔕 اعلان آنی: خاموش', 'callback_data' => 'settings_instant_off'],
            ],
            [
                ['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_to_start'],
            ]
        ]
    ];

    sendMessageWithKeyboard($chatId, "⚙️ *تنظیمات اعلانات و گزارش‌ها:*", $keyboard);
}

function handleCallbackQuery(array $callback): void
{
    $callbackId = $callback['id'] ?? null;
    $data       = $callback['data'] ?? '';
    $message    = $callback['message'] ?? [];
    $chatId     = $message['chat']['id'] ?? null;

    if (!$callbackId || !$chatId) return;

    answerCallbackQuery($callbackId);

    if ($data === 'help') { sendHelp($chatId); return; }
    if ($data === 'settings') { sendSettingsMenu($chatId); return; }
    if ($data === 'status') { handleStatus($message['chat'] ?? []); return; }
    if ($data === 'stats') { handleStats($message['chat'] ?? []); return; }
    if ($data === 'back_to_start') {
        sendStartMenu($chatId, $callback['from']['first_name'] ?? 'کاربر');
        return;
    }

    if (strpos($data, 'settings_') === 0) {
        sendPlainText($chatId, "✅ تنظیمات با موفقیت به‌روزرسانی شد.");
        return;
    }

    if (strpos($data, 'replace_owner:') === 0) {
        $parts = explode(':', $data);
        $newOwner = $parts[1] ?? null;
        $targetChat = $parts[2] ?? null;
        if ($newOwner && $targetChat) {
            setChannelOwner($targetChat, $newOwner);
            sendPlainText($targetChat, "✅ مالکیت کانال به شناسه `{$newOwner}` منتقل گردید.");
            sendPlainText($newOwner, "✅ شما به عنوان مدیر جدید برای کانال `{$targetChat}` تنظیم شدید.");
        }
        return;
    }

    if ($data === 'cancel_connect') {
        sendPlainText($chatId, "❌ فرآیند اتصال لغو گردید.");
        return;
    }
}

// ====================================================
// ارسال هشدار و گزارش خروج
// ====================================================

function dispatchLeaveAlert(array $user, array $chat, string $leaveType = 'left'): void
{
    $channelId = $chat['id'] ?? null;
    if (!$channelId || !empty($user['is_bot'])) return;

    $targetAdminId = getChannelOwner($channelId);
    if (!$targetAdminId) return;

    $userId = $user['id'] ?? null;
    if (!$userId) return;

    if (!markLeaveOnce($channelId, $userId)) return;

    if (!checkRateLimit('report_' . $channelId, 25, 60)) {
        error_log("RATE LIMIT EXCEEDED for chat: {$channelId}");
        return;
    }

    $channelTitle = cleanText($chat['title'] ?? 'کانال/گروه');
    $fullName = cleanText(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    if ($fullName === '') $fullName = 'بدون نام';

    $username = !empty($user['username']) ? '@' . cleanText($user['username']) : 'ندارد';
    $leaveLabel = ($leaveType === 'kicked') ? '🚫 اخراج توسط مدیر' : '🚪 خروج اختیاری';

    $durationText = '';
    $joinTime = getJoinTime($channelId, $userId);
    if ($joinTime !== null) {
        $duration = time() - $joinTime;
        $durationText = "\n⏱️ مدت زمان عضویت: " . formatDuration($duration);
    }

    $report = "📋 *گزارش ترک عضو*\n\n"
        . "📢 عنوان: {$channelTitle}\n"
        . "🆔 شناسه چت: `{$channelId}`\n\n"
        . "👤 کاربر: {$fullName}\n"
        . "🔢 شناسه عددی: `{$userId}`\n"
        . "📛 نام کاربری: {$username}\n"
        . "🔹 وضعیت: {$leaveLabel}"
        . $durationText . "\n\n"
        . "📅 تاریخ: " . date('Y/m/d') . "\n"
        . "🕐 ساعت: " . date('H:i:s');

    saveLeaveHistory($channelId, $userId, $fullName, $leaveType);
    sendWithRetry($targetAdminId, $report);
}

// ====================================================
// توابع کار با API و متدهای کمکی
// ====================================================

function cleanText(string $text): string
{
    // حذف کاراکترهایی که باعث تداخل در Markdown بله می‌شوند
    return str_replace(['*', '_', '`', '[', ']'], '', $text);
}

function normalizeDigits(string $text): string
{
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    $text = str_replace($persian, $english, $text);
    return str_replace($arabic, $english, $text);
}

function isBotAdminInChat($chatId): bool
{
    $botId = getBotId();
    if (!$botId) return false;

    $res = apiPost('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $botId,
    ]);
    $status = $res['result']['status'] ?? '';
    return in_array($status, ['administrator', 'creator'], true);
}

function isChatAdmin($chatId, $userId): bool
{
    $res = apiPost('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
    ]);
    $status = $res['result']['status'] ?? '';
    return in_array($status, ['administrator', 'creator'], true);
}

function getBotId(): ?int
{
    $cached = redisGet('system:bot_id');
    if ($cached !== null) return (int)$cached;

    $res = apiPost('getMe', []);
    $id = $res['result']['id'] ?? null;
    if ($id) {
        redisSet('system:bot_id', (string)$id, 86400 * 7);
    }
    return $id;
}

function checkRateLimit(string $key, int $max, int $windowSec): bool
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') return true;

    $redisKey = 'ratelimit:' . $key;
    $current = redisIncr($redisKey);
    if ($current === null) return true;

    if ($current === 1) {
        redisExpire($redisKey, $windowSec);
    }

    return $current <= $max;
}

function sendWithRetry($chatId, string $text, int $maxRetries = 2): void
{
    $attempt = 0;
    while ($attempt <= $maxRetries) {
        $res = apiPost('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);

        if ($res !== null && !empty($res['ok'])) return;

        // اگر به خاطر Markdown خطا داد، بدون فرمت ارسال شود
        if (isset($res['error_code']) && $res['error_code'] == 400) {
            apiPost('sendMessage', [
                'chat_id' => $chatId,
                'text' => str_replace(['*', '`'], '', $text),
            ]);
            return;
        }

        $errorCode  = $res['error_code'] ?? 0;
        $retryAfter = $res['parameters']['retry_after'] ?? null;

        if ($errorCode === 429 && $retryAfter) {
            sleep(min((int)$retryAfter, 5));
            $attempt++;
            continue;
        }

        error_log("SEND FAILED: chat={$chatId} code={$errorCode}");
        return;
    }
}

// ====================================================
// ارتباط با Upstash Redis REST
// ====================================================

function setChannelOwner($channelId, $ownerId): void
{
    redisSet('channel:' . $channelId, (string)$ownerId);
}

function getChannelOwner($channelId): ?string
{
    return redisGet('channel:' . $channelId);
}

function deleteChannelOwner($channelId): void
{
    redisDelete('channel:' . $channelId);
}

function markLeaveOnce($chatId, $userId): bool
{
    if (REDIS_URL === '' || REDIS_TOKEN === '') return true;

    $key = 'leave_event:' . $chatId . ':' . $userId;
    $res = redisCommand('/set/' . rawurlencode($key) . '/1?EX=60&NX=true');

    if ($res === null) return true;
    $json = json_decode($res, true);
    if (isset($json['error'])) return true;
    return (($json['result'] ?? null) === 'OK');
}

function saveLeaveHistory($chatId, $userId, $fullName, $leaveType): void
{
    $entry = json_encode([
        'user_id' => $userId,
        'name'    => $fullName,
        'type'    => $leaveType,
        'time'    => time(),
    ], JSON_UNESCAPED_UNICODE);

    redisCommand('/lpush/' . rawurlencode('history:' . $chatId) . '/' . rawurlencode($entry));
    redisCommand('/ltrim/' . rawurlencode('history:' . $chatId) . '/0/499');
}

function getTotalLeaves($chatId): int
{
    $res = redisCommand('/llen/' . rawurlencode('history:' . $chatId));
    if ($res === null) return 0;
    $json = json_decode($res, true);
    return (int)($json['result'] ?? 0);
}

function getTodayLeaves($chatId): int
{
    $start = strtotime('today');
    $end   = $start + 86400;
    return countLeavesInRange($chatId, $start, $end);
}

function getLeavesInRange($chatId, $days): int
{
    $start = time() - ($days * 86400);
    return countLeavesInRange($chatId, $start, time());
}

function countLeavesInRange($chatId, $start, $end): int
{
    $res = redisCommand('/lrange/' . rawurlencode('history:' . $chatId) . '/0/-1');
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

function saveJoinTime($chatId, $userId): void
{
    redisSet('join:' . $chatId . ':' . $userId, (string)time(), 86400 * 90);
}

function getJoinTime($chatId, $userId): ?int
{
    $val = redisGet('join:' . $chatId . ':' . $userId);
    return $val !== null ? (int)$val : null;
}

function formatDuration(int $seconds): string
{
    $days  = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $mins  = floor(($seconds % 3600) / 60);

    $parts = [];
    if ($days > 0) $parts[] = "{$days} روز";
    if ($hours > 0) $parts[] = "{$hours} ساعت";
    if ($mins > 0 && $days == 0) $parts[] = "{$mins} دقیقه";

    return empty($parts) ? 'کمتر از یک دقیقه' : implode(' و ', $parts);
}

function getAllChannelsWithOwners(): array
{
    $res = redisCommand('/keys/' . rawurlencode('channel:*'));
    if ($res === null) return [];
    $json = json_decode($res, true);
    $keys = $json['result'] ?? [];
    $result = [];
    foreach ($keys as $key) {
        $chatId = str_replace('channel:', '', $key);
        $owner = redisGet($key);
        if ($owner !== null) {
            $result[] = ['chat_id' => $chatId, 'owner_id' => $owner];
        }
    }
    return $result;
}

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
    return isset($json['result']) ? (int)$json['result'] : null;
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
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res === false ? null : $res;
}

// ====================================================
// توابع ارتباط با Bale Bot API
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
    ];
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }

    $res = apiPost('sendMessage', $payload);
    if ($res === null || empty($res['ok'])) {
        // تلاش مجدد بدون Markdown در صورت بروز خطای فرمت
        unset($payload['parse_mode']);
        $payload['text'] = str_replace(['*', '`'], '', $text);
        apiPost('sendMessage', $payload);
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
        CURLOPT_CONNECTTIMEOUT => 5,
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

    if (empty($json['ok'])) {
        $code = $json['error_code'] ?? 0;
        $desc = $json['description'] ?? '';
        error_log("API ERROR: method={$method} code={$code} desc={$desc}");
    }

    return $json;
}

