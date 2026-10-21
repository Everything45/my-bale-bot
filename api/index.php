<?php

// ==================================================
// ۱. تنظیمات اولیه
// ==================================================
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // توکن ربات بله
define('ADMIN_CHAT_ID', 'YOUR_NUMERIC_CHAT_ID'); // شناسه عددی ادمین یا کانالی که گزارش به آن ارسال شود

// ==================================================
// ۲. دریافت و پردازش ورودی
// ==================================================
$input = file_get_contents('php://input');

if (empty($input)) {
    http_response_code(200);
    exit;
}

// ثبت در لاگ برای عیب‌یابی (اختیاری)
file_put_contents(__DIR__ . '/log.txt', date('Y-m-d H:i:s') . "\n" . $input . "\n---\n", FILE_APPEND | LOCK_EX);

$update = json_decode($input, true);
if (!$update) {
    exit;
}

// ==================================================
// ۳. تشخیص خروج کاربر از کانال/گروه (chat_member)
// ==================================================
if (isset($update['chat_member'])) {
    $chatMember = $update['chat_member'];
    
    $chatTitle = $chatMember['chat']['title'] ?? 'کانال/گروه';
    $oldStatus = $chatMember['old_chat_member']['status'] ?? '';
    $newStatus = $chatMember['new_chat_member']['status'] ?? '';

    // بررسی وضعیت خروج کاربر
    // حالت عضو عادی یا محدود شده بوده و اکنون خارج شده است
    if (in_array($oldStatus, ['member', 'restricted']) && $newStatus === 'left') {
        $user = $chatMember['new_chat_member']['user'];
        
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

// ==================================================
// ۴. پاسخ به دستور /start برای دریافت Chat ID
// ==================================================
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

// ==================================================
// ۵. تابع ارسال پیام با استفاده از cURL
// ==================================================
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // برای جلوگیری از مشکلات احتمالی گواهی SSL در هاست‌های رایگان

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}
