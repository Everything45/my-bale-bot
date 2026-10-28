<?php
// src/bot_logic.php
declare(strict_types=1);

const KHATM_EDIT_THROTTLE_SECONDS = 3; // حداقل فاصله‌ی ادیت پست کانال
const KHATM_MAX_TITLE = 100;
const KHATM_MAX_DESC = 700;            // کپشن عکس حداکثر ۱۰۲۴ کاراکتر است
const KHATM_MAX_TARGET = 1000000000;
const KHATM_MAX_BUTTON = 1000000;

function finishRequest(): void {
    if (!headers_sent()) { http_response_code(200); }
    echo 'OK';
    exit;
}

function isBotOwner($userId): bool {
    if (!$userId || BOT_OWNER_ID === '') return false;
    return (string)$userId === (string)BOT_OWNER_ID;
}

function getBotId(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $stored = redisGet('bot_self_id');
    if ($stored !== null && $stored !== '') return $cached = $stored;
    $res = apiPost('getMe', []);
    if ($res === null || empty($res['ok']) || empty($res['result']['id'])) return null;
    $id = (string)$res['result']['id'];
    redisSet('bot_self_id', $id, 86400);
    return $cached = $id;
}

function getBotUsername(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $stored = redisGet('bot_self_username');
    if ($stored !== null && $stored !== '') return $cached = $stored;
    $res = apiPost('getMe', []);
    if ($res === null || empty($res['ok']) || empty($res['result']['username'])) return null;
    $username = (string)$res['result']['username'];
    redisSet('bot_self_username', $username, 86400);
    return $cached = $username;
}

function parseCommand(string $text): array {
    $text = trim($text);
    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/us', $text, $m)) return [null, null];
    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

/** ارقام فارسی و عربی را به لاتین تبدیل می‌کند */
function normalizeDigits(string $s): string {
    return strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

/* ───────────────── عضویت اجباری ───────────────── */

function checkBotIsAdminInChannel(string $channelRef): array {
    $channelRef = trim($channelRef);
    if ($channelRef === '') return ['ok' => false, 'error' => 'نام کانال خالی است.'];
    $ref = $channelRef;
    if ($ref[0] !== '@' && !preg_match('/^-?\d+$/', $ref)) $ref = '@' . $ref;
    $chatRes = apiPost('getChat', ['chat_id' => $ref]);
    if ($chatRes === null || empty($chatRes['ok'])) return ['ok' => false, 'error' => 'کانال پیدا نشد.'];
    $realId = (string)$chatRes['result']['id'];
    $botId = getBotId();
    if ($botId === null) return ['ok' => false, 'error' => 'شناسایی ربات ناموفق بود.'];
    $memberRes = apiPost('getChatMember', ['chat_id' => $realId, 'user_id' => $botId]);
    if ($memberRes === null || empty($memberRes['ok'])) return ['ok' => false, 'error' => 'بررسی وضعیت ربات ناموفق بود.'];
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['administrator', 'creator'], true)) return ['ok' => false, 'error' => 'ربات ادمین نیست.'];
    return ['ok' => true, 'real_id' => $realId, 'title' => $chatRes['result']['title'] ?? 'بدون نام'];
}

function checkSingleChannelMembership(string $channelRef, $userId): ?bool {
    $res = apiPost('getChatMember', ['chat_id' => $channelRef, 'user_id' => $userId]);
    if ($res === null || empty($res['ok'])) return null;
    $status = $res['result']['status'] ?? 'left';
    if ($status === 'restricted') return (bool)($res['result']['is_member'] ?? false);
    return in_array($status, ['creator', 'administrator', 'member'], true);
}

function checkUserForceJoin($userId, bool $reset = false): array {
    if (isBotOwner($userId)) return ['ok' => true, 'missing' => []];
    $assigned = assignRandomForceChannels($userId, $reset);
    if (empty($assigned)) return ['ok' => true, 'missing' => []];
    $missing = [];
    foreach ($assigned as $ch) {
        $result = checkSingleChannelMembership($ch, $userId);
        if ($result === null || $result === false) $missing[] = $ch;
    }
    return ['ok' => empty($missing), 'missing' => $missing];
}

function sendForceJoinPrompt($chatId, array $missingChannels): void {
    $msg = "⚠️ عضویت اجباری\n\nبرای استفاده از ربات، اول تو کانال‌های زیر عضو شوید و «عضو شدم» رو بزنید.\n\n";
    $inlineKeyboard = [];
    foreach ($missingChannels as $index => $ch) {
        $num = $index + 1;
        $cleanUser = ltrim($ch, '@');
        $msg .= "کانال " . $num . ": " . $ch . "\n";
        if (!preg_match('/^-?\d+$/', $ch)) {
            $inlineKeyboard[] = [['text' => "📢 ورود به کانال " . $num, 'url' => "https://ble.ir/" . $cleanUser]];
        }
    }
    $inlineKeyboard[] = [['text' => '✅ عضو شدم، بررسی کن', 'callback_data' => 'check_force_join']];
    sendMessageWithKeyboard($chatId, $msg, ['inline_keyboard' => $inlineKeyboard]);
}

/* ───────────────── منو و متن‌ها ───────────────── */

function sendStartMenu($chatId): void {
    $text = "👋 به ربات آنفالویاب خوش آمدید!\n\nلحظه‌ای می‌فهمید چه اعضایی از کانالتون خارج می‌شن.\n\nاز دکمه‌های پایین استفاده کنید 👇";
    $keyboard = [
        'keyboard' => [
            [['text' => '➕ اتصال کانال'], ['text' => '📋 کانال‌های من']],
            [['text' => '✨ قابلیت‌های ویژه'], ['text' => '🌟 اسپانسر']],
            [['text' => '📖 راهنما'], ['text' => '🐞 گزارش باگ']]
        ],
        'resize_keyboard' => true
    ];
    sendMessageWithKeyboard($chatId, $text, $keyboard);
}

function getHelpText(): string {
    return "📖 راهنمای استفاده\n━━━━━━━━━━━━━━━━━━\n\n"
         . "1️⃣ ربات رو تو کانالتون ادمین کنید (با دسترسی مدیریت اعضا)\n\n"
         . "2️⃣ کانال رو وصل کنید:\n/add نام یا آیدی کانال\n\n"
         . "━━━━━━━━━━━━━━━━━━\n🛠 دستورات\n\n"
         . "/my — لیست کانال‌ها\n/status — وضعیت ربات\n/history نام‌کانال — آخرین خروج‌ها\n/remove نام‌کانال — قطع اتصال\n/bug متن — گزارش مشکل\n/start — منوی اصلی";
}

function getAdminHelpText(): string {
    return "👑 پنل مدیریت (مخصوص مالک)\n━━━━━━━━━━━━━━━━━━\n\n"
         . "📊 مشاهده\n/zk9xch — کل کانال‌ها\n/zk9xfl — استخر عضویت اجباری\n/zk9xst [آیدی] — دیباگ\n\n"
         . "⚙️ تنظیمات\n/zk9xfa کانال — افزودن به استخر\n/zk9xfd کانال — حذف از استخر\n/zk9xsp متن — تنظیم اسپانسر\n\n"
         . "━━━━━━━━━━━━━━━━━━\n⚠️ این دستورات رو جایی منتشر نکنید.";
}

function resolveChannelId(string $target): ?string {
    $target = trim($target);
    if ($target === '') return null;
    if (preg_match('/^-?\d+$/', $target)) return $target;
    if ($target[0] !== '@') $target = '@' . $target;
    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) return null;
    return (string)$chatRes['result']['id'];
}

/* ───────────────── مدیریت کانال ───────────────── */

function handleConnect($chatId, $userId, string $arg): void {
    if ($arg === '') { sendPlainText($chatId, "❌ نام کانال رو بعد از دستور add بنویسید.\n\nمثال:\n/add mynews"); return; }
    $target = trim($arg);
    if ($target[0] !== '@' && !preg_match('/^-?\d+$/', $target)) $target = '@' . $target;
    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) { sendPlainText($chatId, "❌ کانال پیدا نشد.\n\nچک کنید ربات ادمین باشه."); return; }
    $chatObj = $chatRes['result'];
    $realChannelId = (string)$chatObj['id'];
    $title = $chatObj['title'] ?? 'بدون نام';
    $username = isset($chatObj['username']) ? '@' . $chatObj['username'] : '';
    $existingOwner = getChannelOwner($realChannelId);
    if ($existingOwner !== null && (string)$existingOwner !== (string)$userId && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ این کانال قبلاً توسط کاربر دیگری ثبت شده."); return;
    }
    $botId = getBotId();
    if ($botId !== null) {
        $botMember = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $botId]);
        $botStatus = $botMember['result']['status'] ?? '';
        if (!in_array($botStatus, ['administrator', 'creator'], true)) {
            sendPlainText($chatId, "⚠️ اول ربات رو تو کانال ادمین کنید، بعد دوباره add رو بزنید."); return;
        }
    }
    $memberRes = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $userId]);
    if ($memberRes === null || empty($memberRes['ok'])) { sendPlainText($chatId, "❌ ربات دسترسی بررسی اعضا نداره."); return; }
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['creator', 'administrator'], true) && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ شما تو این کانال ادمین یا سازنده نیستید."); return;
    }
    setChannelOwner($realChannelId, $userId, $title, $username);
    sendPlainText($chatId, "✅ کانال «" . $title . "» متصل شد.\n\nاز الان گزارش خروج اعضا براتون میاد.");
}

function handleMyList($chatId, $userId): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) { sendPlainText($chatId, "📭 هنوز کانالی ثبت نکردید.\n\nبرای اتصال:\n1. ربات رو ادمین کنید\n2. /add نام‌کانال"); return; }
    $msg = "📋 کانال‌های شما:\n\n";
    foreach ($channels as $chId => $info) {
        $msg .= "🔹 " . ($info['title'] ?: 'بدون نام') . ($info['username'] ? " ({$info['username']})" : '') . "\nآیدی: {$chId}\n\n";
    }
    sendPlainText($chatId, $msg);
}

function handleRemoveChannel($chatId, $userId, string $arg): void {
    if ($arg === '') { sendPlainText($chatId, "❌ نام کانال رو بعد از remove بنویسید."); return; }
    $realId = resolveChannelId($arg);
    if ($realId === null) { sendPlainText($chatId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null) { sendPlainText($chatId, "❌ این کانال تو ربات ثبت نشده."); return; }
    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) { sendPlainText($chatId, "⛔ شما مالک این کانال نیستید."); return; }
    removeChannel($realId);
    sendPlainText($chatId, "🗑 کانال حذف شد.");
}

function handleHistory($chatId, $userId, string $arg): void {
    if ($arg === '') { sendPlainText($chatId, "❌ نام کانال رو بعد از history بنویسید."); return; }
    $realId = resolveChannelId($arg);
    if ($realId === null) { sendPlainText($chatId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) { sendPlainText($chatId, "⛔ دسترسی ندارید."); return; }
    $history = getLeaveHistory($realId);
    if (empty($history)) { sendPlainText($chatId, "📭 هنوز خروجی ثبت نشده."); return; }
    $msg = "📊 آخرین خروج‌ها از «{$realId}»:\n\n";
    foreach (array_slice($history, 0, 15) as $idx => $item) {
        $num = $idx + 1;
        $msg .= "{$num}. {$item['name']} ({$item['user_id']})\n🕒 {$item['time']}\n\n";
    }
    sendPlainText($chatId, $msg);
}

/* ───────────────── قابلیت‌های ویژه (ختم کانال) ───────────────── */

function sendOrEditMenu($chatId, ?int $messageId, string $text, array $inlineKeyboard): void {
    if ($messageId !== null) {
        $res = apiPost('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'reply_markup' => $inlineKeyboard]);
        if ($res !== null && !empty($res['ok'])) return;
    }
    sendMessageWithKeyboard($chatId, $text, $inlineKeyboard);
}

function sendSpecialMenu($chatId, ?int $messageId = null): void {
    $inlineKeyboard = [[['text' => '📿 ختم‌های کانال', 'callback_data' => 'khatm_menu']]];
    sendOrEditMenu($chatId, $messageId, "✨ قابلیت‌های ویژه\n\nاز بین گزینه‌های زیر انتخاب کنید:", ['inline_keyboard' => $inlineKeyboard]);
}

function sendKhatmMenu($chatId, $userId, ?int $messageId = null): void {
    $rows = [];
    if (!empty(getOwnerChannels($userId))) {
        $rows[] = [['text' => '➕ ساخت ختم جدید', 'callback_data' => 'khatm_create']];
    } else {
        $rows[] = [['text' => '⚠️ ابتدا یک کانال متصل کنید', 'callback_data' => 'menu_connect']];
    }
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'special_menu']];
    sendOrEditMenu($chatId, $messageId, "📿 بخش ختم‌های کانال\n\nیک گزینه را انتخاب کنید:", ['inline_keyboard' => $rows]);
}

function handleKhatmCallback(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $messageId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
    $userId = $cb['from']['id'] ?? null;
    $userIdStr = (string)$userId;

    if (!$chatId || !$userId) { answerCallbackQuery($cbId); return; }

    if ($data === 'special_menu') { answerCallbackQuery($cbId); sendSpecialMenu($chatId, $messageId); return; }
    if ($data === 'khatm_menu') { answerCallbackQuery($cbId); sendKhatmMenu($chatId, $userIdStr, $messageId); return; }

    if ($data === 'khatm_create') {
        answerCallbackQuery($cbId);
        if (empty(getOwnerChannels($userId))) {
            sendPlainText($chatId, "❌ برای ساخت ختم، ابتدا باید یک کانال به ربات متصل کنید.");
            return;
        }
        clearUserState($userIdStr); // جلوگیری از باقی ماندن دیتای فرایند قبلی
        setUserState($userIdStr, 'khatm_title');
        sendPlainText($chatId, "➕ ساخت ختم جدید\n\nعنوان ختم را وارد کنید (مثلاً: صلوات):\n\nبرای لغو، /start را بزنید.");
        return;
    }
    answerCallbackQuery($cbId);
}

/* ───────────────── ابزارهای ختم (متن و کیبورد) ───────────────── */

function khatmRemaining(array $khatm): int {
    return max(0, (int)($khatm['target'] ?? 0) - (int)($khatm['total_done'] ?? 0));
}

function isKhatmComplete(array $khatm): bool {
    return khatmRemaining($khatm) === 0;
}

function buildKhatmCaption(array $khatm): string {
    $caption = "عنوان ختم : " . $khatm['title'] . "\n"
             . "توضیحات ختم : " . $khatm['desc'];
    if (isKhatmComplete($khatm)) {
        $caption .= "\n\n✅ این ختم با موفقیت تکمیل شد.";
    }
    return $caption;
}

// کیبورد پست کانال: ردیف اول آمار (فقط نمایشی)، ردیف دوم لینک شرکت در ختم
function buildKhatmChannelKeyboard(array $khatm, string $botUsername): array {
    $total = (int)($khatm['total_done'] ?? 0);
    $remain = khatmRemaining($khatm);

    $rows = [[
        ['text' => "مجموع: {$total}", 'callback_data' => 'khatm_refresh'],
        ['text' => "مانده: {$remain}", 'callback_data' => 'khatm_refresh'],
    ]];

    if ($remain === 0) {
        $rows[] = [['text' => '✅ ختم تکمیل شد', 'callback_data' => 'khatm_refresh']];
    } else {
        $deepLink = "https://ble.ir/{$botUsername}?start=" . $khatm['id'];
        $rows[] = [['text' => '📿 شرکت در ختم', 'url' => $deepLink]];
    }
    return ['inline_keyboard' => $rows];
}

function buildKhatmPrivateText(array $khatm): string {
    if (isKhatmComplete($khatm)) {
        return "✅ ختم «{$khatm['title']}» تکمیل شد. از مشارکت شما سپاسگزاریم.";
    }
    return "📿 شما در حال شرکت در ختم «{$khatm['title']}» هستید.\n\nبرای ثبت شمارش، روی دکمه‌های زیر بزنید:";
}

function buildKhatmPrivateKeyboard(array $khatm): array {
    $khatmId = $khatm['id'];
    $total = (int)($khatm['total_done'] ?? 0);
    $remain = khatmRemaining($khatm);
    $rows = [];

    if ($remain > 0) {
        foreach ($khatm['buttons'] as $amount) {
            $amount = (int)$amount;
            $rows[] = [['text' => (string)$amount, 'callback_data' => "khatm_count:{$khatmId}:{$amount}"]];
        }
    }
    $rows[] = [
        ['text' => "مجموع: {$total}", 'callback_data' => 'khatm_refresh'],
        ['text' => "مانده: {$remain}", 'callback_data' => 'khatm_refresh'],
    ];
    return ['inline_keyboard' => $rows];
}

/* ───────────────── فرآیند ساخت ختم (State Machine) ───────────────── */

function handleKhatmState($chatId, $userId, string $state, string $text, array $message): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);

    if ($state === 'khatm_title') {
        if ($text === '' || mb_strlen($text) > KHATM_MAX_TITLE) {
            sendPlainText($chatId, "❌ عنوان باید متنی و حداکثر " . KHATM_MAX_TITLE . " کاراکتر باشد.");
            return;
        }
        $data['title'] = $text;
        setUserState($userIdStr, 'khatm_desc', $data);
        sendPlainText($chatId, "✅ عنوان ثبت شد.\n\nحالا توضیحات ختم را وارد کنید:");
        return;
    }

    if ($state === 'khatm_desc') {
        if ($text === '' || mb_strlen($text) > KHATM_MAX_DESC) {
            sendPlainText($chatId, "❌ توضیحات باید متنی و حداکثر " . KHATM_MAX_DESC . " کاراکتر باشد.");
            return;
        }
        $data['desc'] = $text;
        setUserState($userIdStr, 'khatm_image', $data);
        sendPlainText($chatId, "✅ توضیحات ثبت شد.\n\nحالا عکس ختم را ارسال کنید (یا کلمه «ندارد» را بفرستید):");
        return;
    }

    if ($state === 'khatm_image') {
        if ($text === 'ندارد') {
            $data['image'] = '';
        } elseif (isset($message['photo']) && is_array($message['photo']) && !empty($message['photo'])) {
            $photo = end($message['photo']);
            $data['image'] = $photo['file_id'];
        } else {
            sendPlainText($chatId, "❌ لطفاً یک عکس ارسال کنید یا کلمه «ندارد» را بفرستید.");
            return;
        }
        setUserState($userIdStr, 'khatm_target', $data);
        sendPlainText($chatId, "✅ عکس ثبت شد.\n\nحالا تعداد کل ذکر را وارد کنید (مثلاً 1000):");
        return;
    }

    if ($state === 'khatm_target') {
        $target = (int)preg_replace('/[^0-9]/', '', normalizeDigits($text));
        if ($target <= 0 || $target > KHATM_MAX_TARGET) {
            sendPlainText($chatId, "❌ لطفاً یک عدد معتبر وارد کنید.");
            return;
        }
        $data['target'] = $target;
        setUserState($userIdStr, 'khatm_buttons', $data);
        sendPlainText($chatId, "✅ تعداد کل ثبت شد.\n\nحالا ۳ دکمه شمارش را با کاما جدا کنید (مثلاً: 1,3,7):");
        return;
    }

    if ($state === 'khatm_buttons') {
        $normalized = str_replace(['،', '٬'], ',', normalizeDigits($text));
        $parts = array_map('trim', explode(',', $normalized));

        $valid = count($parts) === 3;
        if ($valid) {
            foreach ($parts as $p) {
                if ($p === '' || !ctype_digit($p) || (int)$p <= 0 || (int)$p > KHATM_MAX_BUTTON) {
                    $valid = false;
                    break;
                }
            }
        }
        if (!$valid) {
            sendPlainText($chatId, "❌ لطفاً دقیقاً ۳ عدد مثبت را با کاما وارد کنید (مثلاً: 1,3,7).");
            return;
        }
        $data['buttons'] = array_map('intval', $parts);

        $channels = getOwnerChannels($userId);
        if (empty($channels)) {
            clearUserState($userIdStr);
            sendPlainText($chatId, "❌ هیچ کانال متصلی ندارید. ابتدا یک کانال متصل کنید.");
            return;
        }
        $rows = [];
        foreach ($channels as $chId => $info) {
            $rows[] = [['text' => $info['title'] ?: (string)$chId, 'callback_data' => "khatm_channel:{$chId}"]];
        }
        $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'khatm_cancel']];

        setUserState($userIdStr, 'khatm_channel', $data);
        sendMessageWithKeyboard($chatId, "✅ دکمه‌ها ثبت شد.\n\nحالا کانالی که می‌خواهید ختم در آن ارسال شود را انتخاب کنید:", ['inline_keyboard' => $rows]);
        return;
    }

    if ($state === 'khatm_channel') {
        sendPlainText($chatId, "👆 لطفاً یکی از کانال‌ها را از دکمه‌های بالا انتخاب کنید یا برای لغو /start را بزنید.");
        return;
    }
}

function sendKhatmChannelPreview($chatId, $userId, string $chId): void {
    $userIdStr = (string)$userId;

    if (getUserState($userIdStr) !== 'khatm_channel') {
        sendPlainText($chatId, "⚠️ این درخواست منقضی شده است. لطفاً ساخت ختم را از ابتدا شروع کنید.");
        return;
    }

    $data = getUserStateData($userIdStr);
    foreach (['title', 'desc', 'image', 'target', 'buttons'] as $required) {
        if (!isset($data[$required])) {
            clearUserState($userIdStr);
            sendPlainText($chatId, "⚠️ اطلاعات ختم ناقص است. لطفاً ساخت ختم را از ابتدا شروع کنید.");
            return;
        }
    }

    $botUsername = getBotUsername();
    if (!$botUsername) {
        sendPlainText($chatId, "❌ خطا در شناسایی ربات. لطفاً دوباره تلاش کنید.");
        return;
    }

    $data['channel_id'] = $chId;
    $data['owner_id'] = $userIdStr;

    $khatmId = createKhatm($data);
    $khatm = getKhatm($khatmId);
    if ($khatm === null) {
        sendPlainText($chatId, "❌ خطا در ذخیره ختم. لطفاً دوباره تلاش کنید.");
        return;
    }

    $keyboard = buildKhatmChannelKeyboard($khatm, $botUsername);
    $caption = buildKhatmCaption($khatm);

    $channelMsgId = null;
    $msgType = 'text';

    if (!empty($khatm['image'])) {
        $res = apiPost('sendPhoto', [
            'chat_id' => $chId,
            'photo' => $khatm['image'],
            'caption' => $caption,
            'reply_markup' => $keyboard,
        ]);
        if ($res !== null && !empty($res['ok'])) {
            $channelMsgId = $res['result']['message_id'];
            $msgType = 'photo';
        }
    }

    if ($channelMsgId === null) {
        $res = apiPost('sendMessage', [
            'chat_id' => $chId,
            'text' => $caption,
            'reply_markup' => $keyboard,
        ]);
        if ($res !== null && !empty($res['ok'])) {
            $channelMsgId = $res['result']['message_id'];
        }
    }

    if ($channelMsgId) {
        $khatm['channel_msg_id'] = $channelMsgId;
        $khatm['msg_type'] = $msgType;
        updateKhatm($khatmId, $khatm);
        setChannelActiveKhatm($chId, $khatmId);
        clearUserState($userIdStr);
        sendPlainText($chatId, "✅ ختم با موفقیت در کانال ارسال شد!\n\nاعضا با کلیک روی دکمه «شرکت در ختم» به ربات هدایت شده و شمارش را ثبت می‌کنند. آمار کانال هم به صورت زنده روی همان پست آپدیت می‌شود.");
    } else {
        // ختمِ ارسال‌نشده نباید فعال بماند
        deleteKhatm($khatmId);
        sendPlainText($chatId, "❌ خطا در ارسال پیام به کانال. مطمئن شوید ربات ادمین است و دوباره کانال را انتخاب کنید.");
    }
}

// آپدیت متن و دکمه‌های پست کانال (کپشن برای عکس، متن برای پیام متنی)
// برای جلوگیری از محدودیت نرخ، ادیت‌ها throttle می‌شوند مگر $force=true
function updateChannelKhatmMessage(string $khatmId, bool $force = false): void {
    $khatm = getKhatm($khatmId);
    if (!$khatm || empty($khatm['channel_id']) || empty($khatm['channel_msg_id'])) return;

    if (!$force && !redisAcquireLock("khatm_edit_lock:{$khatmId}", KHATM_EDIT_THROTTLE_SECONDS)) {
        return;
    }

    $botUsername = getBotUsername();
    if (!$botUsername) return;

    $keyboard = buildKhatmChannelKeyboard($khatm, $botUsername);
    $caption = buildKhatmCaption($khatm);

    if (($khatm['msg_type'] ?? 'text') === 'photo') {
        apiPost('editMessageCaption', [
            'chat_id' => $khatm['channel_id'],
            'message_id' => $khatm['channel_msg_id'],
            'caption' => $caption,
            'reply_markup' => $keyboard,
        ]);
    } else {
        apiPost('editMessageText', [
            'chat_id' => $khatm['channel_id'],
            'message_id' => $khatm['channel_msg_id'],
            'text' => $caption,
            'reply_markup' => $keyboard,
        ]);
    }
}

/* ───────────────── Callback ها ───────────────── */

function handleCallbackQuery(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $chatType = $cb['message']['chat']['type'] ?? 'private';
    $messageId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
    $userId = $cb['from']['id'] ?? null;

    if (!$chatId || !$userId) { answerCallbackQuery($cbId); return; }

    // دکمه‌های شمارش (در چت خصوصی)
    if (strpos($data, 'khatm_count:') === 0) {
        $parts = explode(':', $data);
        if (count($parts) !== 3 || !preg_match('/^khatm_[A-Za-z0-9]+$/', $parts[1])) {
            answerCallbackQuery($cbId);
            return;
        }
        $khatmId = $parts[1];
        $amount = (int)$parts[2];
        $khatm = getKhatm($khatmId);

        if (!$khatm) {
            answerCallbackQuery($cbId, '❌ ختم پیدا نشد.', true);
            return;
        }

        // مقدار باید دقیقاً یکی از سه دکمه‌ی تعریف‌شده باشد
        $allowed = array_map('intval', $khatm['buttons'] ?? []);
        if ($amount <= 0 || !in_array($amount, $allowed, true)) {
            answerCallbackQuery($cbId, '❌ مقدار نامعتبر.', true);
            return;
        }

        if (isKhatmComplete($khatm)) {
            answerCallbackQuery($cbId, '✅ این ختم تکمیل شده است.', true);
            if ($messageId !== null) {
                apiPost('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => buildKhatmPrivateText($khatm),
                    'reply_markup' => buildKhatmPrivateKeyboard($khatm),
                ]);
            }
            return;
        }

        addKhatmContribution($khatmId, (string)$userId, $amount);
        $newKhatm = getKhatm($khatmId);
        if (!$newKhatm) {
            answerCallbackQuery($cbId, '❌ ختم پیدا نشد.', true);
            return;
        }

        answerCallbackQuery($cbId, "✅ {$amount} ذکر ثبت شد.");

        // ویرایش پیام در چت خصوصی (بدون ایجاد پیام جدید)
        if ($messageId !== null) {
            $privateText = buildKhatmPrivateText($newKhatm);
            $privateKeyboard = buildKhatmPrivateKeyboard($newKhatm);

            $editRes = apiPost('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $privateText,
                'reply_markup' => $privateKeyboard,
            ]);

            if ($editRes === null || empty($editRes['ok'])) {
                apiPost('editMessageReplyMarkup', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'reply_markup' => $privateKeyboard,
                ]);
            }
        }

        // آپدیت زنده پست کانال؛ در لحظه‌ی تکمیل بدون throttle
        updateChannelKhatmMessage($khatmId, isKhatmComplete($newKhatm));
        return;
    }

    if ($data === 'khatm_refresh') {
        answerCallbackQuery($cbId);
        return;
    }

    if ($data === 'show_sponsor_channel') {
        $sponsorText = getSponsorText();
        $sponsorText = mb_substr($sponsorText, 0, 200);
        answerCallbackQuery($cbId, $sponsorText, true);
        return;
    }

    if ($data === 'check_force_join') {
        $check = checkUserForceJoin($userId, false);
        if ($check['ok']) { answerCallbackQuery($cbId, '✅ عضویت تایید شد.', false); sendStartMenu($chatId); }
        else { answerCallbackQuery($cbId, '❌ هنوز عضو همه‌ی کانال‌ها نشدید.', true); }
        return;
    }

    if ($data === 'khatm_cancel') {
        clearUserState((string)$userId);
        answerCallbackQuery($cbId, 'لغو شد.');
        sendStartMenu($chatId);
        return;
    }

    if (strpos($data, 'khatm_channel:') === 0) {
        if ($chatType !== 'private') { answerCallbackQuery($cbId); return; }
        $chId = substr($data, 14);

        // فقط مالک کانال (یا مالک ربات) اجازه‌ی ارسال ختم دارد
        $owner = getChannelOwner($chId);
        if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
            answerCallbackQuery($cbId, '⛔ این کانال متعلق به شما نیست.', true);
            return;
        }

        answerCallbackQuery($cbId, 'در حال ارسال...');
        sendKhatmChannelPreview($chatId, $userId, $chId);
        return;
    }

    if ($chatType !== 'private') { answerCallbackQuery($cbId); return; }

    if ($data !== 'menu_bug') {
        $check = checkUserForceJoin($userId, false);
        if (!$check['ok']) {
            answerCallbackQuery($cbId, '⚠️ اول تو کانال‌های معرفی‌شده عضو شوید.', true);
            sendForceJoinPrompt($chatId, $check['missing']);
            return;
        }
    }

    if ($data === 'special_menu' || strpos($data, 'khatm_') === 0) {
        handleKhatmCallback($cb);
        return;
    }

    answerCallbackQuery($cbId);

    switch ($data) {
        case 'menu_connect': sendPlainText($chatId, "➕ اتصال کانال\n\n1. ربات رو تو کانال ادمین کنید\n2. این رو بفرستید:\n/add mynews"); break;
        case 'menu_mylist': handleMyList($chatId, $userId); break;
        case 'menu_help': sendPlainText($chatId, getHelpText()); break;
        case 'menu_sponsor': sendPlainText($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
        case 'menu_bug': sendPlainText($chatId, "🐞 گزارش باگ\n\nمشکل رو این‌طوری بفرستید:\n\n/bug متن مشکل"); break;
    }
}

/* ───────────────── Router متن ───────────────── */

function handleTextMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? null;
    $chatType = $message['chat']['type'] ?? 'private';
    $userId = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? '');

    if (!$chatId || !$userId || ($text === '' && !isset($message['photo']))) return;
    if ($chatType !== 'private' && strpos($text, '/') !== 0) return;

    $userIdStr = (string)$userId;

    $replyButtonMap = [
        '➕ اتصال کانال' => 'connect', '➕ اتصال کانال جدید' => 'connect',
        '📋 کانال‌های من' => 'mylist', '📖 راهنما' => 'help',
        '📖 راهنمای استفاده' => 'help', '🌟 اسپانسر' => 'sponsor',
        '🌟 اسپانسر ما' => 'sponsor', '🐞 گزارش باگ' => 'bug',
        '✨ قابلیت‌های ویژه' => 'special',
        '🔙 بازگشت به منوی اصلی' => 'back_to_main'
    ];

    // State Machine فقط در چت خصوصی؛ دستورات و دکمه‌های منو، فرایند را لغو می‌کنند
    if ($chatType === 'private') {
        $userState = getUserState($userIdStr);
        if ($userState !== null && strpos($userState, 'khatm_') === 0) {
            if (strpos($text, '/') === 0 || isset($replyButtonMap[$text])) {
                clearUserState($userIdStr);
            } else {
                handleKhatmState($chatId, $userId, $userState, $text, $message);
                return;
            }
        }
    }

    if (isset($replyButtonMap[$text])) {
        $forceCheck = checkUserForceJoin($userId, false);
        if (!$forceCheck['ok']) { sendForceJoinPrompt($chatId, $forceCheck['missing']); return; }

        if ($replyButtonMap[$text] === 'back_to_main') {
            sendStartMenu($chatId); return;
        }

        switch ($replyButtonMap[$text]) {
            case 'connect': sendPlainText($chatId, "➕ اتصال کانال\n\n1. ربات رو تو کانال ادمین کنید\n2. این رو بفرستید:\n/add mynews"); break;
            case 'mylist': handleMyList($chatId, $userId); break;
            case 'help': sendPlainText($chatId, getHelpText()); break;
            case 'sponsor': sendPlainText($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
            case 'bug': sendPlainText($chatId, "🐞 گزارش باگ\n\nمشکل رو این‌طوری بفرستید:\n\n/bug متن مشکل"); break;
            case 'special': sendSpecialMenu($chatId); break;
        }
        return;
    }

    [$cmd, $arg] = parseCommand($text);
    if ($cmd === null) return;

    $resetForce = ($cmd === 'start');
    $skipForceCheck = in_array($cmd, ['help', 'bug', 'status', 'khatm_stats'], true);

    if ($cmd === 'start' && strpos((string)$arg, 'khatm_') === 0) $skipForceCheck = true;

    if (!$skipForceCheck) {
        $forceCheck = checkUserForceJoin($userId, $resetForce);
        if (!$forceCheck['ok']) { sendForceJoinPrompt($chatId, $forceCheck['missing']); return; }
    }

    switch ($cmd) {
        case 'start':
            if (strpos((string)$arg, 'khatm_') === 0) {
                $khatmId = (string)$arg;
                $khatm = preg_match('/^khatm_[A-Za-z0-9]+$/', $khatmId) ? getKhatm($khatmId) : null;

                if ($khatm) {
                    sendMessageWithKeyboard($chatId, buildKhatmPrivateText($khatm), buildKhatmPrivateKeyboard($khatm));
                    return;
                }
            }
            sendStartMenu($chatId);
            break;

        case 'help': sendPlainText($chatId, getHelpText()); break;
        case 'status':
            $msg = "🤖 ربات آنفالویاب: آنلاین\n🕒 " . date('Y-m-d H:i:s');
            if (isBotOwner($userId)) $msg .= "\n🗄 Redis: ✅\n📦 استخر: " . count(getForceChannelsPool());
            sendPlainText($chatId, $msg);
            break;
        case 'add':
        case 'connect': handleConnect($chatId, $userId, (string)$arg); break;
        case 'my': handleMyList($chatId, $userId); break;
        case 'remove': handleRemoveChannel($chatId, $userId, (string)$arg); break;
        case 'history': handleHistory($chatId, $userId, (string)$arg); break;

        case 'bug':
            if ($arg === '') { sendPlainText($chatId, "❌ متن مشکل رو بعد از bug بنویسید."); return; }
            if (BOT_OWNER_ID === '') { sendPlainText($chatId, "❌ دریافت گزارش غیرفعاله."); return; }
            $senderName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')) ?: 'بدون نام';
            $senderUsername = $message['from']['username'] ?? '';
            $bugText = "🐞 گزارش باگ\n\n👤 " . $senderName . "\n🔖 " . ($senderUsername !== '' ? '@' . $senderUsername : 'ندارد') . "\n🔢 " . $userId . "\n━━━━━━━━━━━━━━\n" . $arg;
            sendPlainText(BOT_OWNER_ID, $bugText);
            sendPlainText($chatId, "✅ گزارش ارسال شد.");
            break;

        case 'zk9xad':
            if (!isBotOwner($userId)) return;
            sendPlainText($chatId, getAdminHelpText()); break;
        case 'zk9xch':
            if (!isBotOwner($userId)) return;
            $all = getAllChannelsMap();
            if (empty($all)) { sendPlainText($chatId, "📭 خالی است."); return; }
            $msg = "👑 کل کانال‌ها (" . count($all) . "):\n\n";
            foreach ($all as $chId => $info) $msg .= "🔹 " . ($info['title'] ?: 'بدون نام') . "\nآیدی: {$chId}\nمالک: {$info['owner_id']}\n\n";
            sendPlainText($chatId, $msg);
            break;
        case 'zk9xfa':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendPlainText($chatId, "❌ مثال: /zk9xfa mychannel"); return; }
            $check = checkBotIsAdminInChannel($arg);
            if (!$check['ok'] || !addForceChannelToPool($arg)) { sendPlainText($chatId, "❌ خطا. اول ربات رو ادمین کنید."); return; }
            sendPlainText($chatId, "✅ اضافه شد.");
            break;
        case 'zk9xfd':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendPlainText($chatId, "❌ مثال: /zk9xfd mychannel"); return; }
            sendPlainText($chatId, removeForceChannelFromPool($arg) ? "🗑 حذف شد." : "❌ پیدا نشد.");
            break;
        case 'zk9xfl':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            if (empty($pool)) { sendPlainText($chatId, "📭 استخر خالیه."); return; }
            $msg = "📢 استخر (" . count($pool) . "):\n\n";
            foreach ($pool as $idx => $ch) $msg .= ($idx + 1) . ". " . $ch . "\n";
            sendPlainText($chatId, $msg);
            break;
        case 'zk9xst':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            $msg = "🔍 دیباگ (" . count($pool) . " کانال):\n\n";
            foreach ($pool as $idx => $ch) {
                $c = checkBotIsAdminInChannel($ch);
                $msg .= ($idx + 1) . ". " . $ch . ($c['ok'] ? " ✅" : " ❌ ({$c['error']})") . "\n";
            }
            sendPlainText($chatId, $msg);
            break;
        case 'zk9xsp':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendPlainText($chatId, "❌ متن اسپانسر رو بنویسید."); return; }
            setSponsorText($arg);
            sendPlainText($chatId, "✅ اسپانسر بروزرسانی شد.");
            break;
    }
}

/* ───────────────── رویداد خروج عضو ───────────────── */

function processMemberLeft(array $chat, array $user, ?array $actor = null): void {
    if (!empty($user['is_bot'])) return;
    $channelId = $chat['id'] ?? null;
    if (!$channelId) return;
    $channelIdStr = (string)$channelId;
    $ownerId = getChannelOwner($channelIdStr);
    if (!$ownerId) return;

    $channelTitle = $chat['title'] ?? 'کانال بدون نام';
    $channelUsername = isset($chat['username']) ? '@' . $chat['username'] : '';
    $userId = $user['id'] ?? 'نامشخص';
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'بدون نام';
    $username = $user['username'] ?? '';
    $timeStr = date('Y/m/d - H:i:s');

    saveLeaveHistory($channelIdStr, ['user_id' => $userId, 'name' => $fullName, 'username' => $username, 'time' => $timeStr]);

    $usernameLine = $username !== '' ? "@" . $username : 'ندارد';
    $channelLine = $channelUsername !== '' ? $channelTitle . " (" . $channelUsername . ")" : $channelTitle;
    $actionType = 'خروج از کانال';

    if ($actor !== null && isset($actor['id']) && (string)$actor['id'] !== (string)$userId) {
        $actorName = trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? '')) ?: 'ادمین';
        $actionType = "حذف توسط " . $actorName;
    }

    $report = "📤 گزارش خروج عضو\n\n"
            . "📢 کانال: " . $channelLine . "\n"
            . "🆔 آیدی: " . $channelIdStr . "\n"
            . "━━━━━━━━━━━━━━\n"
            . "👤 نام: " . $fullName . "\n"
            . "🔖 یوزرنیم: " . $usernameLine . "\n"
            . "🔢 آیدی: " . $userId . "\n"
            . "📌 نوع: " . $actionType . "\n"
            . "🕒 زمان: " . $timeStr;

    sendPlainText($ownerId, $report);
}

function handleChatMemberUpdate(array $cm): void {
    $chat = $cm['chat'] ?? [];
    $oldStatus = $cm['old_chat_member']['status'] ?? '';
    $newStatus = $cm['new_chat_member']['status'] ?? '';
    $user = $cm['new_chat_member']['user'] ?? ($cm['old_chat_member']['user'] ?? []);
    $actor = $cm['from'] ?? null;

    $wasMember = in_array($oldStatus, ['member', 'administrator', 'restricted'], true);
    $isGone = in_array($newStatus, ['left', 'kicked'], true);

    if ($wasMember && $isGone && !empty($user)) {
        processMemberLeft($chat, $user, $actor);
    }
}