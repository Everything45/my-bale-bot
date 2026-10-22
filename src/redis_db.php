<?php
declare(strict_types=1);

function redisCommand(string $path): ?string {
    if (REDIS_URL === '' || REDIS_TOKEN === '') {
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
    curl_close($ch);
    return $res === false ? null : $res;
}

function redisSet(string $key, string $value, ?int $ttl = null): void {
    $path = '/set/' . rawurlencode($key) . '/' . rawurlencode($value);
    if ($ttl !== null) {
        $path .= '?EX=' . $ttl;
    }
    redisCommand($path);
}

function redisGet(string $key): ?string {
    $res = redisCommand('/get/' . rawurlencode($key));
    if ($res === null) {
        return null;
    }
    $json = json_decode($res, true);
    if (!is_array($json) || !array_key_exists('result', $json) || $json['result'] === null) {
        return null;
    }
    return (string)$json['result'];
}

function redisDel(string $key): void {
    redisCommand('/del/' . rawurlencode($key));
}

// ---------------- مدیریت مالکیت کانال‌ها ----------------

function getAllChannelsMap(): array {
    $raw = redisGet('all_channels_map');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function setChannelOwner($channelId, $ownerId, string $title = '', string $username = ''): void {
    $channelIdStr = (string)$channelId;
    $ownerIdStr = (string)$ownerId;

    redisSet("channel_owner:{$channelIdStr}", $ownerIdStr);

    $map = getAllChannelsMap();
    $map[$channelIdStr] = [
        'owner_id' => $ownerIdStr,
        'title' => $title,
        'username' => $username,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    redisSet('all_channels_map', json_encode($map, JSON_UNESCAPED_UNICODE));
}

function getChannelOwner($channelId): ?string {
    return redisGet("channel_owner:" . (string)$channelId);
}

function removeChannel($channelId): bool {
    $channelIdStr = (string)$channelId;
    $owner = getChannelOwner($channelIdStr);
    if ($owner === null) {
        return false;
    }
    redisDel("channel_owner:{$channelIdStr}");
    redisDel("leave_history:{$channelIdStr}");

    $map = getAllChannelsMap();
    if (isset($map[$channelIdStr])) {
        unset($map[$channelIdStr]);
        redisSet('all_channels_map', json_encode($map, JSON_UNESCAPED_UNICODE));
    }
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

// ---------------- مدیریت تاریخچه خروج ----------------

function saveLeaveHistory($channelId, array $record): void {
    $key = "leave_history:" . (string)$channelId;
    $raw = redisGet($key);
    $list = $raw ? json_decode($raw, true) : [];
    if (!is_array($list)) {
        $list = [];
    }
    array_unshift($list, $record);
    $list = array_slice($list, 0, 50); // نگهداری ۵۰ خروج آخر هر کانال
    redisSet($key, json_encode($list, JSON_UNESCAPED_UNICODE));
}

function getLeaveHistory($channelId): array {
    $key = "leave_history:" . (string)$channelId;
    $raw = redisGet($key);
    if (!$raw) {
        return [];
    }
    $list = json_decode($raw, true);
    return is_array($list) ? $list : [];
}

// ---------------- سیستم اد اجباری تصادفی ----------------

function getForceChannelsPool(): array {
    $raw = redisGet('force_channels_pool');
    if (!$raw) {
        return [];
    }
    $pool = json_decode($raw, true);
    return is_array($pool) ? $pool : [];
}

function addForceChannelToPool(string $channelRef): void {
    $channelRef = trim($channelRef);
    if ($channelRef === '') {
        return;
    }
    if ($channelRef[0] !== '@' && !preg_match('/^-?\d+$/', $channelRef)) {
        $channelRef = '@' . $channelRef;
    }
    $pool = getForceChannelsPool();
    if (!in_array($channelRef, $pool, true)) {
        $pool[] = $channelRef;
        redisSet('force_channels_pool', json_encode(array_values($pool), JSON_UNESCAPED_UNICODE));
    }
}

function removeForceChannelFromPool(string $channelRef): bool {
    $channelRef = trim($channelRef);
    if ($channelRef !== '' && $channelRef[0] !== '@' && !preg_match('/^-?\d+$/', $channelRef)) {
        $channelRef = '@' . $channelRef;
    }
    $pool = getForceChannelsPool();
    $index = array_search($channelRef, $pool, true);
    if ($index === false) {
        return false;
    }
    unset($pool[$index]);
    redisSet('force_channels_pool', json_encode(array_values($pool), JSON_UNESCAPED_UNICODE));
    return true;
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
        $selected = array_values($pool);
    } else {
        $keys = array_rand($pool, 2);
        $selected = [$pool[$keys[0]], $pool[$keys[1]]];
    }

    redisSet($key, json_encode($selected, JSON_UNESCAPED_UNICODE), 86400 * 7);
    return $selected;
}

// ---------------- متن‌های پویا (اسپانسر و حمایت مالی) ----------------

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
