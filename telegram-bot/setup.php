<?php
// یک بار از مرورگر باز کنید: https://YOURDOMAIN/telegram-bot/setup.php?key=SECRET
$c = require __DIR__ . '/config.php';
if (($_GET['key'] ?? '') !== $c['secret']) { http_response_code(403); exit('forbidden'); }
$url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
     . dirname($_SERVER['SCRIPT_NAME']) . '/webhook.php';
$r = file_get_contents("https://api.telegram.org/bot{$c['bot_token']}/setWebhook?" . http_build_query([
    'url' => $url, 'secret_token' => hash('sha256', $c['secret']), 'allowed_updates' => json_encode(['message','callback_query']),
]));
header('Content-Type: text/plain; charset=utf-8');
echo "Webhook: $url\n$r";
