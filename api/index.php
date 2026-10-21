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

    $welcomeMessage = "سلام <b>{$senderName}</b> عزیز، به ربات مدیریت و گزارش خروج اعضا خوش آمدید! 🌸\n\n"
        . "📌 <b>شناسه عددی شما:</b> <code>{$chatId}</code>\n\n"
        . "⚙️ <b>راهنمای راه‌اندازی و نحوه کار:</b>\n"
        . "۱. ربات را به کانال یا گروه مورد نظر خود اضافه کنید.\n"
        . "۲. ربات را حتماً در کانال <b>مدیر (ادمین)</b> کنید و دسترسی‌های لازم (مشاهده اعضا و پیام‌ها) را به آن بدهید.\n"
        . "۳. به محض اینکه کاربری از کانال لفت بدهد یا اخراج شود، اطلاعات کامل و لاگ خروج او به همین چت شخصی ارسال خواهد شد.\n\n"
        . "📜 <b>قوانین و نکات مهم:</b>\n"
        . "• اگر ربات ادمین نباشد، پلتفرم بله رویدادهای عضویت را به ربات گزارش نمی‌کند.\n"
        . "• این ربات فقط به عنوان ناظر و لاگر عمل کرده و هیچ پیامی در کانال شما ارسال نخواهد کرد.\n"
        . "• تمامی گزارش‌ها مستقیماً به حساب شخصی شما مخابره می‌گردد.\n\n"
        . "✅ ربات در حال حاضر آماده به کار و گوش‌به‌زنگ است.";

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

    // شرایط لفت یا اخراج شدن عضو
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

/**
 * ارسال گزارش کامل و جامع خروج کاربر به ربات (پیوی ادمین)
 */
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

    $report = "🚨 <b>گزارش خروج کاربر از کانال</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "📢 <b>کانال هدف:</b> {$safeTitle}\n"
        . "🆔 <b>شناسه کانال:</b> <code>{$channelId}</code>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "👤 <b>نام کاربر:</b> {$fullName}\n"
        . "🔢 <b>شناسه عددی (User ID):</b> <code>{$userId}</code>\n"
        . "🔗 <b>نام کاربری (Username):</b> {$username}\n"
        . "🤖 <b>آیا ربات است؟:</b> {$isBot}\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "📅 <b>تاریخ:</b> " . date('Y/m/d') . "\n"
        . "⏰ <b>ساعت:</b> " . date('H:i:s') . "\n"
        . "⚠️ <i>کاربر فوق از کانال شما لفت داد یا حذف گردید.</i>";

    sendMessage(ADMIN_CHAT_ID, $report);
}

/**
 * ارسال درخواست POST به متد sendMessage پیام‌رسان بله
 */
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
