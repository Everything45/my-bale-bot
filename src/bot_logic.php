<?php
// src/bot_logic.php
declare(strict_types=1);

const KHATM_EDIT_THROTTLE_SECONDS = 2; // حداقل فاصله‌ی ادیت پست کانال
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
    return "📖 راهنمای استفاده\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "1️⃣ ربات رو تو کانالتون ادمین کنید (با دسترسی کامل)\n"
         . "2️⃣ کانال رو وصل کنید:\n"
         . "🔹 دستور اتصال کانال:\n"
         . "/add mynews\n"
         . "(به‌جای mynews آیدی کانالتون رو بنویسید)\n\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "🔰 متن و توضیحات دستورات\n"
         . "/start\n"
         . "/my\n"
         . "/status\n"
         . "/history mynews\n"
         . "/remove mynews\n"
         . "/bug\n"
         . "🔹 منوی اصلی و شروع\n"
         . "🔹 لیست کانال‌های من\n"
         . "🔹 وضعیت ربات\n"
         . "🔹 آخرین خروج‌های کانال\n"
         . "🔹 قطع اتصال کانال\n"
         . "🔹 گزارش مشکل (بعد از دستور، متن مشکل را بنویسید)\n"
         . "━━━━━━━━━━━━━━━━━━\n⚠️ (به‌جای mynews آیدی کانالتون رو بنویسید)";
}

function getConnectText(): string {
    return "➕ اتصال کانال\n\n"
         . "1. ربات رو تو کانال ادمین کنید\n"
         . "2. دستور زیر رو بفرستید:\n"
         . "/add mynews\n"
         . "(به‌جای mynews آیدی کانالتون رو بنویسید)";
}

function getAdminHelpText(): string {
    return "👑 پنل مدیریت (مخصوص مالک)\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "📊 مشاهده\n"
         . "/zk9xch\n"
         . "/zk9xfl\n"
         . "/zk9xst\n"
         . "🔹 کل کانال‌ها\n"
         . "🔹 استخر عضویت اجباری\n"
         . "🔹 دیباگ استخر\n\n"
         . "⚙️ تنظیمات\n"
         . "/zk9xfa mychannel\n"
         . "/zk9xfd mychannel\n"
         . "/zk9xsp text\n"
         . "🔹 افزودن به استخر\n"
         . "🔹 حذف از استخر\n"
         . "🔹 تنظیم متن اسپانسر\n\n"
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
    if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بعد از دستور بنویسید.\n\nمثال:\n/add mynews"); return; }
    $target = trim($arg);
    if ($target[0] !== '@' && !preg_match('/^-?\d+$/', $target)) $target = '@' . $target;
    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) { sendMessageWithKeyboard($chatId, "❌ کانال پیدا نشد.\n\nچک کنید ربات ادمین باشه."); return; }
    $chatObj = $chatRes['result'];
    $realChannelId = (string)$chatObj['id'];
    $title = $chatObj['title'] ?? 'بدون نام';
    $username = isset($chatObj['username']) ? '@' . $chatObj['username'] : '';
    $existingOwner = getChannelOwner($realChannelId);
    if ($existingOwner !== null && (string)$existingOwner !== (string)$userId && !isBotOwner($userId)) {
        sendMessageWithKeyboard($chatId, "⛔ این کانال قبلاً توسط کاربر دیگری ثبت شده."); return;
    }
    $botId = getBotId();
    if ($botId !== null) {
        $botMember = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $botId]);
        $botStatus = $botMember['result']['status'] ?? '';
        if (!in_array($botStatus, ['administrator', 'creator'], true)) {
            sendMessageWithKeyboard($chatId, "⚠️ اول ربات رو تو کانال ادمین کنید، بعد دوباره add رو بزنید."); return;
        }
    }
    $memberRes = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $userId]);
    if ($memberRes === null || empty($memberRes['ok'])) { sendMessageWithKeyboard($chatId, "❌ ربات دسترسی بررسی اعضا نداره."); return; }
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['creator', 'administrator'], true) && !isBotOwner($userId)) {
        sendMessageWithKeyboard($chatId, "⛔ شما تو این کانال ادمین یا سازنده نیستید."); return;
    }
    setChannelOwner($realChannelId, $userId, $title, $username);
    sendMessageWithKeyboard($chatId, "✅ کانال «" . $title . "» متصل شد.\n\nاز الان گزارش خروج اعضا براتون میاد.");
}

function handleMyList($chatId, $userId): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) { sendMessageWithKeyboard($chatId, "📭 هنوز کانالی ثبت نکردید.\n\nبرای اتصال:\n1. ربات رو ادمین کنید\n2. این دستور رو بفرستید:\n/add mynews"); return; }
    $msg = "📋 کانال‌های شما:\n\n";
    foreach ($channels as $chId => $info) {
        $msg .= "🔹 " . ($info['title'] ?: 'بدون نام') . ($info['username'] ? " ({$info['username']})" : '') . "\nآیدی: {$chId}\n\n";
    }
    sendMessageWithKeyboard($chatId, $msg);
}

function handleRemoveChannel($chatId, $userId, string $arg): void {
    if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ نام کانال رو بعد از remove بنویسید."); return; }
    $realId = resolveChannelId($arg);
    if ($realId === null) { sendMessageWithKeyboard($chatId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null) { sendMessageWithKeyboard($chatId, "❌ این کانال تو ربات ثبت نشده."); return; }
    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) { sendMessageWithKeyboard($chatId, "⛔ شما مالک این کانال نیستید."); return; }
    removeChannel($realId);
    sendMessageWithKeyboard($chatId, "🗑 کانال حذف شد.");
}

function handleHistory($chatId, $userId, string $arg): void {
    if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ نام کانال رو بعد از history بنویسید."); return; }
    $realId = resolveChannelId($arg);
    if ($realId === null) { sendMessageWithKeyboard($chatId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) { sendMessageWithKeyboard($chatId, "⛔ دسترسی ندارید."); return; }
    $history = getLeaveHistory($realId);
    if (empty($history)) { sendMessageWithKeyboard($chatId, "📭 هنوز خروجی ثبت نشده."); return; }
    $msg = "📊 آخرین خروج‌ها از «{$realId}»:\n\n";
    foreach (array_slice($history, 0, 15) as $idx => $item) {
        $num = $idx + 1;
        $msg .= "{$num}. {$item['name']} ({$item['user_id']})\n🕒 {$item['time']}\n\n";
    }
    sendMessageWithKeyboard($chatId, $msg);
}

/* ───────────────── قابلیت‌های ویژه (ختم کانال) ───────────────── */

function sendOrEditMenu($chatId, ?int $messageId, string $text, array $inlineKeyboard): void {
    if ($messageId !== null) {
        editOrSendMessage($chatId, $messageId, $text, $inlineKeyboard);
        return;
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
            editOrSendMessage(
                $chatId, $messageId,
                "❌ برای ساخت ختم، ابتدا باید یک کانال به ربات متصل کنید.",
                ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'khatm_menu']]]]
            );
            return;
        }
        clearUserState($userIdStr);

        $promptId = editOrSendMessage($chatId, $messageId, khatmInfoPrompt(), khatmCancelKeyboard());
        setUserState($userIdStr, 'khatm_info', ['prompt_msg_id' => $promptId ?? 0]);
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
    $caption = "عنوان ختم : " . $khatm['title'];
    if (!empty($khatm['desc'])) {
        $caption .= "\n" . "توضیحات ختم : " . $khatm['desc'];
    }
    if (isKhatmComplete($khatm)) {
        $caption .= "\n\n✅ این ختم با موفقیت تکمیل شد.";
    }
    return $caption;
}

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

function buildKhatmPrivateText(array $khatm, ?string $notice = null, int $mine = 0): string {
    $text = ($notice !== null && $notice !== '') ? $notice . "\n\n" : '';

    if (isKhatmComplete($khatm)) {
        $text .= "✅ ختم «{$khatm['title']}» تکمیل شد. از مشارکت شما سپاسگزاریم.";
        if ($mine > 0) $text .= "\n📊 مجموع شمارش شما: {$mine}";
        return $text;
    }

    $text .= "📿 ختم «{$khatm['title']}»";
    if ($mine > 0) $text .= "\n📊 مجموع شمارش شما: {$mine}";
    $text .= "\n\nبرای ثبت شمارش، روی دکمه‌های زیر بزنید:";
    return $text;
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

/* ───────────────── فرآیند ساخت ختم ───────────────── */

function khatmCancelKeyboard(): array {
    return ['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'khatm_cancel']]]];
}

function khatmBackKeyboard(): array {
    return ['inline_keyboard' => [[['text' => '🔙 قابلیت‌های ویژه', 'callback_data' => 'special_menu']]]];
}

function khatmInfoPrompt(?string $error = null): string {
    $text = $error !== null ? "❌ {$error}\n\n" : '';
    return $text
        . "➕ ساخت ختم جدید (مرحله ۱ از ۲)\n\n"
        . "عنوان و توضیحات را در «یک پیام» بفرستید:\n"
        . "• خط اول: عنوان\n"
        . "• خط‌های بعدی: توضیحات\n"
        . "• اگر عکس می‌خواهید، عکس را همراه همین متن (به‌عنوان کپشن) بفرستید.\n\n"
        . "مثال:\nصلوات\nبه نیت سلامتی همه";
}

function khatmNumbersPrompt(?string $error = null): string {
    $text = $error !== null ? "❌ {$error}\n\n" : '';
    return $text
        . "➕ ساخت ختم جدید (مرحله ۲ از ۲)\n\n"
        . "در «یک پیام» بفرستید:\n"
        . "• خط اول: تعداد کل ذکر\n"
        . "• خط دوم: ۳ عدد دکمه‌ی شمارش، با کاما\n\n"
        . "مثال:\n1000\n1,3,7";
}

function buildKhatmChannelPicker($userId): array {
    $rows = [];
    foreach (getOwnerChannels($userId) as $chId => $info) {
        $rows[] = [['text' => ($info['title'] ?? '') ?: (string)$chId, 'callback_data' => "khatm_channel:{$chId}"]];
    }
    $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'khatm_cancel']];
    return ['inline_keyboard' => $rows];
}

function khatmShowPrompt($chatId, string $userIdStr, string $state, array $data, string $text, ?array $keyboard): void {
    $promptId = (int)($data['prompt_msg_id'] ?? 0);
    $newId = editOrSendMessage($chatId, $promptId > 0 ? $promptId : null, $text, $keyboard);
    $data['prompt_msg_id'] = $newId ?? $promptId;
    setUserState($userIdStr, $state, $data);
}

function abortKhatmFlow($chatId, string $userIdStr): void {
    $data = getUserStateData($userIdStr);
    $promptId = (int)($data['prompt_msg_id'] ?? 0);
    clearUserState($userIdStr);
    if ($promptId > 0) {
        apiPost('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $promptId,
            'text' => "❌ ساخت ختم لغو شد.",
            'reply_markup' => khatmBackKeyboard(),
        ]);
    }
}

function handleKhatmState($chatId, $userId, string $state, string $text, array $message): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);

    if ($state === 'khatm_info') {
        $text = trim($text);
        $lines = preg_split('/\R/u', $text) ?: [];
        $title = trim((string)array_shift($lines));
        $desc = trim(implode("\n", $lines));

        $image = '';
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);
            $image = (string)($photo['file_id'] ?? '');
        }

        $error = null;
        if ($title === '') {
            $error = 'عنوان خالی است. خط اول پیام را عنوان بنویسید.';
        } elseif (mb_strlen($title) > KHATM_MAX_TITLE) {
            $error = 'عنوان خیلی طولانی است (حداکثر ' . KHATM_MAX_TITLE . ' کاراکتر).';
        } elseif (mb_strlen($desc) > KHATM_MAX_DESC) {
            $error = 'توضیحات خیلی طولانی است (حداکثر ' . KHATM_MAX_DESC . ' کاراکتر).';
        }

        if ($error !== null) {
            khatmShowPrompt($chatId, $userIdStr, 'khatm_info', $data, khatmInfoPrompt($error), khatmCancelKeyboard());
            return;
        }

        $data['title'] = $title;
        $data['desc'] = $desc;
        $data['image'] = $image;
        khatmShowPrompt($chatId, $userIdStr, 'khatm_numbers', $data, khatmNumbersPrompt(), khatmCancelKeyboard());
        return;
    }

    if ($state === 'khatm_numbers') {
        $norm = str_replace(['،', '٬'], ',', normalizeDigits($text));
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $norm) ?: []),
            static fn($l) => $l !== ''
        ));

        $target = 0;
        $buttons = [];
        if (count($lines) === 2) {
            $targetDigits = preg_replace('/\D/', '', $lines[0]);
            $target = ($targetDigits !== '' && strlen($targetDigits) <= 10) ? (int)$targetDigits : 0;
            $buttons = preg_split('/[\s,]+/', $lines[1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            preg_match_all('/\d+/', $norm, $mm);
            $nums = $mm[0] ?? [];
            if (count($nums) === 4 && strlen($nums[0]) <= 10) {
                $target = (int)array_shift($nums);
                $buttons = $nums;
            }
        }

        $valid = $target > 0 && $target <= KHATM_MAX_TARGET && count($buttons) === 3;
        if ($valid) {
            foreach ($buttons as $b) {
                if (!ctype_digit($b) || strlen($b) > 7 || (int)$b <= 0 || (int)$b > KHATM_MAX_BUTTON) {
                    $valid = false;
                    break;
                }
            }
        }
        if (!$valid) {
            khatmShowPrompt(
                $chatId, $userIdStr, 'khatm_numbers', $data,
                khatmNumbersPrompt('فرمت درست نیست. خط اول تعداد کل، خط دوم دقیقاً ۳ عدد مثبت با کاما.'),
                khatmCancelKeyboard()
            );
            return;
        }

        $data['target'] = $target;
        $data['buttons'] = array_map('intval', $buttons);

        $channels = getOwnerChannels($userId);
        if (empty($channels)) {
            khatmShowPrompt($chatId, $userIdStr, 'khatm_numbers', $data, "❌ هیچ کانال متصلی ندارید. ابتدا یک کانال متصل کنید.", khatmBackKeyboard());
            clearUserState($userIdStr);
            return;
        }

        if (count($channels) === 1) {
            setUserState($userIdStr, 'khatm_channel', $data);
            sendKhatmChannelPreview($chatId, $userId, (string)array_key_first($channels));
            return;
        }

        khatmShowPrompt(
            $chatId, $userIdStr, 'khatm_channel', $data,
            "✅ اطلاعات ثبت شد.\n\nکانالی که ختم در آن ارسال شود را انتخاب کنید:",
            buildKhatmChannelPicker($userId)
        );
        return;
    }
}

function sendKhatmChannelPreview($chatId, $userId, string $chId): void {
    $userIdStr = (string)$userId;

    if (getUserState($userIdStr) !== 'khatm_channel') {
        sendMessageWithKeyboard($chatId, "⚠️ این درخواست منقضی شده است. لطفاً ساخت ختم را از ابتدا شروع کنید.");
        return;
    }

    $data = getUserStateData($userIdStr);
    foreach (['title', 'desc', 'image', 'target', 'buttons'] as $required) {
        if (!isset($data[$required])) {
            clearUserState($userIdStr);
            sendMessageWithKeyboard($chatId, "⚠️ اطلاعات ختم ناقص است. لطفاً ساخت ختم را از ابتدا شروع کنید.");
            return;
        }
    }

    $retryKeyboard = buildKhatmChannelPicker($userId);

    $botUsername = getBotUsername();
    if (!$botUsername) {
        khatmShowPrompt($chatId, $userIdStr, 'khatm_channel', $data, "❌ خطا در شناسایی ربات. دوباره کانال را انتخاب کنید.", $retryKeyboard);
        return;
    }

    $data['channel_id'] = $chId;
    $data['owner_id'] = $userIdStr;
    $promptId = (int)($data['prompt_msg_id'] ?? 0);

    $khatmId = createKhatm($data);
    $khatm = getKhatm($khatmId);
    if ($khatm === null) {
        khatmShowPrompt($chatId, $userIdStr, 'khatm_channel', $data, "❌ خطا در ذخیره ختم. دوباره کانال را انتخاب کنید.", $retryKeyboard);
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

        editOrSendMessage(
            $chatId,
            $promptId > 0 ? $promptId : null,
            "✅ ختم با موفقیت در کانال ارسال شد!\n\nاعضا با دکمه «شرکت در ختم» به ربات هدایت شده و شمارش را ثبت می‌کنند. آمار پست کانال هم زنده آپدیت می‌شود.",
            khatmBackKeyboard()
        );
    } else {
        deleteKhatm($khatmId);
        khatmShowPrompt(
            $chatId, $userIdStr, 'khatm_channel', $data,
            "❌ خطا در ارسال پیام به کانال. مطمئن شوید ربات ادمین است و دوباره کانال را انتخاب کنید.",
            $retryKeyboard
        );
    }
}

function flushResponseEarly(): void {
    if (function_exists('fastcgi_finish_request')) {
        if (!headers_sent()) { http_response_code(200); }
        echo 'OK';
        fastcgi_finish_request();
    }
}

function khatmEditWindow(): int {
    return function_exists('fastcgi_finish_request') ? KHATM_EDIT_THROTTLE_SECONDS : 1;
}

function renderChannelKhatmPost(string $khatmId): void {
    $khatm = getKhatm($khatmId);
    if (!$khatm || empty($khatm['channel_id']) || empty($khatm['channel_msg_id'])) return;

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

function updateChannelKhatmMessage(string $khatmId, bool $force = false): void {
    $lockKey = "khatm_edit_lock:{$khatmId}";
    $dirtyKey = "khatm_dirty:{$khatmId}";
    $window = khatmEditWindow();

    if ($force) {
        redisDel($dirtyKey);
        renderChannelKhatmPost($khatmId);
        return;
    }

    if (!redisAcquireLock($lockKey, $window)) {
        redisSet($dirtyKey, '1', 30);
        return;
    }

    redisDel($dirtyKey);
    renderChannelKhatmPost($khatmId);

    flushResponseEarly();
    usleep($window * 1000000 + 100000);

    if (redisGet($dirtyKey) !== null) {
        redisDel($dirtyKey);
        renderChannelKhatmPost($khatmId);
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

        $allowed = array_map('intval', $khatm['buttons'] ?? []);
        if ($amount <= 0 || !in_array($amount, $allowed, true)) {
            answerCallbackQuery($cbId, '❌ مقدار نامعتبر.', true);
            return;
        }

        if (isKhatmComplete($khatm)) {
            answerCallbackQuery($cbId, '✅ این ختم تکمیل شده است.', true);
