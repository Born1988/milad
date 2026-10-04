<?php
// یک بار باز کنید: https://دامنه/telegram-bot/setup.php?key=SECRET
require __DIR__ . '/lib.php';
$c = cfg();
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals($c['secret'], (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
if (!function_exists('curl_init')) exit('افزونه cURL در PHP فعال نیست.');
if (!is_dir(data_dir()) || !is_writable(data_dir())) exit('پوشه data وجود ندارد یا قابل نوشتن نیست: ' . data_dir());

$url = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/webhook.php';
echo "Webhook URL: $url\n\n";
print_r(tg('setWebhook', ['url' => $url, 'secret_token' => hash('sha256', $c['secret']), 'allowed_updates' => ['message', 'callback_query']]));
print_r(tg('setMyCommands', ['commands' => [['command' => 'start', 'description' => 'شروع / منوی اصلی'], ['command' => 'myid', 'description' => 'نمایش شناسه عددی شما']]]));
print_r(tg('getMe'));
