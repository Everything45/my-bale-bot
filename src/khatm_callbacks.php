<?php
// src/khatm_callbacks.php
declare(strict_types=1);

function sendKhatmMenu($chatId, $userId, $messageId = null): void {
    $rows = [];
    if (!empty(getOwnerChannels($userId))) {
        $rows[] = [['text'=>'➕ ساخت ختم جدید','callback_data'=>'khatm_create']];
        $rows[] = [['text'=>'🗂 مدیریت ختم‌ها (ریست / حذف)','callback_data'=>'khatm_manage']];
    } else {
        $rows[] = [['text'=>'⚠️ ابتدا یک کانال متصل کنید','callback_data'=>'menu_connect']];
        if (isBotOwner($userId)) $rows[] = [['text'=>'🗂 مدیریت ختم‌ها (ریست / حذف)','callback_data'=>'khatm_manage']];
    }
    $rows[] = [['text'=>'🔙 بازگشت به منوی اصلی','callback_data'=>'main_menu']];
    editOrSend($chatId, $messageId, "📿 بخش ختم‌های کانال\n\nیک گزینه را انتخاب کنید:", ['inline_keyboard'=>$rows]);
}

function handleKhatmCallback(array $cb): void {
    $cbId = $cb['id'] ?? null;
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $messageId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
    $userId = $cb['from']['id'] ?? null;
    $userIdStr = (string)$userId;

    if (!$chatId || !$userId) { answerCallbackQuery($cbId); return; }

    if ($data === 'khatm_menu') { answerCallbackQuery($cbId); sendKhatmMenu($chatId, $userIdStr, $messageId); return; }

    if ($data === 'khatm_create') {
        answerCallbackQuery($cbId);
        if (empty(getOwnerChannels($userId))) {
            editOrSend($chatId, $messageId, "❌ برای ساخت ختم، ابتدا باید یک کانال به ربات متصل کنید.",
                ['inline_keyboard'=>[[['text'=>'🔙 بازگشت','callback_data'=>'khatm_menu']]]]);
            return;
        }
        clearUserState($userIdStr);
        setUserState($userIdStr, 'khatm_info', []);
        sendMessageWithKeyboard($chatId, khatmInfoPrompt(), khatmCancelKeyboard());
        return;
    }

    if ($data === 'khatm_manage') { answerCallbackQuery($cbId); sendKhatmManageList($chatId, $userId, $messageId); return; }

    if (strpos($data, 'khatm_view:') === 0) {
        answerCallbackQuery($cbId);
        sendKhatmDetails($chatId, $userId, substr($data, strlen('khatm_view:')), $messageId);
        return;
    }

    if (strpos($data, 'khatm_reset:') === 0) {
        answerCallbackQuery($cbId);
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_reset:')), $cbId, $messageId);
        if ($khatm === null) return;
        editOrSend($chatId, $messageId,
            "🔄 ریست ختم «{$khatm['title']}»\n\nمجموع و شمارش همه‌ی کاربران صفر می‌شود و قابل برگشت نیست. مطمئنید؟",
            ['inline_keyboard'=>[
                [['text'=>'✅ بله، ریست کن','callback_data'=>'khatm_reset_ok:'.$khatm['id']]],
                [['text'=>'❌ انصراف','callback_data'=>'khatm_view:'.$khatm['id']]],
            ]]);
        return;
    }

    if (strpos($data, 'khatm_reset_ok:') === 0) {
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_reset_ok:')), $cbId, $messageId);
        if ($khatm === null) return;
        answerCallbackQuery($cbId, 'در حال ریست...');
        resetKhatm($khatm['id']);
        updateChannelKhatmMessage($khatm['id'], true);
        editOrSend($chatId, $messageId,
            "✅ ختم «{$khatm['title']}» ریست شد و از صفر شروع می‌شود.",
            ['inline_keyboard'=>[
                [['text'=>'📄 جزئیات ختم','callback_data'=>'khatm_view:'.$khatm['id']]],
                [['text'=>'🔙 لیست ختم‌ها','callback_data'=>'khatm_manage']],
            ]]);
        return;
    }

    if (strpos($data, 'khatm_del:') === 0) {
        answerCallbackQuery($cbId);
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_del:')), $cbId, $messageId);
        if ($khatm === null) return;
        editOrSend($chatId, $messageId,
            "🗑 حذف ختم «{$khatm['title']}»\n\nختم و همه‌ی آمارش پاک می‌شود و پست کانال هم حذف می‌شود (اگر ربات اجازه داشته باشد). مطمئنید؟",
            ['inline_keyboard'=>[
                [['text'=>'✅ بله، حذف کن','callback_data'=>'khatm_del_ok:'.$khatm['id']]],
                [['text'=>'❌ انصراف','callback_data'=>'khatm_view:'.$khatm['id']]],
            ]]);
        return;
    }

    if (strpos($data, 'khatm_del_ok:') === 0) {
        $khatm = loadManageableKhatm($chatId, $userId, substr($data, strlen('khatm_del_ok:')), $cbId, $messageId);
        if ($khatm === null) return;
        answerCallbackQuery($cbId, 'در حال حذف...');
        performDeleteKhatm($khatm);
        editOrSend($chatId, $messageId,
            "🗑 ختم «{$khatm['title']}» حذف شد.",
            ['inline_keyboard'=>[[['text'=>'🔙 لیست ختم‌ها','callback_data'=>'khatm_manage']]]]);
        return;
    }

    if ($data === 'khatm_del_all') {
        $manageable = getManageableKhatms($userId);
        if (empty($manageable)) { answerCallbackQuery($cbId, 'شما هیچ ختمی ندارید.', true); return; }
        answerCallbackQuery($cbId);
        editOrSend($chatId, $messageId,
            "⚠️ آیا از حذف **تمام** ".count($manageable)." ختم خود مطمئن هستید؟ این عمل غیرقابل بازگشت است.",
            ['inline_keyboard'=>[
                [['text'=>'✅ بله، همه را حذف کن','callback_data'=>'khatm_del_all_ok']],
                [['text'=>'❌ انصراف','callback_data'=>'khatm_manage']]
            ]]);
        return;
    }

    if ($data === 'khatm_del_all_ok') {
        $manageable = getManageableKhatms($userId);
        answerCallbackQuery($cbId, 'در حال حذف...');
        foreach ($manageable as $k) performDeleteKhatm($k);
        editOrSend($chatId, $messageId,
            "✅ تمام ختم‌های شما با موفقیت حذف شدند.",
            ['inline_keyboard'=>[[['text'=>'🔙 بازگشت','callback_data'=>'khatm_menu']]]]);
        return;
    }

    answerCallbackQuery($cbId);
}