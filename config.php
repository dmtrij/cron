<?php
declare(strict_types=1);

$env = __DIR__.'/.env';
if (is_file($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        putenv(trim($line));
    }
}

define('TELEGRAM_BOT_TOKEN', (string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
define('TELEGRAM_CHAT_ID',  (string)(getenv('TELEGRAM_CHAT_ID')  ?: ''));

// Единый лог-файл (log.txt)
define('CRON_LOG_FILE', __DIR__ . '/logs/log.txt');

// Явный timezone (чтобы даты не “плыли” относительно Украины)
define('CRON_TIMEZONE', 'Europe/Kyiv');

// Таймауты сетевых запросов (сек)
define('HTTP_TIMEOUT_SEC', 10);
