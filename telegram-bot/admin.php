<?php
/** پنل مدیریت وب: برند/مدل، بخش‌ها، مشکلات و راه‌حل‌ها، درخواست‌ها و آمار */
require __DIR__ . '/lib.php';
session_start();
$c = cfg();
$self = basename(__FILE__);

if (isset($_POST['password'])) {
    if ($c['admin_password'] !== 'CHANGE_ME_ADMIN_PASSWORD' && hash_equals($c['admin_password'], $_POST['password'])) {
        session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header("Location: $self"); exit;
    }
    $err = 'رمز اشتباه است (یا هنوز در config.php تغییر نکرده).';
}
if (empty($_SESSION['ok'])) {
    ?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود</title>
    <body dir="rtl" style="font-family:tahoma;max-width:320px;margin:80px auto"><h3>ورود به پنل ربات</h3>
    <?php if (!empty($err)) echo '<p style="color:#c00">' . h($err) . '</p>'; ?>
    <form method="post"><input type="password" name="password" placeholder="رمز" style="width:100%;padding:8px" autofocus>
    <button style="margin-top:8px;padding:8px 16px">ورود</button></form></body><?php
    exit;
}

/* ---------- عملیات (POST + CSRF) ---------- */
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) exit('CSRF');
    $do = $_POST['do']; $t = trim($_POST['title'] ?? '');
    $cat = catalog();
    switch ($do) {
        case 'brand_add': if ($t !== '') $cat['brands'][newid('b')] = ['name' => $t, 'models' => []]; break;
        case 'brand_del': unset($cat['brands'][$_POST['id'] ?? '']); break;
        case 'model_add': if ($t !== '' && isset($cat['brands'][$_POST['brand'] ?? ''])) $cat['brands'][$_POST['brand']]['models'][newid('m')] = $t; break;
        case 'model_del': unset($cat['brands'][$_POST['brand'] ?? '']['models'][$_POST['id'] ?? '']); break;
        case 'cat_add': if ($t !== '') $cat['categories'][newid('c')] = $t; break;
        case 'cat_del': unset($cat['categories'][$_POST['id'] ?? '']); break;
        case 'prob_save':
            $id = $_POST['id'] ?: newid('p');
            $row = ['id' => $id, 'cat' => $_POST['cat'] ?? '', 'title' => $t, 'text' => trim($_POST['text'] ?? ''), 'models' => array_values((array) ($_POST['models'] ?? []))];
            if ($t === '' || !isset($cat['categories'][$row['cat']])) { $msg = 'عنوان و بخش الزامی است.'; break; }
            $done = false;
            foreach ($cat['problems'] as &$p) if ($p['id'] === $id) { $p = $row; $done = true; }
            unset($p);
            if (!$done) $cat['problems'][] = $row;
            $msg = 'ذخیره شد.'; break;
        case 'prob_del': $cat['problems'] = array_values(array_filter($cat['problems'], fn($p) => $p['id'] !== ($_POST['id'] ?? ''))); break;
        case 'lead_del': jupdate('leads.json', fn($l) => array_values(array_filter($l, fn($x) => $x['id'] !== ($_POST['id'] ?? '')))); break;
    }
    if (!str_starts_with($do, 'lead_')) jupdate('catalog.json', fn() => $cat);
    if ($msg === '') { header("Location: $self?v=" . urlencode($_POST['v'] ?? 'problems')); exit; }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: $self"); exit; }

$cat = catalog(); $v = $_GET['v'] ?? 'problems'; $csrf = $_SESSION['csrf'];
function f(string $do, array $hid, string $label, string $cls = '') {
    global $csrf, $v;
    $x = '<form method="post" style="display:inline" onsubmit="' . (str_ends_with($do, '_del') ? "return confirm('حذف شود؟')" : '') . '"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="do" value="' . h($do) . '"><input type="hidden" name="v" value="' . h($v) . '">';
    foreach ($hid as $k => $val) $x .= '<input type="hidden" name="' . h($k) . '" value="' . h($val) . '">';
    return $x . '<button class="' . $cls . '">' . $label . '</button></form>';
}
?><!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پنل ربات</title>
<style>body{font-family:tahoma,sans-serif;max-width:900px;margin:20px auto;padding:0 12px;background:#f6f7f9;color:#222}
nav a{display:inline-block;padding:8px 14px;background:#fff;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#222;margin:2px}
nav a.on{background:#2271b1;color:#fff}.box{background:#fff;border:1px solid #ddd;border-radius:10px;padding:14px;margin:12px 0}
input,select,textarea{padding:7px;border:1px solid #bbb;border-radius:6px;font-family:inherit;max-width:100%}textarea{width:100%}
button{padding:6px 12px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer}button.d{background:#c0392b}
table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #eee;padding:7px;text-align:right;vertical-align:top}.m{color:#666;font-size:.9em}</style>
<h2>پنل مدیریت ربات عیب‌یابی</h2>
<nav><?php foreach (['problems' => 'مشکلات و راه‌حل‌ها', 'catalog' => 'برند / مدل / بخش‌ها', 'leads' => 'درخواست‌ها', 'stats' => 'آمار'] as $k => $n) echo "<a class='" . ($v === $k ? 'on' : '') . "' href='?v=$k'>$n</a>"; ?><a href="?logout=1">خروج</a></nav>
<?php if ($msg) echo '<div class="box">' . h($msg) . '</div>'; ?>

<?php if ($v === 'problems'):
    $edit = null;
    foreach ($cat['problems'] as $p) if ($p['id'] === ($_GET['edit'] ?? '')) $edit = $p;
    $e = $edit ?: ['id' => '', 'cat' => '', 'title' => '', 'text' => '', 'models' => []]; ?>
    <div class="box"><h3><?= $edit ? 'ویرایش مشکل' : 'افزودن مشکل جدید' ?></h3>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="prob_save"><input type="hidden" name="v" value="problems"><input type="hidden" name="id" value="<?= h($e['id']) ?>">
    <p>بخش: <select name="cat"><?php foreach ($cat['categories'] as $id => $n) echo '<option value="' . h($id) . '"' . ($e['cat'] === $id ? ' selected' : '') . '>' . h($n) . '</option>'; ?></select>
    &nbsp; عنوان: <input name="title" value="<?= h($e['title']) ?>" size="40" required></p>
    <p>راه‌حل / توضیح:<br><textarea name="text" rows="6"><?= h($e['text']) ?></textarea></p>
    <p>مخصوص چه خودروهایی؟ <span class="m">(اگر هیچ‌کدام انتخاب نشود، برای همه خودروها نمایش داده می‌شود)</span><br>
    <select name="models[]" multiple size="8" style="min-width:260px"><?php foreach ($cat['brands'] as $bid => $b) { echo '<option value="' . h($bid) . '"' . (in_array($bid, $e['models'], true) ? ' selected' : '') . '>همه ' . h($b['name']) . '</option>';
        foreach ($b['models'] as $mid => $mn) echo '<option value="' . h("$bid|$mid") . '"' . (in_array("$bid|$mid", $e['models'], true) ? ' selected' : '') . '>— ' . h($b['name'] . ' / ' . $mn) . '</option>'; } ?></select></p>
    <button>ذخیره</button> <?= $edit ? '<a href="?v=problems">انصراف</a>' : '' ?></form></div>
    <div class="box"><table><tr><th>بخش</th><th>عنوان</th><th>بازدید</th><th></th></tr>
    <?php $views = jread('stats.json')['views'] ?? [];
    foreach ($cat['problems'] as $p) echo '<tr><td>' . h($cat['categories'][$p['cat']] ?? '?') . '</td><td>' . h($p['title']) . ($p['models'] ? ' <span class="m">(محدود)</span>' : '') . '</td><td>' . (int) ($views[$p['id']] ?? 0) . '</td><td><a href="?v=problems&edit=' . h($p['id']) . '">ویرایش</a> ' . f('prob_del', ['id' => $p['id']], 'حذف', 'd') . '</td></tr>'; ?></table></div>

<?php elseif ($v === 'catalog'): ?>
    <div class="box"><h3>برندها و مدل‌ها</h3>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="brand_add"><input type="hidden" name="v" value="catalog"><input name="title" placeholder="برند جدید" required> <button>افزودن برند</button></form>
    <?php foreach ($cat['brands'] as $bid => $b): ?><hr><b>🚘 <?= h($b['name']) ?></b> <?= f('brand_del', ['id' => $bid], 'حذف برند', 'd') ?><br>
        <?php foreach ($b['models'] as $mid => $mn) echo h($mn) . ' ' . f('model_del', ['brand' => $bid, 'id' => $mid], '✕', 'd') . ' &nbsp; '; ?>
        <form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="model_add"><input type="hidden" name="v" value="catalog"><input type="hidden" name="brand" value="<?= h($bid) ?>"><input name="title" placeholder="مدل جدید" required> <button>افزودن مدل</button></form>
    <?php endforeach; ?></div>
    <div class="box"><h3>بخش‌های خودرو</h3>
    <?php foreach ($cat['categories'] as $id => $n) echo h($n) . ' ' . f('cat_del', ['id' => $id], '✕', 'd') . ' &nbsp; '; ?>
    <form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="cat_add"><input type="hidden" name="v" value="catalog"><input name="title" placeholder="مثلاً 🔩 بدنه" required> <button>افزودن بخش</button></form></div>

<?php elseif ($v === 'leads'): $leads = array_reverse(jread('leads.json')); ?>
    <div class="box"><h3>درخواست‌های کارشناس (<?= count($leads) ?>)</h3><table><tr><th>زمان</th><th>مشتری</th><th>شماره</th><th>موضوع</th><th></th></tr>
    <?php foreach ($leads as $l) echo '<tr><td>' . h($l['time']) . '</td><td>' . h($l['name'] . ' ' . $l['user']) . '</td><td dir="ltr">' . h($l['phone']) . '</td><td>' . h($l['note']) . '</td><td>' . f('lead_del', ['id' => $l['id']], 'حذف', 'd') . '</td></tr>'; ?></table></div>

<?php else: $st = jread('stats.json'); ?>
    <div class="box"><h3>آمار</h3>کاربران ربات: <b><?= (int) ($st['users'] ?? 0) ?></b><br>جستجوهای متنی: <b><?= (int) ($st['searches'] ?? 0) ?></b><br>درخواست کارشناس: <b><?= count(jread('leads.json')) ?></b></div>
<?php endif; ?></html>
