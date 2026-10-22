<?php
// test
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

define('BOT_TOKEN', trim(getenv('BOT_TOKEN') ?: ''));
define('REDIS_URL', trim(getenv('UPSTASH_REDIS_REST_URL') ?: ''));
define('REDIS_TOKEN', trim(getenv('UPSTASH_REDIS_REST_TOKEN') ?: ''));
define('BOT_OWNER_ID', trim(getenv('BOT_OWNER_ID') ?: ''));

if (BOT_TOKEN === '') {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Error: BOT_TOKEN environment variable is missing.';
    exit;
}

// ----------------------------------------------------
// ۰. دریافت ورودی و تست سلامت ربات در مرورگر
// ----------------------------------------------------
$input = file_get_contents('php://input');

if ($input === '' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['setwebhook'])) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https';
        $host  = $_SERVER['HTTP_HOST'] ?? '';
        $webhookUrl = $proto . '://' . $host . '/api/index.php';
        $res = apiPost('setWebhook', ['url' => $webhookUrl]);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
        }

        echo json_encode([
            'status' => 'Webhook Configuration Attempt',
            'webhook_url' => $webhookUrl,
            'bale_response' => $res
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if (!headers_sent()) {
        http_response_code(200);
    }
    echo '✅ Bale Bot is running successfully!';
    exit;
}

$update = json_decode($input, true);
if (!is_array($update)) {
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo 'OK';
    exit;
}

// ----------------------------------------------------
// ۰.۱ پردازش Callback Query (دکمه‌های شیشه‌ای)
// ----------------------------------------------------
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    if (!headers_sent()) {
        http_response_code(200);
    }
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
    $fromId     = $message['from']['id'] ?? null;
    $senderName = $message['from']['first_name'] ?? 'کاربر';

    [$command, $args] = parseCommand($message['text']);

    if ($command !== null) {
        // دستورات ویژه سازنده ربات (بدون نیاز به بررسی عضویت اجباری)
        if (isBotOwner($fromId)) {
            if ($command === 'admin') {
                sendOwnerHelp($chatId);
                finishRequest();
            }
            if ($command === 'mychannels') {
                handleMyChannels($chat, $message);
                finishRequest();
            }
            if ($command === 'addforce') {
                handleAddForceChannel($chatId, (string)$args);
                finishRequest();
            }
            if ($command === 'delforce') {
                handleDelForceChannel($chatId, (string)$args);
                finishRequest();
            }
            if ($command === 'listforce') {
                handleListForceChannels($chatId);
                finishRequest();
            }
            if ($command === 'setsponsor') {
                handleSetMetaText($chatId, 'bot:sponsor_text', (string)$args, 'اسپانسر');
                finishRequest();
            }
            if ($command === 'setdonate') {
                handleSetMetaText($chatId, 'bot:donate_text', (string)$args, 'حمایت مالی');
                finishRequest();
            }
        }

        // اگر کاربر در چت خصوصی دوباره استارت زد، ۲ کانال تصادفی جدید برایش انتخاب شود
        if ($command === 'start' && $chatType === 'private' && $fromId && !isBotOwner($fromId)) {
            assignRandomForceChannels($fromId, true);
        }

        // بررسی عضویت اجباری برای تمام کاربران و ادمین‌ها پیش از اجرای دستورات
        if ($fromId && !isBotOwner($fromId)) {
            if (!checkUserForceJoin($fromId, $chatId, $chatType)) {
                finishRequest();
            }
        }

        // اجرای دستورات عمومی
        if ($command === 'start' && $chatType === 'private' && $chatId) {
            sendStartMenu($chatId, $senderName);
            finishRequest();
        }

        if ($command === 'help') {
            sendHelp($chatId);
            finishRequest();
        }

        if ($command === 'connect') {
            handleConnect($chat, $message, $args);
            finishRequest();
        }

        if ($command === 'disconnect') {
            handleDisconnect($chat, $message);
            finishRequest();
        }

        if ($command === 'status') {
            handleStatus($chat);
            finishRequest();
        }

        if ($command === 'stats') {
            handleStats($chat);
            finishRequest();
        }

        if ($command === 'settings') {
            sendSettingsMenu($chatId);
            finishRequest();
        }
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

    if (in_array($newStatus, ['member', 'administrator'], true) && $oldStatus === 'left') {
        $user = $chatMember['new_chat_member']['user'] ?? [];
        $chat = $chatMember['chat'] ?? [];
        if (!empty($user['id']) && !empty($chat['id'])) {
            saveJoinTime($chat['id'], $user['id']);
        }
    }
}

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

finishRequest();

// ====================================================
// توابع کمکی پایان درخواست و بررسی سازنده
// ====================================================

function finishRequest(): void
{
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo 'OK';
    exit;
}

function isBotOwner($userId): bool
{
    if (!$userId || BOT_OWNER_ID === '') return false;
    return (string)$userId === (string)BOT_OWNER_ID;
}

// ====================================================
// سیستم عضویت اجباری تصادفی (حداکثر ۲ کانال)
// ====================================================

function getForceChannelsPool(): array
{
    $raw = redisGet('bot:force_channels_pool');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveForceChannelsPool(array $pool): void
{
    redisSet('bot:force_channels_pool', json_encode(array_values($pool), JSON_UNESCAPED_UNICODE));
}

function assignRandomForceChannels($userId, bool $forceReassign = false): array
{
    $pool = getForceChannelsPool();
    if (empty($pool)) {
        return [];
    }

    $poolMap = [];
    foreach ($pool as $ch) {
        if (!empty($ch['chat_id'])) {
            $poolMap[(string)$ch['chat_id']] = $ch;
        }
    }

    if (!$forceReassign) {
        $existingRaw = redisGet('user:force_assigned:' . $userId);
        if ($existingRaw) {
            $existingIds = json_decode($existingRaw, true);
            if (is_array($existingIds) && !empty($existingIds)) {
                $validAssigned = [];
                foreach ($existingIds as $id) {
                    if (isset($poolMap[(string)$id])) {
                        $validAssigned[] = $poolMap[(string)$id];
                    }
                }
                if (!empty($validAssigned)) {
                    return array_slice($validAssigned, 0, 2);
                }
            }
        }
    }

    // انتخاب تصادفی حداکثر ۲ کانال از لیست کل کانال‌ها
    $keys = array_keys($poolMap);
    shuffle($keys);
    $selectedKeys = array_slice($keys, 0, min(2, count($keys)));

    redisSet('user:force_assigned:' . $userId, json_encode($selectedKeys, JSON_UNESCAPED_UNICODE), 86400 * 30);

    $assigned = [];
    foreach ($selectedKeys as $k) {
        $assigned[] = $poolMap[$k];
    }
    return $assigned;
}

function isUserJoinedInChannel($channelId, $userId): bool
{
    $res = apiPost('getChatMember', [
        'chat_id' => $channelId,
        'user_id' => $userId,
    ]);

    // اگر ربات در کانال ادمین نباشد یا خطایی رخ دهد، وضعیت را بررسی می‌کند
    if ($res === null || empty($res['ok'])) {
        return false;
    }

    $status = $res['result']['status'] ?? 'left';
    return in_array($status, ['creator', 'administrator', 'member'], true);
}

function getUnjoinedAssignedChannels($userId): array
{
    $assigned = assignRandomForceChannels($userId, false);
    if (empty($assigned)) return [];

    $notJoined = [];
    foreach ($assigned as $ch) {
        if (!isUserJoinedInChannel($ch['chat_id'], $userId)) {
            $notJoined[] = $ch;
        }
    }
    return $notJoined;
}

function checkUserForceJoin($userId, $replyChatId, string $chatType = 'private'): bool
{
    $unjoined = getUnjoinedAssignedChannels($userId);
    if (empty($unjoined)) {
        return true;
    }

    if ($chatType !== 'private') {
        sendPlainText($replyChatId, "⚠️ برای استفاده از دستورات ربات، ابتدا باید به پی‌وی ربات مراجعه کرده و در کانال‌های اسپانسر عضو شوید.");
        return false;
    }

    sendForceJoinPrompt($replyChatId, $unjoined);
    return false;
}

function sendForceJoinPrompt($chatId, array $channels): void
{
    $msg = "⚠️ *کاربر گرامی، برای فعال‌سازی و استفاده از ربات ابتدا باید در کانال‌های زیر عضو شوید:*\n\n"
         . "پس از عضویت در هر دو کانال، روی دکمه *«✅ عضو شدم / بررسی عضویت»* بزنید:";

    $inlineKeyboard = [];
    foreach ($channels as $index => $ch) {
        $num = $index + 1;
        $title = !empty($ch['title']) ? $ch['title'] : "کانال اسپانسر {$num}";
        $url   = $ch['url'] ?? 'https://ble.ir/';
        $inlineKeyboard[] = [
            ['text' => "📢 عضویت در {$title}", 'url' => $url]
        ];
    }

    $inlineKeyboard[] = [
        ['text' => '✅ عضو شدم / بررسی عضویت', 'callback_data' => 'check_force_join']
    ];

    sendMessageWithKeyboard($chatId, $msg, ['inline_keyboard' => $inlineKeyboard]);
}

// ====================================================
// دستورات مدیریتی ویژه سازنده (پنل ادمین)
// ====================================================

function sendOwnerHelp($chatId): void
{
    $msg = "👑 *پنل راهنمای سازنده ربات*\n\n"
        . "🔹 *افزودن کانال به لیست عضویت اجباری:*\n"
        . "`/addforce @channel_id https://ble.ir/link عنوان کانال`\n"
        . "_(توجه: ربات باید در کانال مورد نظر ادمین باشد)_\n\n"
        . "🔹 *حذف کانال از عضویت اجباری:*\n"
        . "`/delforce @channel_id`\n\n"
        . "🔹 *مشاهده لیست کانال‌های عضویت اجباری:*\n"
        . "`/listforce`\n\n"
        . "🔹 *تنظیم متن و لینک دکمه اسپانسر:*\n"
        . "`/setsponsor متن و لینک کانال شما`\n\n"
        . "🔹 *تنظیم متن و لینک دکمه حمایت مالی:*\n"
        . "`/setdonate متن و لینک یا شماره کارت`\n\n"
        . "🔹 *مشاهده کانال‌های متصل به ربات:*\n"
        . "`/mychannels`";

    sendMessageWithKeyboard($chatId, $msg, null);
}

function handleAddForceChannel($chatId, string $args): void
{
    $parts = preg_split('/\s+/u', trim($args), 3);
    if (count($parts) < 2) {
        sendPlainText($chatId, "⚠️ فرمت دستور صحیح نیست.\n\nنمونه صحیح:\n`/addforce @mychannel https://ble.ir/mychannel کانال رسمی ما`");
        return;
    }

    $channelId = trim($parts[0]);
    $url       = trim($parts[1]);
    $title     = isset($parts[2]) && trim($parts[2]) !== '' ? cleanText(trim($parts[2])) : 'کانال اسپانسر';

    $pool = getForceChannelsPool();
    foreach ($pool as &$item) {
        if ((string)$item['chat_id'] === $channelId) {
            $item['url']   = $url;
            $item['title'] = $title;
            saveForceChannelsPool($pool);
            sendPlainText($chatId, "✅ اطلاعات کانال `{$channelId}` در لیست عضویت اجباری بروزرسانی شد.");
            return;
        }
    }
    unset($item);

    $pool[] = [
        'chat_id' => $channelId,
        'url'     => $url,
        'title'   => $title,
    ];
    saveForceChannelsPool($pool);

    $total = count($pool);
    sendPlainText($chatId, "✅ کانال «{$title}» (`{$channelId}`) به لیست عضویت اجباری اضافه شد.\n📊 تعداد کل کانال‌های داخل لیست: {$total}\n\n⚠️ فراموش نکنید ربات را در این کانال *ادمین* کنید تا بتواند عضویت کاربران را بررسی کند.");
}

function handleDelForceChannel($chatId, string $args): void
{
    $targetId = trim($args);
    if ($targetId === '') {
        sendPlainText($chatId, "⚠️ لطفاً آیدی کانال را وارد کنید.\nنمونه:\n`/delforce @mychannel`");
        return;
    }

    $pool = getForceChannelsPool();
    $newPool = [];
    $found = false;

    foreach ($pool as $item) {
        if ((string)($item['chat_id'] ?? '') === $targetId) {
            $found = true;
            continue;
        }
        $newPool[] = $item;
    }

    if (!$found) {
        sendPlainText($chatId, "❌ کانالی با شناسه `{$targetId}` در لیست پیدا نشد.");
        return;
    }

    saveForceChannelsPool($newPool);
    sendPlainText($chatId, "🗑️ کانال `{$targetId}` از لیست عضویت اجباری حذف شد.");
}

function handleListForceChannels($chatId): void
{
    $pool = getForceChannelsPool();
    if (empty($pool)) {
        sendPlainText($chatId, "ℹ️ لیست کانال‌های عضویت اجباری خالی است.");
        return;
    }

    $msg = "📋 *لیست کانال‌های عضویت اجباری (انتخاب تصادفی ۲ مورد برای هر کاربر):*\n\n";
    foreach ($pool as $idx => $ch) {
        $n = $idx + 1;
        $msg .= "{$n}. *{$ch['title']}*\n"
             . "🆔 آیدی: `{$ch['chat_id']}`\n"
             . "🔗 لینک: {$ch['url']}\n\n";
    }

    sendPlainText($chatId, $msg);
}

function handleSetMetaText($chatId, string $redisKey, string $text, string $label): void
{
    $text = trim($text);
    if ($text === '') {
        sendPlainText($chatId, "⚠️ لطفاً متن یا لینک مورد نظر برای بخش «{$label}» را بعد از دستور وارد کنید.");
        return;
    }

    redisSet($redisKey, $text);
    sendPlainText($chatId, "✅ متن بخش «{$label}» با موفقیت ذخیره شد.");
}

function sendSponsorInfo($chatId): void
{
    $text = redisGet('bot:sponsor_text');
    if (!$text) {
        $text = "🌟 *بخش اسپانسر ربات*\n\nبرای ثبت تبلیغات و اسپانسرینگ یا مشاهده کانال رسمی ما با مدیریت در ارتباط باشید.";
    }
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_to_start']]
        ]
    ];
    sendMessageWithKeyboard($chatId, $text, $keyboard);
}

function sendDonateInfo($chatId): void
{
    $text = redisGet('bot:donate_text');
    if (!$text) {
        $text = "💝 *حمایت مالی از ربات*\n\nاگر از خدمات رایگان این ربات رضایت دارید، می‌توانید برای پایداری سرورها و توسعه امکانات بیشتر از ما حمایت کنید.";
    }
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_to_start']]
        ]
    ];
    sendMessageWithKeyboard($chatId, $text, $keyboard);
}

// ====================================================
// توابع اصلی منوها و دستورات عمومی
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
            [
                ['text' => '💝 حمایت مالی', 'callback_data' => 'donate'],
                ['text' => '🌟 اسپانسر ما', 'callback_data' => 'sponsor'],
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
        . "🔹 `/settings` — تنظیمات اعلان‌ها\n\n"
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

    // بررسی اینکه مدیر هدف نیز در کانال‌های اجباری عضو باشد
    if (!isBotOwner($ownerId)) {
        $unjoinedOwner = getUnjoinedAssignedChannels($ownerId);
        if (!empty($unjoinedOwner)) {
            sendPlainText($chatId, "⚠️ مدیر مورد نظر (`{$ownerId}`) هنوز در کانال‌های اسپانسر ربات عضو نشده است. لطفاً ابتدا در پی‌وی ربات `/start` را بزنید و در کانال‌ها عضو شوید.");
            return;
        }
    }

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

    if (!isBotOwner($fromId)) {
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
    $chatType   = $message['chat']['type'] ?? 'private';
    $fromId     = $callback['from']['id'] ?? null;
    $senderName = $callback['from']['first_name'] ?? 'کاربر';

    if (!$callbackId || !$chatId) return;

    answerCallbackQuery($callbackId);

    // بررسی دکمه تایید عضویت اجباری
    if ($data === 'check_force_join') {
        if ($fromId && !isBotOwner($fromId)) {
            $unjoined = getUnjoinedAssignedChannels($fromId);
            if (!empty($unjoined)) {
                sendPlainText($chatId, "❌ شما هنوز در تمام کانال‌های معرفی شده عضو نشده‌اید! لطفاً ابتدا عضو شوید و مجدداً تلاش کنید.");
                sendForceJoinPrompt($chatId, $unjoined);
                return;
            }
        }
        sendPlainText($chatId, "✅ عضویت شما تایید شد! ربات برای شما فعال گردید.");
        sendStartMenu($chatId, $senderName);
        return;
    }

    // قفل دکمه‌های شیشه‌ای در صورت عدم عضویت در کانال‌های اجباری
    if ($fromId && !isBotOwner($fromId)) {
        if (!checkUserForceJoin($fromId, $chatId, $chatType)) {
            return;
        }
    }

    if ($data === 'help') { sendHelp($chatId); return; }
    if ($data === 'settings') { sendSettingsMenu($chatId); return; }
    if ($data === 'status') { handleStatus($message['chat'] ?? []); return; }
    if ($data === 'stats') { handleStats($message['chat'] ?? []); return; }
    if ($data === 'donate') { sendDonateInfo($chatId); return; }
    if ($data === 'sponsor') { sendSponsorInfo($chatId); return; }
    if ($data === 'back_to_start') {
        sendStartMenu($chatId, $senderName);
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

    // اگر ادمین کانال، کانال‌های اجباری را ترک کرده باشد، ربات برایش گزارش ارسال نمی‌کند تا زمانی که عضو شود
    if (!isBotOwner($targetAdminId)) {
        $unjoined = getUnjoinedAssignedChannels($targetAdminId);
        if (!empty($unjoined)) {
            return;
        }
    }

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

    $username = !empty($user['username']) ? '@' . cleanText($user['u
