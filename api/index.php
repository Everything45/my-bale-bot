<?php

// ۱. تنظیم تایم‌زون
date_default_timezone_set('Asia/Tehran');

// ۲. خواندن متغیرهای محیطی از Vercel
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID', getenv('ADMIN_CHAT_ID') ?: 'YOUR_NUMERIC_CHAT_ID');

// ۳. دریافت ورودی وب‌هوک
$input = file_get_contents('php://input');

if (empty($input)) {
    http_response_code(200);
    echo "Bot is active and running.";
    exit;
}

// ثبت لاگ در پنل Vercel (مشاهده در تب Logs)
error_log("Bale Update: " . $input);

$update = json_decode($input, true);
if (!$update) {
    http_response_code(400);
    exit;
}

// ۴. رویداد خروج کاربر (chat_member)
if (isset($update['chat_member'])) {
    $chatMember = $update['chat_member'];

    $chatTitle = $chatMember['chat']['title'] ?? 'کانال/گروه';
    $oldStatus = $chatMember['old_chat_member']['status'] ?? '';
    $newStatus = $chatMember['new_chat_member']['status'] ?? '';

    // اگر کاربر قبلاً عضو بوده و اکنون خارج شده است
    if (in_array($oldStatus, ['member', 'restricted']) && $newStatus === 'left') {
        $user = $chatMember['new_chat_member']['user'] ?? $chatMember['old_chat_member']['user'];

        $userId   = $user['id'] ?? 'نامشخص';
        $name     = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $username = isset($user['username']) ? '@' . $user['username'] : 'ندارد';

        $reportText = "🚨 <b>گزارش لفت از کانال</b>\n\n"
                    . "📢 <b>کانال:</b> {$chatTitle}\n"
                    . "👤 <b>نام کاربر:</b> {$name}\n"
                    . "🆔 <b>شناسه عددی:</b> <code>{$userId}</code>\n"
                    . "🔗 <b>نام کاربری:</b> {$username}\n"
                    . "⏰ <b>زمان:</b> " . date('H:i:s Y/m/d');

        sendMessage(ADMIN_CHAT_ID, $reportText);
    }
}

// ۵. پاسخ به دستور /start
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId  = $message['chat']['id'];
    $text    = trim($message['text'] ?? '');

    if ($text === '/start') {
        $msg = "سلام 👋\n"
             . "ربات فعال است.\n"
             . "شناسه عددی شما (برای قرار دادن در ADMIN_CHAT_ID):\n"
             . "<code>{$chatId}</code>";

        sendMessage($chatId, $msg);
    }
}

// پایان پاسخ سریع به سرور بله (200 OK)
http_response_code(200);
echo "OK";

// ۶. تابع ارسال پیام با cURL
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

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);

    return $response;
}
