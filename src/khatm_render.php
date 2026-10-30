<?php
// src/khatm_render.php
declare(strict_types=1);

function khatmEditWindow(): int {
    return function_exists('fastcgi_finish_request') ? KHATM_EDIT_THROTTLE_SECONDS : 1;
}

function renderChannelKhatmPost(string $khatmId): void {
    $khatm = getKhatm($khatmId);
    if (!$khatm || empty($khatm['channel_id']) || empty($khatm['channel_msg_id'])) return;

    $botUsername = getBotUsername();
    if (!$botUsername) return;

    $keyboard = buildKhatmChannelKeyboard($khatm, $botUsername);
    $caption = buildKhatmCaption($khatm);

    if (($khatm['msg_type'] ?? 'text') === 'photo') {
        apiPost('editMessageCaption', [
            'chat_id'=>$khatm['channel_id'],'message_id'=>$khatm['channel_msg_id'],
            'caption'=>$caption,'reply_markup'=>$keyboard,
        ]);
    } else {
        apiPost('editMessageText', [
            'chat_id'=>$khatm['channel_id'],'message_id'=>$khatm['channel_msg_id'],
            'text'=>$caption,'reply_markup'=>$keyboard,
        ]);
    }
}

function updateChannelKhatmMessage(string $khatmId, bool $force = false): void {
    $lockKey = "khatm_edit_lock:{$khatmId}";
    $dirtyKey = "khatm_dirty:{$khatmId}";
    $window = khatmEditWindow();

    if ($force) { redisDel($dirtyKey); renderChannelKhatmPost($khatmId); return; }

    if (!redisAcquireLock($lockKey, $window)) { redisSet($dirtyKey, '1', 30); return; }

    redisDel($dirtyKey);
    renderChannelKhatmPost($khatmId);

    flushResponseEarly();
    usleep($window * 1000000 + 100000);

    if (redisGet($dirtyKey) !== null) {
        redisDel($dirtyKey);
        renderChannelKhatmPost($khatmId);
    }
}