<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function cron_log(string $tag, string $message): void
{
    $line = date('Y-m-d H:i:s') . " [$tag] $message\n";
    static $logResetDone = false;
    static $logResetAllowed = null;

    if ($logResetAllowed === null) {
        $candidates = [];
        $scriptFilename = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($scriptFilename !== '') {
            $candidates[] = $scriptFilename;
        }

        $phpSelf = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if ($phpSelf !== '') {
            $candidates[] = $phpSelf;
        }

        if (PHP_SAPI === 'cli' && isset($GLOBALS['argv'][0])) {
            $argv0 = basename((string)$GLOBALS['argv'][0]);
            if ($argv0 !== '') {
                $candidates[] = $argv0;
            }
        }

        $candidates = array_values(array_unique($candidates));
        $logResetAllowed =
            in_array('rss_popular_queue.php', $candidates, true) ||
            $tag === 'news_rss_popular';
    }

    if ($logResetAllowed && !$logResetDone) {
        $fpReset = @fopen(CRON_LOG_FILE, 'c+b');
        if ($fpReset !== false) {
            try {
                flock($fpReset, LOCK_EX);
                ftruncate($fpReset, 0);
                fflush($fpReset);
            } finally {
                flock($fpReset, LOCK_UN);
                fclose($fpReset);
            }
        }
        $logResetDone = true;
    }

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

function send_photo_to_telegram(string $photoPath, string $caption = '', string $tag = 'telegram', bool $captionAboveMedia = false): bool
{
    return send_photo_to_telegram_chat(TELEGRAM_CHAT_ID, $photoPath, $caption, $tag, null, $captionAboveMedia) !== null;
}

function send_photo_to_telegram_chat(
    string $chatId,
    string $photoPath,
    string $caption = '',
    string $tag = 'telegram',
    ?array $replyMarkup = null,
    bool $captionAboveMedia = false
): ?array
{
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        cron_log($tag, 'TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID are empty');
        return null;
    }

    if ($chatId === '') {
        cron_log($tag, 'chat_id is empty');
        return null;
    }

    if (!is_file($photoPath) || !is_readable($photoPath)) {
        cron_log($tag, 'Photo file is not readable: ' . $photoPath);
        return null;
    }

    if (!class_exists('CURLFile')) {
        cron_log($tag, 'CURLFile is unavailable');
        return null;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendPhoto';
    $ch = curl_init($url);
    if ($ch === false) {
        cron_log($tag, 'curl_init failed');
        return null;
    }

    $data = [
        'chat_id' => $chatId,
        'parse_mode' => 'HTML',
    ];

    $mimeType = function_exists('mime_content_type') ? @mime_content_type($photoPath) : false;
    if (!is_string($mimeType) || $mimeType === '') {
        $ext = strtolower((string)pathinfo($photoPath, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    $data['photo'] = new CURLFile($photoPath, $mimeType, basename($photoPath));

    if ($caption !== '') {
        $data['caption'] = $caption;
        $data['show_caption_above_media'] = $captionAboveMedia;
    }

    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_SEC,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);

    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!is_string($result) || $result === '') {
        cron_log($tag, 'Telegram sendPhoto failed; http=' . $httpCode . '; curl=' . $curlError);
        return null;
    }

    $json = json_decode($result, true);
    if (!is_array($json) || !($json['ok'] ?? false)) {
        $desc = is_array($json) ? (string)($json['description'] ?? 'unknown error') : 'bad JSON';
        cron_log($tag, 'Telegram sendPhoto ok=false; http=' . $httpCode . '; ' . $desc);
        return null;
    }

    return $json;
}
