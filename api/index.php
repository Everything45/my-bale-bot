<?php

date_default_timezone_set('Asia/Tehran');

// شناسه توکن و آیدی عددی شما
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID', getenv('ADMIN_CHAT_ID') ?: '560178334');

$input = file_get_contents('php://input');

if (empty($input)) {
    http_response_code(200);
    echo "Bot is active and running.";
    exit;
}

// ثبت داده‌های خام برای عیب‌یابی در لاگ ورسل
error_log("BALE_RAW_UPDATE: " . $input);

$update = json_decode($input, true);
if (!$update) {
    http_response_code(200);
    exit;
}

// ==================================================
// حالت اول: خروج به صورت Service Message در بله
// ==================================================
if (isset($update['message']['left_chat_member'])) {
    $user      = $update['message']['left_chat_member'];
    $chatTitle = $update['message']['chat']['title'] ?? 'کانال/گروه';
    
    sendLeaveAlert($user, $chatTitle);
}

// ==================================================
// حالت دوم: رویداد chat_member (استاندارد تلگرام/برخی آپدیت‌های بله)
// ==================================================
if (isset($update['chat_member'])) {
    $chatMember = $update['chat_member'];
    $oldStatus  = $chatMember['old_chat_member']['status'] ?? '';
    $newStatus  = $chatMember['new_chat_member']['status'] ?? '';

    if (in_array($oldStatus, ['member', 'restricted']) && in_array($newStatus, ['left', 'kicked'])) {
        $user      = $chatMember['old_chat_member']['user'] ?? $chatMember['new_chat_member']['user'];
        $chatTitle = $chatMember['chat']['title'] ?? 'کانال/گروه';
        
        sendLeaveAlert($user, $chatTitle);
    }
}

// ==================================================
// پاسخ به دستور /start
// ==================================================
if (isset($update['message']['text']) && trim($update['message']['text']) === '/start') {
    $chatId = $update['message']['chat']['id'];
    $msg = "سلام 👋\nربات فعال است.\nشناسه عددی شما:\n<code>{$chatId}</code>";
    sendMessage($chatId, $msg);
}

http_response_code(200);
echo "OK";

// ==================================================
// توابع کمکی
// ==================================================
function sendLeaveAlert($user, $chatTitle)
{
    $userId   = $user['id'] ?? 'نامشخص';
    $name     = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $username = !empty($user['username']) ? '@' . $user['username'] : 'ندارد';

    $reportText = "🚨 <b>گزارش لفت از کانال</b>\n\n"
                . "📢 <b>کانال:</b> {$chatTitle}\n"
                . "👤 <b>نام کاربر:</b> {$name}\n"
                . "🆔 <b>شناسه عددی:</b> <code>{$userId}</code>\n"
                . "🔗 <b>نام کاربری:</b> {$username}\n"
                . "⏰ <b>زمان:</b> " . date('H:i:s Y/m/d');

    sendMessage(ADMIN_CHAT_ID, $reportText);
}

function sendMessage($chatId, $text)
{
    $url = 'https://tapi.bale.ai/bot' . BOT_TOKEN . '/sendMessage';

    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}
