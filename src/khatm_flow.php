<?php
// src/khatm_flow.php
declare(strict_types=1);

function handleKhatmState($chatId, $userId, string $state, string $text, array $message): void {
    $userIdStr = (string)$userId;
    $data = getUserStateData($userIdStr);

    if ($state === 'khatm_info') {
        $text = trim($text);
        $lines = preg_split('/\R/u', $text) ?: [];
        $title = trim((string)array_shift($lines));
        $desc = trim(implode("\n", $lines));

        $image = '';
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);
            $image = (string)($photo['file_id'] ?? '');
        }

        $error = null;
        if ($title === '') $error = 'عنوان خالی است. خط اول پیام را عنوان بنویسید.';
        elseif (mb_strlen($title) > KHATM_MAX_TITLE) $error = 'عنوان خیلی طولانی است (حداکثر ' . KHATM_MAX_TITLE . ' کاراکتر).';
        elseif (mb_strlen($desc) > KHATM_MAX_DESC) $error = 'توضیحات خیلی طولانی است (حداکثر ' . KHATM_MAX_DESC . ' کاراکتر).';

        if ($error !== null) {
            setUserState($userIdStr, 'khatm_info', $data);
            sendMessageWithKeyboard($chatId, khatmInfoPrompt($error), khatmCancelKeyboard());
            return;
        }

        $data['title'] = $title; $data['desc'] = $desc; $data['image'] = $image;
        setUserState($userIdStr, 'khatm_numbers', $data);
        sendMessageWithKeyboard($chatId, khatmNumbersPrompt(), khatmCancelKeyboard());
        return;
    }

    if ($state === 'khatm_numbers') {
        $norm = str_replace(['،','٬'], ',', normalizeDigits($text));
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $norm) ?: []),
            static fn($l) => $l !== ''
        ));

        $target = 0; $buttons = [];
        if (count($lines) === 2) {
            $targetDigits = preg_replace('/\D/', '', $lines[0]);
            $target = ($targetDigits !== '' && strlen($targetDigits) <= 10) ? (int)$targetDigits : 0;
            $buttons = preg_split('/[\s,]+/', $lines[1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            preg_match_all('/\d+/', $norm, $mm);
            $nums = $mm[0] ?? [];
            if (count($nums) === 4 && strlen($nums[0]) <= 10) {
                $target = (int)array_shift($nums);
                $buttons = $nums;
            }
        }

        $valid = $target > 0 && $target <= KHATM_MAX_TARGET && count($buttons) === 3;
        if ($valid) {
            foreach ($buttons as $b) {
                if (!ctype_digit($b) || strlen($b) > 7 || (int)$b <= 0 || (int)$b > KHATM_MAX_BUTTON) { $valid = false; break; }
            }
        }
        if (!$valid) {
            setUserState($userIdStr, 'khatm_numbers', $data);
            sendMessageWithKeyboard($chatId,
                khatmNumbersPrompt('فرمت درست نیست. خط اول تعداد کل، خط دوم دقیقاً ۳ عدد مثبت با کاما.'),
                khatmCancelKeyboard());
            return;
        }

        $data['target'] = $target;
        $data['buttons'] = array_map('intval', $buttons);

        $channels = getOwnerChannels($userId);
        if (empty($channels)) {
            clearUserState($userIdStr);
            sendMessageWithKeyboard($chatId, "❌ هیچ کانال متصلی ندارید. ابتدا یک کانال متصل کنید.", khatmBackKeyboard());
            return;
        }

        setUserState($userIdStr, 'khatm_channel', $data);
        if (count($channels) === 1) { sendKhatmChannelPreview($chatId, $userId, (string)array_key_first($channels)); return; }
        sendMessageWithKeyboard($chatId, "✅ اطلاعات ثبت شد.\n\nکانالی که ختم در آن ارسال شود را انتخاب کنید:", buildKhatmChannelPicker($userId));
        return;
    }

    if ($state === 'khatm_channel') {
        setUserState($userIdStr, 'khatm_channel', $data);
        sendMessageWithKeyboard($chatId, "کانال مورد نظر را از دکمه‌های زیر انتخاب کنید:", buildKhatmChannelPicker($userId));
        return;
    }
}

function sendKhatmChannelPreview($chatId, $userId, string $chId): void {
    $userIdStr = (string)$userId;

    if (getUserState($userIdStr) !== 'khatm_channel') {
        sendMessageWithKeyboard($chatId, "⚠️ این درخواست منقضی شده است. لطفاً ساخت ختم را از ابتدا شروع کنید.", khatmBackKeyboard());
        return;
    }

    $data = getUserStateData($userIdStr);
    foreach (['title','desc','image','target','buttons'] as $required) {
        if (!isset($data[$required])) {
            clearUserState($userIdStr);
            sendMessageWithKeyboard($chatId, "⚠️ اطلاعات ختم ناقص است. لطفاً ساخت ختم را از ابتدا شروع کنید.", khatmBackKeyboard());
            return;
        }
    }

    $retryKeyboard = buildKhatmChannelPicker($userId);
    $botUsername = getBotUsername();
    if (!$botUsername) {
        sendMessageWithKeyboard($chatId, "❌ خطا در شناسایی ربات. دوباره کانال را انتخاب کنید.", $retryKeyboard);
        return;
    }

    $data['channel_id'] = $chId;
    $data['owner_id'] = $userIdStr;

    $khatmId = createKhatm($data);
    $khatm = getKhatm($khatmId);
    if ($khatm === null) {
        sendMessageWithKeyboard($chatId, "❌ خطا در ذخیره ختم. دوباره کانال را انتخاب کنید.", $retryKeyboard);
        return;
    }

    $keyboard = buildKhatmChannelKeyboard($khatm, $botUsername);
    $caption = buildKhatmCaption($khatm);
    $channelMsgId = null; $msgType = 'text';

    if (!empty($khatm['image'])) {
        $res = apiPost('sendPhoto', ['chat_id'=>$chId,'photo'=>$khatm['image'],'caption'=>$caption,'reply_markup'=>$keyboard]);
        if (apiOk($res)) { $channelMsgId = $res['result']['message_id']; $msgType = 'photo'; }
    }
    if ($channelMsgId === null) {
        $res = apiPost('sendMessage', ['chat_id'=>$chId,'text'=>$caption,'reply_markup'=>$keyboard]);
        if (apiOk($res)) $channelMsgId = $res['result']['message_id'];
    }

    if ($channelMsgId) {
        $khatm['channel_msg_id'] = $channelMsgId;
        $khatm['msg_type'] = $msgType;
        updateKhatm($khatmId, $khatm);
        setChannelActiveKhatm($chId, $khatmId);
        clearUserState($userIdStr);
        sendMessageWithKeyboard($chatId,
            "✅ ختم با موفقیت در کانال ارسال شد!\n\nاعضا با دکمه «شرکت در ختم» به ربات هدایت شده و شمارش را ثبت می‌کنند. آمار پست کانال هم زنده آپدیت می‌شود.\n\nبرای ریست یا حذف: /khatms",
            khatmBackKeyboard());
    } else {
        deleteKhatm($khatmId);
        setUserState($userIdStr, 'khatm_channel', $data);
        sendMessageWithKeyboard($chatId, "❌ خطا در ارسال پیام به کانال. مطمئن شوید ربات ادمین است و دوباره کانال را انتخاب کنید.", $retryKeyboard);
    }
}