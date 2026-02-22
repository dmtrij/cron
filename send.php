<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function cron_log(string $tag, string $message): void
{
    $line = date('Y-m-d H:i:s') . " [$tag] $message\n";
    $fp = @fopen(CRON_LOG_FILE, 'ab');
    if ($fp === false) {
        return;
    }
    try {
        flock($fp, LOCK_EX);
        fwrite($fp, $line);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * @return bool true если Telegram вернул ok=true
 */
function send_to_telegram(string $text, string $tag = 'telegram'): bool
{
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        cron_log($tag, 'TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID не заданы');
        return false;
    }

    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => HTTP_TIMEOUT_SEC,
        ],
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        // Попробуем вытащить HTTP код из заголовков
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('~HTTP/\S+\s+(\d{3})~', $h, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }
        cron_log($tag, "Ошибка отправки в Telegram (HTTP $httpCode)");
        return false;
    }

    $json = json_decode($result, true);
    if (!is_array($json)) {
        cron_log($tag, 'Telegram вернул не-JSON ответ');
        return false;
    }

    if (!($json['ok'] ?? false)) {
        $desc = (string)($json['description'] ?? 'unknown error');
        cron_log($tag, "Telegram ok=false: $desc");
        return false;
    }

    return true;
}
