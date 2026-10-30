<?php
// src/ai_chat.php
declare(strict_types=1);

/**
 * ارسال پیام به DeepSeek و دریافت پاسخ
 */
function deepSeekChat(array $messages): ?string {
    if (DEEPSEEK_API_KEY === '') {
        error_log('[DeepSeek] DEEPSEEK_API_KEY is empty');
        return null;
    }

    $payload = [
        'model' => DEEPSEEK_MODEL,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'stream' => false,
    ];

    $ch = curl_init(DEEPSEEK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('[DeepSeek] curl failed: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        error_log('[DeepSeek] invalid JSON: ' . substr($res, 0, 300));
        return null;
    }
    return $decoded['choices'][0]['message']['content'] ?? null;
}

function getAiChatHistory(string $userId): array {
    $raw = redisGet("ai_chat_history:{$userId}");
    if ($raw === null) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveAiChatHistory(string $userId, array $history): void {
    redisSet("ai_chat_history:{$userId}", json_encode($history, JSON_UNESCAPED_UNICODE), AI_CHAT_HISTORY_TTL);
}

function clearAiChatHistory(string $userId): void {
    redisDel("ai_chat_history:{$userId}");
}

/**
 * پردازش پیام در حالت AI Chat
 */
function handleAiChat($chatId, $userId, string $text): void {
    $userIdStr = (string)$userId;
    $history = getAiChatHistory($userIdStr);

    if (empty($history)) {
        $history[] = [
            'role' => 'system',
            'content' => 'You are a helpful AI assistant inside a Bale messenger bot. '
                       . 'Always respond in the same language as the user\'s message. '
                       . 'Keep answers concise unless the user asks for detail.',
        ];
    }

    $history[] = ['role' => 'user', 'content' => $text];

    // محدود کردن تاریخچه
    if (count($history) > AI_CHAT_MAX_HISTORY * 2 + 1) {
        $system = array_shift($history);
        $history = array_slice($history, -AI_CHAT_MAX_HISTORY * 2);
        array_unshift($history, $system);
    }

    $reply = deepSeekChat($history);

    if ($reply === null) {
        sendMessageWithKeyboard(
            $chatId,
            "❌ خطا در ارتباط با سرویس AI. لطفاً چند لحظه بعد دوباره تلاش کنید.",
            ['inline_keyboard' => [[['text' => '❌ خروج از چت AI', 'callback_data' => 'ai_exit']]]]
        );
        return;
    }

    $history[] = ['role' => 'assistant', 'content' => $reply];
    saveAiChatHistory($userIdStr, $history);

    sendMessageWithKeyboard(
        $chatId,
        "🤖 " . $reply,
        ['inline_keyboard' => [[['text' => '❌ خروج از چت AI', 'callback_data' => 'ai_exit']]]]
    );
}