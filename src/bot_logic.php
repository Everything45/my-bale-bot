<?php
// src/bot_logic.php
declare(strict_types=1);

function finishRequest(): void {
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo 'OK';
    exit;
}

function isBotOwner($userId): bool {
    if (!$userId || BOT_OWNER_ID === '') {
        return false;
    }
    return (string)$userId === (string)BOT_OWNER_ID;
}

function getBotId(): ?string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $stored = redisGet('bot_self_id');
    if ($stored !== null && $stored !== '') {
        return $cached = $stored;
    }
    $res = apiPost('getMe', []);
    if ($res === null || empty($res['ok']) || empty($res['result']['id'])) {
        return null;
    }
    $id = (string)$res['result']['id'];
    redisSet('bot_self_id', $id, 86400);
    return $cached = $id;
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
    if ($channelRef === '') {
        return ['ok' => false, 'error' => 'نام کانال خالی است.'];
    }
    $ref = $channelRef;
    if ($ref[0] !== '@' && !preg_match('/^-?\d+$/', $ref)) {
        $ref = '@' . $ref;
    }
    $chatRes = apiPost('getChat', ['chat_id' => $ref]);
    if ($chatRes === null || empty($chatRes['ok'])) {
        return ['ok' => false, 'error' => 'کانال پیدا نشد یا ربات دسترسی ندارد.'];
    }
    $realId = (string)$chatRes['result']['id'];
    $title = $chatRes['result']['title'] ?? 'بدون نام';
    $username = isset($chatRes['result']['username']) ? '@' . $chatRes['result']['username'] : '';

    $botId = getBotId();
    if ($botId === null) {
        return ['ok' => false, 'error' => 'شناسایی ربات ناموفق بود.'];
    }
    $memberRes = apiPost('getChatMember', [
        'chat_id' => $realId,
        'user_id' => $botId,
    ]);
    if ($memberRes === null || empty($memberRes['ok'])) {
        return ['ok' => false, 'error' => 'بررسی وضعیت ربات در کانال ناموفق بود.'];
    }
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['administrator', 'creator'], true)) {
        return ['ok' => false, 'error' => 'ربات در این کانال ادمین نیست.'];
    }
    return [
        'ok' => true,
        'real_id' => $realId,
        'title' => $title,
        'username' => $username,
    ];
}

function checkSingleChannelMembership(string $channelRef, $userId): ?bool {
    $res = apiPost('getChatMember', [
        'chat_id' => $channelRef,
        'user_id' => $userId,
    ]);
    if ($res === null) {
        error_log('[ForceJoin] getChatMember null for channel=' . $channelRef . ' user=' . $userId);
        return null;
    }
    if (empty($res['ok'])) {
        $desc = $res['description'] ?? 'unknown';
        error_log('[ForceJoin] getChatMember error for channel=' . $channelRef . ' user=' . $userId . ' desc=' . $desc);
        return null;
    }
    $status = $res['result']['status'] ?? 'left';
    $isMemberField = $res['result']['is_member'] ?? null;

    if ($status === 'restricted') {
        return (bool)$isMemberField;
    }

    return in_array($status, ['creator', 'administrator', 'member'], true);
}

function checkUserForceJoin($userId, bool $reset = false): array {
    if (isBotOwner($userId)) {
        return ['ok' => true, 'missing' => []];
    }
    $assigned = assignRandomForceChannels($userId, $reset);
    if (empty($assigned)) {
        return ['ok' => true, 'missing' => []];
    }
    $missing = [];
    foreach ($assigned as $ch) {
        $result = checkSingleChannelMembership($ch, $userId);
        if ($result === null) {
            error_log('[ForceJoin] Skipping channel=' . $ch . ' for user=' . $userId . ' due to API error');
            $missing[] = $ch;
            continue;
        }
        if ($result === false) {
            $missing[] = $ch;
        }
    }
    return [
        'ok' => empty($missing),
        'missing' => $missing,
    ];
}

function sendForceJoinPrompt($chatId, array $missingChannels): void {
    $msg = "⚠️ عضویت اجباری\n\n"
         . "برای استفاده از ربات، اول تو کانال‌های زیر عضو شوید و «عضو شدم» رو بزنید.\n\n";
    $inlineKeyboard = [];

    foreach ($missingChannels as $index => $ch) {
        $num = $index + 1;
        $cleanUser = ltrim($ch, '@');
        $msg .= "کانال " . $num . ": " . $ch . "\n";
        if (!preg_match('/^-?\d+$/', $ch)) {
            $inlineKeyboard[] = [
                ['text' => "📢 ورود به کانال " . $num . " (" . $ch . ")", 'url' => "https://ble.ir/" . $cleanUser]
            ];
        }
    }

    $inlineKeyboard[] = [
        ['text' => '✅ عضو شدم، بررسی کن', 'callback_data' => 'check_force_join']
    ];

    sendMessageWithKeyboard($chatId, $msg, ['inline_keyboard' => $inlineKeyboard]);
}

/* ───────────────── منو و متن‌ها ───────────────── */

function sendStartMenu($chatId): void {
    $text = "👋 به ربات آنفالویاب خوش آمدید!\n\n"
          . "لحظه‌ای می‌فهمید چه اعضایی از کانالتون خارج می‌شن.\n\n"
          . "از دکمه‌های پایین استفاده کنید 👇";

    $keyboard = [
        'keyboard' => [
            [['text' => '➕ اتصال کانال'], ['text' => '📋 کانال‌های من']],
            [['text' => '📖 راهنما'], ['text' => '🌟 اسپانسر']],
            [['text' => '🐞 گزارش باگ']]
        ],
        'resize_keyboard' => true
    ];

    sendMessageWithKeyboard($chatId, $text, $keyboard);
}

function getHelpText(): string {
    return "📖 راهنمای استفاده\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "1️⃣ ربات رو تو کانالتون ادمین کنید (با دسترسی مدیریت اعضا)\n\n"
         . "2️⃣ کانال رو وصل کنید:\n"
         . "/add نام یا آیدی کانال\n\n"
         . "مثال:\n"
         . "/add mynews\n"
         . "/add @mynews\n"
         . "/add -1001234567890\n\n"
         . "━━━━━━━━━━━━━━━━━━\n"
         . "🛠 دستورات\n\n"
         . "/my — لیست کانال‌ها\n"
         . "/status — وضعیت ربات\n"
         . "/history نام‌کانال — آخرین خروج‌ها\n"
         . "/remove نام‌کانال — قطع اتصال\n"
         . "/bug متن — گزارش مشکل\n"
         . "/start — منوی اصلی";
}

function getAdminHelpText(): string {
    return "👑 پنل مدیریت (مخصوص مالک)\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "📊 مشاهده\n"
         . "/zk9xch — کل کانال‌ها\n"
         . "/zk9xfl — استخر عضویت اجباری\n"
         . "/zk9xst [آیدی] — دیباگ\n\n"
         . "⚙️ تنظیمات\n"
         . "/zk9xfa کانال — افزودن به استخر\n"
         . "/zk9xfd کانال — حذف از استخر\n"
         . "/zk9xsp متن — تنظیم اسپانسر\n\n"
         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚠️ این دستورات رو جایی منتشر نکنید.";
}

/* ───────────────── کانال‌ها ───────────────── */

function resolveChannelId(string $target): ?string {
    $target = trim($target);
    if ($target === '') {
        return null;
    }
    if (preg_match('/^-?\d+$/', $target)) {
        return $target;
    }
    if ($target[0] !== '@') {
        $target = '@' . $target;
    }
    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) {
        return null;
    }
    return (string)$chatRes['result']['id'];
}

function handleConnect($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ نام کانال رو بعد از دستور add بنویسید.\n\nمثال:\n/add mynews");
        return;
    }

    $target = trim($arg);
    if ($target[0] !== '@' && !preg_match('/^-?\d+$/', $target)) {
        $target = '@' . $target;
    }

    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) {
        sendPlainText($chatId, "❌ کانال پیدا نشد.\n\nچک کنید:\n"
            . "1. ربات تو کانال ادمین باشه\n"
            . "2. نام کانال درست باشه\n"
            . "3. بعد از add یک فاصله و نام کانال");
        return;
    }

    $chatObj = $chatRes['result'];
    $realChannelId = (string)$chatObj['id'];
    $title = $chatObj['title'] ?? 'بدون نام';
    $username = isset($chatObj['username']) ? '@' . $chatObj['username'] : '';

    $existingOwner = getChannelOwner($realChannelId);
    if ($existingOwner !== null && (string)$existingOwner !== (string)$userId && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ این کانال قبلاً توسط کاربر دیگری ثبت شده.");
        return;
    }

    $botId = getBotId();
    if ($botId !== null) {
        $botMember = apiPost('getChatMember', [
            'chat_id' => $realChannelId,
            'user_id' => $botId,
        ]);
        $botStatus = $botMember['result']['status'] ?? '';
        if (!in_array($botStatus, ['administrator', 'creator'], true)) {
            sendPlainText($chatId, "⚠️ اول ربات رو تو کانال ادمین کنید، بعد دوباره add رو بزنید.");
            return;
        }
    }

    $memberRes = apiPost('getChatMember', [
        'chat_id' => $realChannelId,
        'user_id' => $userId,
    ]);

    if ($memberRes === null || empty($memberRes['ok'])) {
        sendPlainText($chatId, "❌ ربات دسترسی بررسی اعضا نداره. لطفاً ادمینش کنید.");
        return;
    }

    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['creator', 'administrator'], true) && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ شما تو این کانال ادمین یا سازنده نیستید.");
        return;
    }

    setChannelOwner($realChannelId, $userId, $title, $username);
    sendPlainText($chatId, "✅ کانال «" . $title . "» متصل شد.\n\nاز الان گزارش خروج اعضا براتون میاد.");
}

function handleMyList($chatId, $userId): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) {
        sendPlainText($chatId, "📭 هنوز کانالی ثبت نکردید.\n\n"
            . "برای اتصال:\n"
            . "1. ربات رو ادمین کنید\n"
            . "2. /add نام‌کانال رو بفرستید");
        return;
    }

    $msg = "📋 کانال‌های شما:\n\n";
    foreach ($channels as $chId => $info) {
        $title = $info['title'] ?: 'بدون نام';
        $un = $info['username'] ? " (" . $info['username'] . ")" : '';
        $msg .= "🔹 " . $title . $un . "\n"
              . "آیدی: " . $chId . "\n"
              . "ثبت: " . $info['updated_at'] . "\n\n";
    }
    sendPlainText($chatId, $msg);
}

function handleRemoveChannel($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ نام کانال رو بعد از remove بنویسید.\n\nمثال:\n/remove mynews");
        return;
    }

    $realId = resolveChannelId($arg);
    if ($realId === null) {
        sendPlainText($chatId, "❌ کانال پیدا نشد. از آیدی عددی استفاده کنید.");
        return;
    }

    $owner = getChannelOwner($realId);
    if ($owner === null) {
        sendPlainText($chatId, "❌ این کانال تو ربات ثبت نشده.");
        return;
    }

    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ شما مالک این کانال نیستید.");
        return;
    }

    removeChannel($realId);
    sendPlainText($chatId, "🗑 کانال حذف شد. دیگه گزارشی ارسال نمی‌شه.");
}

function handleHistory($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ نام کانال رو بعد از history بنویسید.\n\nمثال:\n/history mynews");
        return;
    }

    $realId = resolveChannelId($arg);
    if ($realId === null) {
        sendPlainText($chatId, "❌ کانال پیدا نشد.");
        return;
    }

    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
        sendPlainText($chatId, "⛔ دسترسی ندارید یا کانال ثبت نشده.");
        return;
    }

    $history = getLeaveHistory($realId);
    if (empty($history)) {
        sendPlainText($chatId, "📭 هنوز خروجی ثبت نشده.");
        return;
    }

    $msg = "📊 آخرین خروج‌ها از «" . $realId . "»:\n\n";
    $slice = array_slice($history, 0, 15);
    foreach ($slice as $idx => $item) {
        $num = $idx + 1;
        $name = $item['name'] ?? 'نامشخص';
        $uid = $item['user_id'] ?? '';
        $un = !empty($item['username']) ? "@" . $item['username'] : 'بدون یوزرنیم';
        $time = $item['time'] ?? '';
        $msg .= $num . ". " . $name . " (" . $uid . " | " . $un . ")\n"
              . "🕒 " . $time . "\n\n";
    }
    sendPlainText($chatId, $msg);
}

/* ───────────────── Callback ───────────────── */

function handleCallbackQuery(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $userId = $cb['from']['id'] ?? null;

    if (!$chatId || !$userId) {
        answerCallbackQuery($cbId);
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

    // سازگاری با منوهای قدیمی (Inline)
    if ($data !== 'menu_bug') {
        $check = checkUserForceJoin($userId, false);
        if (!$check['ok']) {
            answerCallbackQuery($cbId, '⚠️ اول تو کانال‌های معرفی‌شده عضو شوید.', true);
            sendForceJoinPrompt($chatId, $check['missing']);
            return;
        }
    }

    answerCallbackQuery($cbId);

    switch ($data) {
        case 'menu_connect':
            sendPlainText($chatId, "➕ اتصال کانال\n\n"
                . "1. ربات رو تو کانال ادمین کنید\n"
                . "2. این رو بفرستید:\n/add mynews");
            break;
        case 'menu_mylist':
            handleMyList($chatId, $userId);
            break;
        case 'menu_help':
            sendPlainText($chatId, getHelpText());
            break;
        case 'menu_sponsor':
            sendPlainText($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText());
            break;
        case 'menu_bug':
            sendPlainText($chatId, "🐞 گزارش باگ\n\n"
                . "مشکل رو این‌طوری بفرستید:\n\n"
                . "/bug متن مشکل\n\n"
                . "مستقیم برای مدیر ربات میاد.");
            break;
        // ⛔ case 'menu_donate' حذف شد (فاز ۱)
    }
}

/* ───────────────── Router متن ───────────────── */

function handleTextMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? null;
    $chatType = $message['chat']['type'] ?? 'private';
    $userId = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? '');

    if (!$chatId || !$userId || $text === '') {
        return;
    }

    if ($chatType !== 'private' && strpos($text, '/') !== 0) {
        return;
    }

    /* ── دکمه‌های Reply Keyboard (شامل سازگاری با لیبل‌های قدیمی) ── */
    $replyButtonMap = [
        '➕ اتصال کانال'        => 'connect',
        '➕ اتصال کانال جدید'    => 'connect',
        '📋 کانال‌های من'       => 'mylist',
        '📖 راهنما'            => 'help',
        '📖 راهنمای استفاده'    => 'help',
        '🌟 اسپانسر'           => 'sponsor',
        '🌟 اسپانسر ما'         => 'sponsor',
        '🐞 گزارش باگ'         => 'bug',
    ];

    if (isset($replyButtonMap[$text])) {
        $forceCheck = checkUserForceJoin($userId, false);
        if (!$forceCheck['ok']) {
            sendForceJoinPrompt($chatId, $forceCheck['missing']);
            return;
        }

        switch ($replyButtonMap[$text]) {
            case 'connect':
                sendPlainText($chatId, "➕ اتصال کانال\n\n"
                    . "1. ربات رو تو کانال ادمین کنید\n"
                    . "2. این رو بفرستید:\n/add mynews");
                break;
            case 'mylist':
                handleMyList($chatId, $userId);
                break;
            case 'help':
                sendPlainText($chatId, getHelpText());
                break;
            case 'sponsor':
                sendPlainText($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText());
                break;
            case 'bug':
                sendPlainText($chatId, "🐞 گزارش باگ\n\n"
                    . "مشکل رو این‌طوری بفرستید:\n\n"
                    . "/bug متن مشکل\n\n"
                    . "مستقیم برای مدیر ربات میاد.");
                break;
        }
        return;
    }

    /* ── دستورات متنی ── */
    [$cmd, $arg] = parseCommand($text);
    if ($cmd === null) {
        return;
    }

    $resetForce = ($cmd === 'start');
    $skipForceCheck = in_array($cmd, ['help', 'bug', 'status'], true);
    if (!$skipForceCheck) {
        $forceCheck = checkUserForceJoin($userId, $resetForce);
        if (!$forceCheck['ok']) {
            sendForceJoinPrompt($chatId, $forceCheck['missing']);
            return;
        }
    }

    switch ($cmd) {

        /* ════════ عمومی ════════ */

        case 'start':
            sendStartMenu($chatId);
            break;

        case 'help':
            sendPlainText($chatId, getHelpText());
            break;

        case 'status':
            $msg = "🤖 ربات آنفالویاب: آنلاین\n"
                 . "🕒 " . date('Y-m-d H:i:s');
            if (isBotOwner($userId)) {
                $dbOk = (REDIS_URL !== '' && REDIS_TOKEN !== '') ? '✅ متصل' : '❌ قطع';
                $msg .= "\n🗄 Redis: " . $dbOk
                      . "\n📦 استخر عضویت اجباری: " . count(getForceChannelsPool());
            }
            sendPlainText($chatId, $msg);
            break;

        /* ════════ مدیریت کانال ════════ */

        case 'add':
        case 'connect':
            handleConnect($chatId, $userId, (string)$arg);
            break;

        case 'my':
            handleMyList($chatId, $userId);
            break;

        case 'remove':
            handleRemoveChannel($chatId, $userId, (string)$arg);
            break;

        case 'history':
            handleHistory($chatId, $userId, (string)$arg);
            break;

        /* ════════ گزارش باگ ════════ */

        case 'bug':
            if ($arg === '') {
                sendPlainText($chatId, "❌ متن مشکل رو بعد از bug بنویسید.\n\nمثال:\n/bug ربات گزارش نمی‌ده");
                return;
            }
            if (BOT_OWNER_ID === '') {
                sendPlainText($chatId, "❌ دریافت گزارش فعلاً غیرفعاله.");
                return;
            }
            $senderName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')) ?: 'بدون نام';
            $senderUsername = $message['from']['username'] ?? '';
            $bugText = "🐞 گزارش باگ\n\n"
                . "👤 " . $senderName . "\n"
                . "🔖 " . ($senderUsername !== '' ? '@' . $senderUsername : 'ندارد') . "\n"
                . "🔢 " . $userId . "\n"
                . "━━━━━━━━━━━━━━\n"
                . $arg;
            sendPlainText(BOT_OWNER_ID, $bugText);
            sendPlainText($chatId, "✅ گزارش ارسال شد. ممنون از همکاریتون!");
            break;

        /* ════════ پنل مدیریت (فقط zk9x*) ════════ */

        case 'zk9xad':
            if (!isBotOwner($userId)) return;
            sendPlainText($chatId, getAdminHelpText());
            break;

        case 'zk9xch':
            if (!isBotOwner($userId)) return;
            $all = getAllChannelsMap();
            if (empty($all)) {
                sendPlainText($chatId, "📭 هیچ کانالی ثبت نشده.");
                return;
            }
            $msg = "👑 کل کانال‌ها (" . count($all) . "):\n\n";
            foreach ($all as $chId => $info) {
                $title = $info['title'] ?: 'بدون نام';
                $un = $info['username'] ? " (" . $info['username'] . ")" : '';
                $owner = $info['owner_id'] ?? 'نامشخص';
                $msg .= "🔹 " . $title . $un . "\n"
                      . "آیدی: " . $chId . "\n"
                      . "مالک: " . $owner . "\n\n";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'zk9xfa':
            if (!isBotOwner($userId)) return;
            if ($arg === '') {
                sendPlainText($chatId, "❌ مثال: /zk9xfa mychannel");
                return;
            }
            $check = checkBotIsAdminInChannel($arg);
            if (!$check['ok']) {
                sendPlainText($chatId, "❌ " . $check['error'] . "\n\nاول ربات رو ادمین کنید.");
                return;
            }
            if (!addForceChannelToPool($arg)) {
                sendPlainText($chatId, "❌ ذخیره نشد. دوباره تلاش کنید.");
                return;
            }
            $displayTitle = $check['title'] ?: $arg;
            sendPlainText($chatId, "✅ کانال «" . $displayTitle . "» به استخر اضافه شد.");
            break;

        case 'zk9xfd':
            if (!isBotOwner($userId)) return;
            if ($arg === '') {
                sendPlainText($chatId, "❌ مثال: /zk9xfd mychannel");
                return;
            }
            if (removeForceChannelFromPool($arg)) {
                sendPlainText($chatId, "🗑 کانال از استخر حذف شد.");
            } else {
                sendPlainText($chatId, "❌ این کانال تو استخر پیدا نشد.");
            }
            break;

        case 'zk9xfl':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            if (empty($pool)) {
                sendPlainText($chatId, "📭 استخر خالیه.");
                return;
            }
            $msg = "📢 استخر عضویت اجباری (" . count($pool) . "):\n\n";
            foreach ($pool as $idx => $ch) {
                $num = $idx + 1;
                $msg .= $num . ". " . $ch . "\n";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'zk9xst':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            $msg = "🔍 دیباگ عضویت اجباری\n"
                 . "━━━━━━━━━━━━━━━━━━\n\n"
                 . "📦 استخر: " . count($pool) . " کانال\n\n";

            if (empty($pool)) {
                $msg .= "⚠️ استخر خالیه. هیچ کاربری عضویت اجباری نمی‌بینه.\n"
                      . "با /zk9xfa کانال اضافه کنید.\n";
            } else {
                foreach ($pool as $idx => $ch) {
                    $num = $idx + 1;
                    $adminCheck = checkBotIsAdminInChannel($ch);
                    $msg .= $num . ". " . $ch . "\n";
                    if ($adminCheck['ok']) {
                        $msg .= "   ✅ ربات ادمین است\n"
                              . "   نام: " . ($adminCheck['title'] ?: 'بدون نام') . "\n"
                              . "   آیدی: " . $adminCheck['real_id'] . "\n";
                    } else {
                        $msg .= "   ❌ " . $adminCheck['error'] . "\n"
                              . "   این کانال عملاً غیرفعال است.\n";
                    }
                    $msg .= "\n";
                }
            }

            $targetUserId = null;
            if ($arg !== '') {
                $targetUserId = preg_replace('/[^0-9-]/', '', $arg);
                if ($targetUserId === '') {
                    $targetUserId = null;
                }
            }

            if ($targetUserId !== null) {
                $msg .= "━━━━━━━━━━━━━━━━━━\n"
                      . "👤 تست برای کاربر: " . $targetUserId . "\n\n";

                if (isBotOwner($targetUserId)) {
                    $msg .= "⚠️ این کاربر مالک ربات است و معاف است.\n";
                } else {
                    $assignmentKey = "user_force_channels:" . $targetUserId;
                    $assignedRaw = redisGet($assignmentKey);
                    $assigned = $assignedRaw ? json_decode($assignedRaw, true) : [];

                    if (empty($assigned) || !is_array($assigned)) {
                        $msg .= "هیچ کانالی به این کاربر اختصاص نیافته.\n"
                              . "با زدن /start در حساب کاربر، اختصاص انجام می‌شه.\n";
                    } else {
                        $msg .= "کانال‌های اختصاص‌یافته:\n";
                        $missing = [];
                        foreach ($assigned as $ch) {
                            $memberResult = checkSingleChannelMembership($ch, $targetUserId);
                            if ($memberResult === true) {
                                $msg .= "  " . $ch . " : ✅ عضو است\n";
                            } elseif ($memberResult === false) {
                                $msg .= "  " . $ch . " : ❌ عضو نیست\n";
                                $missing[] = $ch;
                            } else {
                                $msg .= "  " . $ch . " : ⚠️ خطا در بررسی\n";
                            }
                        }
                        $msg .= "\n";
                        if (empty($missing)) {
                            $msg .= "نتیجه: کاربر نباید عضویت اجباری ببینه.\n";
                        } else {
                            $msg .= "نتیجه: کاربر باید عضویت اجباری ببینه (" . count($missing) . " کانال کم).\n";
                        }
                    }
                }
            } else {
                $msg .= "━━━━━━━━━━━━━━━━━━\n"
                      . "برای تست کاربر خاص، آیدی عددی رو بعد از دستور بنویسید:\n\n"
                      . "/zk9xst 123456789";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'zk9xsp':
            if (!isBotOwner($userId)) return;
            if ($arg === '') {
                sendPlainText($chatId, "❌ متن اسپانسر رو بعد از دستور بنویسید.\n\nمثال:\n/zk9xsp کانال ما: mychannel");
                return;
            }
            setSponsorText($arg);
            sendPlainText($chatId, "✅ متن اسپانسر بروزرسانی شد.");
            break;

        // ⛔ case 'zk9xdn' حذف شد (فاز ۱)
    }
}

/* ───────────────── رویداد خروج عضو ───────────────── */

function processMemberLeft(array $chat, array $user, ?array $actor = null): void {
    if (!empty($user['is_bot'])) {
        return;
    }

    $channelId = $chat['id'] ?? null;
    if (!$channelId) {
        return;
    }

    $channelIdStr = (string)$channelId;
    $ownerId = getChannelOwner($channelIdStr);
    if (!$ownerId) {
        return;
    }

    $channelTitle = $chat['title'] ?? 'کانال بدون نام';
    $channelUsername = isset($chat['username']) ? '@' . $chat['username'] : '';

    $userId = $user['id'] ?? 'نامشخص';
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $fullName = trim($firstName . ' ' . $lastName) ?: 'بدون نام';
    $username = $user['username'] ?? '';

    $timeStr = date('Y/m/d - H:i:s');

    saveLeaveHistory($channelIdStr, [
        'user_id' => $userId,
        'name' => $fullName,
        'username' => $username,
        'time' => $timeStr,
    ]);

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