<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

define('BOT_TOKEN', trim(getenv('BOT_TOKEN') ?: ''));
define('REDIS_URL', trim(getenv('UPSTASH_REDIS_REST_URL') ?: ''));
define('REDIS_TOKEN', trim(getenv('UPSTASH_REDIS_REST_TOKEN') ?: ''));
define('BOT_OWNER_ID', trim(getenv('BOT_OWNER_ID') ?: ''));

if (BOT_TOKEN === '') {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Error: BOT_TOKEN environment variable is not set.';
    exit;
}
