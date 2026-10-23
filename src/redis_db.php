<?php
// src/redis_db.php
declare(strict_types=1);

function redisExec(array $command): ?array {
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
        error_log('[Redis] REDIS_URL or REDIS_TOKEN is empty');
        return null;
    }
    $ch = curl_init(rtrim(REDIS_URL, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($command, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . REDIS_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('[Redis] curl failed: ' . curl_error($ch) . ' | cmd=' . ($command[0] ?? '?'));
        return null;
    }
    $json = json_decode($res, true);
    if (!is_array($json)) {
        error_log('[Redis] invalid JSON: ' . substr($res, 0, 300));
        return null;
    }
    if (isset($json['error'])) {
        error_log('[Redis] error: ' . $json['error'] . ' | cmd=' . ($command[0] ?? '?'));
        return null;
    }
    return $json;
}

function redisResult(?array $json): mixed {
    if ($json === null || !array_key_exists('result', $json)) {
        return null;
    }
    return $json['result'];
}

function redisSet(string $key, string $value, ?int $ttl = null): bool {
    $cmd = ['SET', $key, $value];
    if ($ttl !== null) {
        $cmd[] = 'EX';
        $cmd[] = (string)$ttl;
    }
    return redisExec($cmd) !== null;
}

function redisGet(string $key): ?string {
    $res = redisResult(redisExec(['GET', $key]));
    if ($res === null) {
        return null;
    }
    if (is_array($res)) {
        return json_encode($res, JSON_UNESCAPED_UNICODE);
    }
    return (string)$res;
}

function redisDel(string $key): void {
    redisExec(['DEL', $key]);
}

function redisHSet(string $key, string $field, string $value): bool {
    return redisExec(['HSET', $key, $field, $value]) !== null;
}

function redisHGetAll(string $key): array {
    $result = redisResult(redisExec(['HGETALL', $key]));
    if (!is_array($result) || empty($result)) {
        return [];
    }
    $keys = array_keys($result);
    $isFlat = $keys === range(0, count($result) - 1);
    if (!$isFlat) {
        return $result;
    }
    $pairs = [];
    $count = count($result);
    for ($i = 0; $i + 1 < $count; $i += 2) {
        $pairs[(string)$result[$i]] = $result[$i + 1];
    }
    return $pairs;
}

function redisHDel(string $key, string $field): void {
    redisExec(['HDEL', $key, $field]);
}

function redisLPushTrim(string $key, string $value, int $max = 50): void {
    redisExec(['LPUSH', $key, $value]);
    redisExec(['LTRIM', $key, '0', (string)($max - 1)]);
}

function redisLRange(string $key, int $start = 0, int $stop = 49): array {
    $res = redisResult(redisExec(['LRANGE', $key, (string)$start, (string)$stop]));
    return is_array($res) ? $res : [];
}

function redisSAdd(string $key, string $member): bool {
    return redisExec(['SADD', $key, $member]) !== null;
}

function redisSRem(string $key, string $member): bool {
    return redisExec(['SREM', $key, $member]) !== null;
}

function redisSMembers(string $key): array {
    $res = redisResult(redisExec(['SMEMBERS', $key]));
    return is_array($res) ? array_values($res) : [];
}

/* ───────────────── ذخیره‌سازی کانال‌ها (مقاوم) ───────────────── */

/**
 * ذخیره در چند مسیر:
 *  1) channel_owner:{id}  → GET/SET  (تست‌شده)
 *  2) channel_info:{id}   → GET/SET  (تست‌شده)
 *  3) all_channels_set    → SADD     (تست‌شده)
 *  4) all_channels_map    → HSET     (مسیر سازگاری/فال‌بک)
 */
function setChannelOwner($channelId, $ownerId, string $title = '', string $username = ''): void {
    $channelIdStr = (string)$channelId;
    $ownerIdStr = (string)$ownerId;

    $info = json_encode([
        'owner_id' => $ownerIdStr,
        'title' => $title,
        'username' => $username,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

    redisSet("channel_owner:{$channelIdStr}", $ownerIdStr);
    redisSet("channel_info:{$channelIdStr}", $info);
    redisSAdd('all_channels_set', $channelIdStr);

    // مسیر قدیمی؛ اگر شکست بخورد فقط لاگ می‌شود
    if (!redisHSet('all_channels_map', $channelIdStr, $info)) {
        error_log('[Channel] HSET failed (fallback paths still active) for ' . $channelIdStr);
    }
}

function getChannelOwner($channelId): ?string {
    return redisGet("channel_owner:" . (string)$channelId);
}

function getAllChannelsMap(): array {
    $result = [];

    // مسیر اصلی: SET + GET
    $ids = redisSMembers('all_channels_set');
    foreach ($ids as $chId) {
        $chIdStr = (string)$chId;
        $infoStr = redisGet("channel_info:{$chIdStr}");
        if ($infoStr === null) {
            continue;
        }
        $decoded = json_decode($infoStr, true);
        if (is_array($decoded)) {
            $result[$chIdStr] = $decoded;
        }
    }

    // مسیر سازگاری: hash قدیمی
    $raw = redisHGetAll('all_channels_map');
    foreach ($raw as $chId => $infoVal) {
        $chIdStr = (string)$chId;
        if (isset($result[$chIdStr])) {
            continue;
        }
        if (is_array($infoVal)) {
            $decoded = $infoVal;
        } else {
            $str = (string)$infoVal;
            $decoded = json_decode($str, true);
            if (!is_array($decoded)) {
                // سازگاری با داده‌های URL-encoded قدیمی
                $decoded = json_decode(urldecode($str), true);
            }
        }
        if (is_array($decoded)) {
            $result[$chIdStr] = $decoded;
        } else {
            error_log('[Channel] Unparseable info for ch=' . $chIdStr);
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
    redisDel("channel_info:{$channelIdStr}");
    redisDel("leave_history:{$channelIdStr}");
    redisHDel('all_channels_map', $channelIdStr);
    redisSRem('all_channels_set', $channelIdStr);
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

/* ───────────────── بقیه (بدون تغییر) ───────────────── */

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
        if (is_array($item)) {
            $result[] = $item;
            continue;
        }
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
        if ($existingRaw !== null && $existingRaw !== '') {
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