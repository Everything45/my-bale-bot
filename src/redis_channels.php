<?php
// src/redis_channels.php
declare(strict_types=1);

function setChannelOwner($channelId, $ownerId, string $title = '', string $username = '', string $type = ''): void {
    $channelIdStr = (string)$channelId;
    $ownerIdStr = (string)$ownerId;
    $info = json_encode([
        'owner_id'=>$ownerIdStr,'title'=>$title,'username'=>$username,'type'=>$type,
        'updated_at'=>date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    redisSet("channel_owner:{$channelIdStr}", $ownerIdStr);
    redisSet("channel_info:{$channelIdStr}", $info);
    redisSAdd('all_channels_set', $channelIdStr);
    redisHSet('all_channels_map', $channelIdStr, $info);
}

function getChannelOwner($channelId): ?string {
    $v = redisGet("channel_owner:" . (string)$channelId);
    return ($v === null || $v === '') ? null : $v;
}
function getChannelInfo($channelId): ?array {
    $id = (string)$channelId;
    $owner = getChannelOwner($id);
    if ($owner === null) return null;
    $raw = redisGet("channel_info:{$id}");
    $info = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($info)) $info = ['title'=>'', 'username'=>''];
    $info['owner_id'] = $owner;
    return $info;
}

function getAllChannelsMap(): array {
    $ids = [];
    foreach (redisSMembers('all_channels_set') as $id) $ids[(string)$id] = true;
    foreach (redisHKeys('all_channels_map') as $id) $ids[(string)$id] = true;
    $ids = array_keys($ids);
    if (empty($ids)) return [];

    $keys = [];
    foreach ($ids as $id) { $keys[] = "channel_owner:{$id}"; $keys[] = "channel_info:{$id}"; }
    $vals = redisMGet($keys);
    if ($vals === null) return [];

    $result = []; $i = 0;
    foreach ($ids as $id) {
        $owner = $vals[$i++] ?? null;
        $infoRaw = $vals[$i++] ?? null;
        if ($owner === null || $owner === '') {
            redisSRem('all_channels_set', (string)$id);
            redisHDel('all_channels_map', (string)$id);
            redisDel("channel_info:{$id}");
            continue;
        }
        $info = is_string($infoRaw) ? json_decode($infoRaw, true) : null;
        if (!is_array($info)) $info = ['title'=>'', 'username'=>''];
        $info['owner_id'] = (string)$owner;
        $result[(string)$id] = $info;
    }
    return $result;
}

function removeChannel($channelId): bool {
    $id = (string)$channelId;
    $existed = getChannelOwner($id) !== null;
    redisDel("channel_owner:{$id}");
    redisDel("channel_info:{$id}");
    redisDel("leave_history:{$id}");
    redisDel("channel_active_khatm:{$id}");
    redisHDel('all_channels_map', $id);
    redisSRem('all_channels_set', $id);
    return $existed;
}
function getOwnerChannels($ownerId): array {
    $ownerIdStr = (string)$ownerId;
    $result = [];
    foreach (getAllChannelsMap() as $chId => $info) {
        if ((string)($info['owner_id'] ?? '') === $ownerIdStr) $result[$chId] = $info;
    }
    return $result;
}

function saveLeaveHistory($channelId, array $record): void {
    redisLPushTrim('leave_history:' . (string)$channelId, json_encode($record, JSON_UNESCAPED_UNICODE), 50);
}
function getLeaveHistory($channelId): array {
    $raw = redisLRange('leave_history:' . (string)$channelId, 0, 49);
    $result = [];
    foreach ($raw as $item) {
        if (is_array($item)) { $result[] = $item; continue; }
        $decoded = json_decode((string)$item, true);
        if (is_array($decoded)) $result[] = $decoded;
    }
    return $result;
}