<?php
// src/events.php
declare(strict_types=1);

function processMemberLeft(array $chat, array $user, ?array $actor = null): void {
    if (!empty($user['is_bot'])) return;
    $channelId = $chat['id'] ?? null;
    if (!$channelId) return;
    $channelIdStr = (string)$channelId;
    $ownerId = getChannelOwner($channelIdStr);
    if (!$ownerId) return;

    $channelTitle = $chat['title'] ?? 'کانال بدون نام';
    $channelUsername = isset($chat['username']) ? '@'.$chat['username'] : '';
    $userId = $user['id'] ?? 'نامشخص';
    $fullName = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: 'بدون نام';
    $username = $user['username'] ?? '';
    $timeStr = date('Y/m/d - H:i:s');

    saveLeaveHistory($channelIdStr, ['user_id'=>$userId,'name'=>$fullName,'username'=>$username,'time'=>$timeStr]);

    $usernameLine = $username !== '' ? "@".$username : 'ندارد';
    $channelLine = $channelUsername !== '' ? $channelTitle . " (" . $channelUsername . ")" : $channelTitle;
    $actionType = 'خروج از کانال';

    if ($actor !== null && isset($actor['id']) && (string)$actor['id'] !== (string)$userId) {
        $actorName = trim(($actor['first_name'] ?? '').' '.($actor['last_name'] ?? '')) ?: 'ادمین';
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

    $wasMember = in_array($oldStatus, ['member','administrator','restricted'], true);
    $isGone = in_array($newStatus, ['left','kicked'], true);
    if ($wasMember && $isGone && !empty($user)) processMemberLeft($chat, $user, $actor);
}

function handleMyChatMember(array $m): void {
    $chat = $m['chat'] ?? [];
    $chatId = $chat['id'] ?? null;
    $type = (string)($chat['type'] ?? '');
    if (!$chatId || $type === 'private' || $type === '') return;

    $chatIdStr = (string)$chatId;
    $newStatus = $m['new_chat_member']['status'] ?? '';
    $title = (string)($chat['title'] ?? 'بدون نام');
    $kind = $type === 'channel' ? 'کانال' : 'گروه';

    if (in_array($newStatus, ['left','kicked'], true)) {
        $owner = getChannelOwner($chatIdStr);
        if ($owner !== null) {
            removeChannel($chatIdStr);
            sendPlainText($owner, "⚠️ ربات از {$kind} «{$title}» حذف شد و اتصال آن لغو شد.\nبرای اتصال دوباره، ربات را ادمین کنید و /add بزنید.");
        }
        return;
    }

    $from = $m['from'] ?? null;
    if ($newStatus === 'administrator' && is_array($from) && !empty($from['id']) && empty($from['is_bot'])) {
        $ref = isset($chat['username']) ? (string)$chat['username'] : $chatIdStr;
        sendPlainText($from['id'], "✅ ربات تو {$kind} «{$title}» ادمین شد.\n\nبرای اتصال این دستور رو بفرستید:\n/add {$ref}");
    }
}