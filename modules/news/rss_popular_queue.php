<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_news_rss.php';

date_default_timezone_set(CRON_TIMEZONE);

const TAG = 'news_rss_popular';

$filtersFile = __DIR__ . '/news_filters_manual_rss.json';
$sourcesFileDefault = __DIR__ . '/news_sources_manual_rss.json';
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
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? repair_mojibake_deep($json) : [];
}

function repair_mojibake_deep(mixed $value): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = repair_mojibake_deep($item);
        }
        return $value;
    }

    if (is_string($value)) {
        return rss_news_fix_mojibake($value);
    }

    return $value;
}

function save_json_atomic(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return;
    }

    $tmp = $path . '.tmp';
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

function norm_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('~\s+~u', ' ', $text) ?? '';
    $text = rss_news_fix_mojibake($text);
    return trim($text);
}

function repair_mojibake_text(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (!preg_match('~[РСЃі]~u', $text)) {
        return $text;
    }

    $bytes = @mb_convert_encoding($text, 'Windows-1251', 'UTF-8');
    if (!is_string($bytes) || $bytes === '') {
        return $text;
    }

    $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
    if (!is_string($converted) || $converted === '') {
        return $text;
    }

    $scoreOriginal = preg_match_all('~[А-Яа-яЁёЇїІіЄєҐґA-Za-z0-9]~u', $text);
    $scoreConverted = preg_match_all('~[А-Яа-яЁёЇїІіЄєҐґA-Za-z0-9]~u', $converted);

    return $scoreConverted > $scoreOriginal ? trim($converted) : $text;
}

function normalize_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }

    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = strtolower((string)$parts['host']);
    $path = (string)($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    return $scheme . '://' . $host . $path;
}

function normalize_media_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }

    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = strtolower((string)$parts['host']);
    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $path . $query;
}

function contains_any(string $text, array $keywords): bool
{
    $text = mb_strtolower($text, 'UTF-8');

    foreach ($keywords as $keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            continue;
        }

        if (mb_strpos($text, mb_strtolower($keyword, 'UTF-8')) !== false) {
            return true;
        }
    }

    return false;
}

function parse_feed_date(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }

    $ts = strtotime($raw);
    return $ts === false ? 0 : (int)$ts;
}

function telegram_api_post(string $method, array $data): ?array
{
    if (TELEGRAM_BOT_TOKEN === '') {
        cron_log(TAG, 'TELEGRAM_BOT_TOKEN is empty');
        return null;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => HTTP_TIMEOUT_SEC,
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
        cron_log(TAG, 'Telegram API ' . $method . ' failed');
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !($json['ok'] ?? false)) {
        cron_log(TAG, 'Telegram API ' . $method . ' error');
        return null;
    }

    return $json;
}

function telegram_send_html_message(string $chatId, string $text, ?array $replyMarkup = null): ?array
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

    return telegram_api_post('sendMessage', $data);
}

function telegram_delete_message(string $chatId, int $messageId): bool
{
    if ($messageId <= 0) {
        return false;
    }

    return telegram_api_post('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ]) !== null;
}

function moderation_reply_markup(string $id): array
{
    $publish = "\u{041E}\u{043F}\u{0443}\u{0431}\u{043B}\u{0438}\u{043A}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}";
    $reject = "\u{041E}\u{0442}\u{043A}\u{043B}\u{043E}\u{043D}\u{0438}\u{0442}\u{044C}";
    $edit = "\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}";
    $clear = "\u{1F9F9}";

    return [
        'inline_keyboard' => [
            [
                ['text' => $publish, 'callback_data' => 'rssmod:pub:' . $id],
                ['text' => $reject, 'callback_data' => 'rssmod:rej:' . $id],
            ],
            [
                ['text' => $edit, 'callback_data' => 'rssmod:edit:' . $id],
                ['text' => $clear, 'callback_data' => 'rssmod:clear:' . $id],
            ],
        ],
    ];
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function cleanup_temp_images(string $dir, int $now, int $ttlSec): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $removed = 0;
    $ttlSec = max(60, $ttlSec);
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }

        $mtime = (int)@filemtime($path);
        if ($mtime > 0 && ($now - $mtime) < $ttlSec) {
            continue;
        }

        if (@unlink($path)) {
            $removed++;
        }
    }

    return $removed;
}

function extract_first_image_url(string $html): string
{
    if ($html === '') {
        return '';
    }

    if (preg_match('~<img[^>]+src=["\']([^"\']+)["\']~iu', $html, $m)) {
        return normalize_media_url($m[1]);
    }

    return '';
}

function xml_node_image_url(SimpleXMLElement $node): string
{
    $imageFields = ['image', 'thumbnail'];
    foreach ($imageFields as $name) {
        if (isset($node->{$name})) {
            $url = normalize_media_url((string)$node->{$name});
            if ($url !== '') {
                return $url;
            }
        }
    }

    $enclosures = $node->xpath('./enclosure[@url]');
    if (is_array($enclosures)) {
        foreach ($enclosures as $enclosure) {
            $type = strtolower(trim((string)($enclosure['type'] ?? '')));
            if ($type !== '' && !str_starts_with($type, 'image/')) {
                continue;
            }

            $url = normalize_media_url((string)($enclosure['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
    }

    $mediaNodes = $node->xpath('./*[local-name()="content" or local-name()="thumbnail" or local-name()="group"]');
    if (is_array($mediaNodes)) {
        foreach ($mediaNodes as $mediaNode) {
            $attrs = ['url', 'href'];
            foreach ($attrs as $attr) {
                $url = normalize_media_url((string)($mediaNode[$attr] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }

            $nested = $mediaNode->xpath('./*[local-name()="content" or local-name()="thumbnail"]');
            if (!is_array($nested)) {
                continue;
            }

            foreach ($nested as $nestedNode) {
                foreach ($attrs as $attr) {
                    $url = normalize_media_url((string)($nestedNode[$attr] ?? ''));
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }
    }

    $htmlFields = ['description', 'summary', 'content', 'encoded'];
    foreach ($htmlFields as $field) {
        if (isset($node->{$field})) {
            $url = extract_first_image_url((string)$node->{$field});
            if ($url !== '') {
                return $url;
            }
        }

        $result = $node->xpath('./*[local-name()="' . $field . '"]');
        if (!is_array($result)) {
            continue;
        }

        foreach ($result as $candidate) {
            $url = extract_first_image_url((string)$candidate);
            if ($url !== '') {
                return $url;
            }
        }
    }

    return '';
}

function download_news_image(string $url, string $targetPath): bool
{
    if ($url === '') {
        return false;
    }

    ensure_dir(dirname($targetPath));

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: image/*,*/*;q=0.8\r\nConnection: close\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    if (@file_put_contents($targetPath, $raw, LOCK_EX) === false) {
        return false;
    }

    $imageInfo = @getimagesize($targetPath);
    if (!is_array($imageInfo)) {
        @unlink($targetPath);
        return false;
    }

    return true;
}

function generate_moderation_id(string $seed, int $now): string
{
    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $random = uniqid('', true);
    }

    return substr(hash('sha256', $seed . '|' . $now . '|' . $random), 0, 20);
}

function load_moderation_queue(string $path): array
{
    $queue = load_json($path);
    if (!isset($queue['items']) || !is_array($queue['items'])) {
        $queue['items'] = [];
    }
    return $queue;
}

function prune_moderation_queue(array &$queue, int $now, int $windowSec): void
{
    $ttl = max(60, $windowSec);

    foreach ($queue['items'] as $id => $item) {
        if (!is_array($item)) {
            unset($queue['items'][$id]);
            continue;
        }

        $createdAt = (int)($item['createdAt'] ?? 0);
        if ($createdAt <= 0 || ($now - $createdAt) > $ttl) {
            unset($queue['items'][$id]);
        }
    }
}

function queue_has_pending_for_url(array $queue, string $url): bool
{
    foreach ($queue['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (($item['status'] ?? '') === 'pending' && ($item['url'] ?? '') === $url) {
            return true;
        }
    }

    return false;
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
    $messages = array_values(array_unique(array_filter(array_map('intval', $messages), static fn(int $id): bool => $id > 0)));
    $state['messages'][$chatId] = $messages;
    save_moderation_messages_state($path, $state);
}

function clear_moderation_state(string $queuePath, string $editStatePath, string $messagesPath, string $moderatorChatId): array
{
    $queue = load_moderation_queue($queuePath);
    $items = is_array($queue['items']) ? $queue['items'] : [];
    $imagePaths = [];
    $messagesState = load_moderation_messages_state($messagesPath);
    $messageIds = is_array($messagesState['messages'][$moderatorChatId] ?? null)
        ? array_values(array_unique(array_map('intval', $messagesState['messages'][$moderatorChatId])))
        : [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $chatId = (string)($item['moderatorChatId'] ?? '');
        if ($moderatorChatId !== '' && $chatId !== '' && $chatId !== $moderatorChatId) {
            continue;
        }

        $imagePath = trim((string)($item['imagePath'] ?? ''));
        if ($imagePath !== '') {
            $imagePaths[] = $imagePath;
        }
    }

    $deleted = 0;
    foreach ($messageIds as $messageId) {
        if (telegram_delete_message($moderatorChatId, $messageId)) {
            $deleted++;
        }
    }

    $queue['items'] = [];
    save_json_atomic($queuePath, $queue);
    $messagesState['messages'][$moderatorChatId] = [];
    save_moderation_messages_state($messagesPath, $messagesState);

    foreach ($imagePaths as $imagePath) {
        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }

    $state = load_json($editStatePath);
    $sessions = is_array($state['sessions'] ?? null) ? $state['sessions'] : [];
    $state['sessions'] = [];
    save_json_atomic($editStatePath, $state);

    return [
        'removed_items' => count($items),
        'removed_sessions' => count($sessions),
        'delete_attempted' => count($messageIds),
        'delete_ok' => $deleted,
    ];
}

function xml_node_text(SimpleXMLElement $node, array $names): string
{
    foreach ($names as $name) {
        if (isset($node->{$name})) {
            $value = norm_text((string)$node->{$name});
            if ($value !== '') {
                return $value;
            }
        }
    }

    foreach ($names as $name) {
        $result = $node->xpath('./*[local-name()="' . $name . '"]');
        if (!is_array($result)) {
            continue;
        }

        foreach ($result as $candidate) {
            $value = norm_text((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function atom_entry_link(SimpleXMLElement $entry): string
{
    $links = $entry->xpath('./*[local-name()="link"]');
    if (!is_array($links)) {
        return '';
    }

    foreach ($links as $link) {
        $href = trim((string)($link['href'] ?? ''));
        $rel = strtolower(trim((string)($link['rel'] ?? '')));
        if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
            return $href;
        }
    }

    return '';
}

function xml_node_link(SimpleXMLElement $node): string
{
    $direct = xml_node_text($node, ['link', 'guid', 'id']);
    if ($direct !== '') {
        return $direct;
    }

    $links = $node->xpath('./*[local-name()="link"]');
    if (!is_array($links)) {
        return '';
    }

    foreach ($links as $link) {
        $href = trim((string)($link['href'] ?? ''));
        if ($href !== '') {
            return $href;
        }
    }

    return '';
}

function parse_feed_items(string $xmlRaw, string $source): array
{
    $xmlRaw = preg_replace('/^\xEF\xBB\xBF/', '', $xmlRaw) ?? $xmlRaw;
    $xmlRaw = trim($xmlRaw);
    if ($xmlRaw === '') {
        return [];
    }

    $encoding = null;
    if (preg_match('~<\?xml[^>]*encoding=["\']([^"\']+)["\']~i', $xmlRaw, $m)) {
        $encoding = strtoupper(trim($m[1]));
    }

    if ($encoding !== null && $encoding !== '' && $encoding !== 'UTF-8') {
        $converted = @mb_convert_encoding($xmlRaw, 'UTF-8', $encoding);
        if (is_string($converted) && $converted !== '') {
            $xmlRaw = $converted;
            $xmlRaw = preg_replace('~<\?xml([^>]*)encoding=["\'][^"\']+["\']([^>]*)\?>~i', '<?xml$1encoding="UTF-8"$2?>', $xmlRaw) ?? $xmlRaw;
        }
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        return [];
    }

    $items = [];

    $rssItems = $xml->xpath('/rss/channel/item');
    if (!is_array($rssItems) || $rssItems === []) {
        $rssItems = $xml->xpath('/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"]');
    }

    if (is_array($rssItems)) {
        foreach ($rssItems as $item) {
            $title = norm_text(xml_node_text($item, ['title']));
            $url = normalize_url(xml_node_link($item));
            if ($title === '' || $url === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'desc' => norm_text(xml_node_text($item, ['description', 'summary', 'content'])),
                'url' => $url,
                'imageUrl' => xml_node_image_url($item),
                'src' => $source,
                'pubTs' => parse_feed_date(xml_node_text($item, ['pubDate', 'published', 'updated', 'date'])),
            ];
        }
    }

    $entries = $xml->xpath('/feed/entry');
    if (!is_array($entries) || $entries === []) {
        $entries = $xml->xpath('/*[local-name()="feed"]/*[local-name()="entry"]');
    }

    if (is_array($entries)) {
        foreach ($entries as $entry) {
            $title = norm_text(xml_node_text($entry, ['title']));
            $url = normalize_url(atom_entry_link($entry));
            if ($url === '') {
                $url = normalize_url(xml_node_text($entry, ['id']));
            }
            if ($title === '' || $url === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'desc' => norm_text(xml_node_text($entry, ['summary', 'content', 'description'])),
                'url' => $url,
                'imageUrl' => xml_node_image_url($entry),
                'src' => $source,
                'pubTs' => parse_feed_date(xml_node_text($entry, ['published', 'updated', 'date'])),
            ];
        }
    }

    return $items;
}

function fetch_feed(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.8\r\nConnection: close\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    return is_string($raw) && $raw !== '' ? $raw : null;
}

function format_rss_moderation_message(array $item): string
{
    $base = format_rss_news_message_block([$item]);
    if ($base === '') {
        return '';
    }

    $pubTs = (int)($item['pubTs'] ?? 0);
    $dateText = $pubTs > 0 ? date('d.m.Y H:i', $pubTs) : 'не указано';
    $notes = [];

    if (!empty($item['fallbackLatest'])) {
        $notes[] = 'Вне окна: взята последняя новость источника';
    }

    $duplicateSources = is_array($item['duplicateSources'] ?? null) ? $item['duplicateSources'] : [];
    if ($duplicateSources !== []) {
        $notes[] = 'Похожий сюжет есть у: ' . implode(', ', $duplicateSources);
    }

    $noteBlock = '';
    if ($notes !== []) {
        $escapedNotes = array_map(
            static fn(string $note): string => htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $notes
        );
        $noteBlock = "\n<i>" . implode("\n", $escapedNotes) . '</i>';
    }

    return "<b>🟡 Новость</b>\n<i>Дата в источнике: " .
        htmlspecialchars($dateText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
        "</i>{$noteBlock}\n\n" . $base;
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

function news_normalize_title(string $title): string
{
    $title = rss_news_fix_mojibake(mb_strtolower(trim($title), 'UTF-8'));
    $title = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $title) ?? '';
    $title = preg_replace('~\s+~u', ' ', $title) ?? '';
    return trim($title);
}

function news_title_tokens(string $title): array
{
    $normalized = news_normalize_title($title);
    if ($normalized === '') {
        return [];
    }

    $stop = [
        'и', 'в', 'во', 'на', 'по', 'из', 'за', 'для', 'под', 'при', 'к', 'ко', 'о', 'об', 'от',
        'до', 'не', 'что', 'как', 'a', 'the', 'of', 'to', 'for', 'in', 'on', 'at'
    ];

    $parts = preg_split('~\s+~u', $normalized) ?: [];
    $parts = array_values(array_filter($parts, static function (string $part) use ($stop): bool {
        return $part !== '' && !in_array($part, $stop, true) && mb_strlen($part, 'UTF-8') >= 3;
    }));

    return array_values(array_unique($parts));
}

function news_titles_are_similar(string $left, string $right): bool
{
    $leftNorm = news_normalize_title($left);
    $rightNorm = news_normalize_title($right);
    if ($leftNorm === '' || $rightNorm === '') {
        return false;
    }

    if ($leftNorm === $rightNorm) {
        return true;
    }

    $leftTokens = news_title_tokens($leftNorm);
    $rightTokens = news_title_tokens($rightNorm);
    if ($leftTokens === [] || $rightTokens === []) {
        return false;
    }

    $intersection = array_values(array_intersect($leftTokens, $rightTokens));
    $unionCount = count(array_unique(array_merge($leftTokens, $rightTokens)));
    $similarity = $unionCount > 0 ? (count($intersection) / $unionCount) : 0.0;

    if (count($intersection) >= 4 && $similarity >= 0.55) {
        return true;
    }

    if (count($intersection) >= 3 && $similarity >= 0.7) {
        return true;
    }

    return false;
}

function history_contains_similar(array $state, array $item): bool
{
    $url = normalize_url((string)($item['url'] ?? ''));
    $title = (string)($item['title'] ?? '');

    foreach (($state['items'] ?? []) as $historyItem) {
        if (!is_array($historyItem)) {
            continue;
        }

        $historyUrl = normalize_url((string)($historyItem['url'] ?? ''));
        if ($url !== '' && $historyUrl !== '' && $url === $historyUrl) {
            return true;
        }

        $historyTitle = (string)($historyItem['title'] ?? '');
        if (news_titles_are_similar($title, $historyTitle)) {
            return true;
        }
    }

    return false;
}

function history_add_item(string $path, array $item, string $flow, int $publishedAt): void
{
    $state = load_history_state($path);
    history_prune($state, $publishedAt);
    $state['items'][] = [
        'url' => normalize_url((string)($item['url'] ?? '')),
        'title' => norm_text((string)($item['title'] ?? '')),
        'src' => (string)($item['src'] ?? 'RSS'),
        'flow' => $flow,
        'publishedAt' => $publishedAt,
    ];
    save_history_state($path, $state);
}

function format_rss_moderation_message_v2(array $item): string
{
    $base = format_rss_news_message_block([$item]);
    if ($base === '') {
        return '';
    }

    $notes = [];

    if (!empty($item['fallbackLatest'])) {
        $notes[] = 'Вне окна: взята последняя новость источника';
    }

    $duplicateSources = is_array($item['duplicateSources'] ?? null) ? $item['duplicateSources'] : [];
    if ($duplicateSources !== []) {
        $notes[] = 'Похожий сюжет есть у: ' . implode(', ', array_map('rss_news_fix_mojibake', $duplicateSources));
    }

    $noteBlock = '';
    if ($notes !== []) {
        $escapedNotes = array_map(
            static fn(string $note): string => htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $notes
        );
        $noteBlock = "\n<i>" . implode("\n", $escapedNotes) . '</i>';
    }

    return "<b>\u{1F7E1} Новость</b>{$noteBlock}\n\n" . $base;
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

function queue_image_path(string $imagesDir, string $id): string
{
    return rtrim($imagesDir, '/\\') . DIRECTORY_SEPARATOR . $id . '.jpg';
}

function item_passes_filters(array $item, int $windowStartTs, int $windowEndTs, array $allow, array $block): bool
{
    $title = norm_text((string)($item['title'] ?? ''));
    $desc = norm_text((string)($item['desc'] ?? ''));
    $src = trim((string)($item['src'] ?? 'RSS'));
    $pubTs = (int)($item['pubTs'] ?? 0);
    $url = normalize_url((string)($item['url'] ?? ''));

    if ($title === '' || $url === '' || $pubTs <= 0) {
        return false;
    }

    if ($pubTs < $windowStartTs || $pubTs >= $windowEndTs) {
        return false;
    }

    $blob = $title . ' ' . $desc . ' ' . $src;
    if ($block !== [] && contains_any($blob, $block)) {
        return false;
    }
    if ($allow !== [] && !contains_any($blob, $allow)) {
        return false;
    }

    return true;
}

function collect_candidates(array $allItems, int $windowStartTs, int $windowEndTs, array $allow, array $block): array
{
    $candidatesByUrl = [];
    $sourcesByUrl = [];

    foreach ($allItems as $item) {
        $url = normalize_url((string)($item['url'] ?? ''));
        $title = norm_text((string)($item['title'] ?? ''));
        $desc = norm_text((string)($item['desc'] ?? ''));
        $src = trim((string)($item['src'] ?? 'RSS'));
        $pubTs = (int)($item['pubTs'] ?? 0);

        if ($title === '' || $url === '') {
            continue;
        }

        $candidate = [
        'title' => $title,
        'desc' => $desc,
        'url' => $url,
        'imageUrl' => normalize_media_url((string)($item['imageUrl'] ?? '')),
        'src' => $src,
        'pubTs' => $pubTs,
        'duplicateSources' => [],
        ];

        if (!isset($sourcesByUrl[$url])) {
            $sourcesByUrl[$url] = [];
        }
        $sourcesByUrl[$url][$src] = true;

        if (!item_passes_filters($item, $windowStartTs, $windowEndTs, $allow, $block)) {
            continue;
        }

        if (!isset($candidatesByUrl[$url]) || $pubTs > (int)$candidatesByUrl[$url]['pubTs']) {
            $candidatesByUrl[$url] = $candidate;
        }
    }

    foreach ($candidatesByUrl as $url => $candidate) {
        $src = (string)($candidate['src'] ?? 'RSS');
        $duplicateSources = array_keys($sourcesByUrl[$candidate['url']] ?? []);
        $duplicateSources = array_values(array_filter($duplicateSources, static fn(string $name): bool => $name !== $src));
        if ($duplicateSources !== []) {
            $candidate['duplicateSources'] = $duplicateSources;
        }
        $candidatesByUrl[$url] = $candidate;
    }

    $candidates = array_values($candidatesByUrl);
    usort($candidates, static fn(array $a, array $b): int => ((int)$a['pubTs'] <=> (int)$b['pubTs']));

    return $candidates;
}

function source_path_from_config(array $config, string $defaultPath): string
{
    $path = isset($config['sources_file'])
        ? __DIR__ . '/' . trim((string)$config['sources_file'])
        : $defaultPath;

    return is_file($path) ? $path : $defaultPath;
}

function fetch_sources_items(array $config, string $defaultSourcesPath, int $windowSec, int $maxRuntimeSec, int $maxFeedsPerSource, string $tag): array
{
    $sourcesPath = source_path_from_config($config, $defaultSourcesPath);
    $sourcesRaw = load_json($sourcesPath);
    $sources = is_array($sourcesRaw['sources'] ?? null) ? $sourcesRaw['sources'] : [];
    $startedAt = microtime(true);
    $allItems = [];
    $fetched = 0;

    cron_log(
        $tag,
        'SOURCES total=' . count($sources) .
        '; window_sec=' . $windowSec .
        '; max_runtime_sec=' . $maxRuntimeSec .
        '; max_feeds_per_source=' . $maxFeedsPerSource .
        '; sources_file=' . basename($sourcesPath)
    );

    foreach ($sources as $source) {
        if ((microtime(true) - $startedAt) >= $maxRuntimeSec) {
            cron_log($tag, 'STOP runtime budget exceeded');
            break;
        }

        if (!is_array($source)) {
            continue;
        }

        $name = trim((string)($source['name'] ?? 'RSS'));
        $site = (string)($source['url'] ?? '');
        $feeds = array_slice(is_array($source['feeds'] ?? null) ? $source['feeds'] : [], 0, $maxFeedsPerSource);

        cron_log($tag, 'SOURCE prepared name=' . $name . '; site=' . $site . '; feeds=' . count($feeds));

        foreach ($feeds as $feed) {
            if ((microtime(true) - $startedAt) >= $maxRuntimeSec) {
                cron_log($tag, 'STOP runtime budget exceeded during feed loop');
                break 2;
            }

            $feedUrl = normalize_url((string)$feed);
            if ($feedUrl === '') {
                continue;
            }

            $raw = fetch_feed($feedUrl);
            cron_log(
                $tag,
                'FEED fetch src=' . $name .
                '; url=' . $feedUrl .
                '; http=' . (is_string($raw) ? '200' : '0') .
                '; err=' . (is_string($raw) ? '' : 'fetch_failed')
            );

            if (!is_string($raw)) {
                continue;
            }

            $items = parse_feed_items($raw, $name);
            cron_log($tag, 'FEED parsed src=' . $name . '; url=' . $feedUrl . '; items=' . count($items));
            $fetched++;

            foreach ($items as $item) {
                $allItems[] = $item;
            }
        }
    }

    return ['items' => $allItems, 'fetched' => $fetched, 'sources_file' => $sourcesPath];
}

function publish_auto_candidates(array $items, string $publishChatId, string $imagesDir, string $queueFile, string $historyPath, int $now, string $tag): int
{
    if ($publishChatId === '' || $items === []) {
        return 0;
    }

    $queue = load_moderation_queue($queueFile);
    $history = load_history_state($historyPath);
    history_prune($history, $now);
    save_history_state($historyPath, $history);
    $published = 0;

    foreach ($items as $item) {
        $url = (string)($item['url'] ?? '');
        if ($url === '' || queue_has_pending_for_url($queue, $url) || history_contains_similar($history, $item)) {
            continue;
        }

        $message = format_rss_news_message_block([$item]);
        if ($message === '') {
            continue;
        }

        $imagePath = '';
        $imageUrl = normalize_media_url((string)($item['imageUrl'] ?? ''));
        if ($imageUrl !== '') {
            $candidatePath = queue_image_path($imagesDir, 'auto_' . md5($url . '|' . $now));
            if (download_news_image($imageUrl, $candidatePath)) {
                $imagePath = $candidatePath;
            }
        }

        if ($imagePath !== '' && news_should_send_with_image($message)) {
            $ok = send_photo_to_telegram_chat($publishChatId, $imagePath, $message, $tag) !== null;
        } else {
            $ok = telegram_send_html_message($publishChatId, $message) !== null;
        }

        if ($imagePath !== '' && is_file($imagePath)) {
            @unlink($imagePath);
        }

        if ($ok) {
            $published++;
            history_add_item($historyPath, $item, 'auto', $now);
            $history = load_history_state($historyPath);
        }
    }

    return $published;
}

$config = load_json($filtersFile);

$allow = is_array($config['allow_keywords'] ?? null) ? $config['allow_keywords'] : [];
$block = is_array($config['block_keywords'] ?? null) ? $config['block_keywords'] : [];
$maxSend = (int)($config['max_items_to_send'] ?? 0);
$windowSec = isset($config['window_minutes']) ? max(1, (int)$config['window_minutes']) * 60 : 30 * 60;
$maxFeedsPerSource = max(1, (int)($config['max_feeds_per_source'] ?? 4));
$maxRuntimeSec = max(15, (int)($config['max_runtime_sec'] ?? 295));
$moderationEnabled = (bool)($config['moderation_enabled'] ?? true);
$moderatorChatId = trim((string)($config['moderator_chat_id'] ?? (getenv('TELEGRAM_MODERATOR_CHAT_ID') ?: '')));
$publishChatId = trim((string)($config['publish_chat_id'] ?? TELEGRAM_CHAT_ID));
$freshRunCleanup = true;
$now = time();

ensure_dir($imagesDir);
$history = load_history_state($historyFile);
if (history_prune($history, $now)) {
    save_history_state($historyFile, $history);
}
$removedImages = cleanup_temp_images($imagesDir, $now, $windowSec);
    $clear = clear_moderation_state($queueFile, $editStateFile, $moderationMessagesFile, $moderatorChatId);
cron_log(TAG, 'RUN start; fresh_run_cleanup=1; window_sec=' . $windowSec);
cron_log(
    TAG,
    'MODERATION startup clear items=' . (int)$clear['removed_items'] .
    '; sessions=' . (int)$clear['removed_sessions'] .
    '; delete_attempted=' . (int)$clear['delete_attempted'] .
    '; delete_ok=' . (int)$clear['delete_ok'] .
    '; old_images_removed=' . $removedImages
);

$sourcesPath = isset($config['sources_file'])
    ? __DIR__ . '/' . trim((string)$config['sources_file'])
    : $sourcesFileDefault;

if (!is_file($sourcesPath)) {
    $sourcesPath = $sourcesFileDefault;
}

$sourcesRaw = load_json($sourcesPath);
$sources = is_array($sourcesRaw['sources'] ?? null) ? $sourcesRaw['sources'] : [];

$windowEndTs = intdiv($now, $windowSec) * $windowSec;
$windowStartTs = $windowEndTs - $windowSec;

cron_log(
    TAG,
    'SOURCES total=' . count($sources) .
    '; window_sec=' . $windowSec .
    '; window_start=' . date('Y-m-d H:i:s', $windowStartTs) .
    '; window_end=' . date('Y-m-d H:i:s', $windowEndTs) .
    '; max_runtime_sec=' . $maxRuntimeSec .
    '; max_feeds_per_source=' . $maxFeedsPerSource
);

$allItems = [];
$fetched = 0;
$startedAt = microtime(true);

foreach ($sources as $source) {
    if ((microtime(true) - $startedAt) >= $maxRuntimeSec) {
        cron_log(TAG, 'STOP runtime budget exceeded');
        break;
    }

    if (!is_array($source)) {
        continue;
    }

    $name = trim((string)($source['name'] ?? 'RSS'));
    $site = (string)($source['url'] ?? '');
    $feeds = array_slice(is_array($source['feeds'] ?? null) ? $source['feeds'] : [], 0, $maxFeedsPerSource);

    cron_log(TAG, 'SOURCE prepared name=' . $name . '; site=' . $site . '; feeds=' . count($feeds));

    foreach ($feeds as $feed) {
        if ((microtime(true) - $startedAt) >= $maxRuntimeSec) {
            cron_log(TAG, 'STOP runtime budget exceeded during feed loop');
            break 2;
        }

        $feedUrl = normalize_url((string)$feed);
        if ($feedUrl === '') {
            continue;
        }

        $raw = fetch_feed($feedUrl);
        cron_log(
            TAG,
            'FEED fetch src=' . $name .
            '; url=' . $feedUrl .
            '; http=' . (is_string($raw) ? '200' : '0') .
            '; err=' . (is_string($raw) ? '' : 'fetch_failed')
        );

        if (!is_string($raw)) {
            continue;
        }

        $items = parse_feed_items($raw, $name);
        cron_log(TAG, 'FEED parsed src=' . $name . '; url=' . $feedUrl . '; items=' . count($items));

        $fetched++;
        foreach ($items as $item) {
            $allItems[] = $item;
        }
    }
}

$candidates = [];
if ($fetched === 0) {
    send_to_telegram('Warning ' . TAG . ': all RSS feeds are unavailable', TAG);
} else {
    $candidates = collect_candidates($allItems, $windowStartTs, $windowEndTs, $allow, $block);
}
cron_log(TAG, 'CANDIDATES count=' . count($candidates));

$toSend = $candidates === [] ? [] : ($maxSend > 0 ? array_slice($candidates, 0, $maxSend) : $candidates);

$autoConfig = load_json(__DIR__ . '/news_filters_auto_rss.json');
if ($autoConfig !== []) {
    $autoTag = TAG . '_auto';
    $autoAllow = is_array($autoConfig['allow_keywords'] ?? null) ? $autoConfig['allow_keywords'] : [];
    $autoBlock = is_array($autoConfig['block_keywords'] ?? null) ? $autoConfig['block_keywords'] : [];
    $autoMaxSend = (int)($autoConfig['max_items_to_send'] ?? 0);
    $autoWindowSec = isset($autoConfig['window_minutes']) ? max(1, (int)$autoConfig['window_minutes']) * 60 : $windowSec;
    $autoMaxFeedsPerSource = max(1, (int)($autoConfig['max_feeds_per_source'] ?? 4));
    $autoMaxRuntimeSec = max(15, (int)($autoConfig['max_runtime_sec'] ?? 295));
    $autoPublishChatId = trim((string)($autoConfig['publish_chat_id'] ?? $publishChatId));
    $autoWindowEndTs = intdiv($now, $autoWindowSec) * $autoWindowSec;
    $autoWindowStartTs = $autoWindowEndTs - $autoWindowSec;
    $autoFetch = fetch_sources_items(
        $autoConfig,
        __DIR__ . '/news_sources_auto_rss.json',
        $autoWindowSec,
        $autoMaxRuntimeSec,
        $autoMaxFeedsPerSource,
        $autoTag
    );

    $autoCandidates = [];
    if ((int)($autoFetch['fetched'] ?? 0) > 0) {
        $autoCandidates = collect_candidates(
            is_array($autoFetch['items'] ?? null) ? $autoFetch['items'] : [],
            $autoWindowStartTs,
            $autoWindowEndTs,
            $autoAllow,
            $autoBlock
        );
    }

    cron_log($autoTag, 'CANDIDATES count=' . count($autoCandidates));
    $autoToSend = $autoCandidates === [] ? [] : ($autoMaxSend > 0 ? array_slice($autoCandidates, 0, $autoMaxSend) : $autoCandidates);
    $autoPublished = publish_auto_candidates($autoToSend, $autoPublishChatId, $imagesDir, $queueFile, $historyFile, $now, $autoTag);
    cron_log($autoTag, 'AUTO published=' . $autoPublished . '; candidates=' . count($autoToSend));
    $history = load_history_state($historyFile);
    history_prune($history, $now);
}

if ($toSend !== [] && $moderationEnabled && $moderatorChatId !== '') {
    $queue = load_moderation_queue($queueFile);
    prune_moderation_queue($queue, $now, $windowSec);

    $queued = 0;
    foreach ($toSend as $item) {
        $url = (string)$item['url'];
        if (queue_has_pending_for_url($queue, $url) || history_contains_similar($history, $item)) {
            continue;
        }

        $text = format_rss_moderation_message_v2($item);
        if ($text === '') {
            continue;
        }

        $id = generate_moderation_id($url, $now);
        $imageUrl = normalize_media_url((string)($item['imageUrl'] ?? ''));
        $imagePath = '';
        if ($imageUrl !== '') {
            $candidatePath = queue_image_path($imagesDir, $id);
            if (download_news_image($imageUrl, $candidatePath)) {
                $imagePath = $candidatePath;
            }
        }

        if ($imagePath !== '' && news_should_send_with_image($text)) {
            $response = send_photo_to_telegram_chat(
                $moderatorChatId,
                $imagePath,
                $text,
                TAG,
                moderation_reply_markup($id)
            );
        } else {
            $response = telegram_send_html_message($moderatorChatId, $text, moderation_reply_markup($id));
        }
        if ($response === null) {
            cron_log(TAG, 'MODERATION send failed; url=' . $url);
            if ($imagePath !== '' && is_file($imagePath)) {
                @unlink($imagePath);
            }
            continue;
        }

        $queue['items'][$id] = [
            'id' => $id,
            'title' => (string)$item['title'],
            'desc' => (string)($item['desc'] ?? ''),
            'url' => $url,
            'imageUrl' => $imageUrl,
            'imagePath' => $imagePath,
            'src' => (string)$item['src'],
            'pubTs' => (int)$item['pubTs'],
            'duplicateSources' => array_values(array_map('strval', is_array($item['duplicateSources'] ?? null) ? $item['duplicateSources'] : [])),
            'status' => 'pending',
            'createdAt' => $now,
            'moderatorChatId' => $moderatorChatId,
            'moderationMessageId' => (int)($response['result']['message_id'] ?? 0),
            'publishChatId' => $publishChatId,
        ];
        moderation_messages_add($moderationMessagesFile, $moderatorChatId, (int)($response['result']['message_id'] ?? 0));
        $queued++;
    }

    save_json_atomic($queueFile, $queue);
    cron_log(TAG, 'MODERATION queued=' . $queued);
} elseif ($toSend !== []) {
    $message = format_rss_news_message_block($toSend);
    if ($message === '') {
        cron_log(TAG, 'SEND skipped; empty formatter output');
    } else {
        $firstItem = $toSend[0] ?? null;
        $hasSinglePhoto = is_array($firstItem) && count($toSend) === 1
            && is_string($firstItem['imagePath'] ?? null)
            && $firstItem['imagePath'] !== ''
            && is_file($firstItem['imagePath']);

        if ($hasSinglePhoto && news_should_send_with_image($message)) {
            $ok = send_photo_to_telegram_chat($publishChatId, $firstItem['imagePath'], $message, TAG) !== null;
        } else {
            $ok = telegram_send_html_message($publishChatId, $message) !== null;
        }
        cron_log(TAG, $ok ? 'SEND ok; count=' . count($toSend) : 'SEND failed');
    }
}

