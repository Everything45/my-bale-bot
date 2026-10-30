<?php
// src/config.php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

define('BOT_TOKEN', trim(getenv('BOT_TOKEN') ?: ''));
define('REDIS_URL', trim(getenv('UPSTASH_REDIS_REST_URL') ?: ''));
define('REDIS_TOKEN', trim(getenv('UPSTASH_REDIS_REST_TOKEN') ?: ''));
define('BOT_OWNER_ID', trim(getenv('BOT_OWNER_ID') ?: ''));
define('WEBHOOK_SECRET', trim(getenv('WEBHOOK_SECRET') ?: ''));

// ─── DeepSeek AI ───
define('DEEPSEEK_API_KEY', trim(getenv('DEEPSEEK_API_KEY') ?: ''));
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/chat/completions');
define('DEEPSEEK_MODEL', 'deepseek-chat');
define('AI_CHAT_HISTORY_TTL', 3600);   // ۱ ساعت
define('AI_CHAT_MAX_HISTORY', 20);     // حداکثر ۲۰ پیام متقابل

// ─── کش و throttle ───
define('FORCE_CHECK_CACHE_TTL', 300);
define('KHATM_EDIT_THROTTLE_SECONDS', 2);
define('USER_STATE_TTL', 1800);

// ─── محدودیت‌ها ───
define('KHATM_MAX_TITLE', 100);
define('KHATM_MAX_DESC', 700);
define('KHATM_MAX_TARGET', 1000000000);
define('KHATM_MAX_BUTTON', 1000000);

if (BOT_TOKEN === '') {
    if (!headers_sent()) http_response_code(500);
    echo 'Error: BOT_TOKEN environment variable is not set.';
    exit;
}