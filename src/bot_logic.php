<?php
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
        return ['ok' => false, 'error' => 'کانال پیدا نشد یا ربات به آن دسترسی ندارد.'];
    }
    $realId = (string)$chatRes['result']['id'];
    $title = $chatRes['result']['title'] ?? 'بدون نام';
    $username = isset($chatRes['result']['username']) ? '@' . $chatRes['result']['username'] : '';

    $botId = getBotId();
    if ($botId === null) {
        return ['ok' => false, 'error' => 'شناسایی هویت ربات ممکن نشد.'];
    }
    $memberRes = apiPost('getChatMember', [
        'chat_id' => $realId,
        'user_id' => $botId,
    ]);
    if ($memberRes === null || empty($memberRes['ok'])) {
        return ['ok' => false, 'error' => 'بررسی وضعیت ربات در کانال ممکن نشد.'];
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
         . "برای استفاده از امکانات ربات آنفالویاب، ابتدا در کانال‌های زیر عضو شوید و سپس دکمه‌ی «عضو شدم» را بزنید.\n\n";
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

function sendStartMenu($chatId): void {
    $text = "👋 به ربات آنفالویاب خوش آمدید!\n\n"
          . "آنفالویاب به شما کمک می‌کند به صورت لحظه‌ای بفهمید چه اعضایی از کانال شما خارج می‌شوند.\n\n"
          . "📌 از دکمه‌های زیر استفاده کنید:";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ اتصال کانال جدید', 'callback_data' => 'menu_connect'],
                ['text' => '📋 کانال‌های من', 'callback_data' => 'menu_mylist']
            ],
            [
                ['text' => '📖 راهنمای استفاده', 'callback_data' => 'menu_help']
            ],
            [
                ['text' => '💝 حمایت مالی', 'callback_data' => 'menu_donate'],
                ['text' => '🌟 اسپانسر ما', 'callback_data' => 'menu_sponsor']
            ],
            [
                ['text' => '🐞 گزارش باگ یا مشکل', 'callback_data' => 'menu_bug']
            ]
        ]
    ];

    sendMessageWithKeyboard($chatId, $text, $keyboard);
}

function getHelpText(): string {
    return "📖 راهنمای استفاده از ربات آنفالویاب\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "این ربات به شما کمک می‌کند بفهمید چه اعضایی از کانال شما خارج می‌شوند.\n\n"

         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚙️ مرحله ۱: ادمین کردن ربات\n\n"
         . "ابتدا ربات آنفالویاب را در کانال خود ادمین کنید با دسترسی مدیریت اعضا.\n"
         . "بدون این دسترسی، ربات نمی‌تواند خروج اعضا را تشخیص دهد.\n\n"

         . "━━━━━━━━━━━━━━━━━━\n"
         . "🔗 مرحله ۲: اتصال کانال به ربات\n\n"
         . "برای اتصال کانال، دستور زیر را در همین چت ارسال کنید.\n"
         . "بلافاصله بعد از کلمه add یک فاصله بگذارید و سپس نام یا آیدی کانال خود را بنویسید.\n\n"
         . "ساختار دستور:\n\n"
         . "/add نام یا آیدی کانال شما\n\n"
         . "نمونه‌های واقعی و درست:\n\n"
         . "اگر آیدی کانال شما mynews است، دقیقاً این را بفرستید:\n\n"
         . "/add mynews\n\n"
         . "اگر آیدی کانال شما با علامت @ شروع می‌شود، این را بفرستید:\n\n"
         . "/add @mynews\n\n"
         . "اگر آیدی عددی کانال شما منفی است، همان عدد را وارد کنید:\n\n"
         . "/add -1001234567890\n\n"

         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚠️ نکته بسیار مهم\n\n"
         . "دستور نمونه را عیناً و بدون تغییر نفرستید. بعد از کلمه add باید نام واقعی کانال خود را بنویسید.\n\n"
         . "اشتباه (این را نفرستید):\n\n"
         . "/add نام یا آیدی کانال شما\n\n"
         . "درست (اگر آیدی کانال شما mynews است):\n\n"
         . "/add mynews\n\n"

         . "━━━━━━━━━━━━━━━━━━\n"
         . "🛠 دستورات کاربردی\n\n"
         . "مشاهده لیست کانال‌های متصل شما:\n\n"
         . "/my\n\n"
         . "بررسی وضعیت ربات:\n\n"
         . "/status\n\n"
         . "بازگشت به منوی اصلی:\n\n"
         . "/start\n\n"
         . "مشاهده تاریخچه آخرین خروج‌ها از یک کانال:\n\n"
         . "/history mynews\n\n"
         . "قطع اتصال کانال از ربات:\n\n"
         . "/remove mynews\n\n"
         . "گزارش باگ یا مشکل:\n\n"
         . "/bug متن مشکل خود را اینجا بنویسید";
}

function getAdminHelpText(): string {
    return "👑 پنل مدیریت ربات آنفالویاب (مخصوص مالک)\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "برای امنیت بیشتر، این پنل را فقط در چت خصوصی با ربات باز کنید.\n\n"

         . "📊 دستورات مشاهده\n\n"
         . "لیست کامل تمام کانال‌های ثبت‌شده در ربات:\n\n"
         . "/zk9xch\n\n"
         . "نمایش لیست کانال‌های موجود در استخر عضویت اجباری:\n\n"
         . "/zk9xfl\n\n"
         . "دیباگ سیستم عضویت اجباری (بدون آیدی برای وضعیت کانال‌ها، با آیدی برای تست کاربر خاص):\n\n"
         . "/zk9xst\n\n"
         . "/zk9xst 123456789\n\n"

         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚙️ دستورات تنظیمی\n\n"
         . "افزودن کانال به استخر عضویت اجباری:\n\n"
         . "/zk9xfa mychannel\n\n"
         . "حذف کانال از استخر عضویت اجباری:\n\n"
         . "/zk9xfd mychannel\n\n"
         . "تغییر متن دکمه‌ی اسپانسر:\n\n"
         . "/zk9xsp متن جدید اسپانسر\n\n"
         . "تغییر متن دکمه‌ی حمایت مالی:\n\n"
         . "/zk9xdn متن جدید حمایت مالی\n\n"

         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚠️ توصیه\n\n"
         . "این دستورات را در اختیار هیچ‌کس قرار ندهید.";
}

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
        sendPlainText($chatId, "❌ بعد از دستور add، نام یا آیدی کانال خود را وارد کنید.\n\n"
            . "نمونه‌ی درست (اگر آیدی کانال شما mynews است):\n\n"
            . "/add mynews");
        return;
    }

    $target = trim($arg);
    if ($target[0] !== '@' && !preg_match('/^-?\d+$/', $target)) {
        $target = '@' . $target;
    }

    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) {
        sendPlainText($chatId, "❌ کانال پیدا نشد.\n\n"
            . "مطمئن شوید:\n"
            . "۱. ربات را در کانال ادمین کرده‌اید.\n"
            . "۲. نام یا آیدی کانال را درست وارد کرده‌اید.\n"
            . "۳. بعد از add یک فاصله و سپس نام کانال را نوشته‌اید.");
        return;
    }

    $chatObj = $chatRes['result'];
    $realChannelId = (string)$chatObj['id'];
    $title = $chatObj['title'] ?? 'بدون نام';
    $username = isset($chatObj['username']) ? '@' . $chatObj['username'] : '';

    $existingOwner = getChannelOwner($realChannelId);
    if ($existingOwner !== null && (string)$existingOwner !== (string)$userId && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ این کانال قبلاً توسط کاربر دیگری در ربات ثبت شده است.");
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
            sendPlainText($chatId, "⚠️ ابتدا ربات آنفالویاب را در کانال خود ادمین کنید تا گزارش‌ها ارسال شوند.\n"
                . "سپس دوباره دستور add را امتحان کنید.");
            return;
        }
    }

    $memberRes = apiPost('getChatMember', [
        'chat_id' => $realChannelId,
        'user_id' => $userId,
    ]);

    if ($memberRes === null || empty($memberRes['ok'])) {
        sendPlainText($chatId, "❌ ربات دسترسی بررسی اعضا را ندارد. لطفاً ربات را در کانال ادمین کنید.");
        return;
    }

    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['creator', 'administrator'], true) && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ شما در این کانال ادمین یا سازنده نیستید.");
        return;
    }

    setChannelOwner($realChannelId, $userId, $title, $username);
    sendPlainText($chatId, "✅ کانال " . $title . " با آیدی " . $realChannelId . " با موفقیت به حساب شما متصل شد.\n\n"
        . "از این پس گزارش خروج اعضا برای شما ارسال می‌شود.");
}

function handleMyList($chatId, $userId): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) {
        sendPlainText($chatId, "📭 شما هنوز هیچ کانالی ثبت نکرده‌اید.\n\n"
            . "برای اتصال کانال، ابتدا ربات را ادمین کنید و سپس این دستور را بفرستید:\n\n"
            . "/add نام کانال شما");
        return;
    }

    $msg = "📋 لیست کانال‌های متصل شما:\n\n";
    foreach ($channels as $chId => $info) {
        $title = $info['title'] ?: 'بدون نام';
        $un = $info['username'] ? " (" . $info['username'] . ")" : '';
        $msg .= "🔹 " . $title . $un . "\n"
              . "آیدی: " . $chId . "\n"
              . "تاریخ ثبت: " . $info['updated_at'] . "\n\n";
    }
    sendPlainText($chatId, $msg);
}

function handleRemoveChannel($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ بعد از دستور remove، نام یا آیدی کانال را وارد کنید.\n\n"
            . "نمونه‌ی درست:\n\n"
            . "/remove mynews");
        return;
    }

    $realId = resolveChannelId($arg);
    if ($realId === null) {
        sendPlainText($chatId, "❌ کانال پیدا نشد. لطفاً از آیدی عددی کانال استفاده کنید.");
        return;
    }

    $owner = getChannelOwner($realId);
    if ($owner === null) {
        sendPlainText($chatId, "❌ این کانال در ربات ثبت نشده است.");
        return;
    }

    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ شما مالک ثبت‌کننده‌ی این کانال نیستید.");
        return;
    }

    removeChannel($realId);
    sendPlainText($chatId, "🗑 کانال با آیدی " . $realId . " با موفقیت حذف شد و دیگر گزارش‌های آن ارسال نمی‌شود.");
}

function handleHistory($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ بعد از دستور history، نام یا آیدی کانال را وارد کنید.\n\n"
            . "نمونه‌ی درست:\n\n"
            . "/history mynews");
        return;
    }

    $realId = resolveChannelId($arg);
    if ($realId === null) {
        sendPlainText($chatId, "❌ کانال پیدا نشد. لطفاً از آیدی عددی کانال استفاده کنید.");
        return;
    }

    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
        sendPlainText($chatId, "⛔ شما به تاریخچه‌ی این کانال دسترسی ندارید یا کانال در ربات ثبت نشده است.");
        return;
    }

    $history = getLeaveHistory($realId);
    if (empty($history)) {
        sendPlainText($chatId, "📭 هنوز هیچ خروجی برای این کانال ثبت نشده است.");
        return;
    }

    $msg = "📊 آخرین خروجی‌های کانال با آیدی " . $realId . ":\n\n";
    $slice = array_slice($history, 0, 15);
    foreach ($slice as $idx => $item) {
        $num = $idx + 1;
        $name = $item['name'] ?? 'نامشخص';
        $uid = $item['user_id'] ?? '';
        $un = !empty($item['username']) ? "@" . $item['username'] : 'بدون یوزرنیم';
        $time = $item['time'] ?? '';
        $msg .= $num . ". " . $name . " (آیدی: " . $uid . " | " . $un . ")\n"
              . "زمان: " . $time . "\n\n";
    }
    sendPlainText($chatId, $msg);
}

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
            answerCallbackQuery($cbId, '✅ عضویت شما تایید شد.', false);
            sendStartMenu($chatId);
        } else {
            answerCallbackQuery($cbId, '❌ هنوز در همه‌ی کانال‌ها عضو نشده‌اید.', true);
        }
        return;
    }

    if ($data !== 'menu_bug') {
        $check = checkUserForceJoin($userId, false);
        if (!$check['ok']) {
            answerCallbackQuery($cbId, '⚠️ ابتدا در کانال‌های معرفی شده عضو شوید.', true);
            sendForceJoinPrompt($chatId, $check['missing']);
            return;
        }
    }

    answerCallbackQuery($cbId);

    switch ($data) {
        case 'menu_connect':
            sendPlainText($chatId, "➕ برای اتصال کانال، ابتدا ربات آنفالویاب را در کانال خود ادمین کنید.\n\n"
                . "سپس دستور زیر را بفرستید و بعد از add، نام واقعی کانال خود را بنویسید:\n\n"
                . "/add mynews\n\n"
                . "در این نمونه، mynews نام کانال است. شما باید نام کانال خود را جایگزین کنید.");
            break;
        case 'menu_mylist':
            handleMyList($chatId, $userId);
            break;
        case 'menu_help':
            sendPlainText($chatId, getHelpText());
            break;
        case 'menu_donate':
            sendPlainText($chatId, "💝 حمایت مالی از ربات آنفالویاب:\n\n" . getDonateText());
            break;
        case 'menu_sponsor':
            sendPlainText($chatId, "🌟 اسپانسر ربات آنفالویاب:\n\n" . getSponsorText());
            break;
        case 'menu_bug':
            sendPlainText($chatId, "🐞 گزارش باگ یا مشکل\n\n"
                . "اگر در استفاده از ربات به مشکلی برخوردید یا باگی دیدید، لطفاً آن را برای ما ارسال کنید.\n\n"
                . "روش ارسال:\n"
                . "دستور bug را نوشته، سپس یک فاصله و بعد متن مشکل خود را بنویسید.\n\n"
                . "نمونه:\n\n"
                . "/bug ربات گزارش خروج اعضا را ارسال نمی‌کند\n\n"
                . "پیام شما مستقیماً برای مدیریت ربات ارسال می‌شود.");
            break;
    }
}

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
        case 'start':
            sendStartMenu($chatId);
            break;

        case 'help':
            sendPlainText($chatId, getHelpText());
            break;

        case 'status':
            $msg = "🤖 وضعیت ربات آنفالویاب: آنلاین\n"
                 . "🕒 زمان سرور: " . date('Y-m-d H:i:s');
            if (isBotOwner($userId)) {
                $dbOk = (REDIS_URL !== '' && REDIS_TOKEN !== '') ? '✅ متصل' : '❌ قطع';
                $msg .= "\n🗄 دیتابیس Redis: " . $dbOk;
                $poolSize = count(getForceChannelsPool());
                $msg .= "\n📦 تعداد کانال‌های استخر عضویت اجباری: " . $poolSize;
            }
            sendPlainText($chatId, $msg);
            break;

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

        case 'bug':
            if ($arg === '') {
                sendPlainText($chatId, "❌ متن مشکل خود را بعد از دستور bug بنویسید.\n\n"
                    . "نمونه:\n\n"
                    . "/bug ربات گزارش خروج اعضا را ارسال نمی‌کند");
                return;
            }
            if (BOT_OWNER_ID === '') {
                sendPlainText($chatId, "❌ در حال حاضر امکان دریافت گزارش وجود ندارد.");
                return;
            }
            $senderName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')) ?: 'بدون نام';
            $senderUsername = $message['from']['username'] ?? '';
            $senderUsernameLine = $senderUsername !== '' ? '@' . $senderUsername : 'ندارد';
            $bugText = "🐞 گزارش باگ یا مشکل جدید\n\n"
                . "👤 فرستنده: " . $senderName . "\n"
                . "🔖 یوزرنیم: " . $senderUsernameLine . "\n"
                . "🔢 آیدی کاربر: " . $userId . "\n"
                . "━━━━━━━━━━━━━━\n"
                . "📝 متن گزارش:\n" . $arg;
            sendPlainText(BOT_OWNER_ID, $bugText);
            sendPlainText($chatId, "✅ گزارش شما با موفقیت برای مدیریت ربات ارسال شد. با تشکر از همکاری شما.");
            break;

        case 'zk9xad':
            if (!isBotOwner($userId)) {
                return;
            }
            sendPlainText($chatId, getAdminHelpText());
            break;

        case 'zk9xch':
            if (!isBotOwner($userId)) {
                return;
            }
            $all = getAllChannelsMap();
            if (empty($all)) {
                sendPlainText($chatId, "📭 هیچ کانالی در ربات ثبت نشده است.");
                return;
            }
            $msg = "👑 لیست کل کانال‌های ثبت‌شده در ربات (" . count($all) . " کانال):\n\n";
            foreach ($all as $chId => $info) {
                $title = $info['title'] ?: 'بدون نام';
                $un = $info['username'] ? " (" . $info['username'] . ")" : '';
                $owner = $info['owner_id'] ?? 'نامشخص';
                $msg .= "🔹 " . $title . $un . "\n"
                      . "آیدی کانال: " . $chId . "\n"
                      . "مالک: " . $owner . "\n\n";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'zk9xfa':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ نمونه: /zk9xfa mychannel");
                return;
            }
            $check = checkBotIsAdminInChannel($arg);
            if (!$check['ok']) {
                sendPlainText($chatId, "❌ " . $check['error'] . "\n\n"
                    . "ابتدا ربات را در کانال ادمین کنید با دسترسی مدیریت اعضا، سپس دوباره تلاش کنید.");
                return;
            }
            if (!addForceChannelToPool($arg)) {
                sendPlainText($chatId, "❌ خطا در ذخیره‌سازی در دیتابیس. لطفاً دوباره تلاش کنید یا لاگ‌ها را بررسی کنید.");
                return;
            }
            $displayTitle = $check['title'] ?: $arg;
            sendPlainText($chatId, "✅ کانال " . $displayTitle . " با موفقیت به استخر عضویت اجباری اضافه شد.");
            break;

        case 'zk9xfd':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ نمونه: /zk9xfd mychannel");
                return;
            }
            if (removeForceChannelFromPool($arg)) {
                sendPlainText($chatId, "🗑 کانال " . $arg . " از استخر عضویت اجباری حذف شد.");
            } else {
                sendPlainText($chatId, "❌ این کانال در استخر عضویت اجباری پیدا نشد.");
            }
            break;

        case 'zk9xfl':
            if (!isBotOwner($userId)) {
                return;
            }
            $pool = getForceChannelsPool();
            if (empty($pool)) {
                sendPlainText($chatId, "📭 استخر عضویت اجباری خالی است.");
                return;
            }
            $msg = "📢 کانال‌های موجود در استخر عضویت اجباری (" . count($pool) . " کانال):\n\n";
            foreach ($pool as $idx => $ch) {
                $num = $idx + 1;
                $msg .= $num . ". " . $ch . "\n";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'zk9xst':
            if (!isBotOwner($userId)) {
                return;
            }
            $pool = getForceChannelsPool();
            $msg = "🔍 دیباگ سیستم عضویت اجباری\n"
                 . "━━━━━━━━━━━━━━━━━━\n\n";
            $msg .= "📦 استخر: " . count($pool) . " کانال\n\n";

            if (empty($pool)) {
                $msg .= "⚠️ استخر خالی است. هیچ کاربری عضویت اجباری نمی‌بیند.\n"
                      . "با دستور /zk9xfa کانال اضافه کنید.\n";
            } else {
                foreach ($pool as $idx => $ch) {
                    $num = $idx + 1;
                    $adminCheck = checkBotIsAdminInChannel($ch);
                    $msg .= $num . ". " . $ch . "\n";
                    if ($adminCheck['ok']) {
                        $msg .= "   ✅ ربات ادمین است\n";
                        $msg .= "   نام: " . ($adminCheck['title'] ?: 'بدون نام') . "\n";
                        $msg .= "   آیدی: " . $adminCheck['real_id'] . "\n";
                    } else {
                        $msg .= "   ❌ " . $adminCheck['error'] . "\n";
                        $msg .= "   این کانال عملاً غیرفعال است.\n";
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
                    $msg .= "⚠️ این کاربر مالک ربات است و همیشه از عضویت اجباری معاف می‌شود.\n";
                } else {
                    $assignmentKey = "user_force_channels:" . $targetUserId;
                    $assignedRaw = redisGet($assignmentKey);
                    $assigned = $assignedRaw ? json_decode($assignedRaw, true) : [];

                    if (empty($assigned) || !is_array($assigned)) {
                        $msg .= "هیچ کانالی به این کاربر اختصاص نیافته.\n"
                              . "با زدن /start در حساب کاربر، اختصاص انجام می‌شود.\n";
                    } else {
                        $msg .= "کانال‌های اختصاص‌یافته:\n";
                        $missing = [];
                        foreach ($assigned as $ch) {
                            $memberResult = checkSingleChannelMembership($ch, $targetUserId);
                            if ($memberResult === true) {
                                $msg .= "  " . $ch . " : ✅ عضو است\n";
                            } elseif ($memberResult === false) {
                                $msg .= "  " . $ch . " : ❌ عضو نیست (باید مسدود شود)\n";
                                $missing[] = $ch;
                            } else {
                                $msg .= "  " . $ch . " : ⚠️ خطا در بررسی\n";
                            }
                        }
                        $msg .= "\n";
                        if (empty($missing)) {
                            $msg .= "نتیجه: این کاربر نباید عضویت اجباری ببیند (در همه عضو است یا خطا).\n";
                        } else {
                            $msg .= "نتیجه: این کاربر باید عضویت اجباری ببیند (" . count($missing) . " کانال کم).\n";
                        }
                    }
                }
            } else {
                $msg .= "━━━━━━━━━━━━━━━━━━\n"
                      . "برای تست یک کاربر خاص، آیدی عددی او را بعد از دستور بنویسید:\n\n"
                      . "/zk9xst 123456789";
            }

            sendPlainText($chatId, $msg);
            break;

        case 'zk9xsp':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ متن اسپانسر را بعد از دستور بنویسید.\n\nنمونه:\n\n/zk9xsp کانال اسپانسر ما: mychannel");
                return;
            }
            setSponsorText($arg);
            sendPlainText($chatId, "✅ متن اسپانسر با موفقیت بروزرسانی شد.");
            break;

        case 'zk9xdn':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ متن حمایت مالی را بعد از دستور بنویسید.\n\nنمونه:\n\n/zk9xdn شماره کارت: 6037xxxxxxxxxxxx");
                return;
            }
            setDonateText($arg);
            sendPlainText($chatId, "✅ متن حمایت مالی با موفقیت بروزرسانی شد.");
            break;
    }
}

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
        $actionType = "حذف شده توسط " . $actorName;
    }

    $report = "📤 گزارش خروج عضو از کانال\n\n"
            . "📢 کانال: " . $channelLine . "\n"
            . "🆔 آیدی کانال: " . $channelIdStr . "\n"
            . "━━━━━━━━━━━━━━\n"
            . "👤 نام کاربر: " . $fullName . "\n"
            . "🔖 یوزرنیم: " . $usernameLine . "\n"
            . "🔢 آیدی عددی: " . $userId . "\n"
            . "📌 نوع رویداد: " . $actionType . "\n"
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