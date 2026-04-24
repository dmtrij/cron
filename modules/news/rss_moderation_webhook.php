<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_news_rss.php';

date_default_timezone_set(CRON_TIMEZONE);

const TAG = 'news_rss_webhook';

$filtersFile = __DIR__ . '/news_filters_manual_rss.json';
$queueFile = __DIR__ . '/news_moderation_queue_rss.json';
$editStateFile = __DIR__ . '/news_moderation_edit_state_rss.json';
$moderationMessagesFile = __DIR__ . '/news_moderation_messages_rss.json';
$historyFile = __DIR__ . '/news_history_24h_rss.json';
$imagesDir = __DIR__ . '/images';

function load_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function save_json_atomic(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $tmp = $path . '.tmp';
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return;
    }

    try {
        flock($fp, LOCK_EX);
        fwrite($fp, $payload);
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    @rename($tmp, $path);
}

function respond_json(int $httpCode, array $payload): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"ok":false}';
    }
    echo $json;
    exit;
}

function telegram_api_post(string $method, array $data, string $tag): ?array
{
    if (TELEGRAM_BOT_TOKEN === '') {
        cron_log($tag, 'TELEGRAM_BOT_TOKEN is empty');
        return null;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => HTTP_TIMEOUT_SEC,
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('~HTTP/\S+\s+(\d{3})~', $h, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }
        cron_log($tag, 'Telegram API ' . $method . ' failed; http=' . $httpCode);
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        cron_log($tag, 'Telegram API ' . $method . ' bad JSON');
        return null;
    }

    if (!($json['ok'] ?? false)) {
        $desc = (string)($json['description'] ?? 'unknown error');
        cron_log($tag, 'Telegram API ' . $method . ' ok=false; ' . $desc);
        return null;
    }

    return $json;
}

function telegram_send_html_message(string $chatId, string $text, string $tag, ?array $replyMarkup = null): ?array
{
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    return telegram_api_post('sendMessage', $data, $tag);
}

function telegram_edit_html_message(string $chatId, int $messageId, string $text, string $tag, ?array $replyMarkup = null): bool
{
    if ($messageId <= 0) {
        return false;
    }

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    return telegram_api_post('editMessageText', $data, $tag) !== null;
}

function telegram_edit_caption_message(string $chatId, int $messageId, string $caption, string $tag, ?array $replyMarkup = null): bool
{
    if ($messageId <= 0) {
        return false;
    }

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];

    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    return telegram_api_post('editMessageCaption', $data, $tag) !== null;
}

function telegram_send_html_reply_message(string $chatId, int $replyToMessageId, string $text, string $tag): bool
{
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_to_message_id' => $replyToMessageId,
        'allow_sending_without_reply' => true,
    ];

    return telegram_api_post('sendMessage', $data, $tag) !== null;
}

function telegram_answer_callback(string $callbackId, string $text): bool
{
    return telegram_api_post('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => false,
    ], TAG) !== null;
}

function telegram_clear_inline_keyboard(string $chatId, int $messageId): void
{
    if ($messageId <= 0) {
        return;
    }

    telegram_api_post('editMessageReplyMarkup', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
    ], TAG);
}

function telegram_delete_message(string $chatId, int $messageId): bool
{
    if ($messageId <= 0) {
        return false;
    }

    return telegram_api_post('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ], TAG) !== null;
}

function telegram_delete_messages_fast(string $chatId, array $messageIds): array
{
    $ids = [];
    foreach ($messageIds as $id) {
        $mid = (int)$id;
        if ($mid > 0) {
            $ids[$mid] = true;
        }
    }
    $ids = array_values(array_keys($ids));
    $attempted = count($ids);
    if ($attempted === 0 || $chatId === '') {
        return ['attempted' => 0, 'deleted' => 0];
    }

    if (!function_exists('curl_multi_init') || TELEGRAM_BOT_TOKEN === '') {
        $deleted = 0;
        foreach ($ids as $mid) {
            if (telegram_delete_message($chatId, (int)$mid)) {
                $deleted++;
            }
        }
        return ['attempted' => $attempted, 'deleted' => $deleted];
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/deleteMessage';
    $parallelLimit = 12;
    $deleted = 0;
    $index = 0;
    $active = [];
    $mh = curl_multi_init();
    if ($mh === false) {
        return ['attempted' => $attempted, 'deleted' => 0];
    }

    $buildHandle = static function (int $messageId) use ($url, $chatId) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]),
        ]);
        return $ch;
    };

    try {
        while ($index < $attempted || $active !== []) {
            while ($index < $attempted && count($active) < $parallelLimit) {
                $messageId = (int)$ids[$index];
                $ch = $buildHandle($messageId);
                if ($ch === false) {
                    $index++;
                    continue;
                }
                $key = (int)$ch;
                $active[$key] = $ch;
                curl_multi_add_handle($mh, $ch);
                $index++;
            }

            do {
                $exec = curl_multi_exec($mh, $running);
            } while ($exec === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'] ?? null;
                if (!is_resource($ch) && !is_object($ch)) {
                    continue;
                }

                $raw = curl_multi_getcontent($ch);
                if ($info['result'] === CURLE_OK && is_string($raw) && $raw !== '') {
                    $json = json_decode($raw, true);
                    if (is_array($json) && ($json['ok'] ?? false)) {
                        $deleted++;
                    }
                }

                $key = (int)$ch;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$key]);
            }

            if (($running ?? 0) > 0) {
                $wait = curl_multi_select($mh, 0.3);
                if ($wait === -1) {
                    usleep(50_000);
                }
            }
        }
    } finally {
        foreach ($active as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    return ['attempted' => $attempted, 'deleted' => $deleted];
}

function tg_html_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_publish_text(array $item): string
{
    $publishText = trim((string)($item['publishText'] ?? ''));
    $edited = trim((string)($item['editedText'] ?? ''));
    if ($edited !== '') {
        if ($publishText !== '') {
            return $edited;
        }
        $patched = $item;
        $patched['title'] = $edited;
        return format_rss_news_message_block([$patched]);
    }

    if ($publishText !== '') {
        return $publishText;
    }

    return format_rss_news_message_block([$item]);
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function queue_image_path(string $imagesDir, string $id): string
{
    return rtrim($imagesDir, '/\\') . DIRECTORY_SEPARATOR . $id . '.jpg';
}

function item_image_path(array $item): string
{
    return trim((string)($item['imagePath'] ?? ''));
}

function telegram_api_get(string $method, array $data, string $tag): ?array
{
    if (TELEGRAM_BOT_TOKEN === '') {
        cron_log($tag, 'TELEGRAM_BOT_TOKEN is empty');
        return null;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method . '?' . http_build_query($data);
    $raw = @file_get_contents($url);
    if ($raw === false || $raw === '') {
        cron_log($tag, 'Telegram API ' . $method . ' GET failed');
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !($json['ok'] ?? false)) {
        cron_log($tag, 'Telegram API ' . $method . ' GET error');
        return null;
    }

    return $json;
}

function telegram_download_photo(array $message, string $targetPath): bool
{
    $photos = is_array($message['photo'] ?? null) ? $message['photo'] : [];
    if ($photos === []) {
        return false;
    }

    $photo = end($photos);
    $fileId = trim((string)($photo['file_id'] ?? ''));
    if ($fileId === '') {
        return false;
    }

    $fileMeta = telegram_api_get('getFile', ['file_id' => $fileId], TAG);
    $filePath = trim((string)($fileMeta['result']['file_path'] ?? ''));
    if ($filePath === '') {
        return false;
    }

    $url = 'https://api.telegram.org/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $filePath;
    $raw = @file_get_contents($url);
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    ensure_dir(dirname($targetPath));
    if (@file_put_contents($targetPath, $raw, LOCK_EX) === false) {
        return false;
    }

    $info = @getimagesize($targetPath);
    if (!is_array($info)) {
        @unlink($targetPath);
        return false;
    }

    return true;
}

function news_should_send_with_image(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    $maxCaptionChars = 900;
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    return $length <= $maxCaptionChars;
}

function send_news_item_to_chat(string $chatId, array $item, string $tag, ?array $replyMarkup = null): ?array
{
    $text = build_publish_text($item);
    if ($text === '') {
        return null;
    }

    $imagePath = item_image_path($item);
    if ($imagePath !== '' && is_file($imagePath) && is_readable($imagePath) && news_should_send_with_image($text)) {
        return send_photo_to_telegram_chat($chatId, $imagePath, $text, $tag, $replyMarkup);
    }

    return telegram_send_html_message($chatId, $text, $tag, $replyMarkup);
}

function format_edit_saved_message(array $item): string
{
    $base = build_publish_text($item);
    if ($base === '') {
        return '<b>Черновик сохранен. Нажмите "Опубликовать" для публикации.</b>';
    }

    return "<b>Черновик сохранен. Нажмите \"Опубликовать\" для публикации.</b>\n\n" . $base;
}

function format_moderation_decision_message(array $item, string $status): string
{
    $base = build_publish_text($item);
    if ($base === '') {
        $title = tg_html_escape((string)($item['title'] ?? 'Untitled'));
        $url = trim((string)($item['url'] ?? ''));
        if ($url !== '') {
            $base = '<b>' . $title . '</b>' . "\n\n" . '<a href="' . tg_html_escape($url) . '">Открыть источник</a>';
        } else {
            $base = '<b>' . $title . '</b>';
        }
    }

    if ($status === 'approved') {
        return "<b>🟢 Новость опубликована</b>\n\n" . $base;
    }

    return "<b>🔴 Новость отклонена</b>\n\n" . $base;
}

function format_moderation_decision_message_safe(array $item, string $status): string
{
    $title = rss_news_fix_mojibake(trim((string)($item['title'] ?? '')));
    if ($title === '') {
        $title = 'Без заголовка';
    }

    if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 180) {
        $title = mb_substr($title, 0, 177, 'UTF-8') . '...';
    }

    $url = trim((string)($item['url'] ?? ''));
    $prefix = $status === 'approved'
        ? "<b>\u{1F7E2} Новость опубликована</b>"
        : "<b>\u{1F534} Новость отклонена</b>";

    $body = '<b>' . tg_html_escape($title) . '</b>';
    if ($url !== '') {
        $body .= "\n" . '<a href="' . tg_html_escape($url) . '">Источник</a>';
    }

    return $prefix . "\n\n" . $body;
}

function format_moderation_decision_message_safe_v2(array $item, string $status): string
{
    $title = rss_news_fix_mojibake(trim((string)($item['title'] ?? '')));
    if ($title === '') {
        $title = "\u{0411}\u{0435}\u{0437} \u{0437}\u{0430}\u{0433}\u{043E}\u{043B}\u{043E}\u{0432}\u{043A}\u{0430}";
    }

    if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 180) {
        $title = mb_substr($title, 0, 177, 'UTF-8') . '...';
    }

    $url = trim((string)($item['url'] ?? ''));
    $prefix = $status === 'approved'
        ? "<b>\u{1F7E2} \u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0442}\u{044C} \u{043E}\u{043F}\u{0443}\u{0431}\u{043B}\u{0438}\u{043A}\u{043E}\u{0432}\u{0430}\u{043D}\u{0430}</b>"
        : "<b>\u{1F534} \u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0442}\u{044C} \u{043E}\u{0442}\u{043A}\u{043B}\u{043E}\u{043D}\u{0435}\u{043D}\u{0430}</b>";

    $body = '<b>' . tg_html_escape($title) . '</b>';
    if ($url !== '') {
        $body .= "\n" . '<a href="' . tg_html_escape($url) . '">' .
            "\u{0418}\u{0441}\u{0442}\u{043E}\u{0447}\u{043D}\u{0438}\u{043A}" .
            '</a>';
    }

    return $prefix . "\n\n" . $body;
}

function telegram_set_moderation_decision_message(string $chatId, int $messageId, array $item, string $status): void
{
    if ($messageId <= 0) {
        return;
    }

    $text = format_moderation_decision_message_safe_v2($item, $status);
    if (item_image_path($item) !== '') {
        $ok = telegram_edit_caption_message($chatId, $messageId, $text, TAG, ['inline_keyboard' => []]);
        if (!$ok) {
            telegram_clear_inline_keyboard($chatId, $messageId);
            telegram_send_html_reply_message($chatId, $messageId, $text, TAG);
        }
        return;
    }

    $ok = telegram_edit_html_message($chatId, $messageId, $text, TAG, ['inline_keyboard' => []]);
    if (!$ok) {
        telegram_clear_inline_keyboard($chatId, $messageId);
        telegram_send_html_reply_message($chatId, $messageId, $text, TAG);
    }
}

function telegram_set_moderation_generic_message(string $chatId, int $messageId, string $header): void
{
    if ($messageId <= 0) {
        return;
    }

    $text = '<b>' . tg_html_escape($header) . '</b>';
    if ($header !== '' && $header === 'Уже обработано') {
        $ok = telegram_edit_html_message($chatId, $messageId, $text, TAG, ['inline_keyboard' => []]);
        if (!$ok) {
            telegram_clear_inline_keyboard($chatId, $messageId);
        }
        return;
    }

    $ok = telegram_edit_html_message($chatId, $messageId, $text, TAG, ['inline_keyboard' => []]);
    if (!$ok) {
        telegram_clear_inline_keyboard($chatId, $messageId);
        telegram_send_html_reply_message($chatId, $messageId, $text, TAG);
    }
}
function parse_allowed_moderators(array $cfg): array
{
    $ids = [];
    if (isset($cfg['moderator_user_ids']) && is_array($cfg['moderator_user_ids'])) {
        foreach ($cfg['moderator_user_ids'] as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }

    return array_values(array_unique($ids));
}

function load_queue(string $path): array
{
    $q = load_json($path);
    if (!isset($q['items']) || !is_array($q['items'])) {
        $q['items'] = [];
    }
    return $q;
}

function moderation_window_sec(array $cfg): int
{
    if (isset($cfg['window_minutes'])) {
        return max(1, (int)$cfg['window_minutes']) * 60;
    }
    if (isset($cfg['window_hours'])) {
        return max(1, (int)$cfg['window_hours']) * 3600;
    }
    return 30 * 60;
}

function prune_queue(array &$queue, int $now, int $windowSec): bool
{
    $changed = false;

    foreach (($queue['items'] ?? []) as $id => $item) {
        if (!is_array($item)) {
            unset($queue['items'][$id]);
            $changed = true;
            continue;
        }

        $created = (int)($item['createdAt'] ?? 0);
        $ttl = max(60, $windowSec);

        if ($created <= 0 || ($now - $created) > $ttl) {
            unset($queue['items'][$id]);
            $changed = true;
        }
    }

    return $changed;
}

function load_edit_state(string $path): array
{
    $state = load_json($path);
    if (!isset($state['sessions']) || !is_array($state['sessions'])) {
        $state['sessions'] = [];
    }
    return $state;
}

function save_edit_state(string $path, array $state): void
{
    if (!isset($state['sessions']) || !is_array($state['sessions'])) {
        $state['sessions'] = [];
    }
    save_json_atomic($path, $state);
}

function load_history_state(string $path): array
{
    $state = load_json($path);
    if (!isset($state['items']) || !is_array($state['items'])) {
        $state['items'] = [];
    }
    return $state;
}

function save_history_state(string $path, array $state): void
{
    if (!isset($state['items']) || !is_array($state['items'])) {
        $state['items'] = [];
    }
    save_json_atomic($path, $state);
}

function history_prune(array &$state, int $now, int $ttlSec = 86400): bool
{
    $changed = false;
    $items = is_array($state['items'] ?? null) ? $state['items'] : [];
    $kept = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            $changed = true;
            continue;
        }

        $publishedAt = (int)($item['publishedAt'] ?? 0);
        if ($publishedAt <= 0 || ($now - $publishedAt) > $ttlSec) {
            $changed = true;
            continue;
        }

        $kept[] = $item;
    }

    if ($changed || count($kept) !== count($items)) {
        $state['items'] = array_values($kept);
        return true;
    }

    return false;
}

function history_add_item(string $path, array $item, string $flow, int $publishedAt): void
{
    $state = load_history_state($path);
    history_prune($state, $publishedAt);
    $state['items'][] = [
        'url' => trim((string)($item['url'] ?? '')),
        'title' => trim((string)($item['title'] ?? '')),
        'src' => (string)($item['src'] ?? 'RSS'),
        'flow' => $flow,
        'publishedAt' => $publishedAt,
    ];
    save_history_state($path, $state);
}

function load_moderation_messages_state(string $path): array
{
    $state = load_json($path);
    if (!isset($state['messages']) || !is_array($state['messages'])) {
        $state['messages'] = [];
    }
    return $state;
}

function save_moderation_messages_state(string $path, array $state): void
{
    if (!isset($state['messages']) || !is_array($state['messages'])) {
        $state['messages'] = [];
    }
    save_json_atomic($path, $state);
}

function moderation_messages_add(string $path, string $chatId, int $messageId): void
{
    if ($chatId === '' || $messageId <= 0) {
        return;
    }

    $state = load_moderation_messages_state($path);
    $messages = is_array($state['messages'][$chatId] ?? null) ? $state['messages'][$chatId] : [];
    $messages[] = $messageId;
    $messages = array_values(array_unique(array_filter(array_map('intval', $messages), static fn (int $id): bool => $id > 0)));
    $state['messages'][$chatId] = $messages;
    save_moderation_messages_state($path, $state);
}

function moderation_messages_remove(string $path, string $chatId, int $messageId): void
{
    if ($chatId === '' || $messageId <= 0) {
        return;
    }

    $state = load_moderation_messages_state($path);
    $messages = is_array($state['messages'][$chatId] ?? null) ? $state['messages'][$chatId] : [];
    $messages = array_values(array_filter(
        array_map('intval', $messages),
        static fn (int $id): bool => $id > 0 && $id !== $messageId
    ));
    $state['messages'][$chatId] = $messages;
    save_moderation_messages_state($path, $state);
}

function moderation_messages_clear_chat(string $path, string $chatId): void
{
    if ($chatId === '') {
        return;
    }

    $state = load_moderation_messages_state($path);
    $state['messages'][$chatId] = [];
    save_moderation_messages_state($path, $state);
}

function prune_edit_state(array &$state, int $now): bool
{
    if (!isset($state['sessions']) || !is_array($state['sessions'])) {
        $state['sessions'] = [];
        return false;
    }

    $changed = false;
    foreach ($state['sessions'] as $key => $session) {
        if (!is_array($session)) {
            unset($state['sessions'][$key]);
            $changed = true;
            continue;
        }

        $exp = (int)($session['expiresAt'] ?? 0);
        if ($exp > 0 && $exp < $now) {
            unset($state['sessions'][$key]);
            $changed = true;
        }
    }

    return $changed;
}

function edit_state_key(string $chatId, string $userId): string
{
    return $chatId . ':' . $userId;
}

function moderation_reply_markup(string $itemId): array
{
    $publish = "\u{041E}\u{043F}\u{0443}\u{0431}\u{043B}\u{0438}\u{043A}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}";
    $reject = "\u{041E}\u{0442}\u{043A}\u{043B}\u{043E}\u{043D}\u{0438}\u{0442}\u{044C}";
    $edit = "\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}";

    $clear = "\u{1F9F9}";

    return [
        'inline_keyboard' => [
            [
                ['text' => $publish, 'callback_data' => 'rssmod:pub:' . $itemId],
                ['text' => $reject, 'callback_data' => 'rssmod:rej:' . $itemId],
            ],
            [
                ['text' => $edit, 'callback_data' => 'rssmod:edit:' . $itemId],

            ],
            [
                ['text' => $clear, 'callback_data' => 'rssmod:clear:' . $itemId],
            ],
        ],
    ];
}

function find_pending_item_id_by_message(array $queue, string $chatId, int $messageId): ?string
{
    foreach (($queue['items'] ?? []) as $id => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string)($item['status'] ?? '') !== 'pending') {
            continue;
        }
        if ((string)($item['moderatorChatId'] ?? '') !== $chatId) {
            continue;
        }
        if ((int)($item['moderationMessageId'] ?? 0) === $messageId) {
            return (string)$id;
        }
    }

    return null;
}

function find_any_item_id_by_message(array $queue, string $chatId, int $messageId): ?string
{
    foreach (($queue['items'] ?? []) as $id => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string)($item['moderatorChatId'] ?? '') !== $chatId) {
            continue;
        }
        if ((int)($item['moderationMessageId'] ?? 0) === $messageId) {
            return (string)$id;
        }
    }

    return null;
}

function find_single_pending_item_id(array $queue, string $chatId): ?string
{
    $found = [];
    foreach (($queue['items'] ?? []) as $id => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string)($item['status'] ?? '') !== 'pending') {
            continue;
        }
        if ((string)($item['moderatorChatId'] ?? '') !== $chatId) {
            continue;
        }
        $found[] = (string)$id;
        if (count($found) > 1) {
            return null;
        }
    }

    return $found[0] ?? null;
}

function webhook_secret_is_valid(string $secret): bool
{
    if ($secret === '') {
        return true;
    }

    $querySecret = trim((string)($_GET['secret'] ?? ''));
    if ($querySecret !== '' && hash_equals($secret, $querySecret)) {
        return true;
    }

    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if ($headerSecret !== '' && hash_equals($secret, $headerSecret)) {
        return true;
    }

    return false;
}

function read_update_payload(): ?array
{
    $raw = @file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

$cfg = load_json($filtersFile);
$windowSec = moderation_window_sec($cfg);

$moderatorChatId = trim((string)($cfg['moderator_chat_id'] ?? (getenv('TELEGRAM_MODERATOR_CHAT_ID') ?: '')));
$publishChatId = trim((string)($cfg['publish_chat_id'] ?? TELEGRAM_CHAT_ID));
$allowedModerators = parse_allowed_moderators($cfg);
$webhookSecret = trim((string)($cfg['moderation_webhook_secret'] ?? (getenv('TELEGRAM_MODERATION_WEBHOOK_SECRET') ?: '')));

if (!webhook_secret_is_valid($webhookSecret)) {
    cron_log(TAG, 'WEBHOOK forbidden: invalid secret');
    respond_json(403, ['ok' => false, 'error' => 'forbidden']);
}

if (($cfg['moderation_enabled'] ?? true) === false) {
    respond_json(200, ['ok' => true, 'ignored' => 'moderation_disabled']);
}

if ($moderatorChatId === '' || $publishChatId === '') {
    cron_log(TAG, 'moderator_chat_id or publish_chat_id is empty');
    respond_json(200, ['ok' => false, 'error' => 'bad_config']);
}

$update = read_update_payload();
if ($update === null) {
    respond_json(200, ['ok' => true, 'ignored' => 'empty_payload']);
}

$queue = load_queue($queueFile);
if (prune_queue($queue, time(), $windowSec)) {
    save_json_atomic($queueFile, $queue);
    moderation_messages_clear_chat($moderationMessagesFile, $moderatorChatId);
}

$editState = load_edit_state($editStateFile);
if (prune_edit_state($editState, time())) {
    save_edit_state($editStateFile, $editState);
}

$message = $update['message'] ?? null;
if (is_array($message)) {
    $messageChatId = trim((string)($message['chat']['id'] ?? ''));
    $messageFromId = trim((string)($message['from']['id'] ?? ''));
    $messageId = (int)($message['message_id'] ?? 0);
    $messageText = trim((string)($message['text'] ?? ''));
    $hasPhoto = is_array($message['photo'] ?? null) && ($message['photo'] ?? []) !== [];

    if ($messageChatId !== $moderatorChatId) {
        respond_json(200, ['ok' => true, 'ignored' => 'message_wrong_chat']);
    }

    if ($allowedModerators !== [] && !in_array($messageFromId, $allowedModerators, true)) {
        respond_json(200, ['ok' => true, 'ignored' => 'message_not_allowed']);
    }

    $stateKey = edit_state_key($messageChatId, $messageFromId);
    $session = $editState['sessions'][$stateKey] ?? null;
    if (!is_array($session)) {
        respond_json(200, ['ok' => true, 'ignored' => 'no_edit_session']);
    }

    if ($messageText !== '' && mb_strtolower($messageText, 'UTF-8') === '/cancel') {
        unset($editState['sessions'][$stateKey]);
        save_edit_state($editStateFile, $editState);
        telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В Р’В Р В Р’ВµР В РўвЂР В Р’В°Р В РЎвЂќР РЋРІР‚С™Р В РЎвЂР РЋР вЂљР В РЎвЂўР В Р вЂ Р В Р’В°Р В Р вЂ¦Р В РЎвЂР В Р’Вµ Р В РЎвЂўР РЋРІР‚С™Р В РЎВР В Р’ВµР В Р вЂ¦Р В Р’ВµР В Р вЂ¦Р В РЎвЂў.</b>', TAG);
        respond_json(200, ['ok' => true, 'status' => 'edit_cancelled']);
    }

    $sessionMode = trim((string)($session['mode'] ?? 'text'));
    $sessionItemId = trim((string)($session['itemId'] ?? ''));
    $sessionMessageId = (int)($session['messageId'] ?? 0);

    if ($sessionItemId === '' || !isset($queue['items'][$sessionItemId]) || !is_array($queue['items'][$sessionItemId])) {
        unset($editState['sessions'][$stateKey]);
        save_edit_state($editStateFile, $editState);
        telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В РІР‚вЂќР В Р’В°Р В РўвЂР В Р’В°Р РЋРІР‚РЋР В Р’В° Р В Р вЂ¦Р В Р’Вµ Р В Р вЂ¦Р В Р’В°Р В РІвЂћвЂ“Р В РўвЂР В Р’ВµР В Р вЂ¦Р В Р’В°.</b>', TAG);
        respond_json(200, ['ok' => true, 'status' => 'edit_task_not_found']);
    }

    if ((string)($queue['items'][$sessionItemId]['status'] ?? 'pending') !== 'pending') {
        unset($editState['sessions'][$stateKey]);
        save_edit_state($editStateFile, $editState);
        telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В РІР‚вЂќР В Р’В°Р В РўвЂР В Р’В°Р РЋРІР‚РЋР В Р’В° Р РЋРЎвЂњР В Р’В¶Р В Р’Вµ Р В РЎвЂўР В Р’В±Р РЋР вЂљР В Р’В°Р В Р’В±Р В РЎвЂўР РЋРІР‚С™Р В Р’В°Р В Р вЂ¦Р В Р’В°.</b>', TAG);
        respond_json(200, ['ok' => true, 'status' => 'edit_task_processed']);
    }

    if ($sessionMode === 'image') {
        if (!$hasPhoto) {
            telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В РЎвЂєР РЋРІР‚С™Р В РЎвЂ”Р РЋР вЂљР В Р’В°Р В Р вЂ Р РЋР Р‰Р РЋРІР‚С™Р В Р’Вµ Р РЋРІР‚С›Р В РЎвЂўР РЋРІР‚С™Р В РЎвЂў Р В РЎвЂўР В РўвЂР В Р вЂ¦Р В РЎвЂР В РЎВ Р РЋР С“Р В РЎвЂўР В РЎвЂўР В Р’В±Р РЋРІР‚В°Р В Р’ВµР В Р вЂ¦Р В РЎвЂР В Р’ВµР В РЎВ.</b> Р В РІР‚СњР В Р’В»Р РЋР РЏ Р В РЎвЂўР РЋРІР‚С™Р В РЎВР В Р’ВµР В Р вЂ¦Р РЋРІР‚в„–: /cancel', TAG);
            respond_json(200, ['ok' => true, 'ignored' => 'image_expected']);
        }

        $imagePath = queue_image_path($imagesDir, $sessionItemId);
        if (!telegram_download_photo($message, $imagePath)) {
            telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В РЎСљР В Р’Вµ Р РЋРЎвЂњР В РўвЂР В Р’В°Р В Р’В»Р В РЎвЂўР РЋР С“Р РЋР Р‰ Р РЋР С“Р В РЎвЂўР РЋРІР‚В¦Р РЋР вЂљР В Р’В°Р В Р вЂ¦Р В РЎвЂР РЋРІР‚С™Р РЋР Р‰ Р РЋРІР‚С›Р В РЎвЂўР РЋРІР‚С™Р В РЎвЂў.</b>', TAG);
            respond_json(200, ['ok' => true, 'status' => 'image_save_failed']);
        }

        $queue['items'][$sessionItemId]['imagePath'] = $imagePath;
        $queue['items'][$sessionItemId]['imageUpdatedAt'] = time();
        $queue['items'][$sessionItemId]['imageUpdatedBy'] = $messageFromId;
        save_json_atomic($queueFile, $queue);

        unset($editState['sessions'][$stateKey]);
        save_edit_state($editStateFile, $editState);

        $previewResp = send_news_item_to_chat($messageChatId, $queue['items'][$sessionItemId], TAG, moderation_reply_markup($sessionItemId));
        if ($previewResp !== null) {
            if ($sessionMessageId > 0) {
                telegram_delete_message($messageChatId, $sessionMessageId);
                moderation_messages_remove($moderationMessagesFile, $messageChatId, $sessionMessageId);
            }
            $newMessageId = (int)($previewResp['result']['message_id'] ?? 0);
            $queue['items'][$sessionItemId]['moderationMessageId'] = $newMessageId;
            moderation_messages_add($moderationMessagesFile, $messageChatId, $newMessageId);
            save_json_atomic($queueFile, $queue);
        } else {
            telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В Р’В¤Р В РЎвЂўР РЋРІР‚С™Р В РЎвЂў Р РЋР С“Р В РЎвЂўР РЋРІР‚В¦Р РЋР вЂљР В Р’В°Р В Р вЂ¦Р В Р’ВµР В Р вЂ¦Р В РЎвЂў, Р В Р вЂ¦Р В РЎвЂў Р В РЎвЂ”Р РЋР вЂљР В Р’ВµР В Р вЂ Р РЋР Р‰Р РЋР вЂ№ Р В Р вЂ¦Р В Р’Вµ Р В РЎвЂўР В Р’В±Р В Р вЂ¦Р В РЎвЂўР В Р вЂ Р В РЎвЂР В Р’В»Р В РЎвЂўР РЋР С“Р РЋР Р‰.</b>', TAG);
        }

        cron_log(TAG, 'IMAGE_REPLACED id=' . $sessionItemId . '; by=' . $messageFromId);
        respond_json(200, ['ok' => true, 'status' => 'image_replaced']);
    }

    if ($messageText === '') {
        respond_json(200, ['ok' => true, 'ignored' => 'empty_edit_text']);
    }

    if (mb_strlen($messageText, 'UTF-8') > 3500) {
        telegram_send_html_reply_message($messageChatId, $messageId, '<b>Р В РЎС›Р В Р’ВµР В РЎвЂќР РЋР С“Р РЋРІР‚С™ Р РЋР С“Р В Р’В»Р В РЎвЂР РЋРІвЂљВ¬Р В РЎвЂќР В РЎвЂўР В РЎВ Р В РўвЂР В Р’В»Р В РЎвЂР В Р вЂ¦Р В Р вЂ¦Р РЋРІР‚в„–Р В РІвЂћвЂ“.</b> Р В РЎС™Р В Р’В°Р В РЎвЂќР РЋР С“Р В РЎвЂР В РЎВР РЋРЎвЂњР В РЎВ 3500 Р РЋР С“Р В РЎвЂР В РЎВР В Р вЂ Р В РЎвЂўР В Р’В»Р В РЎвЂўР В Р вЂ .', TAG);
        respond_json(200, ['ok' => true, 'status' => 'edit_too_long']);
    }

    $queue['items'][$sessionItemId]['editedText'] = $messageText;
    $queue['items'][$sessionItemId]['editedAt'] = time();
    $queue['items'][$sessionItemId]['editedBy'] = $messageFromId;
    save_json_atomic($queueFile, $queue);

    unset($editState['sessions'][$stateKey]);
    save_edit_state($editStateFile, $editState);

    $savedText = format_edit_saved_message($queue['items'][$sessionItemId]);
    if ($sessionMessageId > 0) {
        $ok = telegram_edit_html_message($messageChatId, $sessionMessageId, $savedText, TAG, moderation_reply_markup($sessionItemId));
        if (!$ok) {
            telegram_send_html_reply_message($messageChatId, $sessionMessageId, $savedText, TAG);
        }
    } else {
        telegram_send_html_reply_message($messageChatId, $messageId, $savedText, TAG);
    }

    cron_log(TAG, 'EDIT_SAVED id=' . $sessionItemId . '; by=' . $messageFromId);
    respond_json(200, ['ok' => true, 'status' => 'edited']);
}
$cb = $update['callback_query'] ?? null;
if (!is_array($cb)) {
    respond_json(200, ['ok' => true, 'ignored' => 'not_callback']);
}

$callbackId = (string)($cb['id'] ?? '');
$data = (string)($cb['data'] ?? '');
$fromId = trim((string)($cb['from']['id'] ?? ''));
$chatId = trim((string)($cb['message']['chat']['id'] ?? ''));
$messageId = (int)($cb['message']['message_id'] ?? 0);

if ($callbackId === '') {
    respond_json(200, ['ok' => true, 'ignored' => 'empty_callback_id']);
}

if ($chatId !== $moderatorChatId) {
    telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРЎС™Р В Р’В Р вЂ™Р’ВµР В Р’В Р В РІР‚В Р В Р’В Р вЂ™Р’ВµР В Р Р‹Р В РІР‚С™Р В Р’В Р В РІР‚В¦Р В Р Р‹Р Р†Р вЂљРІвЂћвЂ“Р В Р’В Р Р†РІР‚С›РІР‚вЂњ Р В Р Р‹Р Р†Р вЂљР Р‹Р В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљРЎв„ў');
    respond_json(200, ['ok' => true, 'ignored' => 'wrong_chat']);
}

if ($allowedModerators !== [] && !in_array($fromId, $allowedModerators, true)) {
    telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРЎС™Р В Р’В Р вЂ™Р’ВµР В Р Р‹Р Р†Р вЂљРЎв„ў Р В Р’В Р СћРІР‚ВР В Р’В Р РЋРІР‚СћР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р Р‹Р РЋРІР‚СљР В Р’В Р РЋРІР‚вЂќР В Р’В Р вЂ™Р’В°');
    respond_json(200, ['ok' => true, 'ignored' => 'not_allowed']);
}
if (!preg_match('~^rssmod:(pub|rej|edit|clear):([a-f0-9]{20})$~', $data, $m)) {
    telegram_answer_callback($callbackId, 'Р СњР ВµР С‘Р В·Р Р†Р ВµРЎРѓРЎвЂљР Р…Р С•Р Вµ Р Т‘Р ВµР в„–РЎРѓРЎвЂљР Р†Р С‘Р Вµ');
    telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРЎС™Р В Р’В Р вЂ™Р’ВµР В Р’В Р РЋРІР‚ВР В Р’В Р вЂ™Р’В·Р В Р’В Р В РІР‚В Р В Р’В Р вЂ™Р’ВµР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’Вµ Р В Р’В Р СћРІР‚ВР В Р’В Р вЂ™Р’ВµР В Р’В Р Р†РІР‚С›РІР‚вЂњР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р В РІР‚В Р В Р’В Р РЋРІР‚ВР В Р’В Р вЂ™Р’Вµ');
    cron_log(TAG, 'IGNORE unknown callback data=' . $data);
    respond_json(200, ['ok' => true, 'ignored' => 'unknown_action']);
}

$action = $m[1];
$itemId = $m[2];

if ($action === 'clear') {
    $removed = 0;
    $messagesState = load_moderation_messages_state($moderationMessagesFile);
    $messageIds = is_array($messagesState['messages'][$chatId] ?? null)
        ? array_values(array_unique(array_map('intval', $messagesState['messages'][$chatId])))
        : [];

    foreach (($queue['items'] ?? []) as $queuedId => $queuedItem) {
        if (!is_array($queuedItem)) {
            continue;
        }
        if ((string)($queuedItem['moderatorChatId'] ?? '') !== $chatId) {
            continue;
        }

        unset($queue['items'][$queuedId]);
        $removed++;
    }

    $deleteStat = telegram_delete_messages_fast($chatId, $messageIds);
    $deleted = (int)($deleteStat['deleted'] ?? 0);

    $editChanged = false;
    foreach (($editState['sessions'] ?? []) as $sessionKey => $session) {
        if (!is_array($session)) {
            unset($editState['sessions'][$sessionKey]);
            $editChanged = true;
            continue;
        }
        if ((string)($session['chatId'] ?? '') === $chatId) {
            unset($editState['sessions'][$sessionKey]);
            $editChanged = true;
        }
    }
    if ($editChanged) {
        save_edit_state($editStateFile, $editState);
    }

    save_json_atomic($queueFile, $queue);
    telegram_answer_callback($callbackId, 'Р РЋР вЂљР РЋРЎСџР вЂ™Р’В§Р Р†РІР‚С›РІР‚вЂњ Р В Р’В Р РЋРІР‚С”Р В Р Р‹Р Р†Р вЂљР Р‹Р В Р’В Р РЋРІР‚ВР В Р Р‹Р Р†Р вЂљР’В°Р В Р’В Р вЂ™Р’ВµР В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ: ' . $removed);
    cron_log(TAG, 'CHAT_CLEARED by=' . $fromId . '; chat=' . $chatId . '; removed=' . $removed . '; deleted=' . $deleted);
    respond_json(200, ['ok' => true, 'status' => 'chat_cleared', 'removed' => $removed, 'deleted' => $deleted]);
}

if (!isset($queue['items'][$itemId]) || !is_array($queue['items'][$itemId])) {
    $byMessage = find_pending_item_id_by_message($queue, $chatId, $messageId);
    if ($byMessage !== null) {
        $itemId = $byMessage;
        cron_log(TAG, 'FALLBACK by message_id; callback_id=' . $m[2] . '; resolved=' . $itemId);
    } else {
        $singlePending = find_single_pending_item_id($queue, $chatId);
        if ($singlePending !== null) {
            $itemId = $singlePending;
            cron_log(TAG, 'FALLBACK single pending; callback_id=' . $m[2] . '; resolved=' . $itemId);
        }
    }
}

if (!isset($queue['items'][$itemId]) || !is_array($queue['items'][$itemId])) {
    $anyByMessage = find_any_item_id_by_message($queue, $chatId, $messageId);
    if ($anyByMessage !== null) {
        telegram_answer_callback($callbackId, 'Р В Р’В Р В РІвЂљВ¬Р В Р’В Р вЂ™Р’В¶Р В Р’В Р вЂ™Р’Вµ Р В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’В±Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’В°Р В Р’В Р вЂ™Р’В±Р В Р’В Р РЋРІР‚СћР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ');
        telegram_set_moderation_generic_message($chatId, $messageId, 'Р В Р’В Р В РІвЂљВ¬Р В Р’В Р вЂ™Р’В¶Р В Р’В Р вЂ™Р’Вµ Р В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’В±Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’В°Р В Р’В Р вЂ™Р’В±Р В Р’В Р РЋРІР‚СћР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ');
        cron_log(TAG, 'ALREADY_PROCESSED by message; id=' . $anyByMessage);
        respond_json(200, ['ok' => true, 'status' => 'already_processed']);
    }

    telegram_answer_callback($callbackId, 'Р В Р’В Р Р†Р вЂљРІР‚СњР В Р’В Р вЂ™Р’В°Р В Р’В Р СћРІР‚ВР В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљР Р‹Р В Р’В Р вЂ™Р’В° Р В Р Р‹Р РЋРІР‚СљР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’В°Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’ВµР В Р’В Р вЂ™Р’В»Р В Р’В Р вЂ™Р’В°');
    telegram_set_moderation_generic_message($chatId, $messageId, 'Р В Р’В Р Р†Р вЂљРІР‚СњР В Р’В Р вЂ™Р’В°Р В Р’В Р СћРІР‚ВР В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљР Р‹Р В Р’В Р вЂ™Р’В° Р В Р Р‹Р РЋРІР‚СљР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’В°Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’ВµР В Р’В Р вЂ™Р’В»Р В Р’В Р вЂ™Р’В°');
    cron_log(TAG, 'NOT_FOUND callback_id=' . $m[2] . '; msg=' . $messageId);
    respond_json(200, ['ok' => true, 'status' => 'task_expired']);
}

$item = $queue['items'][$itemId];
$status = (string)($item['status'] ?? 'pending');
if ($status !== 'pending') {
    telegram_answer_callback($callbackId, 'Р В Р’В Р В РІвЂљВ¬Р В Р’В Р вЂ™Р’В¶Р В Р’В Р вЂ™Р’Вµ Р В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’В±Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’В°Р В Р’В Р вЂ™Р’В±Р В Р’В Р РЋРІР‚СћР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ');
    telegram_set_moderation_generic_message($chatId, $messageId, 'Р В Р’В Р В РІвЂљВ¬Р В Р’В Р вЂ™Р’В¶Р В Р’В Р вЂ™Р’Вµ Р В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’В±Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’В°Р В Р’В Р вЂ™Р’В±Р В Р’В Р РЋРІР‚СћР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ');
    cron_log(TAG, 'ALREADY_PROCESSED id=' . $itemId);
    respond_json(200, ['ok' => true, 'status' => 'already_processed']);
}

$now = time();

if ($action === 'edit') {
    $stateKey = edit_state_key($chatId, $fromId);
    $editState['sessions'][$stateKey] = [
        'itemId' => $itemId,
        'messageId' => $messageId,
        'chatId' => $chatId,
        'userId' => $fromId,
        'createdAt' => $now,
        'expiresAt' => $now + 1800,
    ];
    save_edit_state($editStateFile, $editState);

    telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРІР‚С”Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р РЋРІР‚вЂќР В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В Р В Р Р‹Р В Р вЂ°Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’Вµ Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚СћР В Р’В Р В РІР‚В Р В Р Р‹Р Р†Р вЂљРІвЂћвЂ“Р В Р’В Р Р†РІР‚С›РІР‚вЂњ Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’ВµР В Р’В Р РЋРІР‚СњР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ў');
    telegram_send_html_reply_message(
        $chatId,
        $messageId,
        '<b>Р В Р’В Р вЂ™Р’В Р В Р’В Р вЂ™Р’ВµР В Р’В Р вЂ™Р’В¶Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋР’В Р В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’ВµР В Р’В Р СћРІР‚ВР В Р’В Р вЂ™Р’В°Р В Р’В Р РЋРІР‚СњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р РЋРІР‚ВР В Р Р‹Р В РІР‚С™Р В Р’В Р РЋРІР‚СћР В Р’В Р В РІР‚В Р В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚ВР В Р Р‹Р В Р РЏ.</b> Р В Р’В Р РЋРІР‚С”Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р РЋРІР‚вЂќР В Р Р‹Р В РІР‚С™Р В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В Р В Р Р‹Р В Р вЂ°Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’Вµ Р В Р’В Р РЋРІР‚СћР В Р’В Р СћРІР‚ВР В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋР’В Р В Р Р‹Р В РЎвЂњР В Р’В Р РЋРІР‚СћР В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’В±Р В Р Р‹Р Р†Р вЂљР’В°Р В Р’В Р вЂ™Р’ВµР В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚ВР В Р’В Р вЂ™Р’ВµР В Р’В Р РЋР’В Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚СћР В Р’В Р В РІР‚В Р В Р Р‹Р Р†Р вЂљРІвЂћвЂ“Р В Р’В Р Р†РІР‚С›РІР‚вЂњ Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р вЂ™Р’ВµР В Р’В Р РЋРІР‚СњР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ў Р В Р’В Р РЋРІР‚вЂќР В Р Р‹Р РЋРІР‚СљР В Р’В Р вЂ™Р’В±Р В Р’В Р вЂ™Р’В»Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚СњР В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљР’В Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚В. Р В Р’В Р Р†Р вЂљРЎСљР В Р’В Р вЂ™Р’В»Р В Р Р‹Р В Р РЏ Р В Р’В Р РЋРІР‚СћР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р РЋР’ВР В Р’В Р вЂ™Р’ВµР В Р’В Р В РІР‚В¦Р В Р Р‹Р Р†Р вЂљРІвЂћвЂ“: /cancel',
        TAG
    );

    cron_log(TAG, 'EDIT_START id=' . $itemId . '; by=' . $fromId);
    respond_json(200, ['ok' => true, 'status' => 'awaiting_edit']);
}

if ($action === 'pub') {
    if (build_publish_text($item) === '') {
        telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРЎСџР В Р Р‹Р РЋРІР‚СљР В Р Р‹Р В РЎвЂњР В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’Вµ Р В Р Р‹Р В РЎвЂњР В Р’В Р РЋРІР‚СћР В Р’В Р РЋРІР‚СћР В Р’В Р вЂ™Р’В±Р В Р Р‹Р Р†Р вЂљР’В°Р В Р’В Р вЂ™Р’ВµР В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚ВР В Р’В Р вЂ™Р’Вµ');
        respond_json(200, ['ok' => true, 'status' => 'empty_message']);
    }

    $targetChat = trim((string)($item['publishChatId'] ?? $publishChatId));
    if ($targetChat === '') {
        telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРЎС™Р В Р’В Р вЂ™Р’Вµ Р В Р’В Р вЂ™Р’В·Р В Р’В Р вЂ™Р’В°Р В Р’В Р СћРІР‚ВР В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦ Р В Р Р‹Р Р†Р вЂљР Р‹Р В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљРЎв„ў Р В Р’В Р РЋРІР‚вЂќР В Р Р‹Р РЋРІР‚СљР В Р’В Р вЂ™Р’В±Р В Р’В Р вЂ™Р’В»Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚СњР В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљР’В Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚В');
        cron_log(TAG, 'PUBLISH_FAILED id=' . $itemId . '; reason=empty_chat');
        respond_json(200, ['ok' => true, 'status' => 'publish_chat_missing']);
    }

    $sendResp = send_news_item_to_chat($targetChat, $item, TAG);
    if ($sendResp === null) {
        telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРІР‚С”Р В Р Р‹Р Р†РІР‚С™Р’В¬Р В Р’В Р РЋРІР‚ВР В Р’В Р вЂ™Р’В±Р В Р’В Р РЋРІР‚СњР В Р’В Р вЂ™Р’В° Р В Р’В Р РЋРІР‚вЂќР В Р Р‹Р РЋРІР‚СљР В Р’В Р вЂ™Р’В±Р В Р’В Р вЂ™Р’В»Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚СњР В Р’В Р вЂ™Р’В°Р В Р Р‹Р Р†Р вЂљР’В Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚В');
        cron_log(TAG, 'PUBLISH_FAILED id=' . $itemId);
        respond_json(200, ['ok' => true, 'status' => 'publish_failed']);
    }

    $queue['items'][$itemId]['status'] = 'approved';
    $queue['items'][$itemId]['decidedAt'] = $now;
    $queue['items'][$itemId]['decidedBy'] = $fromId;
    $queue['items'][$itemId]['publishedAt'] = $now;
    save_json_atomic($queueFile, $queue);

    telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРІР‚С”Р В Р’В Р РЋРІР‚вЂќР В Р Р‹Р РЋРІР‚СљР В Р’В Р вЂ™Р’В±Р В Р’В Р вЂ™Р’В»Р В Р’В Р РЋРІР‚ВР В Р’В Р РЋРІР‚СњР В Р’В Р РЋРІР‚СћР В Р’В Р В РІР‚В Р В Р’В Р вЂ™Р’В°Р В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ');
    telegram_set_moderation_decision_message($chatId, $messageId, $item, 'approved');
    cron_log(TAG, 'APPROVED id=' . $itemId . '; url=' . (string)($item['url'] ?? ''));
    respond_json(200, ['ok' => true, 'status' => 'approved']);
}

$queue['items'][$itemId]['status'] = 'rejected';
$queue['items'][$itemId]['decidedAt'] = $now;
$queue['items'][$itemId]['decidedBy'] = $fromId;
save_json_atomic($queueFile, $queue);

telegram_answer_callback($callbackId, 'Р В Р’В Р РЋРІР‚С”Р В Р Р‹Р Р†Р вЂљРЎв„ўР В Р’В Р РЋРІР‚СњР В Р’В Р вЂ™Р’В»Р В Р’В Р РЋРІР‚СћР В Р’В Р В РІР‚В¦Р В Р’В Р вЂ™Р’ВµР В Р’В Р В РІР‚В¦Р В Р’В Р РЋРІР‚Сћ');
telegram_set_moderation_decision_message($chatId, $messageId, $item, 'rejected');
cron_log(TAG, 'REJECTED id=' . $itemId . '; url=' . (string)($item['url'] ?? ''));
respond_json(200, ['ok' => true, 'status' => 'rejected']);
