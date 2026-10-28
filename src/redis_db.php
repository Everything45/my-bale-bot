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
    if ($res === null) return null;
    if (is_array($res)) return json_encode($res, JSON_UNESCAPED_UNICODE);
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
    if (!is_array($result) || empty($result)) return [];
    $keys = array_keys($result);
    $isFlat = $keys === range(0, count($result) - 1);
    if (!$isFlat) return $result;
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

/* ───────────────── کانال‌ها ───────────────── */

function setChannelOwner($channelId, $ownerId, string $title = '', string $username = ''): void {
    $channelIdStr = (string)$channelId;
    $ownerIdStr = (string)$ownerId;
    $info = json_encode(['owner_id' => $ownerIdStr, 'title' => $title, 'username' => $username, 'updated_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
    redisSet("channel_owner:{$channelIdStr}", $ownerIdStr);
    redisSet("channel_info:{$channelIdStr}", $info);
    redisSAdd('all_channels_set', $channelIdStr);
    redisHSet('all_channels_map', $channelIdStr, $info);
}

function getChannelOwner($channelId): ?string {
    return redisGet("channel_owner:" . (string)$channelId);
}

function getAllChannelsMap(): array {
    $result = [];
    $ids = redisSMembers('all_channels_set');
    foreach ($ids as $chId) {
        $chIdStr = (string)$chId;
        $infoStr = redisGet("channel_info:{$chIdStr}");
        if ($infoStr === null) continue;
        $decoded = json_decode($infoStr, true);
        if (is_array($decoded)) $result[$chIdStr] = $decoded;
    }
    $raw = redisHGetAll('all_channels_map');
    foreach ($raw as $chId => $infoVal) {
        $chIdStr = (string)$chId;
        if (isset($result[$chIdStr])) continue;
        $decoded = is_array($infoVal) ? $infoVal : json_decode((string)$infoVal, true);
        if (is_array($decoded)) $result[$chIdStr] = $decoded;
    }
    return $result;
}

function removeChannel($channelId): bool {
    $channelIdStr = (string)$channelId;
    if (getChannelOwner($channelIdStr) === null) return false;
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
        if ((string)($info['owner_id'] ?? '') === $ownerIdStr) $result[$chId] = $info;
    }
    return $result;
}

/* ───────────────── تاریخچه و عضویت اجباری ───────────────── */

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

function getForceChannelsPool(): array { return redisSMembers('force_channels_pool'); }

function addForceChannelToPool(string $channelRef): bool {
    $channelRef = trim($channelRef);
    if ($channelRef === '') return false;
    if ($channelRef[0] !== '@' && !preg_match('/^-?\d+$/', $channelRef)) $channelRef = '@' . $channelRef;
    return redisSAdd('force_channels_pool', $channelRef);
}

function removeForceChannelFromPool(string $channelRef): bool {
    $channelRef = trim($channelRef);
    if ($channelRef !== '' && $channelRef[0] !== '@' && !preg_match('/^-?\d+$/', $channelRef)) $channelRef = '@' . $channelRef;
    if (!in_array($channelRef, getForceChannelsPool(), true)) return false;
    return redisSRem('force_channels_pool', $channelRef);
}

function assignRandomForceChannels($userId, bool $reset = false): array {
    $userIdStr = (string)$userId;
    $key = "user_force_channels:{$userIdStr}";
    $pool = getForceChannelsPool();
    if (empty($pool)) { redisDel($key); return []; }
    if (!$reset) {
        $existingRaw = redisGet($key);
        if ($existingRaw !== null && $existingRaw !== '') {
            $existing = json_decode($existingRaw, true);
            if (is_array($existing) && !empty($existing)) {
                $valid = array_values(array_intersect($existing, $pool));
                if (!empty($valid)) return $valid;
            }
        }
    }
    if (count($pool) <= 2) $selected = $pool;
    else { $keys = array_rand($pool, 2); $selected = [$pool[$keys[0]], $pool[$keys[1]]]; }
    redisSet($key, json_encode($selected, JSON_UNESCAPED_UNICODE), 86400 * 7);
    return $selected;
}

function setSponsorText(string $text): void { redisSet('bot_sponsor_text', $text); }

function getSponsorText(): string {
    $text = redisGet('bot_sponsor_text');
    return ($text !== null && trim($text) !== '') ? $text : "🌟 هنوز اسپانسری ثبت نشده.\nبرای رزرو با مدیر ربات در تماس باشید.";
}

/* ───────────────── سیستم ختم (فاز ۲) ───────────────── */

function setUserState(string $userId, string $state, array $data = []): void {
    redisSet("user_state:{$userId}", $state);
    if (!empty($data)) redisSet("user_state_data:{$userId}", json_encode($data, JSON_UNESCAPED_UNICODE));
}

function getUserState(string $userId): ?string { return redisGet("user_state:{$userId}"); }

function getUserStateData(string $userId): array {
    $raw = redisGet("user_state_data:{$userId}");
    return $raw ? json_decode($raw, true) ?? [] : [];
}

function clearUserState(string $userId): void {
    redisDel("user_state:{$userId}");
    redisDel("user_state_data:{$userId}");
}

function createKhatm(array $data): string {
    $id = uniqid('khatm_');
    $data['id'] = $id;
    $data['total_done'] = 0;
    redisSet("khatm:{$id}", json_encode($data, JSON_UNESCAPED_UNICODE));
    redisSet("channel_active_khatm:{$data['channel_id']}", $id);
    return $id;
}

function getKhatm(string $id): ?array {
    $raw = redisGet("khatm:{$id}");
    return $raw ? json_decode($raw, true) : null;
}

function updateKhatm(string $id, array $data): void {
    redisSet("khatm:{$id}", json_encode($data, JSON_UNESCAPED_UNICODE));
}

function updateKhatmTotal(string $id, int $amount): void {
    $khatm = getKhatm($id);
    if ($khatm) {
        $khatm['total_done'] = ($khatm['total_done'] ?? 0) + $amount;
        updateKhatm($id, $khatm);
    }
}

function addKhatmContribution(string $khatmId, string $userId, int $amount): void {
    if ($amount === 0) return;
    $key = "khatm_contrib:{$khatmId}:{$userId}";
    $current = (int)(redisGet($key) ?? 0);
    redisSet($key, (string)($current + $amount));
    updateKhatmTotal($khatmId, $amount);
}