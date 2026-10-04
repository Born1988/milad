<?php
/**
 * ربات عیب‌یابی خودرو — منوی شیشه‌ای (Inline Keyboard)
 * مسیر: برند → مدل → بخش → مشکل → راه‌حل (+ مطالب مرتبط سایت، ثبت درخواست کارشناس، جستجوی متنی)
 */
require __DIR__ . '/lib.php';
$cfg = cfg();

if (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== hash('sha256', $cfg['secret'])) {
    http_response_code(403);
    exit;
}
$update = json_decode(file_get_contents('php://input'), true) ?: [];
$cat    = catalog();

function btn(string $t, string $d): array { return ['text' => $t, 'callback_data' => $d]; }
function grid(array $b, int $n = 2): array { return array_chunk($b, $n); }
function find_problem(string $id): ?array {
    global $cat;
    foreach ($cat['problems'] as $p) if ($p['id'] === $id) return $p;
    return null;
}
function esc(string $s): string { return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8'); }
function home_btn(): array { return [btn('🏠 منوی اصلی', 'home')]; }

/** متن ثابت پایین راه‌حل‌ها */
const DISCLAIMER = "\n\n⚠️ این راهنما اولیه است؛ برای اطمینان خودرو را به تعمیرگاه مجاز ببرید.";

/** مسیر → [متن، کیبورد] */
function screen(string $path): array {
    global $cat, $cfg;
    $p = $path === '' ? [] : explode('|', $path);   // brand|model|cat|pid

    if (!$p || $p[0] === 'home') {
        $rows = [];
        foreach ($cat['brands'] as $id => $b) $rows[] = btn('🚘 ' . $b['name'], $id);
        $rows = grid($rows);
        $last = [['text' => '🌐 وب‌سایت', 'url' => $cfg['site_url']], btn('👨‍🔧 کارشناس', 'agent')];
        $rows[] = $last;
        return ["سلام 👋\nبه ربات عیب‌یابی خودرو خوش آمدید.\n\nلطفاً <b>برند</b> خودرو را انتخاب کنید\n(یا مشکل را به‌صورت متن بنویسید تا جستجو کنم):", $rows];
    }

    $brand = $cat['brands'][$p[0]] ?? null;
    if (!$brand) return screen('');

    if (count($p) === 1) {
        $rows = [];
        foreach ($brand['models'] as $id => $n) $rows[] = btn($n, "{$p[0]}|$id");
        return ["برند: <b>" . esc($brand['name']) . "</b>\nمدل خودرو را انتخاب کنید:", array_merge(grid($rows), [home_btn()])];
    }

    $model = $brand['models'][$p[1]] ?? null;
    if (!$model) return screen($p[0]);

    if (count($p) === 2) {
        $rows = [];
        foreach ($cat['categories'] as $id => $n) $rows[] = btn($n, "{$p[0]}|{$p[1]}|$id");
        $rows = grid($rows);
        $rows[] = [btn('⬅️ برگشت', $p[0])];
        $rows[] = home_btn();
        return ["🚘 <b>" . esc($model) . "</b>\nمشکل شما مربوط به کدام بخش است؟", $rows];
    }

    $catName = $cat['categories'][$p[2]] ?? null;
    if (!$catName) return screen("{$p[0]}|{$p[1]}");

    if (count($p) === 3) {
        $rows = [];
        foreach ($cat['problems'] as $pr) {
            if ($pr['cat'] === $p[2] && problem_matches($pr, $p[0], $p[1])) $rows[] = [btn($pr['title'], "{$p[0]}|{$p[1]}|{$p[2]}|{$pr['id']}")];
        }
        $rows[] = [btn('⬅️ برگشت', "{$p[0]}|{$p[1]}")];
        $rows[] = home_btn();
        $t = count($rows) > 2 ? "کدام مورد را دارید؟" : "فعلاً موردی در این بخش ثبت نشده. می‌توانید «کارشناس» را بزنید.";
        return ["🚘 <b>" . esc($model) . "</b> — $catName\n$t", $rows];
    }

    $pr = find_problem($p[3]);
    if (!$pr) return screen("{$p[0]}|{$p[1]}|{$p[2]}");
    stat_inc('views', $pr['id']);
    $rows = [
        [btn('✅ مشکلم حل شد', 'solved'), btn('❌ هنوز مشکل دارم', "agent|{$p[0]}|{$p[1]}|{$p[2]}|{$p[3]}")],
        [btn('📚 مطالب و محصولات مرتبط', "r|{$p[0]}|{$p[1]}|{$p[3]}")],
        [btn('⬅️ برگشت', "{$p[0]}|{$p[1]}|{$p[2]}")],
        home_btn(),
    ];
    return ["🚘 <b>" . esc($model) . "</b>\n❗️ <b>" . esc($pr['title']) . "</b>\n\n" . esc($pr['text']) . DISCLAIMER, $rows];
}

function show(int $chat, ?int $mid, string $path): void {
    [$text, $rows] = screen($path);
    $pl = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $rows]];
    if ($mid) { $pl['message_id'] = $mid; tg('editMessageText', $pl); } else tg('sendMessage', $pl);
}

/** توضیح مسیر برای ادمین */
function describe_path(string $path): string {
    global $cat;
    $p = $path === '' ? [] : explode('|', $path);
    if (count($p) < 4) return '-';
    $pr = find_problem($p[3]);
    return ($cat['brands'][$p[0]]['name'] ?? '') . ' / ' . ($cat['brands'][$p[0]]['models'][$p[1]] ?? '') . ' / ' . ($pr['title'] ?? '');
}

function save_lead(array $from, int $chat, string $note, string $phone): void {
    $cfg = cfg();
    $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    $user = isset($from['username']) ? '@' . $from['username'] : '';
    jupdate('leads.json', function ($l) use ($chat, $name, $user, $note, $phone) {
        $l[] = ['id' => newid('l'), 'time' => date('Y-m-d H:i'), 'chat' => $chat, 'name' => $name, 'user' => $user, 'phone' => $phone, 'note' => $note];
        return $l;
    });
    if ($cfg['admin_chat']) {
        tg('sendMessage', ['chat_id' => $cfg['admin_chat'], 'parse_mode' => 'HTML', 'text' =>
            "📩 <b>درخواست کارشناس</b>\nنام: " . esc($name) . ' ' . esc($user) . "\n📞 " . esc($phone ?: '—') . "\nموضوع: " . esc($note) . "\n<a href=\"tg://user?id=$chat\">پیام به کاربر</a>"]);
    }
}

function text_search(string $q): array {
    global $cat;
    $words = array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))), fn($w) => mb_strlen($w) >= 2 && !in_array($w, ['ماشین', 'خودرو', 'من', 'من،', 'است', 'شده', 'میشه', 'می‌شود', 'مشکل'], true));
    $scored = [];
    foreach ($cat['problems'] as $pr) {
        $hay = mb_strtolower($pr['title'] . ' ' . $pr['text']);
        $s = 0;
        foreach ($words as $w) { if (mb_strpos($hay, $w) !== false) $s += (mb_strpos(mb_strtolower($pr['title']), $w) !== false) ? 3 : 1; }
        if ($s) $scored[] = [$s, $pr];
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($x) => $x[1], array_slice($scored, 0, 6));
}

/* ============================ دکمه‌ها ============================ */
if (isset($update['callback_query'])) {
    $q = $update['callback_query']; $chat = $q['message']['chat']['id']; $mid = $q['message']['message_id']; $data = $q['data'] ?? '';
    tg('answerCallbackQuery', ['callback_query_id' => $q['id']]);
    $homeKb = ['inline_keyboard' => [home_btn()]];

    if ($data === 'solved') {
        tg('editMessageText', ['chat_id' => $chat, 'message_id' => $mid, 'text' => '🙏 خوشحالیم که مشکل حل شد. سفر امن!', 'reply_markup' => $homeKb]);

    } elseif ($data === 'agent' || str_starts_with($data, 'agent|')) {
        $path = (string) substr($data, 6);
        jupdate('state.json', function ($s) use ($chat, $path) { $s[$chat] = describe_path($path); return $s; });
        tg('editMessageText', ['chat_id' => $chat, 'message_id' => $mid, 'text' => '👨‍🔧 برای ثبت درخواست، شماره تماس خود را با دکمه زیر بفرستید (یا شماره را تایپ کنید).', 'reply_markup' => $homeKb]);
        tg('sendMessage', ['chat_id' => $chat, 'text' => 'شماره تماس:', 'reply_markup' => ['resize_keyboard' => true, 'one_time_keyboard' => true,
            'keyboard' => [[['text' => '📱 ارسال شماره من', 'request_contact' => true]], [['text' => 'انصراف']]]]]);

    } elseif (str_starts_with($data, 'r|')) {                       // مطالب مرتبط سایت
        [, $b, $m, $pid] = array_pad(explode('|', $data), 4, '');
        $pr = find_problem($pid); $model = $cat['brands'][$b]['models'][$m] ?? '';
        $found = $pr ? site_search($pr['title'] . ' ' . $model) : [];
        if (!$found && $pr) $found = site_search($pr['title']);
        $rows = array_map(fn($f) => [['text' => mb_substr($f[0], 0, 60), 'url' => $f[1]]], $found);
        $rows[] = [['text' => '🌐 ورود به سایت', 'url' => $cfg['site_url']]];
        $rows[] = home_btn();
        tg('sendMessage', ['chat_id' => $chat, 'text' => $found ? '📚 این موارد از سایت ممکن است کمک کند:' : 'موردی پیدا نشد؛ می‌توانید سایت را ببینید:', 'reply_markup' => ['inline_keyboard' => $rows]]);

    } elseif (str_starts_with($data, 's|')) {                       // نتیجه جستجوی متنی
        $pr = find_problem(substr($data, 2));
        if ($pr) {
            stat_inc('views', $pr['id']);
            tg('editMessageText', ['chat_id' => $chat, 'message_id' => $mid, 'parse_mode' => 'HTML',
                'text' => "❗️ <b>" . esc($pr['title']) . "</b>\n\n" . esc($pr['text']) . DISCLAIMER,
                'reply_markup' => ['inline_keyboard' => [[btn('❌ هنوز مشکل دارم', 'agent')], home_btn()]]]);
        }
    } else {
        show($chat, $mid, $data === 'home' ? '' : $data);
    }

/* ============================ پیام‌ها ============================ */
} elseif (isset($update['message'])) {
    $m = $update['message']; $chat = $m['chat']['id']; $from = $m['from'] ?? []; $text = trim($m['text'] ?? '');
    $isAdmin = $cfg['admin_chat'] !== '' && (string) $chat === (string) $cfg['admin_chat'];
    $state = jread('state.json');

    if ($text === '/myid') {
        tg('sendMessage', ['chat_id' => $chat, 'text' => "شناسه عددی شما: $chat"]);

    } elseif ($text === '/stats' && $isAdmin) {
        $st = jread('stats.json'); $leads = jread('leads.json');
        $views = $st['views'] ?? []; arsort($views);
        $top = '';
        foreach (array_slice($views, 0, 5, true) as $pid => $n) { $pr = find_problem($pid); $top .= "\n• " . esc($pr['title'] ?? $pid) . " ($n)"; }
        tg('sendMessage', ['chat_id' => $chat, 'parse_mode' => 'HTML', 'text' => "📊 <b>آمار</b>\nکاربران: " . ($st['users'] ?? 0) . "\nدرخواست کارشناس: " . count($leads) . "\nجستجوها: " . ($st['searches'] ?? 0) . "\n\nپربازدیدترین:" . ($top ?: ' —')]);

    } elseif (isset($state[$chat]) && ($text === 'انصراف' || $text === '/start')) {
        jupdate('state.json', function ($s) use ($chat) { unset($s[$chat]); return $s; });
        tg('sendMessage', ['chat_id' => $chat, 'text' => 'لغو شد.', 'reply_markup' => ['remove_keyboard' => true]]);
        show($chat, null, '');

    } elseif (isset($state[$chat])) {                               // در انتظار شماره تماس
        $phone = $m['contact']['phone_number'] ?? (preg_match('/^[\d+\s\-۰-۹]{8,16}$/u', $text) ? $text : '');
        if ($phone === '') {
            tg('sendMessage', ['chat_id' => $chat, 'text' => 'لطفاً شماره را با دکمه ارسال کنید یا به‌صورت عدد بنویسید. برای لغو: «انصراف»']);
        } else {
            save_lead($from, $chat, $state[$chat], $phone);
            jupdate('state.json', function ($s) use ($chat) { unset($s[$chat]); return $s; });
            tg('sendMessage', ['chat_id' => $chat, 'text' => "✅ درخواست شما ثبت شد؛ کارشناس به‌زودی تماس می‌گیرد." . ($cfg['phone'] ? "\n📞 {$cfg['phone']}" : ''), 'reply_markup' => ['remove_keyboard' => true]]);
            show($chat, null, '');
        }

    } elseif ($text === '' || $text === '/start' || $text === '/menu') {
        jupdate('stats.json', function ($s) use ($chat) { $s['seen'][$chat] = 1; $s['users'] = count($s['seen']); return $s; });
        show($chat, null, '');

    } else {                                                        // جستجوی متنی
        stat_inc('searches');
        $res = text_search($text);
        $rows = array_map(fn($pr) => [btn($pr['title'], 's|' . $pr['id'])], $res);
        foreach (site_search($text, 2) as $f) $rows[] = [['text' => mb_substr($f[0], 0, 60), 'url' => $f[1]]];
        $rows[] = [btn('👨‍🔧 کارشناس', 'agent')];
        $rows[] = home_btn();
        tg('sendMessage', ['chat_id' => $chat, 'text' => $res ? '🔎 نزدیک‌ترین موارد:' : 'مورد دقیقی پیدا نشد. از منو انتخاب کنید یا با کارشناس صحبت کنید:', 'reply_markup' => ['inline_keyboard' => $rows]]);
    }
}
http_response_code(200);
