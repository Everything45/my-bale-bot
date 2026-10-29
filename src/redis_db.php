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
        curl_close($ch);
        return null;
    }
    curl_close($ch);

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

/** چند کلید با یک درخواست. در صورت خطا null برمی‌گرداند (تا با خطای شبکه چیزی پاک نشود). */
function redisMGet(array $keys): ?array {
    $keys = array_values($keys);
    if (empty($keys)) return [];
    $res = redisResult(redisExec(array_merge(['MGET'], $keys)));
    return is_array($res) ? $res : null;
}

function redisDel(string $key): void {
    redisExec(['DEL', $key]);
}

/** افزایش اتمیک؛ مقدار جدید را برمی‌گرداند */
function redisIncrBy(string $key, int $n): int {
    return (int)(redisResult(redisExec(['INCRBY', $key, (string)$n])) ?? 0);
}

/** قفل کوتاه‌مدت (SET NX EX). اگر قفل گرفته شد true برمی‌گرداند. */
function redisAcquireLock(string $key, int $ttl): bool {
    $res = redisResult(redisExec(['SET', $key, '1', 'NX', 'EX', (string)$ttl]));
    return $res === 'OK';
}

/** تا $maxWaitMs میلی‌ثانیه برای گرفتن قفل صبر می‌کند (هر ۱۰۰ms یک تلاش). */
function redisWaitLock(string $key, int $ttl, int $maxWaitMs): bool {
    $deadline = microtime(true) + ($maxWaitMs / 1000);
    do {
        if (redisAcquireLock($key, $ttl)) return true;
        usleep(100000);
    } while (microtime(true) < $deadline);
    return false;
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

function redisHKeys(string $key): array {
    $res = redisResult(redisExec(['HKEYS', $key]));
    return is_array($res) ? array_values($res) : [];
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

/** پیدا کردن کلیدها با الگو (SCAN) */
function redisScanKeys(string $pattern, int $maxIterations = 100): array {
    $cursor = '0';
    $found = [];
    $i = 0;
    do {
        $res = redisResult(redisExec(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500']));
        if (!is_array($res) || count($res) < 2) break;
        $cursor = (string)$res[0];
        if (is_array($res[1])) {
            foreach ($res[1] as $k) $found[(string)$k] = true;
        }
        $i++;
    } while ($cursor !== '0' && $i < $maxIterations);
    return array_keys($found);
}

/* ───────────────── کانال‌ها ───────────────── */
/*
 * منبع اصلی حقیقت: کلید channel_owner:{id}.
 * هر ورودی در all_channels_set / all_channels_map که این کلید را نداشته باشد «شبح» است
 * و هنگام خواندن خودکار پاک می‌شود.
 */

function setChannelOwner($channelId, $ownerId, string $title = '', string $username = '', string $type = ''): void {
    $channelIdStr = (string)$channelId;
    $ownerIdStr = (string)$ownerId;
    $info = json_encode([
        'owner_id' => $ownerIdStr,
        'title' => $title,
        'username' => $username,
        'type' => $type,
        'updated_at' => date('Y-m-d H:i:s'),
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
    if (!is_array($info)) $info = ['title' => '', 'username' => ''];
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
    foreach ($ids as $id) {
        $keys[] = "channel_owner:{$id}";
        $keys[] = "channel_info:{$id}";
    }
    $vals = redisMGet($keys);
    if ($vals === null) return []; // خطای Redis: چیزی پاک نکن

    $result = [];
    $i = 0;
    foreach ($ids as $id) {
        $owner = $vals[$i++] ?? null;
        $infoRaw = $vals[$i++] ?? null;
        if ($owner === null || $owner === '') {
            // شبح: مالک ندارد → پاک‌سازی
            redisSRem('all_channels_set', (string)$id);
            redisHDel('all_channels_map', (string)$id);
            redisDel("channel_info:{$id}");
            continue;
        }
        $info = is_string($infoRaw) ? json_decode($infoRaw, true) : null;
        if (!is_array($info)) $info = ['title' => '', 'username' => ''];
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
    redisSet($key, json_encode(array_values($selected), JSON_UNESCAPED_UNICODE), 86400);
    return array_values($selected);
}

function setSponsorText(string $text): void { redisSet('bot_sponsor_text', $text); }

function getSponsorText(): string {
    $text = redisGet('bot_sponsor_text');
    return ($text !== null && trim($text) !== '') ? $text : "🌟 هنوز اسپانسری ثبت نشده.\nبرای رزرو با مدیر ربات در تماس باشید.";
}

/* ───────────────── وضعیت کاربر ───────────────── */

const USER_STATE_TTL = 1800; // ۳۰ دقیقه

/** وضعیت و داده‌ی آن همیشه با هم بازنویسی می‌شوند (داده‌ی قدیمی باقی نمی‌ماند) */
function setUserState(string $userId, string $state, array $data = []): void {
    redisSet("user_state:{$userId}", $state, USER_STATE_TTL);
    redisSet("user_state_data:{$userId}", json_encode($data, JSON_UNESCAPED_UNICODE), USER_STATE_TTL);
}

function getUserState(string $userId): ?string {
    $v = redisGet("user_state:{$userId}");
    return ($v === null || $v === '') ? null : $v;
}

function getUserStateData(string $userId): array {
    $raw = redisGet("user_state_data:{$userId}");
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function clearUserState(string $userId): void {
    redisDel("user_state:{$userId}");
    redisDel("user_state_data:{$userId}");
}

/* ───────────────── سیستم ختم ───────────────── */

function createKhatm(array $data): string {
    $id = uniqid('khatm_');
    $data['id'] = $id;
    $data['created_at'] = date('Y-m-d H:i:s');
    unset($data['total_done']);
    redisSet("khatm:{$id}", json_encode($data, JSON_UNESCAPED_UNICODE));
    redisSAdd('all_khatms_set', $id);
    return $id;
}

function getKhatm(string $id): ?array {
    $raw = redisGet("khatm:{$id}");
    if (!$raw) return null;
    $khatm = json_decode($raw, true);
    if (!is_array($khatm)) return null;
    // مجموع در کلید جدا و اتمیک نگهداری می‌شود
    $khatm['total_done'] = (int)(redisGet("khatm_total:{$id}") ?? 0);
    return $khatm;
}

function updateKhatm(string $id, array $data): void {
    unset($data['total_done']); // مجموع فقط با INCRBY تغییر می‌کند
    redisSet("khatm:{$id}", json_encode($data, JSON_UNESCAPED_UNICODE));
}

/** شناسه‌ی همه‌ی ختم‌ها (هم از ایندکس، هم از SCAN برای ختم‌های قدیمی) */
function getAllKhatmIds(): array {
    $ids = [];
    foreach (redisSMembers('all_khatms_set') as $id) $ids[(string)$id] = true;
    foreach (redisScanKeys('khatm:khatm_*') as $key) {
        $ids[substr($key, strlen('khatm:'))] = true;
    }
    return array_keys($ids);
}

/** همه‌ی ختم‌های موجود (ایندکس‌های شبح پاک می‌شوند) */
function getAllKhatms(): array {
    $list = [];
    foreach (getAllKhatmIds() as $id) {
        $k = getKhatm($id);
        if ($k === null) {
            redisSRem('all_khatms_set', $id);
            continue;
        }
        $list[] = $k;
    }
    usort($list, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $list;
}

/** شناسه‌ی همه‌ی کاربرانی که در این ختم شمارش ثبت کرده‌اند */
function getKhatmContributorIds(string $id): array {
    $ids = [];
    foreach (redisSMembers("khatm_users:{$id}") as $u) $ids[(string)$u] = true;
    $prefix = "khatm_contrib:{$id}:";
    foreach (redisScanKeys($prefix . '*') as $key) {
        if (strpos($key, $prefix) === 0) $ids[substr($key, strlen($prefix))] = true;
    }
    return array_keys($ids);
}

/** صفر کردن کامل شمارش‌ها (مجموع + مشارکت تک‌تک کاربران) */
function resetKhatm(string $id): void {
    foreach (getKhatmContributorIds($id) as $u) {
        redisDel("khatm_contrib:{$id}:{$u}");
    }
    redisDel("khatm_users:{$id}");
    redisDel("khatm_total:{$id}");
    redisDel("khatm_edit_lock:{$id}");
    redisDel("khatm_dirty:{$id}");
}

function deleteKhatm(string $id): void {
    $khatm = getKhatm($id);
    resetKhatm($id);
    redisDel("khatm:{$id}");
    redisSRem('all_khatms_set', $id);
    if ($khatm !== null && !empty($khatm['channel_id'])) {
        $active = redisGet("channel_active_khatm:{$khatm['channel_id']}");
        if ($active === $id) redisDel("channel_active_khatm:{$khatm['channel_id']}");
    }
}

function setChannelActiveKhatm($channelId, string $khatmId): void {
    redisSet("channel_active_khatm:{$channelId}", $khatmId);
}

function updateKhatmTotal(string $id, int $amount): int {
    return redisIncrBy("khatm_total:{$id}", $amount);
}

function getKhatmUserTotal(string $khatmId, string $userId): int {
    return (int)(redisGet("khatm_contrib:{$khatmId}:{$userId}") ?? 0);
}

/** مشارکت را ثبت می‌کند و مجموع مشارکت همین کاربر را برمی‌گرداند */
function addKhatmContribution(string $khatmId, string $userId, int $amount): int {
    if ($amount === 0) return getKhatmUserTotal($khatmId, $userId);
    $mine = redisIncrBy("khatm_contrib:{$khatmId}:{$userId}", $amount);
    redisSAdd("khatm_users:{$khatmId}", $userId);
    updateKhatmTotal($khatmId, $amount);
    return $mine;
}