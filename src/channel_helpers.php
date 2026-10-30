<?php
// src/channel_helpers.php
declare(strict_types=1);

function resolveChannelId(string $target): ?string {
    $ref = normalizeChannelRef($target);
    if ($ref === null) return null;
    $chatRes = apiPost('getChat', ['chat_id'=>$ref]);
    if (!apiOk($chatRes)) return null;
    return (string)$chatRes['result']['id'];
}

function findOwnerChannel($userId, string $arg): ?string {
    $channels = isBotOwner($userId) ? getAllChannelsMap() : getOwnerChannels($userId);
    if (empty($channels)) return null;

    $ref = normalizeChannelRef($arg);
    $needle = ($ref !== null) ? mb_strtolower(ltrim($ref, '@')) : '';

    foreach ($channels as $id => $info) {
        if ($ref !== null && (string)$id === $ref) return (string)$id;
        $u = mb_strtolower(ltrim((string)($info['username'] ?? ''), '@'));
        if ($needle !== '' && $u !== '' && $u === $needle) return (string)$id;
    }
    $title = trim($arg);
    if ($title !== '') {
        foreach ($channels as $id => $info) {
            if (trim((string)($info['title'] ?? '')) === $title) return (string)$id;
        }
    }
    return null;
}

function refreshChannelInfo(string $chId, array $info): ?array {
    $res = apiPost('getChat', ['chat_id'=>$chId]);
    if ($res === null) return $info;

    if (empty($res['ok'])) {
        $desc = mb_strtolower((string)($res['description'] ?? ''));
        $code = (int)($res['error_code'] ?? 0);
        $gone = $code === 404;
        foreach (['not found','kicked','forbidden','deactivated','chat not','bot was'] as $needle) {
            if ($desc !== '' && strpos($desc, $needle) !== false) { $gone = true; break; }
        }
        if ($gone) { removeChannel($chId); return null; }
        return $info;
    }

    $botId = getBotId();
    if ($botId !== null) {
        $m = apiPost('getChatMember', ['chat_id'=>$chId,'user_id'=>$botId]);
        if (apiOk($m)) {
            $st = $m['result']['status'] ?? '';
            if (in_array($st, ['left','kicked'], true)) { removeChannel($chId); return null; }
        }
    }

    $chat = $res['result'];
    $newTitle = (string)($chat['title'] ?? ($info['title'] ?? ''));
    $newUser = isset($chat['username']) ? '@'.$chat['username'] : '';
    $type = (string)($chat['type'] ?? ($info['type'] ?? ''));
    if ($newTitle !== (string)($info['title'] ?? '') || $newUser !== (string)($info['username'] ?? '')) {
        setChannelOwner($chId, (string)$info['owner_id'], $newTitle, $newUser, $type);
        $info['title'] = $newTitle;
        $info['username'] = $newUser;
    }
    return $info;
}