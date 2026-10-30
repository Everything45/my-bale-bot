<?php
// src/callbacks.php
declare(strict_types=1);

function handleCallbackQuery(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $chatType = $cb['message']['chat']['type'] ?? 'private';
    $messageId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
    $userId = $cb['from']['id'] ?? null;

    if (!$chatId || !$userId) { answerCallbackQuery($cbId); return; }
    $userIdStr = (string)$userId;

    // ─── ⭐ بازگشت به منوی اصلی ───
    if ($data === 'main_menu') {
        answerCallbackQuery($cbId);
        $state = getUserState($userIdStr);
        if ($state !== null && strpos($state, 'khatm_') === 0) {
            clearUserState($userIdStr);
        }
        sendStartMenu($chatId);
        return;
    }

    // ─── خروج از لینک ناشناس ───
    if ($data === 'anon_exit') {
        clearUserState($userIdStr);
        answerCallbackQuery($cbId, 'خارج شدید.');
        editOrSend($chatId, $messageId, "✅ از حالت پیام ناشناس خارج شدید.", ['inline_keyboard' => []]);
        return;
    }

    // ─── خروج از چت AI ───
    if ($data === 'ai_exit') {
        clearUserState($userIdStr);
        clearAiChatHistory($userIdStr);
        answerCallbackQuery($cbId, 'از چت AI خارج شدید.');
        editOrSend($chatId, $messageId, "✅ از چت AI خارج شدید.", ['inline_keyboard' => []]);
        return;
    }

    if (strpos($data, 'khatm_count:') === 0) {
        $parts = explode(':', $data);
        if (count($parts) !== 3 || !preg_match('/^khatm_[A-Za-z0-9]+$/', $parts[1])) { answerCallbackQuery($cbId); return; }
        $khatmId = $parts[1];
        $amount = (int)$parts[2];
        $khatm = getKhatm($khatmId);

        if (!$khatm) { answerCallbackQuery($cbId, '❌ این ختم پیدا نشد (ممکن است حذف شده باشد).', true); return; }

        $allowed = array_map('intval', $khatm['buttons'] ?? []);
        if ($amount <= 0 || !in_array($amount, $allowed, true)) { answerCallbackQuery($cbId, '❌ مقدار نامعتبر.', true); return; }

        if (isKhatmComplete($khatm)) {
            answerCallbackQuery($cbId, '✅ این ختم تکمیل شده است.', true);
            editOrSend($chatId, $messageId,
                buildKhatmPrivateText($khatm, null, getKhatmUserTotal($khatmId, (string)$userId)),
                buildKhatmPrivateKeyboard($khatm));
            return;
        }

        answerCallbackQuery($cbId, "✅ {$amount} ذکر ثبت شد.");

        $userLockKey = "khatm_user_lock:{$khatmId}:{$userId}";
        $hasLock = redisWaitLock($userLockKey, 5, 3000);

        $fresh = getKhatm($khatmId);
        if (!$fresh) { if ($hasLock) redisDel($userLockKey); return; }
        if (isKhatmComplete($fresh)) {
            if ($hasLock) redisDel($userLockKey);
            editOrSend($chatId, $messageId,
                buildKhatmPrivateText($fresh, null, getKhatmUserTotal($khatmId, (string)$userId)),
                buildKhatmPrivateKeyboard($fresh));
            return;
        }

        $applied = min($amount, khatmRemaining($fresh));
        $mine = addKhatmContribution($khatmId, (string)$userId, $applied);
        $newKhatm = getKhatm($khatmId);
        if ($hasLock) redisDel($userLockKey);

        if ($newKhatm) {
            editOrSend($chatId, $messageId,
                buildKhatmPrivateText($newKhatm, "✅ {$applied} ذکر ثبت شد.", $mine),
                buildKhatmPrivateKeyboard($newKhatm));
            updateChannelKhatmMessage($khatmId, isKhatmComplete($newKhatm));
        }
        return;
    }

    if ($data === 'khatm_refresh') { answerCallbackQuery($cbId); return; }

    if ($data === 'show_sponsor_channel') {
        $sponsorText = getSponsorText();
        $sponsorText = mb_substr($sponsorText, 0, 200);
        answerCallbackQuery($cbId, $sponsorText, true);
        return;
    }

    if ($data === 'check_force_join') {
        clearForceCheckCache($userId);
        $check = checkUserForceJoin($userId, false);
        if ($check['ok']) {
            answerCallbackQuery($cbId, '✅ عضویت تایید شد.', false);
            $pendingAnon = redisGet("pending_anon:{$userId}");
            if ($pendingAnon !== null && $pendingAnon !== '') {
                redisDel("pending_anon:{$userId}");
                handleAnonStart($chatId, $userId, $pendingAnon);
            } else {
                sendStartMenu($chatId);
            }
        } else {
            answerCallbackQuery($cbId, '❌ هنوز عضو همه‌ی کانال‌ها نشدید.', true);
        }
        return;
    }

    if ($data === 'khatm_cancel') {
        clearUserState($userIdStr);
        answerCallbackQuery($cbId, 'لغو شد.');
        editOrSend($chatId, $messageId, "❌ ساخت ختم لغو شد.", khatmBackKeyboard());
        return;
    }

    if (strpos($data, 'khatm_channel:') === 0) {
        if ($chatType !== 'private') { answerCallbackQuery($cbId); return; }
        $chId = substr($data, strlen('khatm_channel:'));
        $owner = getChannelOwner($chId);
        if ($owner === null || ((string)$owner !== (string)$userId && !isBotOwner($userId))) {
            answerCallbackQuery($cbId, '⛔ این کانال متعلق به شما نیست (یا دیگر ثبت نیست).', true);
            return;
        }
        answerCallbackQuery($cbId, 'در حال ارسال...');
        sendKhatmChannelPreview($chatId, $userId, $chId);
        return;
    }

    if ($chatType !== 'private') { answerCallbackQuery($cbId); return; }

    if ($data !== 'menu_bug') {
        $check = checkUserForceJoin($userId, false);
        if (!$check['ok']) {
            answerCallbackQuery($cbId, '⚠️ اول تو کانال‌های معرفی‌شده عضو شوید.', true);
            sendForceJoinPrompt($chatId, $check['missing']);
            return;
        }
    }

    if (strpos($data, 'khatm_') === 0) {
        handleKhatmCallback($cb);
        return;
    }

    // ─── لینک ناشناس ───
    if ($data === 'anon_link' || $data === 'anon_regen' || $data === 'anon_inbox' || $data === 'anon_inbox_clear') {
        handleAnonCallback($cb);
        return;
    }

    answerCallbackQuery($cbId);

    switch ($data) {
        case 'menu_connect':
            editOrSend($chatId, $messageId, getConnectText(),
                ['inline_keyboard'=>[[['text'=>'🔙 بازگشت به منوی اصلی','callback_data'=>'main_menu']]]]);
            break;
        case 'menu_mylist':  handleMyList($chatId, $userId, $messageId); break;
        case 'menu_help':    editOrSend($chatId, $messageId, getHelpText()); break;
        case 'menu_sponsor': editOrSend($chatId, $messageId, "🌟 اسپانسر ربات:\n\n" . getSponsorText()); break;
        case 'menu_bug':     editOrSend($chatId, $messageId, "🐞 گزارش باگ\n\nمتن مشکل رو بعد از این دستور بنویسید و بفرستید:\n\n/bug"); break;
    }
}