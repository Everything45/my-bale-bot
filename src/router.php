<?php
// src/router.php
declare(strict_types=1);

function handleTextMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? null;
    $chatType = $message['chat']['type'] ?? 'private';
    $userId = $message['from']['id'] ?? null;
    $text = trim($message['text'] ?? '');
    $stateText = trim($message['text'] ?? ($message['caption'] ?? ''));

    if (!$chatId || !$userId || ($text === '' && !isset($message['photo']))) return;
    if ($chatType !== 'private' && strpos($text, '/') !== 0) return;

    $userIdStr = (string)$userId;

    $replyButtonMap = [
        '➕ اتصال آنفالویاب کانال' => 'connect', '➕ اتصال آنفالویاب کانال جدید' => 'connect',
        '📋 کانال‌های من' => 'mylist', '📖 راهنما' => 'help',
        '📖 راهنمای استفاده' => 'help', '🌟 اسپانسر' => 'sponsor',
        '🌟 اسپانسر ما' => 'sponsor', '🐞 گزارش باگ' => 'bug',
        '📿 ختم‌ها' => 'khatm',
        '🤖 چت AI' => 'ai',
        '🔍 استخراج اطلاعات آیدی من' => 'myid',
        '🔗 لینک ناشناس من' => 'anon',
        '🔙 بازگشت به منوی اصلی' => 'back_to_main'
    ];

    // ─── حالت چت AI ───
    if ($chatType === 'private' && getUserState($userIdStr) === 'ai_chat') {
        if (strpos($text, '/') === 0 || isset($replyButtonMap[$text])) {
            clearUserState($userIdStr);
        } else {
            handleAiChat($chatId, $userId, $stateText);
            return;
        }
    }

    // ─── حالت ارسال پیام ناشناس (فرستنده) ───
    // ─── حالت ارسال پیام ناشناس (فرستنده) ───
    if ($chatType === 'private' && getUserState($userIdStr) === 'anon_send') {
        if (strpos($text, '/') === 0 || isset($replyButtonMap[$text])) {
            clearUserState($userIdStr);
        } else {
            handleAnonSend($chatId, $userId, $stateText, (int)($message['message_id'] ?? 0));
            return;
        }
    }

    // ─── ⭐ حالت پاسخ به پیام ناشناس (مالک) ───
    if ($chatType === 'private' && getUserState($userIdStr) === 'anon_reply') {
        if (strpos($text, '/') === 0 || isset($replyButtonMap[$text])) {
            clearUserState($userIdStr);
        } else {
            handleAnonReply($chatId, $userId, $stateText);
            return;
        }
    }

    // ─── حالت ساخت ختم ───
    if ($chatType === 'private') {
        $userState = getUserState($userIdStr);
        if ($userState !== null && strpos($userState, 'khatm_') === 0) {
            if (strpos($text, '/') === 0 || isset($replyButtonMap[$text])) {
                abortKhatmFlow($chatId, $userIdStr);
            } else {
                handleKhatmState($chatId, $userId, $userState, $stateText, $message);
                return;
            }
        }
    }

    if (isset($replyButtonMap[$text])) {
        $forceCheck = checkUserForceJoin($userId, false);
        if (!$forceCheck['ok']) { sendForceJoinPrompt($chatId, $forceCheck['missing']); return; }

        if ($replyButtonMap[$text] === 'back_to_main') { sendStartMenu($chatId); return; }

        switch ($replyButtonMap[$text]) {
            case 'connect': sendMessageWithKeyboard($chatId, getConnectText()); break;
            case 'mylist':  handleMyList($chatId, $userId); break;
            case 'help':    sendMessageWithKeyboard($chatId, getHelpText()); break;
            case 'sponsor': sendMessageWithKeyboard($chatId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
            case 'bug':     sendMessageWithKeyboard($chatId, "🐞 گزارش باگ\n\nمتن مشکل رو بعد از این دستور بنویسید و بفرستید:\n\n/bug"); break;
            case 'khatm':
                if ($chatType !== 'private') { sendMessageWithKeyboard($chatId, "📿 مدیریت ختم‌ها را داخل چت خصوصی ربات انجام دهید."); break; }
                sendKhatmMenu($chatId, $userId);
                break;
            case 'ai':
                clearAiChatHistory($userIdStr);
                setUserState($userIdStr, 'ai_chat');
                sendMessageWithKeyboard(
                    $chatId,
                    "🤖 به چت AI خوش آمدید!\n\nهر سوالی دارید بپرسید. برای خروج /end یا دکمه زیر را بزنید.",
                    ['inline_keyboard' => [[['text' => '❌ خروج از چت AI', 'callback_data' => 'ai_exit']]]]
                );
                break;
            case 'myid':
                $fname = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')) ?: 'بدون نام';
                $uname = (string)($message['from']['username'] ?? '');
                $numId = (string)$userId;
                $msg = "🆔 اطلاعات آیدی شما:\n\n"
                     . "📛 نام: {$fname}\n"
                     . "🔖 یوزرنیم: " . ($uname !== '' ? '@' . $uname : 'ندارد') . "\n"
                     . "🔢 آیدی عددی: {$numId}\n\n"
                     . "💡 این آیدی عددی را می‌توانید برای اتصال گروه/کانال خصوصی با دستور زیر استفاده کنید.\n"
                     . " /add {$numId}\n";
                sendMessageWithKeyboard($chatId, $msg);
                break;
            case 'anon':
                sendAnonMenu($chatId, $userId);
                break;
        }
        return;
    }

    [$cmd, $arg] = parseCommand($text);
    if ($cmd === null) return;

    if ($cmd === 'start' && strpos((string)$arg, 'anon_') === 0) {
        if ($chatType !== 'private') {
            sendMessageWithKeyboard($chatId, "❌ لینک ناشناس فقط در چت خصوصی ربات کار می‌کند.");
            return;
        }
        redisSet("pending_anon:{$userIdStr}", substr((string)$arg, 5), 600);
    }

    $resetForce = ($cmd === 'start');
    $skipForceCheck = in_array($cmd, ['help','bug','status'], true);
    if ($cmd === 'start' && strpos((string)$arg, 'khatm_') === 0) $skipForceCheck = true;

    if (!$skipForceCheck) {
        $forceCheck = checkUserForceJoin($userId, $resetForce);
        if (!$forceCheck['ok']) { sendForceJoinPrompt($chatId, $forceCheck['missing']); return; }
    }

    switch ($cmd) {
        case 'start':
            if (strpos((string)$arg, 'anon_') === 0) {
                $code = substr((string)$arg, 5);
                redisDel("pending_anon:{$userIdStr}");
                handleAnonStart($chatId, $userId, $code);
                return;
            }
            if (strpos((string)$arg, 'khatm_') === 0) {
                $khatmId = (string)$arg;
                $khatm = preg_match('/^khatm_[A-Za-z0-9]+$/', $khatmId) ? getKhatm($khatmId) : null;
                if ($khatm) {
                    $mine = getKhatmUserTotal($khatmId, $userIdStr);
                    sendMessageWithKeyboard($chatId, buildKhatmPrivateText($khatm, null, $mine), buildKhatmPrivateKeyboard($khatm));
                    return;
                }
                sendMessageWithKeyboard($chatId, "❌ این ختم پیدا نشد یا حذف شده است.");
            }
            sendStartMenu($chatId);
            break;

        case 'help':   sendMessageWithKeyboard($chatId, getHelpText()); break;

        case 'status':
            $msg = "🤖 ربات آنفالویاب: آنلاین\n🕒 " . date('Y-m-d H:i:s');
            if (isBotOwner($userId)) $msg .= "\n🗄 Redis: ✅\n📦 استخر: " . count(getForceChannelsPool());
            sendMessageWithKeyboard($chatId, $msg);
            break;

        case 'add':
        case 'connect': handleConnect($chatId, $userId, (string)$arg, (string)$chatType); break;
        case 'my':      handleMyList($chatId, $userId); break;
        case 'remove':  handleRemoveChannel($chatId, $userId, (string)$arg); break;
        case 'history': handleHistory($chatId, $userId, (string)$arg); break;

        case 'khatms':
        case 'khatm':
            if ($chatType !== 'private') { sendMessageWithKeyboard($chatId, "📿 مدیریت ختم‌ها را داخل چت خصوصی ربات انجام دهید."); break; }
            sendKhatmManageList($chatId, $userId);
            break;

        case 'ai':
            if ($chatType !== 'private') { sendMessageWithKeyboard($chatId, "🤖 چت AI فقط در چت خصوصی ربات در دسترس است."); break; }
            clearAiChatHistory($userIdStr);
            setUserState($userIdStr, 'ai_chat');
            sendMessageWithKeyboard(
                $chatId,
                "🤖 به چت AI خوش آمدید!\n\nهر سوالی دارید بپرسید. برای خروج /end یا دکمه زیر را بزنید.",
                ['inline_keyboard' => [[['text' => '❌ خروج از چت AI', 'callback_data' => 'ai_exit']]]]
            );
            break;

        case 'bug':
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ متن مشکل رو بعد از bug بنویسید."); return; }
            if (BOT_OWNER_ID === '') { sendMessageWithKeyboard($chatId, "❌ دریافت گزارش غیرفعاله."); return; }
            $senderName = trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')) ?: 'بدون نام';
            $senderUsername = $message['from']['username'] ?? '';
            $bugText = "🐞 گزارش باگ\n\n👤 ".$senderName."\n🔖 ".($senderUsername !== '' ? '@'.$senderUsername : 'ندارد')."\n🔢 ".$userId."\n━━━━━━━━━━━━━━\n".$arg;
            sendPlainText(BOT_OWNER_ID, $bugText);
            sendMessageWithKeyboard($chatId, "✅ گزارش ارسال شد.");
            break;

        case 'zk9xad':
            if (!isBotOwner($userId)) return;
            sendMessageWithKeyboard($chatId, getAdminHelpText()); break;
        case 'zk9xch':
            if (!isBotOwner($userId)) return;
            $all = getAllChannelsMap();
            if (empty($all)) { sendMessageWithKeyboard($chatId, "📭 خالی است."); return; }
            $msg = "👑 کل کانال‌ها (" . count($all) . "):\n\n";
            foreach ($all as $chId => $info) {
                $fresh = refreshChannelInfo((string)$chId, $info);
                if ($fresh === null) continue;
                $msg .= "🔹 ".(($fresh['title'] ?? '') ?: 'بدون نام')."\nآیدی: {$chId}\nمالک: {$fresh['owner_id']}\n\n";
            }
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xfa':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بنویسید. مثال:\n/zk9xfa mychannel"); return; }
            $check = checkBotIsAdminInChannel((string)$arg);
            if (!$check['ok'] || !addForceChannelToPool((string)normalizeChannelRef((string)$arg))) { sendMessageWithKeyboard($chatId, "❌ خطا. اول ربات رو ادمین کنید."); return; }
            sendMessageWithKeyboard($chatId, "✅ اضافه شد.");
            break;
        case 'zk9xfd':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ آیدی کانال رو بنویسید. مثال:\n/zk9xfd mychannel"); return; }
            $ref = normalizeChannelRef((string)$arg) ?? (string)$arg;
            sendMessageWithKeyboard($chatId, removeForceChannelFromPool($ref) ? "🗑 حذف شد." : "❌ پیدا نشد.");
            break;
        case 'zk9xfl':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            if (empty($pool)) { sendMessageWithKeyboard($chatId, "📭 استخر خالیه."); return; }
            $msg = "📢 استخر (" . count($pool) . "):\n\n";
            foreach ($pool as $idx => $ch) $msg .= ($idx+1).". ".$ch."\n";
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xst':
            if (!isBotOwner($userId)) return;
            $pool = getForceChannelsPool();
            $msg = "🔍 دیباگ (" . count($pool) . " کانال):\n\n";
            foreach ($pool as $idx => $ch) {
                $c = checkBotIsAdminInChannel($ch);
                $msg .= ($idx+1).". ".$ch.($c['ok'] ? " ✅" : " ❌ ({$c['error']})")."\n";
            }
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xsp':
            if (!isBotOwner($userId)) return;
            if ($arg === '') { sendMessageWithKeyboard($chatId, "❌ متن اسپانسر رو بنویسید."); return; }
            setSponsorText((string)$arg);
            sendMessageWithKeyboard($chatId, "✅ اسپانسر بروزرسانی شد.");
            break;
        case 'zk9xkl':
            if (!isBotOwner($userId)) return;
            $all = getAllKhatms();
            if (empty($all)) { sendMessageWithKeyboard($chatId, "📭 هیچ ختمی وجود ندارد."); return; }
            $msg = "📿 همه‌ی ختم‌ها (" . count($all) . "):\n\n";
            foreach ($all as $k) {
                $msg .= "🔹 ".$k['title']."\n{$k['total_done']}/{$k['target']} | کانال: ".($k['channel_id'] ?? '-')."\n".$k['id']."\n\n";
            }
            sendMessageWithKeyboard($chatId, $msg);
            break;
        case 'zk9xcd':
    if (!isBotOwner($userId)) return;
    if ($arg === '') {
        sendMessageWithKeyboard($chatId,
            "❌ آیدی/یوزرنیم/عنوان کانال را بعد از دستور بنویسید.\n\n"
            . "مثال‌ها:\n"
            . "/zk9xcd mychannel\n"
            . "/zk9xcd -1001234567890\n"
            . "/zk9xcd کانال تست\n\n"
            . "💡 لیست کامل کانال‌ها: /zk9xch");
        return;
    }
    $realId = findOwnerChannel($userId, (string)$arg) ?? resolveChannelId((string)$arg);
    if ($realId === null) {
        sendMessageWithKeyboard($chatId, "❌ کانال پیدا نشد.\n\nآیدی معتبر را از /zk9xch بردارید.");
        return;
    }
    $chOwner = getChannelOwner($realId);
    if ($chOwner === null) {
        sendMessageWithKeyboard($chatId, "❌ این کانال در ربات ثبت نیست.");
        return;
    }
    $chInfo = getChannelInfo($realId);
    $chName = ($chInfo['title'] ?? '') ?: $realId;
    removeChannel($realId);

    // ⭐ اطلاع به مالک کانال (کاربری که کانال را متصل کرده)
    sendPlainText(
        $chOwner,
        "⚠️ کانال «{$chName}» توسط مدیر ربات از لیست حذف شد.\n\n"
        . "💡 اگر مایل به اتصال مجدد هستید، ربات را ادمین کنید و /add بزنید."
    );

    sendMessageWithKeyboard($chatId,
        "🗑 کانال «{$chName}» حذف شد.\n"
        . "👤 مالک: {$chOwner}\n"
        . "✅ پیام اطلاع‌رسانی برای مالک ارسال شد.");
    break;
        case 'zk9xkr':
        case 'zk9xkd':
            if (!isBotOwner($userId)) return;
            $khatmId = trim((string)$arg);
            if (!preg_match('/^khatm_[A-Za-z0-9]+$/', $khatmId)) {
                sendMessageWithKeyboard($chatId, "❌ شناسه‌ی ختم را بنویسید. مثال:\n/{$cmd} khatm_abc123");
                return;
            }
            $khatm = getKhatm($khatmId);
            if ($khatm === null) { sendMessageWithKeyboard($chatId, "❌ ختم پیدا نشد."); return; }
            if ($cmd === 'zk9xkr') {
                resetKhatm($khatmId);
                updateChannelKhatmMessage($khatmId, true);
                sendMessageWithKeyboard($chatId, "✅ ختم «{$khatm['title']}» ریست شد.");
            } else {
                performDeleteKhatm($khatm);
                sendMessageWithKeyboard($chatId, "🗑 ختم «{$khatm['title']}» حذف شد.");
            }
            break;
    }
}
