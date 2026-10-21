<?php

date_default_timezone_set('Asia/Tehran');

// شناسه توکن و آیدی عددی ادمین
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID', getenv('ADMIN_CHAT_ID') ?: '560178334');

$input = file_get_contents('php://input');

if (empty($input)) {
    http_response_code(200);
    echo "Bot is active and running.";
    exit;
}

// ثبت داده‌های دریافتی برای بررسی در لاگ ورسل
error_log("BALE_RAW_UPDATE: " . $input);

$update = json_decode($input, true);
if (!$update) {
    http_response_code(200);
    exit;
}

// ==================================================
// 1. دستور /start (راهنما، قوانین و خوش‌آمدگویی)
// ==================================================
if (isset($update['message']['text']) && trim($update['message']['text']) === '/start') {
    $chatId = $update['message']['chat']['id'];
    $senderName = htmlspecialchars($update['message']['from']['first_name'] ?? 'کاربر گرامی');

    $welcomeMessage = "سلام {$senderName} عزیز، به ربات مدیریت و گزارش خروج اعضا خوش آمدید! 🌸\n\n"
        . "📌 شناسه عددی شما: <code>{$chatId}</code>\n\n"
        . "⚙️ راهنمای راه‌اندازی و نحوه کار:\n"
        . "۱. ربات را به کانال یا گروه مورد نظر خود اضافه کنید.\n"
        . "۲. ربات را حتماً در کانال مدیر (ادمین) کنید و دسترسی‌های لازم را به آن بدهید.\n"
        . "۳. به محض اینکه کاربری از کانال لفت بدهد، اطلاعات کامل او به همین چت ارسال خواهد شد.\n\n"
        . "📜 قوانین و نکات مهم:\n"
        . "• ربات باید حتماً ادمین باشد.\n"
        . "• این ربات فقط به عنوان ناظر عمل می‌کند.\n\n"
        . "✅ ربات آماده به کار است.";

    sendMessage($chatId, $welcomeMessage);
    http_response_code(200);
    echo "OK";
    exit;
}

// ==================================================
// 2. رهگیری خروج اعضا (حالت پیام سرویسی message.left_chat_member)
// ==================================================
if (isset($update['message']['left_chat_member'])) {
    $user      = $update['message']['left_chat_member'];
    $chat      = $update['message']['chat'] ?? [];
    $chatTitle = $chat['title'] ?? 'کانال/گروه نامشخص';
    $channelId = $chat['id'] ?? 'نامشخص';

    sendDetailedLeaveAlert($user, $chatTitle, $channelId);
}

// ==================================================
// 3. رهگیری خروج اعضا (حالت رویداد chat_member)
// ==================================================
if (isset($update['chat_member'])) {
    $chatMember = $update['chat_member'];
    $oldStatus  = $chatMember['old_chat_member']['status'] ?? '';
    $newStatus  = $chatMember['new_chat_member']['status'] ?? '';

    if (in_array($oldStatus, ['member', 'administrator', 'restricted']) && in_array($newStatus, ['left', 'kicked'])) {
        $user      = $chatMember['old_chat_member']['user'] ?? ($chatMember['new_chat_member']['user'] ?? []);
        $chat      = $chatMember['chat'] ?? [];
        $chatTitle = $chat['title'] ?? 'کانال/گروه نامشخص';
        $channelId = $chat['id'] ?? 'نامشخص';

        sendDetailedLeaveAlert($user, $chatTitle, $channelId);
    }
}

http_response_code(200);
echo "OK";

// ==================================================
// توابع ارسال پیام
// ==================================================

function sendDetailedLeaveAlert($user, $chatTitle, $channelId)
{
    $userId    = $user['id'] ?? 'نامشخص';
    $firstName = htmlspecialchars($user['first_name'] ?? '');
    $lastName  = htmlspecialchars($user['last_name'] ?? '');
    $fullName  = trim("{$firstName} {$lastName}");
    if (empty($fullName)) {
        $fullName = 'بدون نام';
    }

    $username  = !empty($user['username']) ? '@' . $user['username'] : 'ندارد';
    $isBot     = !empty($user['is_bot']) ? 'بله' : 'خیر';
    $safeTitle = htmlspecialchars($chatTitle);

    $report = "🚨 گزارش خروج کاربر از کانال\n\n"
        . "📢 کانال: {$safeTitle}\n"
        . "🆔 شناسه کانال: <code>{$channelId}</code>\n\n"
        . "👤 نام کاربر: {$fullName}\n"
        . "🔢 شناسه عددی: <code>{$userId}</code>\n"
        . "🔗 نام کاربری: {$username}\n"
        . "🤖 ربات است؟: {$isBot}\n\n"
        . "📅 تاریخ: " . date('Y/m/d') . "\n"
        . "⏰ ساعت: " . date('H:i:s');

    sendMessage(ADMIN_CHAT_ID, $report);
}

function sendMessage($chatId, $text)
{
    $url = 'https://tapi.bale.ai/bot' . BOT_TOKEN . '/sendMessage';

    $payload = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
