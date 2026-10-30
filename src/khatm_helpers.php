<?php
// src/khatm_helpers.php
declare(strict_types=1);

function khatmRemaining(array $khatm): int {
    return max(0, (int)($khatm['target'] ?? 0) - (int)($khatm['total_done'] ?? 0));
}
function isKhatmComplete(array $khatm): bool { return khatmRemaining($khatm) === 0; }

function buildKhatmCaption(array $khatm): string {
    $caption = "عنوان ختم : " . $khatm['title'];
    if (!empty($khatm['desc'])) $caption .= "\n" . "توضیحات ختم : " . $khatm['desc'];
    if (isKhatmComplete($khatm)) $caption .= "\n\n✅ این ختم با موفقیت تکمیل شد.";
    return $caption;
}

function buildKhatmChannelKeyboard(array $khatm, string $botUsername): array {
    $total = (int)($khatm['total_done'] ?? 0);
    $remain = khatmRemaining($khatm);
    $rows = [[
        ['text'=>"مجموع: {$total}",'callback_data'=>'khatm_refresh'],
        ['text'=>"مانده: {$remain}",'callback_data'=>'khatm_refresh'],
    ]];
    if ($remain === 0) $rows[] = [['text'=>'✅ ختم تکمیل شد','callback_data'=>'khatm_refresh']];
    else {
        $deepLink = "https://ble.ir/{$botUsername}?start=" . $khatm['id'];
        $rows[] = [['text'=>'📿 شرکت در ختم','url'=>$deepLink]];
    }
    return ['inline_keyboard'=>$rows];
}

function buildKhatmPrivateText(array $khatm, ?string $notice = null, int $mine = 0): string {
    $text = ($notice !== null && $notice !== '') ? $notice . "\n\n" : '';
    if (isKhatmComplete($khatm)) {
        $text .= "✅ ختم «{$khatm['title']}» تکمیل شد. از مشارکت شما سپاسگزاریم.";
        if ($mine > 0) $text .= "\n📊 مجموع شمارش شما: {$mine}";
        return $text;
    }
    $text .= "📿 ختم «{$khatm['title']}»";
    if ($mine > 0) $text .= "\n📊 مجموع شمارش شما: {$mine}";
    $text .= "\n\nبرای ثبت شمارش، روی دکمه‌های زیر بزنید:";
    return $text;
}

function buildKhatmPrivateKeyboard(array $khatm): array {
    $khatmId = $khatm['id'];
    $total = (int)($khatm['total_done'] ?? 0);
    $remain = khatmRemaining($khatm);
    $rows = [];
    if ($remain > 0) {
        foreach ($khatm['buttons'] as $amount) {
            $amount = (int)$amount;
            $rows[] = [['text'=>(string)$amount,'callback_data'=>"khatm_count:{$khatmId}:{$amount}"]];
        }
    }
    $rows[] = [
        ['text'=>"مجموع: {$total}",'callback_data'=>'khatm_refresh'],
        ['text'=>"مانده: {$remain}",'callback_data'=>'khatm_refresh'],
    ];
    return ['inline_keyboard'=>$rows];
}

function khatmCancelKeyboard(): array { return ['inline_keyboard'=>[[['text'=>'❌ انصراف','callback_data'=>'khatm_cancel']]]]; }
function khatmBackKeyboard(): array { return ['inline_keyboard'=>[[['text'=>'🔙 قابلیت‌های ویژه','callback_data'=>'special_menu']]]]; }

function khatmInfoPrompt(?string $error = null): string {
    $text = $error !== null ? "❌ {$error}\n\n" : '';
    return $text . "➕ ساخت ختم جدید (مرحله ۱ از ۲)\n\n"
        . "عنوان و توضیحات را در «یک پیام» بفرستید:\n• خط اول: عنوان\n• خط‌های بعدی: توضیحات\n"
        . "• اگر عکس می‌خواهید، عکس را همراه همین متن (به‌عنوان کپشن) بفرستید.\n\nمثال:\nصلوات\nبه نیت سلامتی همه";
}

function khatmNumbersPrompt(?string $error = null): string {
    $text = $error !== null ? "❌ {$error}\n\n" : '';
    return $text . "➕ ساخت ختم جدید (مرحله ۲ از ۲)\n\n"
        . "در «یک پیام» بفرستید:\n• خط اول: تعداد کل ذکر\n• خط دوم: ۳ عدد دکمه‌ی شمارش، با کاما\n\n"
        . "مثال:\n1000\n1,3,7";
}

function buildKhatmChannelPicker($userId): array {
    $rows = [];
    foreach (getOwnerChannels($userId) as $chId => $info) {
        $rows[] = [['text'=>(($info['title'] ?? '') ?: (string)$chId),'callback_data'=>"khatm_channel:{$chId}"]];
    }
    $rows[] = [['text'=>'❌ انصراف','callback_data'=>'khatm_cancel']];
    return ['inline_keyboard'=>$rows];
}

function abortKhatmFlow($chatId, string $userIdStr): void { clearUserState($userIdStr); }