<?php
declare(strict_types=1);

function redisCommand(string $path): ?string {
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
        error_log('[Redis] REDIS_URL or REDIS_TOKEN is empty');
        return null;
    }
    $url = rtrim(REDIS_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . REDIS_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('[Redis] curl failed: ' . curl_error($ch) . ' | path=' . $path);
        return null;
    }
    return $res;
}

function redisParseResult(?string $res): mixed {
    if ($res === null) {
        return null;
    }
    $json = json_decode($res, true);
    if (!is_array($json) || !array_key_exists('result', $json)) {
        return null;
    }
    return $json['result'];
}

function redisSet(string $key, string $value, ?int $ttl = null): void {
    $path = '/set/' . rawurlencode($key) . '/' . rawurlencode($value);
    if ($ttl !== null) {
        $path .= '?EX=' . $ttl;
    }
    redisCommand($path);
}

function redisGet(string $key): ?string {
    $result = redisParseResult(redisCommand('/get/' . rawurlencode($key)));
    return $result === null ? null : (string)$result;
}

function redisDel(string $key): void {
    redisCommand('/del/' . rawurlencode($key));
}

function redisHSet(string $key, string $field, string $value): void {
    redisCommand('/hset/' . rawurlencode($key) . '/' . rawurlencode($field) . '/' . rawurlencode($value));
}

function redisHGetAll(string $key): array {
    $result = redisParseResult(redisCommand('/hgetall/' . rawurlencode($key)));
    if (!is_array($result)) {
        return [];
    }
    $isFlat = !empty($result) && array_keys($result) === range(0, count($result) - 1);
    if (!$isFlat) {
        return $result;
    }
    $pairs = [];
    $count = count($result);
    for ($i = 0; $i + 1 < $count; $i += 2) {
        $pairs[(string)$result[$i]] = (string)$result[$i + 1];
    }
    return $pairs;
}

function redisHDel(string $key, string $field): void {
    redisCommand('/hdel/' . rawurlencode($key) . '/' . rawurlencode($field));
}

function redisLPushTrim(string $key, string $value, int $max = 50): void {
    redisCommand('/lpush/' . rawurlencode($key) . '/' . rawurlencode($value));
    redisCommand('/ltrim/' . rawurlencode($key) . '/0/' . ($max - 1));
}

function redisLRange(string $key, int $start = 0, int $stop = 49): array {
    $result = redisParseResult(redisCommand('/lrange/' . rawurlencode($key) . '/' . $start . '/' . $stop));
    return is_array($result) ? $result : [];
}

function redisSAdd(string $key, string $member): bool {
    $res = redisCommand('/sadd/' . rawurlencode($key) . '/' . rawurlencode($member));
    if ($res === null) {
        error_log('[Redis] SADD null response for key=' . $key . ' member=' . $member);
        return false;
    }
    $json = json_decode($res, true);
    if (!is_array($json) || !array_key_exists('result', $json)) {
        error_log('[Redis] SADD failed: ' . substr($res, 0, 300));
        return false;
    }
    return true;
}

function redisSRem(string $key, string $member): bool {
    $res = redisCommand('/srem/' . rawurlencode($key) . '/' . rawurlencode($member));
    if ($res === null) {
        error_log('[Redis] SREM null response for key=' . $key . ' member=' . $member);
        return false;
    }
    $json = json_decode($res, true);
    if (!is_array($json) || !array_key_exists('result', $json)) {
        error_log('[Redis] SREM failed: ' . substr($res, 0, 300));
        return false;
    }
    return true;
}

function redisSMembers(string $key): array {
    $res = redisCommand('/smembers/' . rawurlencode($key));
    if ($res === null) {
        return [];
    }
    $json = json_decode($res, true);
    if (!is_array($json) || !array_key_exists('result', $json)) {
        error_log('[Redis] SMEMBERS failed: ' . substr($res, 0, 300));
        return [];
    }
    if (!is_array($json['result'])) {
        return [];
    }
    return array_values($json['result']);
}

function setChannelOwner($channelId, $ownerId, string $title = '', string $username = ''): void {
    $channelIdStr = (string)$channelId;
    $ownerIdStr = (string)$ownerId;

    redisSet("channel_owner:{$channelIdStr}", $ownerIdStr);

    $info = json_encode([
        'owner_id' => $ownerIdStr,
        'title' => $title,
        'username' => $username,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

    redisHSet('all_channels_map', $channelIdStr, $info);
}

function getChannelOwner($channelId): ?string {
    return redisGet("channel_owner:" . (string)$channelId);
}

function getAllChannelsMap(): array {
    $raw = redisHGetAll('all_channels_map');
    $result = [];
    foreach ($raw as $chId => $infoJson) {
        $decoded = json_decode((string)$infoJson, true);
        if (is_array($decoded)) {
            $result[(string)$chId] = $decoded;
        }
    }
    return $result;
}

function removeChannel($channelId): bool {
    $channelIdStr = (string)$channelId;
    $owner = getChannelOwner($channelIdStr);
    if ($owner === null) {
        return false;
    }
    redisDel("channel_owner:{$channelIdStr}");
    redisDel("leave_history:{$channelIdStr}");
    redisHDel('all_channels_map', $channelIdStr);
    return true;
}

function getOwnerChannels($ownerId): array {
    $ownerIdStr = (string)$ownerId;
    $map = getAllChannelsMap();
    $result = [];
    foreach ($map as $chId => $info) {
        if ((string)($info['owner_id'] ?? '') === $ownerIdStr) {
            $result[$chId] = $info;
        }
    }
    return $result;
}

function saveLeaveHistory($channelId, array $record): void {
    redisLPushTrim(
        'leave_history:' . (string)$channelId,
        json_encode($record, JSON_UNESCAPED_UNICODE),
        50
    );
}

function getLeaveHistory($channelId): array {
    $raw = redisLRange('leave_history:' . (string)$channelId, 0, 49);
    $result = [];
    foreach ($raw as $item) {
        $decoded = json_decode((string)$item, true);
        if (is_array($decoded)) {
            $result[] = $decoded;
        }
    }
    return $result;
}

function getForceChannelsPool(): array {
    return redisSMembers('force_channels_pool');
}

function addForceChannelToPool(string $channelRef): bool {
    $channelRef = trim($channelRef);
    if ($channelRef === '') {
        return false;
    }
    if ($channelRef[0] !== '@' && !preg_match('/^-?\d+$/', $channelRef)) {
        $channelRef = '@' . $channelRef;
    }
    return redisSAdd('force_channels_pool', $channelRef);
}

function removeForceChannelFromPool(string $channelRef): bool {
    $channelRef = trim($channelRef);
    if ($channelRef !== '' && $channelRef[0] !== '@' && !preg_match('/^-?\d+$/', $channelRef)) {
        $channelRef = '@' . $channelRef;
    }
    $pool = getForceChannelsPool();
    $normalized = [];
    foreach ($pool as $ch) {
        $normalized[] = trim((string)$ch);
    }
    if (!in_array($channelRef, $normalized, true)) {
        return false;
    }
    return redisSRem('force_channels_pool', $channelRef);
}

function assignRandomForceChannels($userId, bool $reset = false): array {
    $userIdStr = (string)$userId;
    $key = "user_force_channels:{$userIdStr}";
    $pool = getForceChannelsPool();

    if (empty($pool)) {
        redisDel($key);
        return [];
    }

    if (!$reset) {
        $existingRaw = redisGet($key);
        if ($existingRaw) {
            $existing = json_decode($existingRaw, true);
            if (is_array($existing) && !empty($existing)) {
                $valid = array_values(array_intersect($existing, $pool));
                if (!empty($valid)) {
                    return $valid;
                }
            }
        }
    }

    if (count($pool) <= 2) {
        $selected = $pool;
    } else {
        $keys = array_rand($pool, 2);
        $selected = [$pool[$keys[0]], $pool[$keys[1]]];
    }

    redisSet($key, json_encode($selected, JSON_UNESCAPED_UNICODE), 86400 * 7);
    return $selected;
}

function setSponsorText(string $text): void {
    redisSet('bot_sponsor_text', $text);
}

function getSponsorText(): string {
    $text = redisGet('bot_sponsor_text');
    return ($text !== null && trim($text) !== '')
        ? $text
        : "🌟 هنوز اسپانسری ثبت نشده است.\nجهت رزرو اسپانسری با مدیریت ربات در ارتباط باشید.";
}

function setDonateText(string $text): void {
    redisSet('bot_donate_text', $text);
}

function getDonateText(): string {
    $text = redisGet('bot_donate_text');
    return ($text !== null && trim($text) !== '')
        ? $text
        : "💝 در حال حاضر اطلاعات حمایت مالی ثبت نشده است.";
}