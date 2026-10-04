<?php
/**
 * ربات تلگرام عیب‌یابی خودرو — منوی شیشه‌ای (Inline Keyboard)
 * مسیر: برند → مدل → دسته مشکل → مشکل → راه‌حل
 * همه صفحات با ویرایش همان پیام نمایش داده می‌شوند تا چت شلوغ نشود.
 */
$cfg = require __DIR__ . '/config.php';

// فقط درخواست‌های تلگرام
if (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== hash('sha256', $cfg['secret'])) {
    http_response_code(403);
    exit;
}

$cat    = json_decode(file_get_contents(__DIR__ . '/data/catalog.json'), true);
$update = json_decode(file_get_contents('php://input'), true);

function tg(string $method, array $p = []) {
    global $cfg;
    $ch = curl_init("https://api.telegram.org/bot{$cfg['bot_token']}/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode($p, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $r, true);
}

/** دکمه‌ها را در ردیف‌های $per تایی می‌چیند */
function grid(array $btns, int $per = 2): array {
    return array_chunk($btns, $per);
}
function btn(string $t, string $d): array { return ['text' => $t, 'callback_data' => $d]; }

/** صفحه را بر اساس callback_data می‌سازد. خروجی: [متن، کیبورد] */
function screen(string $path): array {
    global $cat, $cfg;
    $p = $path === '' ? [] : explode('|', $path);   // brand|model|cat|problem
    $nav = fn(array $extra = []) => array_merge($extra, [[btn('🏠 منوی اصلی', 'home')]]);

    if (count($p) === 0 || $p[0] === 'home') {
        $rows = [];
        foreach ($cat['brands'] as $id => $b) $rows[] = btn('🚘 ' . $b['name'], $id);
        $rows = grid($rows);
        $rows[] = [['text' => '🌐 وب‌سایت', 'url' => $cfg['site_url']], btn('👨‍🔧 کارشناس', 'agent')];
        return ["سلام 👋\nبه ربات عیب‌یابی خودرو خوش آمدید.\nلطفاً <b>برند</b> خودرو را انتخاب کنید:", $rows];
    }

    $brand = $cat['brands'][$p[0]] ?? null;
    if (!$brand) return screen('');

    if (count($p) === 1) {
        $rows = [];
        foreach ($brand['models'] as $id => $n) $rows[] = btn($n, "{$p[0]}|$id");
        return ["برند: <b>{$brand['name']}</b>\nمدل خودرو را انتخاب کنید:", $nav(grid($rows))];
    }

    $model = $brand['models'][$p[1]] ?? null;
    if (!$model) return screen($p[0]);

    if (count($p) === 2) {
        $rows = [];
        foreach ($cat['categories'] as $id => $n) $rows[] = btn($n, "{$p[0]}|{$p[1]}|$id");
        $rows = grid($rows);
        $rows[] = [btn('⬅️ برگشت', $p[0])];
        return ["🚘 <b>$model</b>\nمشکل شما مربوط به کدام بخش است؟", $nav($rows)];
    }

    $probs = $cat['problems'][$p[2]] ?? null;
    if (!$probs) return screen("{$p[0]}|{$p[1]}");

    if (count($p) === 3) {
        $rows = [];
        foreach ($probs as $id => $pr) $rows[] = [btn($pr[0], "{$p[0]}|{$p[1]}|{$p[2]}|$id")];
        $rows[] = [btn('⬅️ برگشت', "{$p[0]}|{$p[1]}")];
        return ["🚘 <b>$model</b> — {$cat['categories'][$p[2]]}\nکدام مورد را دارید؟", $nav($rows)];
    }

    $pr = $probs[$p[3]] ?? null;
    if (!$pr) return screen("{$p[0]}|{$p[1]}|{$p[2]}");
    $rows = [
        [btn('✅ مشکلم حل شد', 'solved'), btn('❌ هنوز مشکل دارم', "agent|{$p[0]}|{$p[1]}|{$p[2]}|{$p[3]}")],
        [btn('⬅️ برگشت', "{$p[0]}|{$p[1]}|{$p[2]}")],
    ];
    $txt = "🚘 <b>$model</b>\n❗️ <b>{$pr[0]}</b>\n\n" . htmlspecialchars($pr[1], ENT_NOQUOTES)
         . "\n\n⚠️ این راهنما اولیه است؛ برای اطمینان خودرو را به تعمیرگاه مجاز ببرید.";
    return [$txt, $nav($rows)];
}

/** نمایش صفحه: ویرایش پیام قبلی یا ارسال جدید */
function show(int $chat, ?int $msgId, string $path) {
    [$text, $rows] = screen($path);
    $payload = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $rows]];
    if ($msgId) { $payload['message_id'] = $msgId; tg('editMessageText', $payload); }
    else tg('sendMessage', $payload);
}

if (isset($update['callback_query'])) {
    $q    = $update['callback_query'];
    $chat = $q['message']['chat']['id'];
    $mid  = $q['message']['message_id'];
    $data = $q['data'] ?? '';
    tg('answerCallbackQuery', ['callback_query_id' => $q['id']]);

    if ($data === 'solved') {
        tg('editMessageText', ['chat_id' => $chat, 'message_id' => $mid, 'text' => '🙏 خوشحالیم که مشکل حل شد. سفر امن!',
            'reply_markup' => ['inline_keyboard' => [[btn('🏠 منوی اصلی', 'home')]]]]);
    } elseif (str_starts_with($data, 'agent')) {
        $info = trim(substr($data, 5), '|');
        $who  = trim(($q['from']['first_name'] ?? '') . ' ' . ($q['from']['last_name'] ?? ''));
        $user = isset($q['from']['username']) ? '@' . $q['from']['username'] : 'id:' . $q['from']['id'];
        if ($cfg['admin_chat']) {
            tg('sendMessage', ['chat_id' => $cfg['admin_chat'], 'text' => "📩 درخواست کارشناس\nاز: $who ($user)\nمسیر: " . ($info ?: '-')]);
        }
        $t = "👨‍🔧 درخواست شما برای کارشناس ثبت شد؛ به‌زودی با شما تماس می‌گیریم." . ($cfg['phone'] ? "\n📞 {$cfg['phone']}" : '');
        tg('editMessageText', ['chat_id' => $chat, 'message_id' => $mid, 'text' => $t,
            'reply_markup' => ['inline_keyboard' => [[btn('🏠 منوی اصلی', 'home')]]]]);
    } else {
        show($chat, $mid, $data === 'home' ? '' : $data);
    }
} elseif (isset($update['message'])) {
    show($update['message']['chat']['id'], null, '');
}
http_response_code(200);
