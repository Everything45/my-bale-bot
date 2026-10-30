<?php
// src/redis_force.php
declare(strict_types=1);

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