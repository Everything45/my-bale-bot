<?php
// src/redis_khatm.php
declare(strict_types=1);

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
    $khatm['total_done'] = (int)(redisGet("khatm_total:{$id}") ?? 0);
    return $khatm;
}
function updateKhatm(string $id, array $data): void {
    unset($data['total_done']);
    redisSet("khatm:{$id}", json_encode($data, JSON_UNESCAPED_UNICODE));
}

function getAllKhatmIds(): array {
    $ids = [];
    foreach (redisSMembers('all_khatms_set') as $id) $ids[(string)$id] = true;
    foreach (redisScanKeys('khatm:khatm_*') as $key) $ids[substr($key, strlen('khatm:'))] = true;
    return array_keys($ids);
}
function getAllKhatms(): array {
    $list = [];
    foreach (getAllKhatmIds() as $id) {
        $k = getKhatm($id);
        if ($k === null) { redisSRem('all_khatms_set', $id); continue; }
        $list[] = $k;
    }
    usort($list, static fn($a,$b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $list;
}
function getKhatmContributorIds(string $id): array {
    $ids = [];
    foreach (redisSMembers("khatm_users:{$id}") as $u) $ids[(string)$u] = true;
    $prefix = "khatm_contrib:{$id}:";
    foreach (redisScanKeys($prefix . '*') as $key) {
        if (strpos($key, $prefix) === 0) $ids[substr($key, strlen($prefix))] = true;
    }
    return array_keys($ids);
}
function resetKhatm(string $id): void {
    foreach (getKhatmContributorIds($id) as $u) redisDel("khatm_contrib:{$id}:{$u}");
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
function updateKhatmTotal(string $id, int $amount): int { return redisIncrBy("khatm_total:{$id}", $amount); }
function getKhatmUserTotal(string $khatmId, string $userId): int {
    return (int)(redisGet("khatm_contrib:{$khatmId}:{$userId}") ?? 0);
}
function addKhatmContribution(string $khatmId, string $userId, int $amount): int {
    if ($amount === 0) return getKhatmUserTotal($khatmId, $userId);
    $mine = redisIncrBy("khatm_contrib:{$khatmId}:{$userId}", $amount);
    redisSAdd("khatm_users:{$khatmId}", $userId);
    updateKhatmTotal($khatmId, $amount);
    return $mine;
}