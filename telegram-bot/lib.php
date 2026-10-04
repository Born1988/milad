<?php
/** توابع مشترک ربات و پنل ادمین */

function cfg(): array { static $c; return $c ??= require __DIR__ . '/config.php'; }
function data_dir(): string { $d = cfg()['data_dir'] ?: __DIR__ . '/data'; return rtrim($d, '/') . '/'; }
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function newid(string $p = ''): string { return $p . substr(md5(uniqid('', true)), 0, 5); }

function jread(string $f, array $def = []): array {
    $p = data_dir() . $f;
    if (!is_file($p)) return $def;
    $j = json_decode((string) file_get_contents($p), true);
    return is_array($j) ? $j : $def;
}

/** خواندن-تغییر-نوشتن با قفل. $fn آرایه جدید را برمی‌گرداند */
function jupdate(string $f, callable $fn, array $def = []): array {
    $fh = fopen(data_dir() . $f, 'c+');
    flock($fh, LOCK_EX);
    $d = json_decode((string) stream_get_contents($fh), true);
    $d = $fn(is_array($d) ? $d : $def);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    return $d;
}

function tg(string $method, array $p = []) {
    $ch = curl_init('https://api.telegram.org/bot' . cfg()['bot_token'] . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode($p, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string) $r, true);
}

function catalog(): array {
    return jread('catalog.json', ['brands' => [], 'categories' => [], 'problems' => []]);
}

/** آیا مشکل برای این برند/مدل نمایش داده شود؟ models خالی = همه خودروها */
function problem_matches(array $p, string $brand, string $model): bool {
    $m = $p['models'] ?? [];
    return !$m || in_array($brand, $m, true) || in_array("$brand|$model", $m, true);
}

function stat_inc(string $key, string $sub = ''): void {
    jupdate('stats.json', function ($s) use ($key, $sub) {
        if ($sub === '') $s[$key] = ($s[$key] ?? 0) + 1; else $s[$key][$sub] = ($s[$key][$sub] ?? 0) + 1;
        return $s;
    });
}

/** جستجو در سایت وردپرس (مقالات) و ووکامرس (محصولات). خروجی: [[title,url], ...] */
function site_search(string $q, int $n = 3): array {
    $c = cfg();
    if (empty($c['site_search']) || trim($q) === '') return [];
    $base = rtrim($c['site_url'], '/');
    $get = function (string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true]);
        $r = curl_exec($ch); curl_close($ch);
        $j = json_decode((string) $r, true);
        return is_array($j) ? $j : [];
    };
    $out = [];
    foreach ($get("$base/wp-json/wc/store/v1/products?per_page=$n&search=" . urlencode($q)) as $p) {
        if (isset($p['name'], $p['permalink'])) $out[] = ['🛒 ' . html_entity_decode($p['name'], ENT_QUOTES, 'UTF-8'), $p['permalink']];
    }
    foreach ($get("$base/wp-json/wp/v2/posts?per_page=$n&_fields=title,link&search=" . urlencode($q)) as $p) {
        if (isset($p['title']['rendered'], $p['link'])) $out[] = ['📚 ' . html_entity_decode(strip_tags($p['title']['rendered']), ENT_QUOTES, 'UTF-8'), $p['link']];
    }
    return $out;
}
