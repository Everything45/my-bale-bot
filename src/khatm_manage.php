<?php
// src/khatm_manage.php
declare(strict_types=1);

function canManageKhatm(array $khatm, $userId): bool {
    if (isBotOwner($userId)) return true;
    if ((string)($khatm['owner_id'] ?? '') === (string)$userId) return true;
    $chOwner = getChannelOwner((string)($khatm['channel_id'] ?? ''));
    return $chOwner !== null && $chOwner === (string)$userId;
}

function getManageableKhatms($userId): array {
    $list = [];
    foreach (getAllKhatms() as $k) if (canManageKhatm($k, $userId)) $list[] = $k;
    return $list;
}

function loadManageableKhatm($chatId, $userId, string $khatmId, $cbId = null, $messageId = null): ?array {
    $back = ['inline_keyboard'=>[[['text'=>'🔙 لیست ختم‌ها','callback_data'=>'khatm_manage']]]];
    if (!preg_match('/^khatm_[A-Za-z0-9]+$/', $khatmId)) {
        if ($cbId) answerCallbackQuery($cbId);
        editOrSend($chatId, $messageId, "❌ شناسه‌ی ختم نامعتبر است.", $back);
        return null;
    }
    $khatm = getKhatm($khatmId);
    if ($khatm === null) {
        if ($cbId) answerCallbackQuery($cbId);
        editOrSend($chatId, $messageId, "❌ این ختم دیگر وجود ندارد.", $back);
        return null;
    }
    if (!canManageKhatm($khatm, $userId)) {
        if ($cbId) answerCallbackQuery($cbId, '⛔ دسترسی ندارید.', true);
        else editOrSend($chatId, $messageId, "⛔ شما دسترسی مدیریت این ختم را ندارید.", $back);
        return null;
    }
    return $khatm;
}

function sendKhatmManageList($chatId, $userId, $messageId = null): void {
    $list = array_slice(getManageableKhatms($userId), 0, 20);
    if (empty($list)) {
        editOrSend($chatId, $messageId, "📭 هنوز ختمی ندارید.",
            ['inline_keyboard'=>[[['text'=>'🔙 بازگشت','callback_data'=>'khatm_menu']]]]);
        return;
    }
    $rows = [];
    foreach ($list as $k) {
        $label = mb_substr((string)$k['title'], 0, 28) . " ({$k['total_done']}/{$k['target']})";
        $rows[] = [['text'=>$label,'callback_data'=>'khatm_view:'.$k['id']]];
    }
    $rows[] = [['text'=>'🗑 حذف همه ختم‌ها','callback_data'=>'khatm_del_all']];
    $rows[] = [['text'=>'🔙 بازگشت','callback_data'=>'khatm_menu']];
    editOrSend($chatId, $messageId, "🗂 ختم‌های شما\n\nیکی را برای ریست یا حذف انتخاب کنید:", ['inline_keyboard'=>$rows]);
}

function sendKhatmDetails($chatId, $userId, string $khatmId, $messageId = null): void {
    $khatm = loadManageableKhatm($chatId, $userId, $khatmId, null, $messageId);
    if ($khatm === null) return;

    $info = getChannelInfo((string)($khatm['channel_id'] ?? ''));
    $chName = $info !== null && ($info['title'] ?? '') !== '' ? $info['title'] : (string)($khatm['channel_id'] ?? '-');
    $contributors = count(getKhatmContributorIds($khatm['id']));

    $text = "📿 " . $khatm['title'] . "\n\n"
          . "🎯 هدف: " . $khatm['target'] . "\n"
          . "✅ ثبت‌شده: " . $khatm['total_done'] . "\n"
          . "⏳ مانده: " . khatmRemaining($khatm) . "\n"
          . "👥 تعداد مشارکت‌کنندگان: " . $contributors . "\n"
          . "📢 کانال: " . $chName . "\n"
          . "🆔 " . $khatm['id'];

    editOrSend($chatId, $messageId, $text, ['inline_keyboard'=>[
        [['text'=>'🔄 ریست شمارش','callback_data'=>'khatm_reset:'.$khatm['id']]],
        [['text'=>'🗑 حذف ختم','callback_data'=>'khatm_del:'.$khatm['id']]],
        [['text'=>'🔙 لیست ختم‌ها','callback_data'=>'khatm_manage']],
    ]]);
}

function performDeleteKhatm(array $khatm): void {
    $chId = (string)($khatm['channel_id'] ?? '');
    $msgId = $khatm['channel_msg_id'] ?? null;

    if ($chId !== '' && $msgId) {
        if (!deleteMessageSafe($chId, $msgId)) {
            $payload = ['chat_id'=>$chId,'message_id'=>$msgId,'reply_markup'=>['inline_keyboard'=>[]]];
            if (($khatm['msg_type'] ?? 'text') === 'photo') {
                $payload['caption'] = "🗑 این ختم حذف شد.";
                apiPost('editMessageCaption', $payload);
            } else {
                $payload['text'] = "🗑 این ختم حذف شد.";
                apiPost('editMessageText', $payload);
            }
        }
    }
    deleteKhatm($khatm['id']);
}