<?php
// src/bot_logic.php
declare(strict_types=1);

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
    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/us', $text, $m)) {
        return [null, null];
    }
    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

/* ───────────────── عضویت اجباری ───────────────── */

function checkBotIsAdminInChannel(string $channelRef): array {
    $channelRef = trim($channelRef);
    if ($channelRef === '') return ['ok' => false, 'error' => 'نام کانال خالی است.'];
    $ref = $channelRef;
    if ($ref[0] !== '@' && !preg_match('/^-?\d+$/', $ref)) $ref = '@' . $ref;
    $chatRes = apiPost('getChat', ['chat_id' => $ref]);
    if ($chatRes === null || empty($chatRes['ok'])) return ['ok' => false, 'error' => 'کانال پیدا نشد یا ربات دسترسی ندارد.'];
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

/* ───────────────── قابلیت‌های ویژه (فقط ختم) ───────────────── */

function sendOrEditMenu($chatId, ?int $messageId, string $text, array $inlineKeyboard): void {
    if ($messageId !== null) {
        $res = apiPost('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $inlineKeyboard]);
        if ($res !== null && !empty($res['ok'])) return;
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
        setUserState($userIdStr, 'khatm_title');
        sendPlainText($chatId, "➕ ساخت ختم جدید\n\nعنوان ختم را وارد کنید (مثلاً: صلوات):");
        return;
    }

    answerCallbackQuery($cbId);
}

/* ───────────────── فرآیند ساخت ختم (State Machine) ───────────────── */

function handleKhatmState($chatId, $userId, string $state, string $text, array $message): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);

    if ($state === 'khatm_title') {
        $data['title'] = $text;
        setUserState($userIdStr, 'khatm_desc', $data);
        sendPlainText($chatId, "✅ عنوان ثبت شد.\n\nحالا توضیحات ختم را وارد کنید:");
        return;
    }

    if ($state === 'khatm_desc') {
        $data['desc'] = $text;
        setUserState($userIdStr, 'khatm_image', $data);
        sendPlainText($chatId, "✅ توضیحات ثبت شد.\n\nحالا عکس ختم را ارسال کنید (یا کلمه «ندارد» را بفرستید):");
        return;
    }

    if ($state === 'khatm_image') {
        if ($text === 'ندارد') {
            $data['image'] = '';
        } elseif (isset($message['photo']) && is_array($message['photo'])) {
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
        $target = (int)preg_replace('/[^0-9]/', '', $text);
        if ($target <= 0) {
            sendPlainText($chatId, "❌ لطفاً یک عدد معتبر وارد کنید.");
            return;
        }
        $data['target'] = $target;
        setUserState($userIdStr, 'khatm_buttons', $data);
        sendPlainText($chatId, "✅ تعداد کل ثبت شد.\n\nحالا ۳ دکمه شمارش را با کاما جدا کنید (مثلاً: 1,3,7):");
        return;
    }

    if ($state === 'khatm_buttons') {
        $parts = array_map('trim', explode(',', $text));
        if (count($parts) !== 3 || !ctype_digit(implode('', $parts))) {
            sendPlainText($chatId, "❌ لطفاً دقیقاً ۳ عدد را با کاما وارد کنید (مثلاً: 1,3,7).");
            return;
        }
        $data['buttons'] = $parts;
        
        $channels = getOwnerChannels($userId);
        $rows = [];
        foreach ($channels as $chId => $info) {
            $rows[] = [['text' => $info['title'] ?: $chId, 'callback_data' => "khatm_channel:{$chId}"]];
        }
        $rows[] = [['text' => '❌ انصراف', 'callback_data' => 'khatm_cancel']];

        setUserState($userIdStr, 'khatm_channel', $data);
        sendMessageWithKeyboard($chatId, "✅ دکمه‌ها ثبت شد.\n\nحالا کانالی که می‌خواهید ختم در آن ارسال شود را انتخاب کنید:", ['inline_keyboard' => $rows]);
        return;
    }
}

function sendKhatmChannelPreview($chatId, $userId, string $chId): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);
    $data['channel_id'] = $chId;
    
    $khatmId = createKhatm($data);
    clearUserState($userIdStr);

    $khatm = getKhatm($khatmId);
    $botUsername = getBotUsername();
    $deepLink = "https://ble.ir/{$botUsername}?start={$khatmId}";
    
    $caption = "📿 **ختم جدید**\n\n"
             . "عنوان ختم: " . $khatm['title'] . "\n"
             . "توضیحات: " . $khatm['desc'] . "\n"
             . "تعداد کل: " . $khatm['target'] . "\n\n"
             . "برای شرکت در ختم و شمارش، روی دکمه زیر بزنید:";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📿 شرکت در ختم', 'url' => $deepLink]]
        ]
    ];

    $payload = [
        'chat_id' => $chId,
        'caption' => $caption,
        'parse_mode' => 'Markdown',
        'reply_markup' => $keyboard,
    ];

    if (!empty($khatm['image'])) {
        $payload['photo'] = $khatm['image'];
        apiPost('sendPhoto', $payload);
    } else {
        $payload['text'] = $caption;
        unset($payload['caption']);
        apiPost('sendMessage', $payload);
    }

    sendPlainText($chatId, "✅ ختم با موفقیت در کانال ارسال شد!\n\nاعضا با کلیک روی دکمه «شرکت در ختم» به ربات منتقل شده و کیبورد شمارش برایشان فعال می‌شود.");
}

/* ───────────────── کیبورد و صفحه شمارش ختم ───────────────── */

function sendKhatmUI($chatId, $userId, string $khatmId, string $alertMsg = ''): void {
    $khatm = getKhatm($khatmId);
    if (!$khatm) { sendPlainText($chatId, "❌ ختم پیدا نشد."); return; }

    $userIdStr = (string)$userId;
    $userContrib = getKhatmContribution($khatmId, $userIdStr);
    $totalDone = $khatm['total_done'] ?? 0;
    $remaining = max(0, $khatm['target'] - $totalDone);

    $caption = "عنوان ختم : " . $khatm['title'] . "\n"
             . "توضیحات ختم : " . $khatm['desc'];
             
    if ($alertMsg !== '') {
        $caption = "{$alertMsg}\n\n" . $caption;
    }

    $b = $khatm['buttons'];
    $keyboard = [
        'keyboard' => [
            [['text' => (string)$b[0]]],
            [['text' => (string)$b[1]]],
            [['text' => (string)$b[2]]],
            [['text' => "مجموع: {$totalDone}"], ['text' => "مانده: {$remaining}"]],
            [['text' => '🌟 محل نمایش کانال اسپانسر']],
            [['text' => '🔙 بازگشت به منوی اصلی']]
        ],
        'resize_keyboard' => true
    ];

    if (!empty($khatm['image'])) {
        $res = apiPost('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $khatm['image'],
            'caption' => $caption,
            'reply_markup' => $keyboard,
        ]);
        if ($res !== null && !empty($res['ok'])) return;
    }
    
    sendMessageWithKeyboard($chatId, $caption, $keyboard);
}

/* ───────────────── Callback ها ───────────────── */

function handleCallbackQuery(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $userId = $cb['from']['id'] ?? null;

    if (!$chatId || !$userId) { answerCallbackQuery($cbId); return; }

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
        $chId = substr($data, 14);
        answerCallbackQuery($cbId, 'در حال ارسال...');
        sendKhatmChannelPreview($chatId, $userId, $chId);
        return;
    }

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
            sendPlainText($chatId, "➕ اتصال کانال\n\n1. ربات رو تو کانال ادمین کنید\n2. این رو بفرستید:\n/add mynews");
            break;
        case 'menu_mylist': handleMyList($chatId, $userId); break;
        case 'menu_help': sendPlainText($chatId, getHelpText()); break;
        case 'menu_sponsor': sendPlainText($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
        case 'menu_bug':
            sendPlainText($chatId, "🐞 گزارش باگ\n\nمشکل رو این‌طوری بفرستید:\n\n/bug متن مشکل\n\nمستقیم برای مدیر ربات میاد.");
            break;
    }
}

/* ───────────────── Router متن ───────────────── */

function handleTextMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? null;
    $chatType = $message['chat']['type'] ?? 'private';
    $userId = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? '');

    if (!$chatId || !$userId || $text === '') return;
    if ($chatType !== 'private' && strpos($text, '/') !== 0) return;

    $userIdStr = (string)$userId;

    $userState = getUserState($userIdStr);
    if ($userState !== null && strpos($userState, 'khatm_') === 0) {
        handleKhatmState($chatId, $userId, $userState, $text, $message);
        return;
    }

    $replyButtonMap = [
        '➕ اتصال کانال' => 'connect', '➕ اتصال کانال جدید' => 'connect',
        '📋 کانال‌های من' => 'mylist', '📖 راهنما' => 'help',
        '📖 راهنمای استفاده' => 'help', '🌟 اسپانسر' => 'sponsor',
        '🌟 اسپانسر ما' => 'sponsor', '🐞 گزارش باگ' => 'bug',
        '✨ قابلیت‌های ویژه' => 'special',
        '🔙 بازگشت به منوی اصلی' => 'back_to_main'
    ];

    $activeKhatmId = getUserActiveKhatm($userIdStr);
    if ($activeKhatmId && !isset($replyButtonMap[$text])) {
        $khatm = getKhatm($activeKhatmId);
        if ($khatm) {
            $b = $khatm['buttons'];
            if (in_array($text, $b, true)) {
                addKhatmContribution($activeKhatmId, $userIdStr, (int)$text);
                sendKhatmUI($chatId, $userId, $activeKhatmId, "✅ {$text} ذکر ثبت شد.");
                return;
            } elseif (strpos($text, 'محل نمایش کانال اسپانسر') !== false) {
                sendPlainText($chatId, "🌟 اسپانسر:\n\n" . getSponsorText());
                return;
            } elseif (strpos($text, 'مجموع:') === 0 || strpos($text, 'مانده:') === 0) {
                sendKhatmUI($chatId, $userId, $activeKhatmId, "📊 آمار لحظه‌ای ختم:");
                return;
            }
        } else {
            delUserActiveKhatm($userIdStr);
        }
    }

    if (isset($replyButtonMap[$text])) {
        $forceCheck = checkUserForceJoin($userId, false);
        if (!$forceCheck['ok']) { sendForceJoinPrompt($chatId, $forceCheck['missing']); return; }

        if ($replyButtonMap[$text] === 'back_to_main') {
            delUserActiveKhatm($userIdStr);
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
    $skipForceCheck = in_array($cmd, ['help', 'bug', 'status'], true);
    if (!$skipForceCheck) {
        $forceCheck = checkUserForceJoin($userId, $resetForce);
        if (!$forceCheck['ok']) { sendForceJoinPrompt($chatId, $forceCheck['missing']); return; }
    }

    switch ($cmd) {
        case 'start':
            if (strpos($arg, 'khatm_') === 0) {
                $khatmId = $arg;
                $khatm = getKhatm($khatmId);
                if ($khatm) {
                    setUserActiveKhatm($userIdStr, $khatmId);
                    sendKhatmUI($chatId, $userId, $khatmId);
                    return;
                }
            }
            delUserActiveKhatm($userIdStr);
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
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $fullName = trim($firstName . ' ' . $lastName) ?: 'بدون نام';
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