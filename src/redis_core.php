<?php
// src/redis_core.php
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
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.REDIS_TOKEN, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('[Redis] curl failed: '.curl_error($ch).' | cmd='.($command[0] ?? '?'));
        curl_close($ch); return null;
    }
    curl_close($ch);
    $json = json_decode($res, true);
    if (!is_array($json)) { error_log('[Redis] invalid JSON: '.substr($res,0,300)); return null; }
    if (isset($json['error'])) { error_log('[Redis] error: '.$json['error'].' | cmd='.($command[0] ?? '?')); return null; }
    return $json;
}

function redisResult(?array $json): mixed {
    if ($json === null || !array_key_exists('result', $json)) return null;
    return $json['result'];
}

function redisSet(string $key, string $value, ?int $ttl = null): bool {
    $cmd = ['SET', $key, $value];
    if ($ttl !== null) { $cmd[] = 'EX'; $cmd[] = (string)$ttl; }
    return redisExec($cmd) !== null;
}

function redisGet(string $key): ?string {
    $res = redisResult(redisExec(['GET', $key]));
    if ($res === null) return null;
    if (is_array($res)) return json_encode($res, JSON_UNESCAPED_UNICODE);
    return (string)$res;
}

function redisMGet(array $keys): ?array {
    $keys = array_values($keys);
    if (empty($keys)) return [];
    $res = redisResult(redisExec(array_merge(['MGET'], $keys)));
    return is_array($res) ? $res : null;
}

function redisDel(string $key): void { redisExec(['DEL', $key]); }

function redisIncrBy(string $key, int $n): int {
    return (int)(redisResult(redisExec(['INCRBY', $key, (string)$n])) ?? 0);
}

function redisAcquireLock(string $key, int $ttl): bool {
    return redisResult(redisExec(['SET', $key, '1', 'NX', 'EX', (string)$ttl])) === 'OK';
}

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
    $isFlat = $keys === range(0, count($result)-1);
    if (!$isFlat) return $result;
    $pairs = []; $count = count($result);
    for ($i = 0; $i + 1 < $count; $i += 2) $pairs[(string)$result[$i]] = $result[$i+1];
    return $pairs;
}
function redisHKeys(string $key): array {
    $res = redisResult(redisExec(['HKEYS', $key]));
    return is_array($res) ? array_values($res) : [];
}
function redisHDel(string $key, string $field): void { redisExec(['HDEL', $key, $field]); }

function redisLPushTrim(string $key, string $value, int $max = 50): void {
    redisExec(['LPUSH', $key, $value]);
    redisExec(['LTRIM', $key, '0', (string)($max-1)]);
}
function redisLRange(string $key, int $start = 0, int $stop = 49): array {
    $res = redisResult(redisExec(['LRANGE', $key, (string)$start, (string)$stop]));
    return is_array($res) ? $res : [];
}

function redisSAdd(string $key, string $member): bool { return redisExec(['SADD', $key, $member]) !== null; }
function redisSRem(string $key, string $member): bool { return redisExec(['SREM', $key, $member]) !== null; }
function redisSMembers(string $key): array {
    $res = redisResult(redisExec(['SMEMBERS', $key]));
    return is_array($res) ? array_values($res) : [];
}

function redisScanKeys(string $pattern, int $maxIterations = 100): array {
    $cursor = '0'; $found = []; $i = 0;
    do {
        $res = redisResult(redisExec(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500']));
        if (!is_array($res) || count($res) < 2) break;
        $cursor = (string)$res[0];
        if (is_array($res[1])) foreach ($res[1] as $k) $found[(string)$k] = true;
        $i++;
    } while ($cursor !== '0' && $i < $maxIterations);
    return array_keys($found);
}