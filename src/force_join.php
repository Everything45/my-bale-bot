<?php
// src/force_join.php
declare(strict_types=1);

function checkBotIsAdminInChannel(string $channelRef): array {
    $ref = normalizeChannelRef($channelRef);
    if ($ref === null) return ['ok'=>false,'error'=>'آیدی کانال نامعتبر است.'];
    $chatRes = apiPost('getChat', ['chat_id'=>$ref]);
    if (!apiOk($chatRes)) return ['ok'=>false,'error'=>'کانال پیدا نشد.'];
    $realId = (string)$chatRes['result']['id'];
    $botId = getBotId();
    if ($botId === null) return ['ok'=>false,'error'=>'شناسایی ربات ناموفق بود.'];
    $memberRes = apiPost('getChatMember', ['chat_id'=>$realId,'user_id'=>$botId]);
    if (!apiOk($memberRes)) return ['ok'=>false,'error'=>'بررسی وضعیت ربات ناموفق بود.'];
    $status = $memberRes['result']['status'] ?? '';
    if (!in_array($status, ['administrator','creator'], true)) return ['ok'=>false,'error'=>'ربات ادمین نیست.'];
    return ['ok'=>true,'real_id'=>$realId,'title'=>$chatRes['result']['title'] ?? 'بدون نام'];
}

function checkSingleChannelMembership(string $channelRef, $userId): ?bool {
    $res = apiPost('getChatMember', ['chat_id'=>$channelRef,'user_id'=>$userId]);
    if (!apiOk($res)) return null;
    $status = $res['result']['status'] ?? 'left';
    if ($status === 'restricted') return (bool)($res['result']['is_member'] ?? false);
    return in_array($status, ['creator','administrator','member'], true);
}

/**
 * ⭐ بهینه: نتیجه موفق برای ۵ دقیقه کش می‌شود تا از انفجار درخواست‌های HTTP جلوگیری شود.
 */
function checkUserForceJoin($userId, bool $reset = false): array {
    if (isBotOwner($userId)) return ['ok'=>true, 'missing'=>[]];

    $userIdStr = (string)$userId;
    $cacheKey  = "force_check_ok:{$userIdStr}";

    if (!$reset) {
        $cached = redisGet($cacheKey);
        if ($cached === '1') return ['ok'=>true, 'missing'=>[]];
    }

    $assigned = assignRandomForceChannels($userId, $reset);
    if (empty($assigned)) {
        redisSet($cacheKey, '1', FORCE_CHECK_CACHE_TTL);
        return ['ok'=>true, 'missing'=>[]];
    }

    $missing = [];
    foreach ($assigned as $ch) {
        $result = checkSingleChannelMembership($ch, $userId);
        if ($result === null || $result === false) $missing[] = $ch;
    }

    if (empty($missing)) {
        redisSet($cacheKey, '1', FORCE_CHECK_CACHE_TTL);
    }
    return ['ok'=>empty($missing), 'missing'=>$missing];
}

/** وقتی کاربر دکمه «عضو شدم» را می‌زند، کش پاک می‌شود تا واقعاً چک شود */
function clearForceCheckCache($userId): void {
    redisDel("force_check_ok:{$userId}");
}

function sendForceJoinPrompt($chatId, array $missingChannels): void {
    $msg = "⚠️ عضویت اجباری\n\nبرای استفاده از ربات، اول تو کانال‌های زیر عضو شوید و «عضو شدم» رو بزنید.\n\n";
    $inlineKeyboard = [];
    foreach ($missingChannels as $index => $ch) {
        $num = $index + 1;
        $cleanUser = ltrim($ch, '@');
        $msg .= "کانال " . $num . ": " . $ch . "\n";
        if (!preg_match('/^-?\d+$/', $ch)) {
            $inlineKeyboard[] = [['text'=>"📢 ورود به کانال ".$num, 'url'=>"https://ble.ir/".$cleanUser]];
        }
    }
    $inlineKeyboard[] = [['text'=>'✅ عضو شدم، بررسی کن','callback_data'=>'check_force_join']];
    sendMessageWithKeyboard($chatId, $msg, ['inline_keyboard'=>$inlineKeyboard]);
}