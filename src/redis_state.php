<?php
// src/redis_state.php
declare(strict_types=1);

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