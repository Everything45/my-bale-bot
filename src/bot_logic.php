<?php
// src/bot_logic.php
declare(strict_types=1);

const KHATM_EDIT_THROTTLE_SECONDS = 2;
const KHATM_MAX_TITLE = 100;
const KHATM_MAX_DESC = 700;
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
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
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

/* ───────────────── ابزار ویرایش/ارسال ───────────────── */

function editOrSend($chatId, $messageId, string $text, ?array $replyMarkup = null): void {
    if ($messageId) {
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        if ($replyMarkup !== null) $payload['reply_markup'] = $replyMarkup;
        $res = apiPost('editMessageText', $payload);
        if (apiOk($res)) return;
    }
    sendMessageWithKeyboard($chatId, $text, $replyMarkup);
}

/* ───────────────── عضویت اجباری ───────────────── */

function checkBotIsAdminInChannel(string $channelRef): array {
    $ref = normalizeChannelRef($channelRef);
    if ($ref === null) return ['ok' => false, 'error' => 'آیدی کانال نامعتبر است.'];
    $chatRes = apiPost('getChat', ['chat_id' => $ref]);
    if (!apiOk($chatRes)) return ['ok' => false, 'error' => 'کانال پیدا نشد.'];
    $realId = (string)$chatRes['result']['id'];
    $botId = getBotId();
    if ($botId === null) return ['ok' => false, 'error' => 'شناسایی ربات ناموفق بود.'];
    $memberRes = apiPost('getChatMember', ['chat_id' => $realId, 'user_id' => $botId]);
    if (!apiOk($memberRes)) return ['ok' => false, 'error' => 'بررسی وضعیت ربات ناموفق بود.'];
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['administrator', 'creator'], true)) return ['ok' => false, 'error' => 'ربات ادمین نیست.'];
    return ['ok' => true, 'real_id' => $realId, 'title' => $chatRes['result']['title'] ?? 'بدون نام'];
}

function checkSingleChannelMembership(string $channelRef, $userId): ?bool {
    $res = apiPost('getChatMember', ['chat_id' => $channelRef, 'user_id' => $userId]);
    if (!apiOk($res)) return null;
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
    return "🔰 راهنمای استفاده 🔰\n\n"
         . "1️⃣ ربات رو تو کانال یا گروهتون ادمین کنید (با دسترسی کامل)\n"
         . "2️⃣ کانال رو وصل کنید:\n"
         . "/add mynews\n"
         . "(به‌جای mynews آیدی کانالتون رو بنویسید. لینک ble.ir یا آیدی عددی هم قبوله)\n\n"
         . "🔰 دستورات 🔰\n"
         . "/start - منوی اصلی\n"
         . "/my - لیست کانال‌های من\n"
         . "/status - وضعیت ربات\n"
         . "/history mynews - آخرین خروج‌های کانال\n"
         . "/remove mynews - قطع اتصال کانال\n"
         . "/khatms - مدیریت ختم‌ها (ریست و حذف)\n"
         . "/bug متن مشکل - گزارش مشکل\n\n"
         . "⚠️ به‌جای mynews آیدی کانالتون رو بنویسید ⚠️";
}

function getConnectText(): string {
    return "➕ اتصال کانال یا گروه\n\n"
         . "1. ربات رو به کانال/گروه اضافه کنید (ادمین بودن برای اتصال الزامی نیست، اما برای گزارش خروج لازم است)\n"
         . "2. دستور زیر رو بفرستید:\n"
         . "/add mynews\n"
         . "(به‌جای mynews آیدی کانالتون رو بنویسید)\n\n"
         . "برای گروه یا کانال خصوصی: دستور /add رو بدون متن داخل خود گروه بفرستید، یا آیدی عددی بدید.";
}

function getAdminHelpText(): string {
    return "👑 پنل مدیریت (مخصوص مالک)👑\n\n"
         . "📊 مشاهده\n"
         . "/zk9xch - کل کانال‌ها\n"
         . "/zk9xfl - استخر عضویت اجباری\n"
         . "/zk9xst - دیباگ استخر\n"
         . "/zk9xkl - لیست همه‌ی ختم‌ها\n\n"
         . "⚙️ تنظیمات\n"
         . "/zk9xfa mychannel - افزودن به استخر\n"
         . "/zk9xfd mychannel - حذف از استخر\n"
         . "/zk9xsp text - تنظیم متن اسپانسر\n"
         . "/zk9xkr khatm_id - ریست ختم\n"
         . "/zk9xkd khatm_id - حذف ختم\n\n"
         . "⚠️ این دستورات رو جایی منتشر نکنید. ⚠️";
}

function resolveChannelId(string $target): ?string {
    $ref = normalizeChannelRef($target);
    if ($ref === null) return null;
    $chatRes = apiPost('getChat', ['chat_id' => $ref]);
    if (!apiOk($chatRes)) return null;
    return (string)$chatRes['result']['id'];
}

function findOwnerChannel($userId, string $arg): ?string {
    $channels = isBotOwner($userId) ? getAllChannelsMap() : getOwnerChannels($userId);
    if (empty($channels)) return null;

    $ref = normalizeChannelRef($arg);
    $needle = ($ref !== null) ? mb_strtolower(ltrim($ref, '@')) : '';

    foreach ($channels as $id => $info) {
        if ($ref !== null && (string)$id === $ref) return (string)$id;
        $u = mb_strtolower(ltrim((string)($info['username'] ?? ''), '@'));
        if ($needle !== '' && $u !== '' && $u === $needle) return (string)$id;
    }
    $title = trim($arg);
    if ($title !== '') {
        foreach ($channels as $id => $info) {
            if (trim((string)($info['title'] ?? '')) === $title) return (string)$id;
        }
    }
    return null;
}

function refreshChannelInfo(string $chId, array $info): ?array {
    $res = apiPost('getChat', ['chat_id' => $chId]);
    if ($res === null) return $info;

    if (empty($res['ok'])) {
        $desc = mb_strtolower((string)($res['description'] ?? ''));
        $code = (int)($res['error_code'] ?? 0);
        $gone = $code === 404;
        foreach (['not found', 'kicked', 'forbidden', 'deactivated', 'chat not', 'bot was'] as $needle) {
            if ($desc !== '' && strpos($desc, $needle) !== false) { $gone = true; break; }
        }
        if ($gone) {
            removeChannel($chId);
            return null;
        }
        return $info;
    }

    $botId = getBotId();
    if ($botId !== null) {
        $m = apiPost('getChatMember', ['chat_id' => $chId, 'user_id' => $botId]);
        if (apiOk($m)) {
            $st = $m['result']['status'] ?? '';
            if (in_array($st, ['left', 'kicked'], true)) {
                removeChannel($chId);
                return null;
            }
        }
    }

    $chat = $res['result'];
    $newTitle = (string)($chat['title'] ?? ($info['title'] ?? ''));
    $newUser = isset($chat['username']) ? '@' . $chat['username'] : '';
    $type = (string)($chat['type'] ?? ($info['type'] ?? ''));
    if ($newTitle !== (string)($info['title'] ?? '') || $newUser !== (string)($info['username'] ?? '')) {
        setChannelOwner($chId, (string)$info['owner_id'], $newTitle, $newUser, $type);
        $info['title'] = $newTitle;
        $info['username'] = $newUser;
    }
    return $info;
}

/* ───────────────── مدیریت کانال ───────────────── */

function handleConnect($chatId, $userId, string $arg, string $chatType = 'private'): void {
    if ($arg === '') {
        if ($chatType === 'private') {
            sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بعد از دستور بنویسید.\n\nمثال:\n/add mynews\n\nبرای گروه، دستور /add رو بدون متن داخل خود گروه بفرستید.");
            return;
        }
        $target = (string)$chatId;
    } else {
        $target = normalizeChannelRef($arg);
        if ($target === null) {
            sendMessageWithKeyboard($chatId, "❌ آیدی نامعتبره.\n\nمثال:\n/add mynews");
            return;
        }
    }

    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if (!apiOk($chatRes)) {
        sendMessageWithKeyboard($chatId,
            "❌ کانال/گروه پیدا نشد.\n\n"
            . "• آیدی رو دقیق بنویسید (مثلاً /add mynews)\n"
            . "• ربات باید توی کانال/گروه عضو باشه");
        return;
    }

    $chatObj = $chatRes['result'];
    $type = (string)($chatObj['type'] ?? '');
    if ($type === 'private') {
        sendMessageWithKeyboard($chatId, "❌ این یک چت خصوصیه، نه کانال یا گروه.");
        return;
    }

    $realChannelId = (string)$chatObj['id'];
    $title = (string)($chatObj['title'] ?? 'بدون نام');
    $username = isset($chatObj['username']) ? '@' . $chatObj['username'] : '';
    $kind = $type === 'channel' ? 'کانال' : 'گروه';

    $botId = getBotId();
    if ($botId === null) {
        sendMessageWithKeyboard($chatId, "❌ شناسایی ربات ناموفق بود. چند لحظه بعد دوباره امتحان کنید.");
        return;
    }

    $botMember = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $botId]);
    if (!apiOk($botMember)) {
        sendMessageWithKeyboard($chatId, "⚠️ ربات عضو این {$kind} نیست.\n\nاول ربات رو به {$kind} اضافه کنید.");
        return;
    }
    
    $botStatus = $botMember['result']['status'] ?? '';
    $isBotAdmin = in_array($botStatus, ['administrator', 'creator'], true);

    // بررسی ادمین بودن خود کاربر
    $memberRes = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $userId]);
    if (!apiOk($memberRes)) {
        sendMessageWithKeyboard($chatId, "❌ بررسی عضویت شما تو این {$kind} ناموفق بود. چند لحظه بعد دوباره امتحان کنید.");
        return;
    }
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['creator', 'administrator'], true) && !isBotOwner($userId)) {
        sendMessageWithKeyboard($chatId, "⛔ شما تو این {$kind} ادمین یا سازنده نیستید.");
        return;
    }

    $existingOwner = getChannelOwner($realChannelId);
    if ($existingOwner !== null && $existingOwner !== (string)$userId && !isBotOwner($userId)) {
        $oldMember = apiPost('getChatMember', ['chat_id' => $realChannelId, 'user_id' => $existingOwner]);
        $oldStatus = apiOk($oldMember) ? ($oldMember['result']['status'] ?? '') : '';
        if (in_array($oldStatus, ['creator', 'administrator'], true)) {
            sendMessageWithKeyboard($chatId, "⛔ این {$kind} قبلاً توسط ادمین دیگری ثبت شده.");
            return;
        }
    }

    setChannelOwner($realChannelId, $userId, $title, $username, $type);
    
    $msg = "✅ {$kind} «" . $title . "» متصل شد.\n\n";
    if (!$isBotAdmin) {
        $msg .= "⚠️ **هشدار:** ربات در حال حاضر فقط «عضو» است. برای دریافت گزارش خروج اعضا، باید ربات را در {$kind} **ادمین** کنید.";
    } else {
        $msg .= "از الان گزارش خروج اعضا براتون میاد.";
    }
    sendMessageWithKeyboard($chatId, $msg);
}

function handleMyList($chatId, $userId, $messageId = null): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) {
        $msg = "📭 هنوز کانالی ثبت نکردید.\n\nبرای اتصال:\n1. ربات رو ادمین کنید\n2. این دستور رو بفرستید:\n/add mynews";
        editOrSend($chatId, $messageId, $msg);
        return;
    }

    $msg = "📋 کانال‌های شما (بررسی زنده):\n\n";
    $removed = [];
    $count = 0;
    foreach ($channels as $chId => $info) {
        $fresh = refreshChannelInfo((string)$chId, $info);
        if ($fresh === null) {
            $removed[] = ($info['title'] ?? '') ?: (string)$chId;
            continue;
        }
        $count++;
        $msg .= "🔹 " . (($fresh['title'] ?? '') ?: 'بدون نام') . (!empty($fresh['username']) ? " ({$fresh['username']})" : '') . "\nآیدی: {$chId}\n\n";
    }

    if ($count === 0) {
        $msg = "📭 هیچ کانال فعالی ندارید.\n\n";
    }
    if (!empty($removed)) {
        $msg .= "🧹 این موارد چون ربات دیگه توشون نیست از لیست حذف شدن:\n- " . implode("\n- ", $removed);
    }
    editOrSend($chatId, $messageId, trim($msg));
}

function handleRemoveChannel($chatId, $userId, string $arg, $messageId = null): void {
    if ($arg === '') { 
        editOrSend($chatId, $messageId, "❌ نام کانال رو بعد از remove بنویسید."); 
        return; 
    }
    $realId = findOwnerChannel($userId, $arg) ?? resolveChannelId($arg);
    if ($realId === null) { editOrSend($chatId, $messageId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null) { editOrSend($chatId, $messageId, "❌ این کانال تو ربات ثبت نشده."); return; }
    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) { editOrSend($chatId, $messageId, "⛔ شما مالک این کانال نیستید."); return; }
    removeChannel($realId);
    editOrSend($chatId, $messageId, "🗑 کانال حذف شد.");
}

function handleHistory($chatId, $userId, string $arg, $messageId = null): void {
    if ($arg === '') { 
        editOrSend($chatId, $messageId, "❌ نام کانال رو بعد از history بنویسید."); 
        return; 
    }
    $realId = findOwnerChannel($userId, $arg) ?? resolveChannelId($arg);
    if ($realId === null) { editOrSend($chatId, $messageId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) { 
        editOrSend($chatId, $messageId, "⛔ دسترسی ندارید."); 
        return; 
    }
    $history = getLeaveHistory($realId);
    if (empty($history)) { 
        editOrSend($chatId, $messageId, "📭 هنوز خروجی ثبت نشده."); 
        return; 
    }
    $info = getChannelInfo($realId);
    $name = ($info['title'] ?? '') ?: $realId;
    $msg = "📊 آخرین خروج‌ها از «{$name}»:\n\n";
    foreach (array_slice($history, 0, 15) as $idx => $item) {
        $num = $idx + 1;
        $msg .= "{$num}. {$item['name']} ({$item['user_id']})\n🕒 {$item['time']}\n\n";
    }
    editOrSend($chatId, $messageId, $msg);
}

/* ───────────────── قابلیت‌های ویژه (ختم کانال) ───────────────── */

function sendSpecialMenu($chatId, $messageId = null): void {
    $inlineKeyboard = [[['text' => '📿 ختم‌های کانال', 'callback_data' => 'khatm_menu']]];
    editOrSend($chatId, $messageId, "✨ قابلیت‌های ویژه\n\nاز بین گزینه‌های زیر انتخاب کنید:", ['inline_keyboard' => $inlineKeyboard]);
}

function sendKhatmMenu($chatId, $userId, $messageId = null): void {
    $rows = [];
    if (!empty(getOwnerChannels($userId))) {
        $rows[] = [['text' => '➕ ساخت ختم جدید', 'callback_data' => 'khatm_create']];
        $rows[] = [['text' => '🗂 مدیریت ختم‌ها (ریست / حذف)', 'callback_data' => 'khatm_manage']];
    } else {
        $rows[] = [['text' => '⚠️ ابتدا یک کانال متصل کنید', 'callback_data' => 'menu_connect']];
        if (isBotOwner($userId)) {
            $rows[] = [['text' => '🗂 مدیریت ختم‌ها (ریست / حذف)', 'callback_data' => 'khatm_manage']];
        }
    }
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'special_menu']];
    editOrSend($chatId, $messageId, "📿 بخش ختم‌های کانال\n\nیک گزینه را انتخاب کنید:", ['inline_keyboard' => $rows]);
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
            editOrSend($chatId, $messageId, "❌ برای ساخت ختم، ابتدا باید یک کانال به ربات متصل کنید.", ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'khatm_menu']]]]);
            return;
        }
        clearUserState($userIdStr);
        setUserState($userIdStr, 'khatm_info', []);
        sendMessageWithKeyboard($chatId, khatmInfoPrompt(), khatmCancelKeyboard());
        return;
    }

    if ($data === 'khatm_manage') {
        answerCallbackQuery($cbId);
        sendKhatmManageList($chatId, $userId, $messageId);
        return;
    }

    if (strpos($data, 'khatm_view:') === 0) {
        answerCallbackQuery($cbId);
        sendKhatmDetails($chatId, $userId, substr($data, strlen('khatm_view:')), $messageId);
        return;
    }

    if (strpos($data, 'khatm_reset:') === 0) {
        answerCallbackQuery($cbId);
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_reset:')), $cbId, $messageId);
        if ($khatm === null) return;
        editOrSend(
            $chatId, $messageId,
            "🔄 ریست ختم «{$khatm['title']}»\n\nمجموع و شمارش همه‌ی کاربران صفر می‌شود و قابل برگشت نیست. مطمئنید؟",
            ['inline_keyboard' => [
                [['text' => '✅ بله، ریست کن', 'callback_data' => 'khatm_reset_ok:' . $khatm['id']]],
                [['text' => '❌ انصراف', 'callback_data' => 'khatm_view:' . $khatm['id']]],
            ]]
        );
        return;
    }

    if (strpos($data, 'khatm_reset_ok:') === 0) {
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_reset_ok:')), $cbId, $messageId);
        if ($khatm === null) return;
        answerCallbackQuery($cbId, 'در حال ریست...');
        resetKhatm($khatm['id']);
        updateChannelKhatmMessage($khatm['id'], true);
        editOrSend(
            $chatId, $messageId,
            "✅ ختم «{$khatm['title']}» ریست شد و از صفر شروع می‌شود.",
            ['inline_keyboard' => [
                [['text' => '📄 جزئیات ختم', 'callback_data' => 'khatm_view:' . $khatm['id']]],
                [['text' => '🔙 لیست ختم‌ها', 'callback_data' => 'khatm_manage']],
            ]]
        );
        return;
    }

    if (strpos($data, 'khatm_del:') === 0) {
        answerCallbackQuery($cbId);
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_del:')), $cbId, $messageId);
        if ($khatm === null) return;
        editOrSend(
            $chatId, $messageId,
            "🗑 حذف ختم «{$khatm['title']}»\n\nختم و همه‌ی آمارش پاک می‌شود و پست کانال هم حذف می‌شود (اگر ربات اجازه داشته باشد). مطمئنید؟",
            ['inline_keyboard' => [
                [['text' => '✅ بله، حذف کن', 'callback_data' => 'khatm_del_ok:' . $khatm['id']]],
                [['text' => '❌ انصراف', 'callback_data' => 'khatm_view:' . $khatm['id']]],
            ]]
        );
        return;
    }

    if (strpos($data, 'khatm_del_ok:') === 0) {
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_del_ok:')), $cbId, $messageId);
        if ($khatm === null) return;
        answerCallbackQuery($cbId, 'در حال حذف...');
        performDeleteKhatm($khatm);
        editOrSend(
            $chatId, $messageId,
            "🗑 ختم «{$khatm['title']}» حذف شد.",
            ['inline_keyboard' => [[['text' => '🔙 لیست ختم‌ها', 'callback_data' => 'khatm_manage']]]]
        );
        return;
    }

    // دکمه حذف همه ختم‌ها
    if ($data === 'khatm_del_all') {
        $manageable = getManageableKhatms($userId);
        if (empty($manageable)) {
            answerCallbackQuery($cbId, 'شما هیچ ختمی ندارید.', true);
            return;
        }
        answerCallbackQuery($cbId);
        editOrSend(
            $chatId, $messageId,
            "⚠️ آیا از حذف **تمام** " . count($manageable) . " ختم خود مطمئن هستید؟ این عمل غیرقابل بازگشت است.",
            ['inline_keyboard' => [
                [['text' => '✅ بله، همه را حذف کن', 'callback_data' => 'khatm_del_all_ok']],
                [['text' => '❌ انصراف', 'callback_data' => 'khatm_manage']]
            ]]
        );
        return;
    }

    if ($data === 'khatm_del_all_ok') {
        $manageable = getManageableKhatms($userId);
        answerCallbackQuery($cbId, 'در حال حذف...');
        foreach ($manageable as $k) {
            performDeleteKhatm($k);
        }
        editOrSend(
            $chatId, $messageId,
            "✅ تمام ختم‌های شما با موفقیت حذف شدند.",
            ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'khatm_menu']]]]
        );
        return;
    }

    answerCallbackQuery($cbId);
}

/* ───────────────── مدیریت ختم‌ها (ریست / حذف) ───────────────── */

function canManageKhatm(array $khatm, $userId): bool {
    if (isBotOwner($userId)) return true;
    if ((string)($khatm['owner_id'] ?? '') === (string)$userId) return true;
    $chOwner = getChannelOwner((string)($khatm['channel_id'] ?? ''));
    return $chOwner !== null && $chOwner === (string)$userId;
}

function getManageableKhatms($userId): array {
    $list = [];
    foreach (getAllKhatms() as $k) {
        if (canManageKhatm($k, $userId)) $list[] = $k;
    }
    return $list;
}

function loadManageableKhatm($chatId, $userId, string $khatmId, $cbId = null, $messageId = null): ?array {
    $back = ['inline_keyboard' => [[['text' => '🔙 لیست ختم‌ها', 'callback_data' => 'khatm_manage']]]];
    if (!preg_match('/^khatm_[A-Za-z0-9]+$/', $khatmId)) {
        if ($cbId) answerCallbackQuery($cbId);
        editOrSend($chatId, $messageId, "❌ شناسه‌ی ختم نامعتبر است.", $back);
        return null;
    }
    $khatm = getKhatm($khatmId);
    if ($khatm === null) {
        if ($cbId) answerCallbackQuery($cbId);
        editOrSend($chatId, $messageId, "❌ این ختم دیگر وجود ندارد.", $back);
        return null;
    }
    if (!canManageKhatm($khatm, $userId)) {
        if ($cbId) answerCallbackQuery($cbId, '⛔ دسترسی ندارید.', true);
        else editOrSend($chatId, $messageId, "⛔ شما دسترسی مدیریت این ختم را ندارید.", $back);
        return null;
    }
    return $khatm;
}

function sendKhatmManageList($chatId, $userId, $messageId = null): void {
    $list = array_slice(getManageableKhatms($userId), 0, 20);
    if (empty($list)) {
        editOrSend(
            $chatId, $messageId,
            "📭 هنوز ختمی ندارید.",
            ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'khatm_menu']]]]
        );
        return;
    }
    $rows = [];
    foreach ($list as $k) {
        $label = mb_substr((string)$k['title'], 0, 28) . " ({$k['total_done']}/{$k['target']})";
        $rows[] = [['text' => $label, 'callback_data' => 'khatm_view:' . $k['id']]];
    }
    $rows[] = [['text' => '🗑 حذف همه ختم‌ها', 'callback_data' => 'khatm_del_all']];
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'khatm_menu']];
    
    editOrSend($chatId, $messageId, "🗂 ختم‌های شما\n\nیکی را برای ریست یا حذف انتخاب کنید:", ['inline_keyboard' => $rows]);
}

function sendKhatmDetails($chatId, $userId, string $khatmId, $messageId = null): void {
    $khatm = loadManageableKhatm($chatId, $userId, $khatmId, null, $messageId);
    if ($khatm === null) return;

    $info = getChannelInfo((string)($khatm['channel_id'] ?? ''));
    $chName = $info !== null && ($info['title'] ?? '') !== '' ? $info['title'] : (string)($khatm['channel_id'] ?? '-');
    $contributors = count(getKhatmContributorIds($khatm['id']));

    $text = "📿 " . $khatm['title'] . "\n\n"
          . "🎯 هدف: " . $khatm['target'] . "\n"
          . "✅ ثبت‌شده: " . $khatm['total_done'] . "\n"
          . "⏳ مانده: " . khatmRemaining($khatm) . "\n"
          . "👥 تعداد مشارکت‌کنندگان: " . $contributors . "\n"
          . "📢 کانال: " . $chName . "\n"
          . "🆔 " . $khatm['id'];

    editOrSend($chatId, $messageId, $text, ['inline_keyboard' => [
        [['text' => '🔄 ریست شمارش', 'callback_data' => 'khatm_reset:' . $khatm['id']]],
        [['text' => '🗑 حذف ختم', 'callback_data' => 'khatm_del:' . $khatm['id']]],
        [['text' => '🔙 لیست ختم‌ها', 'callback_data' => 'khatm_manage']],
    ]]);
}

function performDeleteKhatm(array $khatm): void {
    $chId = (string)($khatm['channel_id'] ?? '');
    $msgId = $khatm['channel_msg_id'] ?? null;

    if ($chId !== '' && $msgId) {
        if (!deleteMessageSafe($chId, $msgId)) {
            $payload = [
                'chat_id' => $chId,
                'message_id' => $msgId,
                'reply_markup' => ['inline_keyboard' => []],
            ];
            if (($khatm['msg_type'] ?? 'text') === 'photo') {
                $payload['caption'] = "🗑 این ختم حذف شد.";
                apiPost('editMessageCaption', $payload);
            } else {
                $payload['text'] = "🗑 این ختم حذف شد.";
                apiPost('editMessageText', $payload);
            }
        }
    }
    deleteKhatm($khatm['id']);
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

function abortKhatmFlow($chatId, string $userIdStr): void {
    clearUserState($userIdStr);
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
            setUserState($userIdStr, 'khatm_info', $data);
            sendMessageWithKeyboard($chatId, khatmInfoPrompt($error), khatmCancelKeyboard());
            return;
        }

        $data['title'] = $title;
        $data['desc'] = $desc;
        $data['image'] = $image;
        setUserState($userIdStr, 'khatm_numbers', $data);
        sendMessageWithKeyboard($chatId, khatmNumbersPrompt(), khatmCancelKeyboard());
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
            setUserState($userIdStr, 'khatm_numbers', $data);
            sendMessageWithKeyboard(
                $chatId,
                khatmNumbersPrompt('فرمت درست نیست. خط اول تعداد کل، خط دوم دقیقاً ۳ عدد مثبت با کاما.'),
                khatmCancelKeyboard()
            );
            return;
        }

        $data['target'] = $target;
        $data['buttons'] = array_map('intval', $buttons);

        $channels = getOwnerChannels($userId);
        if (empty($channels)) {
            clearUserState($userIdStr);
            sendMessageWithKeyboard($chatId, "❌ هیچ کانال متصلی ندارید. ابتدا یک کانال متصل کنید.", khatmBackKeyboard());
            return;
        }

        setUserState($userIdStr, 'khatm_channel', $data);

        if (count($channels) === 1) {
            sendKhatmChannelPreview($chatId, $userId, (string)array_key_first($channels));
            return;
        }

        sendMessageWithKeyboard(
            $chatId,
            "✅ اطلاعات ثبت شد.\n\nکانالی که ختم در آن ارسال شود را انتخاب کنید:",
            buildKhatmChannelPicker($userId)
        );
        return;
    }

    if ($state === 'khatm_channel') {
        setUserState($userIdStr, 'khatm_channel', $data);
        sendMessageWithKeyboard($chatId, "کانال مورد نظر را از دکمه‌های زیر انتخاب کنید:", buildKhatmChannelPicker($userId));
        return;
    }
}

function sendKhatmChannelPreview($chatId, $userId, string $chId): void {
    $userIdStr = (string)$userId;

    if (getUserState($userIdStr) !== 'khatm_channel') {
        sendMessageWithKeyboard($chatId, "⚠️ این درخواست منقضی شده است. لطفاً ساخت ختم را از ابتدا شروع کنید.", khatmBackKeyboard());
        return;
    }

    $data = getUserStateData($userIdStr);
    foreach (['title', 'desc', 'image', 'target', 'buttons'] as $required) {
        if (!isset($data[$required])) {
            clearUserState($userIdStr);
            sendMessageWithKeyboard($chatId, "⚠️ اطلاعات ختم ناقص است. لطفاً ساخت ختم را از ابتدا شروع کنید.", khatmBackKeyboard());
            return;
        }
    }

    $retryKeyboard = buildKhatmChannelPicker($userId);

    $botUsername = getBotUsername();
    if (!$botUsername) {
        sendMessageWithKeyboard($chatId, "❌ خطا در شناسایی ربات. دوباره کانال را انتخاب کنید.", $retryKeyboard);
        return;
    }

    $data['channel_id'] = $chId;
    $data['owner_id'] = $userIdStr;

    $khatmId = createKhatm($data);
    $khatm = getKhatm($khatmId);
    if ($khatm === null) {
        sendMessageWithKeyboard($chatId, "❌ خطا در ذخیره ختم. دوباره کانال را انتخاب کنید.", $retryKeyboard);
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
        if (apiOk($res)) {
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
        if (apiOk($res)) {
            $channelMsgId = $res['result']['message_id'];
        }
    }

    if ($channelMsgId) {
        $khatm['channel_msg_id'] = $channelMsgId;
        $khatm['msg_type'] = $msgType;
        updateKhatm($khatmId, $khatm);
        setChannelActiveKhatm($chId, $khatmId);
        clearUserState($userIdStr);

        sendMessageWithKeyboard(
            $chatId,
            "✅ ختم با موفقیت در کانال ارسال شد!\n\nاعضا با دکمه «شرکت در ختم» به ربات هدایت شده و شمارش را ثبت می‌کنند. آمار پست کانال هم زنده آپدیت می‌شود.\n\nبرای ریست یا حذف: /khatms",
            khatmBackKeyboard()
        );
    } else {
        deleteKhatm($khatmId);
        setUserState($userIdStr, 'khatm_channel', $data);
        sendMessageWithKeyboard(
            $chatId,
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
            answerCallbackQuery($cbId, '❌ این ختم پیدا نشد (ممکن است حذف شده باشد).', true);
            return;
        }

        $allowed = array_map('intval', $khatm['buttons'] ?? []);
        if ($amount <= 0 || !in_array($amount, $allowed, true)) {
            answerCallbackQuery($cbId, '❌ مقدار نامعتبر.', true);
            return;
        }

        if (isKhatmComplete($khatm)) {
            answerCallbackQuery($cbId, '✅ این ختم تکمیل شده است.', true);
            editOrSend(
                $chatId, $messageId,
                buildKhatmPrivateText($khatm, null, getKhatmUserTotal($khatmId, (string)$userId)),
                buildKhatmPrivateKeyboard($khatm)
            );
            return;
        }

        answerCallbackQuery($cbId, "✅ {$amount} ذکر ثبت شد.");

        $userLockKey = "khatm_user_lock:{$khatmId}:{$userId}";
        $hasLock = redisWaitLock($userLockKey, 5, 3000);

        $fresh = getKhatm($khatmId);
        if (!$fresh) {
            if ($hasLock) redisDel($userLockKey);
            return;
        }
        if (isKhatmComplete($fresh)) {
            if ($hasLock) redisDel($userLockKey);
            editOrSend(
                $chatId, $messageId,
                buildKhatmPrivateText($fresh, null, getKhatmUserTotal($khatmId, (string)$userId)),
                buildKhatmPrivateKeyboard($fresh)
            );
            return;
        }

        $applied = min($amount, khatmRemaining($fresh));
        $mine = addKhatmContribution($khatmId, (string)$userId, $applied);
        $newKhatm = getKhatm($khatmId);

        if ($hasLock) redisDel($userLockKey);

        if ($newKhatm) {
            // استفاده از editOrSend برای جلوگیری از اسپم در چت خصوصی
            editOrSend(
                $chatId, $messageId,
                buildKhatmPrivateText($newKhatm, "✅ {$applied} ذکر ثبت شد.", $mine),
                buildKhatmPrivateKeyboard($newKhatm)
            );
            updateChannelKhatmMessage($khatmId, isKhatmComplete($newKhatm));
        }
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
        if ($check['ok']) {
            answerCallbackQuery($cbId, '✅ عضویت تایید شد.', false);
            sendStartMenu($chatId);
        } else {
            answerCallbackQuery($cbId, '❌ هنوز عضو همه‌ی کانال‌ها نشدید.', true);
        }
        return;
    }

    if ($data === 'khatm_cancel') {
        clearUserState((string)$userId);
        answerCallbackQuery($cbId, 'لغو شد.');
        editOrSend($chatId, $messageId, "❌ ساخت ختم لغو شد.", khatmBackKeyboard());
        return;
    }

    if (strpos($data, 'khatm_channel:') === 0) {
        if ($chatType !== 'private') { answerCallbackQuery($cbId); return; }
        $chId = substr($data, strlen('khatm_channel:'));

        $owner = getChannelOwner($chId);
        if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
            answerCallbackQuery($cbId, '⛔ این کانال متعلق به شما نیست (یا دیگر ثبت نیست).', true);
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
        case 'menu_connect':
            editOrSend(
                $chatId, $messageId,
                getConnectText(),
                ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'khatm_menu']]]]
            );
            break;
        case 'menu_mylist': handleMyList($chatId, $userId, $messageId); break;
        case 'menu_help': editOrSend($chatId, $messageId, getHelpText()); break;
        case 'menu_sponsor': editOrSend($chatId, $messageId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
        case 'menu_bug': editOrSend($chatId, $messageId, "🐞 گزارش باگ\n\nمتن مشکل رو بعد از این دستور بنویسید و بفرستید:\n\n/bug"); break;
    }
}

/* ───────────────── Router متن ───────────────── */

function handleTextMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? null;
    $chatType = $message['chat']['type'] ?? 'private';
    $userId = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? '');
    $stateText = trim($message['text'] ?? ($message['caption'] ?? ''));

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

    if ($chatType === 'private') {
        $userState = getUserState($userIdStr);
        if ($userState !== null && strpos($userState, 'khatm_') === 0) {
            if (strpos($text, '/') === 0 || isset($replyButtonMap[$text])) {
                abortKhatmFlow($chatId, $userIdStr);
            } else {
                handleKhatmState($chatId, $userId, $userState, $stateText, $message);
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
            case 'connect': sendMessageWithKeyboard($chatId, getConnectText()); break;
            case 'mylist': handleMyList($chatId, $userId); break;
            case 'help': sendMessageWithKeyboard($chatId, getHelpText()); break;
            case 'sponsor': sendMessageWithKeyboard($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
            case 'bug': sendMessageWithKeyboard($chatId, "🐞 گزارش باگ\n\nمتن مشکل رو بعد از این دستور بنویسید و بفرستید:\n\n/bug"); break;
            case 'special': sendSpecialMenu($chatId); break;
        }
        return;
    }

    [$cmd, $arg] = parseCommand($text);
    if ($cmd === null) return;

    $resetForce = ($cmd === 'start');
    $skipForceCheck = in_array($cmd, ['help', 'bug', 'status'], true);

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
                    $mine = getKhatmUserTotal($khatmId, $userIdStr);
                    sendMessageWithKeyboard($chatId, buildKhatmPrivateText($khatm, null, $mine), buildKhatmPrivateKeyboard($khatm));
                    return;
                }
                sendMessageWithKeyboard($chatId, "❌ این ختم پیدا نشد یا حذف شده است.");
            }
            sendStartMenu($chatId);
            break;

        case 'help': sendMessageWithKeyboard($chatId, getHelpText()); break;
        case 'status':
            $msg = "🤖 ربات آنفالویاب: آنلاین\n🕒 " . date('Y-m-d H:i:s');
            if (isBotOwner($userId)) $msg .= "\n🗄 Redis: ✅\n📦 استخر: " . count(getForceChannelsPool());
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'add':
        case 'connect': handleConnect($chatId, $userId, (string)$arg, (string)$chatType); break;
        case 'my': handleMyList($chatId, $userId); break;
        case 'remove': handleRemoveChannel($chatId, $userId, (string)$arg); break;
        case 'history': handleHistory($chatId, $userId, (string)$arg); break;
        case 'khatms':
        case 'khatm':
            if ($chatType !== 'private') {
                sendMessageWithKeyboard($chatId, "📿 مدیریت ختم‌ها را داخل چت خصوصی ربات انجام دهید.");
                break;
            }
            sendKhatmManageList($chatId, $userId);
            break;

        case 'bug':
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ متن مشکل رو بعد از bug بنویسید."); return; }
            if (BOT_OWNER_ID === '') { sendMessageWithKeyboard($chatId, "❌ دریافت گزارش غیرفعاله."); return; }
            $senderName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')) ?: 'بدون نام';
            $senderUsername = $message['from']['username'] ?? '';
            $bugText = "🐞 گزارش باگ\n\n👤 " . $senderName . "\n🔖 " . ($senderUsername !== '' ? '@' . $senderUsername : 'ندارد') . "\n🔢 " . $userId . "\n━━━━━━━━━━━━━━\n" . $arg;
            sendPlainText(BOT_OWNER_ID, $bugText);
            sendMessageWithKeyboard($chatId, "✅ گزارش ارسال شد.");
            break;

        case 'zk9xad':
            if (!isBotOwner($userId)) return;
            sendMessageWithKeyboard($chatId, getAdminHelpText()); break;
        case 'zk9xch':
            if (!isBotOwner($userId)) return;
            // بروزرسانی زنده و بدون کش
            $all = getAllChannelsMap();
            if (empty($all)) { sendMessageWithKeyboard($chatId, "📭 خالی است."); return; }
            $msg = "👑 کل کانال‌ها (" . count($all) . "):\n\n";
            foreach ($all as $chId => $info) {
                $fresh = refreshChannelInfo((string)$chId, $info);
                if ($fresh === null) continue;
                $msg .= "🔹 " . (($fresh['title'] ?? '') ?: 'بدون نام') . "\nآیدی: {$chId}\nمالک: {$fresh['owner_id']}\n\n";
            }
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xfa':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بنویسید. مثال:\n/zk9xfa mychannel"); return; }
            $check = checkBotIsAdminInChannel((string)$arg);
            if (!$check['ok'] || !addForceChannelToPool((string)normalizeChannelRef((string)$arg))) { sendMessageWithKeyboard($chatId, "❌ خطا. اول ربات رو ادمین کنید."); return; }
            sendMessageWithKeyboard($chatId, "✅ اضافه شد.");
            break;
        case 'zk9xfd':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بنویسید. مثال:\n/zk9xfd mychannel"); return; }
            $ref = normalizeChannelRef((string)$arg) ?? (string)$arg;
            sendMessageWithKeyboard($chatId, removeForceChannelFromPool($ref) ? "🗑 حذف شد." : "❌ پیدا نشد.");
            break;
        case 'zk9xfl':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            if (empty($pool)) { sendMessageWithKeyboard($chatId, "📭 استخر خالیه."); return; }
            $msg = "📢 استخر (" . count($pool) . "):\n\n";
            foreach ($pool as $idx => $ch) $msg .= ($idx + 1) . ". " . $ch . "\n";
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xst':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            $msg = "🔍 دیباگ (" . count($pool) . " کانال):\n\n";
            foreach ($pool as $idx => $ch) {
                $c = checkBotIsAdminInChannel($ch);
                $msg .= ($idx + 1) . ". " . $ch . ($c['ok'] ? " ✅" : " ❌ ({$c['error']})") . "\n";
            }
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xsp':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ متن اسپانسر رو بنویسید."); return; }
            setSponsorText((string)$arg);
            sendMessageWithKeyboard($chatId, "✅ اسپانسر بروزرسانی شد.");
            break;
        case 'zk9xkl':
            if (!isBotOwner($userId)) return;
            $all = getAllKhatms();
            if (empty($all)) { sendMessageWithKeyboard($chatId, "📭 هیچ ختمی وجود ندارد."); return; }
            $msg = "📿 همه‌ی ختم‌ها (" . count($all) . "):\n\n";
            foreach ($all as $k) {
                $msg .= "🔹 " . $k['title'] . "\n"
                      . "{$k['total_done']}/{$k['target']} | کانال: " . ($k['channel_id'] ?? '-') . "\n"
                      . $k['id'] . "\n\n";
            }
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xkr':
        case 'zk9xkd':
            if (!isBotOwner($userId)) return;
            $khatmId = trim((string)$arg);
            if (!preg_match('/^khatm_[A-Za-z0-9]+$/', $khatmId)) {
                sendMessageWithKeyboard($chatId, "❌ شناسه‌ی ختم را بنویسید. مثال:\n/{$cmd} khatm_abc123");
                return;
            }
            $khatm = getKhatm($khatmId);
            if ($khatm === null) { sendMessageWithKeyboard($chatId, "❌ ختم پیدا نشد."); return; }
            if ($cmd === 'zk9xkr') {
                resetKhatm($khatmId);
                updateChannelKhatmMessage($khatmId, true);
                sendMessageWithKeyboard($chatId, "✅ ختم «{$khatm['title']}» ریست شد.");
            } else {
                performDeleteKhatm($khatm);
                sendMessageWithKeyboard($chatId, "🗑 ختم «{$khatm['title']}» حذف شد.");
            }
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

function handleMyChatMember(array $m): void {
    $chat = $m['chat'] ?? [];
    $chatId = $chat['id'] ?? null;
    $type = (string)($chat['type'] ?? '');
    if (!$chatId || $type === 'private' || $type === '') return;

    $chatIdStr = (string)$chatId;
    $newStatus = $m['new_chat_member']['status'] ?? '';
    $title = (string)($chat['title'] ?? 'بدون نام');
    $kind = $type === 'channel' ? 'کانال' : 'گروه';

    if (in_array($newStatus, ['left', 'kicked'], true)) {
        $owner = getChannelOwner($chatIdStr);
        if ($owner !== null) {
            removeChannel($chatIdStr);
            sendPlainText($owner, "⚠️ ربات از {$kind} «{$title}» حذف شد و اتصال آن لغو شد.\nبرای اتصال دوباره، ربات را ادمین کنید و /add بزنید.");
        }
        return;
    }

    $from = $m['from'] ?? null;
    if ($newStatus === 'administrator' && is_array($from) && !empty($from['id']) && empty($from['is_bot'])) {
        $ref = isset($chat['username']) ? (string)$chat['username'] : $chatIdStr;
        sendPlainText($from['id'], "✅ ربات تو {$kind} «{$title}» ادمین شد.\n\nبرای اتصال این دستور رو بفرستید:\n/add {$ref}");
    }
}