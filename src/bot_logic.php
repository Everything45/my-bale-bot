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

function parseCommand(string $text): array {
    $text = trim($text);
    if (!preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/us', $text, $m)) {
        return [null, null];
    }
    return [strtolower($m[1]), isset($m[2]) ? trim($m[2]) : ''];
}

// ---------------- بررسی عضویت اجباری (اد اجباری) ----------------

function checkSingleChannelMembership(string $channelRef, $userId): bool {
    $res = apiPost('getChatMember', [
        'chat_id' => $channelRef,
        'user_id' => $userId,
    ]);
    if ($res === null || empty($res['ok'])) {
        // اگر ربات در کانال مقصد ادمین نباشد یا کانال یافت نشود، کاربر را مسدود نمی‌کنیم
        return true;
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
        if (!checkSingleChannelMembership($ch, $userId)) {
            $missing[] = $ch;
        }
    }
    return [
        'ok' => empty($missing),
        'missing' => $missing,
    ];
}

function sendForceJoinPrompt($chatId, array $missingChannels): void {
    $msg = "⚠️ *عضویت اجباری*\n\nبرای استفاده از امکانات ربات، ابتدا در کانال‌های زیر عضو شوید و سپس دکمه «✅ عضو شدم» را بزنید:\n\n";
    $inlineKeyboard = [];

    foreach ($missingChannels as $index => $ch) {
        $num = $index + 1;
        $cleanUser = ltrim($ch, '@');
        $msg .= "🔹 کانال {$num}: {$ch}\n";
        if (!preg_match('/^-?\d+$/', $ch)) {
            $inlineKeyboard[] = [
                ['text' => "📢 ورود به کانال {$num} ({$ch})", 'url' => "https://ble.ir/{$cleanUser}"]
            ];
        }
    }

    $inlineKeyboard[] = [
        ['text' => '✅ عضو شدم (بررسی عضویت)', 'callback_data' => 'check_force_join']
    ];

    sendMessageWithKeyboard($chatId, $msg, ['inline_keyboard' => $inlineKeyboard]);
}

// ---------------- منوها و راهنماها ----------------

function sendStartMenu($chatId): void {
    $text = "👋 *به ربات ردیاب خروج اعضای کانال خوش آمدید!*\n\n"
          . "با استفاده از این ربات می‌توانید به صورت لحظه‌ای متوجه شوید چه کسانی از کانال شما خارج می‌شوند.\n\n"
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
            ]
        ]
    ];

    sendMessageWithKeyboard($chatId, $text, $keyboard);
}

function getHelpText(): string {
    return "📖 *راهنمای استفاده از ربات*\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "1️⃣ ابتدا ربات را در کانال خود *ادمین* کنید.\n\n"
         . "2️⃣ دستور زیر را لمس کنید تا کپی شود، سپس به جای `@username` آیدی کانال خود را قرار داده و ارسال کنید:\n"
         . "`/connect @username`\n\n"
         . "━━━━━━━━━━━━━━━━━━\n"
         . "🛠 *دستورات مستقیم (روی دستور کلیک کنید تا ارسال شود):*\n\n"
         . "🔹 مشاهده لیست کانال‌های ثبت‌شده شما:\n"
         . "/my\n\n"
         . "🔹 بررسی وضعیت آنلاین بودن ربات و دیتابیس:\n"
         . "/status\n\n"
         . "🔹 بازگشت به منوی اصلی:\n"
         . "/start\n\n"
         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚙️ *دستورات کانال (روی دستور بزنید تا کپی شود):*\n\n"
         . "🔸 مشاهده تاریخچه آخرین خروجی‌های کانال:\n"
         . "`/history @username`\n\n"
         . "🔸 قطع اتصال و حذف کانال از ربات:\n"
         . "`/remove @username`";
}

function getAdminHelpText(): string {
    return "👑 *پنل مدیریت کل ربات (مخصوص مالک)*\n"
         . "━━━━━━━━━━━━━━━━━━\n\n"
         . "📊 *دستورات клиکی (برای اجرای مستقیم روی دستور بزنید):*\n\n"
         . "🔹 آمار کلی ربات و لیست تمام کانال‌ها:\n"
         . "/stats\n\n"
         . "🔹 مشاهده لیست کانال‌های عضویت اجباری:\n"
         . "/forcelist\n\n"
         . "━━━━━━━━━━━━━━━━━━\n"
         . "⚙️ *دستورات تنظیمی (روی دستور بزنید تا کپی شود):*\n\n"
         . "🔸 افزودن کانال به لیست عضویت اجباری:\n"
         . "`/addforce @channel`\n\n"
         . "🔸 حذف کانال از لیست عضویت اجباری:\n"
         . "`/delforce @channel`\n\n"
         . "🔸 تغییر متن دکمه اسپانسر:\n"
         . "`/setsponsor متن جدید`\n\n"
         . "🔸 تغییر متن دکمه حمایت مالی:\n"
         . "`/setdonate متن جدید`";
}

// ---------------- هندلرهای دستورات ----------------

function handleConnect($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ لطفاً یوزرنیم یا آیدی کانال را وارد کنید.\nمثال:\n`/connect @mychannel`");
        return;
    }

    $target = trim($arg);
    if ($target[0] !== '@' && !preg_match('/^-?\d+$/', $target)) {
        $target = '@' . $target;
    }

    $chatRes = apiPost('getChat', ['chat_id' => $target]);
    if ($chatRes === null || empty($chatRes['ok'])) {
        sendPlainText($chatId, "❌ کانال پیدا نشد! مطمئن شوید ربات را در کانال ادمین کرده‌اید و یوزرنیم/آیدی را درست وارد کرده‌اید.");
        return;
    }

    $chatObj = $chatRes['result'];
    $realChannelId = $chatObj['id'];
    $title = $chatObj['title'] ?? 'بدون نام';
    $username = isset($chatObj['username']) ? '@' . $chatObj['username'] : '';

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
        sendPlainText($chatId, "⛔ شما در این کانال ادمین یا سازنده نیستید!");
        return;
    }

    setChannelOwner($realChannelId, $userId, $title, $username);
    sendPlainText($chatId, "✅ کانال *{$title}* (`{$realChannelId}`) با موفقیت به حساب شما متصل شد!\nاز این پس گزارش خروج اعضا برای شما ارسال خواهد شد.");
}

function handleMyList($chatId, $userId): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) {
        sendPlainText($chatId, "📭 شما هنوز هیچ کانالی ثبت نکرده‌اید.\nبا دستور `/connect @username` کانال خود را متصل کنید.");
        return;
    }

    $msg = "📋 *لیست کانال‌های متصل شما:*\n\n";
    foreach ($channels as $chId => $info) {
        $title = $info['title'] ?: 'بدون نام';
        $un = $info['username'] ? " ({$info['username']})" : '';
        $msg .= "🔹 *{$title}*{$un}\n🆔 آیدی: `{$chId}`\n📅 ثبت: {$info['updated_at']}\n\n";
    }
    sendPlainText($chatId, $msg);
}

function handleRemoveChannel($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ لطفاً آیدی یا یوزرنیم کانال را وارد کنید.\nمثال:\n`/remove @mychannel`");
        return;
    }

    $target = trim($arg);
    $realId = $target;

    if (!preg_match('/^-?\d+$/', $target)) {
        if ($target[0] !== '@') {
            $target = '@' . $target;
        }
        $chatRes = apiPost('getChat', ['chat_id' => $target]);
        if ($chatRes !== null && !empty($chatRes['ok'])) {
            $realId = (string)$chatRes['result']['id'];
        }
    }

    $owner = getChannelOwner($realId);
    if ($owner === null) {
        sendPlainText($chatId, "❌ این کانال در سیستم ثبت نشده است.");
        return;
    }

    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) {
        sendPlainText($chatId, "⛔ شما مالک ثبت‌کننده این کانال نیستید!");
        return;
    }

    removeChannel($realId);
    sendPlainText($chatId, "🗑 کانال (`{$realId}`) با موفقیت حذف شد و دیگر گزارش‌های آن ارسال نمی‌شود.");
}

function handleHistory($chatId, $userId, string $arg): void {
    if ($arg === '') {
        sendPlainText($chatId, "❌ لطفاً یوزرنیم یا آیدی کانال را وارد کنید.\nمثال:\n`/history @mychannel`");
        return;
    }

    $target = trim($arg);
    $realId = $target;

    if (!preg_match('/^-?\d+$/', $target)) {
        if ($target[0] !== '@') {
            $target = '@' . $target;
        }
        $chatRes = apiPost('getChat', ['chat_id' => $target]);
        if ($chatRes !== null && !empty($chatRes['ok'])) {
            $realId = (string)$chatRes['result']['id'];
        }
    }

    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
        sendPlainText($chatId, "⛔ شما به تاریخچه این کانال دسترسی ندارید یا کانال ثبت نشده است.");
        return;
    }

    $history = getLeaveHistory($realId);
    if (empty($history)) {
        sendPlainText($chatId, "📭 هنوز هیچ خروجی برای این کانال ثبت نشده است.");
        return;
    }

    $msg = "📊 *آخرین خروجی‌های کانال (`{$realId}`):*\n\n";
    $slice = array_slice($history, 0, 15);
    foreach ($slice as $idx => $item) {
        $num = $idx + 1;
        $name = $item['name'] ?? 'نامشخص';
        $uid = $item['user_id'] ?? '';
        $un = !empty($item['username']) ? "@{$item['username']}" : 'بدون یوزرنیم';
        $time = $item['time'] ?? '';
        $msg .= "{$num}. 👤 *{$name}* (`{$uid}` | {$un})\n🕒 {$time}\n\n";
    }
    sendPlainText($chatId, $msg);
}

// ---------------- هندلر دکمه‌های شیشه‌ای (Callback Query) ----------------

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
            answerCallbackQuery($cbId, '✅ عضویت شما تایید شد!', false);
            sendStartMenu($chatId);
        } else {
            answerCallbackQuery($cbId, '❌ هنوز در همه کانال‌ها عضو نشده‌اید!', true);
        }
        return;
    }

    // بررسی عضویت اجباری قبل از کار با دکمه‌های منو
    $check = checkUserForceJoin($userId, false);
    if (!$check['ok']) {
        answerCallbackQuery($cbId, '⚠️ ابتدا در کانال‌های معرفی شده عضو شوید.', true);
        sendForceJoinPrompt($chatId, $check['missing']);
        return;
    }

    answerCallbackQuery($cbId);

    switch ($data) {
        case 'menu_connect':
            sendPlainText($chatId, "➕ برای اتصال کانال، ابتدا ربات را در کانال *ادمین* کنید و سپس دستور زیر را بفرستید:\n\n`/connect @username`");
            break;
        case 'menu_mylist':
            handleMyList($chatId, $userId);
            break;
        case 'menu_help':
            sendPlainText($chatId, getHelpText());
            break;
        case 'menu_donate':
            sendPlainText($chatId, "💝 *حمایت مالی از ربات:*\n\n" . getDonateText());
            break;
        case 'menu_sponsor':
            sendPlainText($chatId, "🌟 *اسپانسر ربات:*\n\n" . getSponsorText());
            break;
    }
}

// ---------------- پردازش پیام‌های متنی ----------------

function handleTextMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? null;
    $chatType = $message['chat']['type'] ?? 'private';
    $userId = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? '');

    if (!$chatId || !$userId || $text === '') {
        return;
    }

    // نادیده گرفتن پیام‌های عادی داخل کانال یا گروه
    if ($chatType !== 'private' && strpos($text, '/') !== 0) {
        return;
    }

    [$cmd, $arg] = parseCommand($text);
    if ($cmd === null) {
        return;
    }

    // اگر کاربر /start زد، ۲ کانال تصادفی جدید برایش انتخاب شود
    $resetForce = ($cmd === 'start');
    $forceCheck = checkUserForceJoin($userId, $resetForce);
    if (!$forceCheck['ok']) {
        sendForceJoinPrompt($chatId, $forceCheck['missing']);
        return;
    }

    switch ($cmd) {
        case 'start':
            sendStartMenu($chatId);
            break;

        case 'help':
            sendPlainText($chatId, getHelpText());
            break;

        case 'status':
            $dbOk = (REDIS_URL !== '' && REDIS_TOKEN !== '') ? '✅ متصل' : '❌ قطع';
            sendPlainText($chatId, "🤖 *وضعیت ربات:* آنلاین\n🗄 *دیتابیس Redis:* {$dbOk}\n🕒 *زمان سرور:* `" . date('Y-m-d H:i:s') . "`");
            break;

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

        // ---------------- دستورات مخصوص سازنده ربات (Creator Only) ----------------
        case 'admin':
            if (!isBotOwner($userId)) {
                sendPlainText($chatId, "⛔ این دستور مخصوص سازنده ربات است.");
                return;
            }
            sendPlainText($chatId, getAdminHelpText());
            break;

        case 'mychannels':
            if (!isBotOwner($userId)) {
                sendPlainText($chatId, "⛔ این دستور مخصوص سازنده ربات است.");
                return;
            }
            $all = getAllChannelsMap();
            if (empty($all)) {
                sendPlainText($chatId, "📭 هیچ کانالی در کل ربات ثبت نشده است.");
                return;
            }
            $msg = "👑 *لیست کل کانال‌های ثبت‌شده در ربات (" . count($all) . " کانال):*\n\n";
            foreach ($all as $chId => $info) {
                $title = $info['title'] ?: 'بدون نام';
                $un = $info['username'] ? " ({$info['username']})" : '';
                $owner = $info['owner_id'] ?? 'نامشخص';
                $msg .= "🔹 *{$title}*{$un}\n🆔 کانال: `{$chId}`\n👤 مالک: `{$owner}`\n\n";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'addforce':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ مثال: `/addforce @channel`");
                return;
            }
            addForceChannelToPool($arg);
            sendPlainText($chatId, "✅ کانال `{$arg}` به لیست اد اجباری اضافه شد.");
            break;

        case 'removeforce':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ مثال: `/removeforce @channel`");
                return;
            }
            if (removeForceChannelFromPool($arg)) {
                sendPlainText($chatId, "🗑 کانال `{$arg}` از لیست اد اجباری حذف شد.");
            } else {
                sendPlainText($chatId, "❌ این کانال در لیست اد اجباری یافت نشد.");
            }
            break;

        case 'forcelist':
            if (!isBotOwner($userId)) {
                return;
            }
            $pool = getForceChannelsPool();
            if (empty($pool)) {
                sendPlainText($chatId, "📭 لیست اد اجباری خالی است.");
                return;
            }
            $msg = "📢 *کانال‌های موجود در استخر اد اجباری:*\n\n";
            foreach ($pool as $idx => $ch) {
                $num = $idx + 1;
                $msg .= "{$num}. `{$ch}`\n";
            }
            sendPlainText($chatId, $msg);
            break;

        case 'setsponsor':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ لطفاً متن اسپانسر را بعد از دستور بنویسید.\nمثال:\n`/setsponsor کانال اسپانسر ما: @example`");
                return;
            }
            setSponsorText($arg);
            sendPlainText($chatId, "✅ متن «اسپانسر ما» با موفقیت بروزرسانی شد.");
            break;

        case 'setdonate':
            if (!isBotOwner($userId)) {
                return;
            }
            if ($arg === '') {
                sendPlainText($chatId, "❌ لطفاً متن حمایت مالی را بعد از دستور بنویسید.\nمثال:\n`/setdonate شماره کارت جهت حمایت: 6037...`");
                return;
            }
            setDonateText($arg);
            sendPlainText($chatId, "✅ متن «حمایت مالی» با موفقیت بروزرسانی شد.");
            break;
    }
}

// ---------------- ردیابی و گزارش خروج اعضا ----------------

function processMemberLeft(array $chat, array $user, ?array $actor = null): void {
    if (!empty($user['is_bot'])) {
        return;
    }

    $channelId = $chat['id'] ?? null;
    if (!$channelId) {
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

    // ذخیره در تاریخچه دیتابیس
    saveLeaveHistory($channelId, [
        'user_id' => $userId,
        'name' => $fullName,
        'username' => $username,
        'time' => $timeStr,
    ]);

    // پیدا کردن صاحب کانال برای ارسال گزارش
    $ownerId = getChannelOwner($channelId);
    if (!$ownerId && BOT_OWNER_ID !== '') {
        $ownerId = BOT_OWNER_ID;
    }
    if (!$ownerId) {
        return;
    }

    $usernameLine = $username !== '' ? "@{$username}" : 'ندارد';
    $channelLine = $channelUsername !== '' ? "{$channelTitle} ({$channelUsername})" : $channelTitle;

    $actionType = 'خروج از کانال (Left)';
    if ($actor !== null && isset($actor['id']) && (string)$actor['id'] !== (string)$userId) {
        $actorName = trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? '')) ?: 'ادمین';
        $actionType = "حذف شده توسط {$actorName}";
    }

    $report = "📤 *گزارش خروج عضو از کانال*\n\n"
            . "📢 *کانال:* {$channelLine}\n"
            . "🆔 *آیدی کانال:* `{$channelId}`\n"
            . "━━━━━━━━━━━━━━\n"
            . "👤 *نام کاربر:* {$fullName}\n"
            . "🔖 *یوزرنیم:* {$usernameLine}\n"
            . "🔢 *آیدی عددی:* `{$userId}`\n"
            . "📌 *نوع رویداد:* {$actionType}\n"
            . "🕒 *زمان:* `{$timeStr}`";

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
