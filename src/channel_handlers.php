<?php
// src/channel_handlers.php
declare(strict_types=1);

function handleConnect($chatId, $userId, string $arg, string $chatType = 'private'): void {
    if ($arg === '') {
        if ($chatType === 'private') {
            sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بعد از دستور بنویسید.\n\nمثال:\n/add mynews\n\nبرای گروه، دستور /add رو بدون متن داخل خود گروه بفرستید.");
            return;
        }
        $target = (string)$chatId;
    } else {
        $target = normalizeChannelRef($arg);
        if ($target === null) {
            sendMessageWithKeyboard($chatId, "❌ آیدی نامعتبره.\n\nمثال:\n/add mynews");
            return;
        }
    }

    $chatRes = apiPost('getChat', ['chat_id'=>$target]);
    if (!apiOk($chatRes)) {
        sendMessageWithKeyboard($chatId,
            "❌ کانال/گروه پیدا نشد.\n\n• آیدی رو دقیق بنویسید (مثلاً /add mynews)\n• ربات باید توی کانال/گروه عضو باشه");
        return;
    }

    $chatObj = $chatRes['result'];
    $type = (string)($chatObj['type'] ?? '');
    if ($type === 'private') {
        sendMessageWithKeyboard($chatId, "❌ این یک چت خصوصیه، نه کانال یا گروه.");
        return;
    }

    $realChannelId = (string)$chatObj['id'];
    $title = (string)($chatObj['title'] ?? 'بدون نام');
    $username = isset($chatObj['username']) ? '@'.$chatObj['username'] : '';
    $kind = $type === 'channel' ? 'کانال' : 'گروه';

    $botId = getBotId();
    if ($botId === null) {
        sendMessageWithKeyboard($chatId, "❌ شناسایی ربات ناموفق بود. چند لحظه بعد دوباره امتحان کنید.");
        return;
    }

    $botMember = apiPost('getChatMember', ['chat_id'=>$realChannelId,'user_id'=>$botId]);
    if (!apiOk($botMember)) {
        sendMessageWithKeyboard($chatId, "⚠️ ربات عضو این {$kind} نیست.\n\nاول ربات رو به {$kind} اضافه کنید.");
        return;
    }
    $botStatus = $botMember['result']['status'] ?? '';
    $isBotAdmin = in_array($botStatus, ['administrator','creator'], true);

    $memberRes = apiPost('getChatMember', ['chat_id'=>$realChannelId,'user_id'=>$userId]);
    if (!apiOk($memberRes)) {
        sendMessageWithKeyboard($chatId, "❌ بررسی عضویت شما تو این {$kind} ناموفق بود. چند لحظه بعد دوباره امتحان کنید.");
        return;
    }
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['creator','administrator'], true) && !isBotOwner($userId)) {
        sendMessageWithKeyboard($chatId, "⛔ شما تو این {$kind} ادمین یا سازنده نیستید.");
        return;
    }

    $existingOwner = getChannelOwner($realChannelId);
    if ($existingOwner !== null && $existingOwner !== (string)$userId && !isBotOwner($userId)) {
        $oldMember = apiPost('getChatMember', ['chat_id'=>$realChannelId,'user_id'=>$existingOwner]);
        $oldStatus = apiOk($oldMember) ? ($oldMember['result']['status'] ?? '') : '';
        if (in_array($oldStatus, ['creator','administrator'], true)) {
            sendMessageWithKeyboard($chatId, "⛔ این {$kind} قبلاً توسط ادمین دیگری ثبت شده.");
            return;
        }
    }

    setChannelOwner($realChannelId, $userId, $title, $username, $type);

    $msg = "✅ {$kind} «".$title."» متصل شد.\n\n";
    $msg .= !$isBotAdmin
        ? "⚠️ **هشدار:** ربات در حال حاضر فقط «عضو» است. برای دریافت گزارش خروج اعضا، باید ربات را در {$kind} **ادمین** کنید."
        : "از الان گزارش خروج اعضا براتون میاد.";
    sendMessageWithKeyboard($chatId, $msg);
}

function handleMyList($chatId, $userId, $messageId = null): void {
    $channels = getOwnerChannels($userId);
    if (empty($channels)) {
        editOrSend($chatId, $messageId, "📭 هنوز کانالی ثبت نکردید.\n\nبرای اتصال:\n1. ربات رو ادمین کنید\n2. این دستور رو بفرستید:\n/add mynews");
        return;
    }

    $msg = "📋 کانال‌های شما (بررسی زنده):\n\n";
    $removed = [];
    $count = 0;
    foreach ($channels as $chId => $info) {
        $fresh = refreshChannelInfo((string)$chId, $info);
        if ($fresh === null) { $removed[] = ($info['title'] ?? '') ?: (string)$chId; continue; }
        $count++;
        $msg .= "🔹 ".(($fresh['title'] ?? '') ?: 'بدون نام').(!empty($fresh['username']) ? " ({$fresh['username']})" : '')."\nآیدی: {$chId}\n\n";
    }
    if ($count === 0) $msg = "📭 هیچ کانال فعالی ندارید.\n\n";
    if (!empty($removed)) $msg .= "🧹 این موارد چون ربات دیگه توشون نیست از لیست حذف شدن:\n- ".implode("\n- ", $removed);
    editOrSend($chatId, $messageId, trim($msg));
}

function handleRemoveChannel($chatId, $userId, string $arg, $messageId = null): void {
    if ($arg === '') { editOrSend($chatId, $messageId, "❌ نام کانال رو بعد از remove بنویسید."); return; }
    $realId = findOwnerChannel($userId, $arg) ?? resolveChannelId($arg);
    if ($realId === null) { editOrSend($chatId, $messageId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null) { editOrSend($chatId, $messageId, "❌ این کانال تو ربات ثبت نشده."); return; }
    if ((string)$owner !== (string)$userId && !isBotOwner($userId)) { editOrSend($chatId, $messageId, "⛔ شما مالک این کانال نیستید."); return; }
    removeChannel($realId);
    editOrSend($chatId, $messageId, "🗑 کانال حذف شد.");
}

function handleHistory($chatId, $userId, string $arg, $messageId = null): void {
    if ($arg === '') { editOrSend($chatId, $messageId, "❌ نام کانال رو بعد از history بنویسید."); return; }
    $realId = findOwnerChannel($userId, $arg) ?? resolveChannelId($arg);
    if ($realId === null) { editOrSend($chatId, $messageId, "❌ کانال پیدا نشد."); return; }
    $owner = getChannelOwner($realId);
    if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
        editOrSend($chatId, $messageId, "⛔ دسترسی ندارید."); return;
    }
    $history = getLeaveHistory($realId);
    if (empty($history)) { editOrSend($chatId, $messageId, "📭 هنوز خروجی ثبت نشده."); return; }
    $info = getChannelInfo($realId);
    $name = ($info['title'] ?? '') ?: $realId;
    $msg = "📊 آخرین خروج‌ها از «{$name}»:\n\n";
    foreach (array_slice($history, 0, 15) as $idx => $item) {
        $num = $idx + 1;
        $msg .= "{$num}. {$item['name']} ({$item['user_id']})\n🕒 {$item['time']}\n\n";
    }
    editOrSend($chatId, $messageId, $msg);
}