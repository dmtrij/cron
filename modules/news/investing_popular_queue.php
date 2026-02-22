<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_news.php';

date_default_timezone_set(CRON_TIMEZONE);

const RSS_URL     = 'https://ru.investing.com/rss/news.rss';
const POPULAR_URL = 'https://ru.investing.com/news/most-popular-news';
const TAG = 'news_investing_popular';

$filtersFile = __DIR__ . '/news_filters_investing_ru.json';
$historyFile = __DIR__ . '/news_history_24h.json';
$cookieJar   = __DIR__ . '/cookies_investing.txt';

const DEBUG_PING_ON_EMPTY = false; // можно включить true, если понадобится отладка

/* ========================= HELPERS ========================= */

function load_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function save_json_atomic(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $tmp = $path . '.tmp';
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($payload === false) return;

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) return;

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

/**
 * Возвращает массив: [body|null, httpCode, errorString]
 */
function http_get_with_meta(string $url, string $cookieJar = null): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT_SEC,
        CURLOPT_CONNECTTIMEOUT => HTTP_TIMEOUT_SEC,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Connection: keep-alive',
            'Referer: https://ru.investing.com/news',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ]);

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        return [null, $http, $err];
    }
    return [(string)$body, $http, $err];
}

function norm_text(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    $s = preg_replace('~\s+~u', ' ', $s ?? '');
    return trim((string)$s);
}

function normalize_url(string $url): string
{
    $url = preg_replace('~^http://~i', 'https://', trim($url));
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['path'])) return $url;

    $scheme = $parts['scheme'];
    $host   = $parts['host'];
    $path   = $parts['path'];

    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    return "{$scheme}://{$host}{$path}";
}

function extract_popular_news_urls(string $html): array
{
    $urls = [];

    if (preg_match_all('~href=["\'](/news/[^"\'>#?]+)~i', $html, $m1)) {
        foreach ($m1[1] as $path) {
            $abs = normalize_url('https://ru.investing.com' . $path);
            $urls[$abs] = true;
        }
    }

    if (preg_match_all('~href=["\'](https?://ru\.investing\.com/news/[^"\'>#?]+)~i', $html, $m2)) {
        foreach ($m2[1] as $abs) {
            $abs = normalize_url($abs);
            $urls[$abs] = true;
        }
    }

    if (preg_match_all('~href=["\'](/article/[^"\'>#?]+)~i', $html, $m3)) {
        foreach ($m3[1] as $path) {
            $abs = normalize_url('https://ru.investing.com' . $path);
            $urls[$abs] = true;
        }
    }

    return array_keys($urls);
}

function contains_any(string $text, array $list): bool
{
    if (!$list) return false;
    $t = mb_strtolower($text, 'UTF-8');
    foreach ($list as $w) {
        $w = trim((string)$w);
        if ($w === '') continue;
        if (mb_strpos($t, mb_strtolower($w, 'UTF-8')) !== false) return true;
    }
    return false;
}

function extract_title_desc_from_article_html(string $html): array
{
    $title = '';
    $desc  = '';

    if (preg_match('~property=["\']og:title["\']\s+content=["\']([^"\']+)~i', $html, $m)) {
        $title = norm_text($m[1]);
    }
    if (preg_match('~property=["\']og:description["\']\s+content=["\']([^"\']+)~i', $html, $m)) {
        $desc = norm_text($m[1]);
    }

    if ($title === '' && preg_match('~<title>(.*?)</title>~is', $html, $m)) {
        $title = norm_text($m[1]);
        $title = preg_replace('~\s*[-—|]\s*Investing\.com.*$~iu', '', $title);
        $title = trim((string)$title);
    }

    return ['title' => $title, 'desc' => $desc];
}

function extract_published_ts_from_article_html(string $html): int
{
    if (preg_match('~"datePublished"\s*:\s*"([^"]+)"~i', $html, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) return (int)$ts;
    }

    if (preg_match('~property=["\']article:published_time["\']\s+content=["\']([^"\']+)~i', $html, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) return (int)$ts;
    }

    return 0;
}

/* ========================= CONFIG ========================= */

$cfg = load_json($filtersFile);

$ALLOW = isset($cfg['allow_keywords']) && is_array($cfg['allow_keywords']) ? $cfg['allow_keywords'] : [];
$BLOCK = isset($cfg['block_keywords']) && is_array($cfg['block_keywords']) ? $cfg['block_keywords'] : [];

$WINDOW_H  = isset($cfg['window_hours']) ? (int)$cfg['window_hours'] : 24;
$MAX_SEND  = isset($cfg['max_items_to_send']) ? (int)$cfg['max_items_to_send'] : 1;
$WINDOW_SEC = max(1, $WINDOW_H) * 3600;

/* ========================= FETCH SOURCES ========================= */

[$popularHtml, $popHttp, $popErr] = http_get_with_meta(POPULAR_URL, $cookieJar);
cron_log(TAG, "POPULAR http={$popHttp}; err={$popErr}");

if ($popularHtml === null) {
    send_to_telegram("⚠️ Ошибка ".TAG.": Popular недоступен (http={$popHttp})", TAG);
    exit;
}

$popularUrls = extract_popular_news_urls($popularHtml);
cron_log(TAG, "POPULAR urls extracted=" . count($popularUrls));

/* RSS как быстрый источник (если совпадёт) */
[$rssRaw, $rssHttp, $rssErr] = http_get_with_meta(RSS_URL);
cron_log(TAG, "RSS http={$rssHttp}; err={$rssErr}");

$rssMap = [];
if ($rssRaw !== null) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rssRaw);
    if ($xml && isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $title = norm_text((string)($item->title ?? ''));
            $desc  = norm_text((string)($item->description ?? ''));
            $link  = trim((string)($item->link ?? ''));
            $pub   = trim((string)($item->pubDate ?? ''));
            if ($title === '' || $link === '') continue;

            $url = normalize_url($link);
            $ts = $pub !== '' ? strtotime($pub) : false;

            $rssMap[$url] = [
                'title' => $title,
                'desc'  => $desc,
                'pubTs' => ($ts !== false) ? (int)$ts : 0,
            ];
        }
    }
}
cron_log(TAG, "RSS items mapped=" . count($rssMap));

/* ========================= HISTORY ========================= */

$history = load_json($historyFile);
if (!is_array($history)) $history = [];

$now = time();

// prune
foreach ($history as $k => $ts) {
    if (!is_int($ts) || ($now - $ts) > $WINDOW_SEC) unset($history[$k]);
}
cron_log(TAG, "HISTORY after_prune=" . count($history) . "; window_sec={$WINDOW_SEC}");

/* ========================= BUILD CANDIDATES FROM POPULAR ========================= */

$candidates = [];

foreach ($popularUrls as $url) {
    $url = normalize_url($url);

    if (isset($history[$url])) continue;

    $title = $rssMap[$url]['title'] ?? '';
    $desc  = $rssMap[$url]['desc'] ?? '';
    $pubTs = (int)($rssMap[$url]['pubTs'] ?? 0);

    if ($title === '' || $pubTs === 0) {
        [$articleHtml, $aHttp, $aErr] = http_get_with_meta($url, $cookieJar);
        if ($articleHtml === null) {
            cron_log(TAG, "ARTICLE fetch failed http={$aHttp}; url={$url}");
            continue;
        }

        $td = extract_title_desc_from_article_html($articleHtml);
        if ($title === '') $title = (string)$td['title'];
        if ($desc  === '') $desc  = (string)$td['desc'];

        if ($pubTs === 0) {
            $pubTs = extract_published_ts_from_article_html($articleHtml);
        }
    }

    if ($title === '') continue;

    // окно 24ч: без даты не берём
    if ($pubTs <= 0) continue;
    if (($now - $pubTs) > $WINDOW_SEC) continue;

    $blob = $title . ' ' . $desc;

    // Только фильтрация по словам
    if ($BLOCK && contains_any($blob, $BLOCK)) continue;
    if ($ALLOW && !contains_any($blob, $ALLOW)) continue;

    $candidates[] = [
        'title' => $title,
        'desc'  => $desc,
        'url'   => $url,
        'src'   => 'Investing.com',
        'pubTs' => $pubTs,
    ];
}

// старые -> новые
usort($candidates, fn($a, $b) => ($a['pubTs'] <=> $b['pubTs']));
cron_log(TAG, "CANDIDATES count=" . count($candidates));

if (!$candidates) {
    save_json_atomic($historyFile, $history);

    if (DEBUG_PING_ON_EMPTY) {
        $stamp = date('d.m.Y, H:i:s');
        $msg = "🧪 ".TAG." DEBUG\n"
             . "Popular http={$popHttp}, urls=" . count($popularUrls) . "\n"
             . "rssMap=" . count($rssMap) . " (RSS http={$rssHttp})\n"
             . "history=" . count($history) . ", candidates=0\n"
             . "window_h={$WINDOW_H}\n"
             . "server_time={$stamp}";
        send_to_telegram($msg, TAG);
    }

    exit;
}

/* ========================= PUBLISH ========================= */

$toSend = array_slice($candidates, 0, max(1, $MAX_SEND));

$msg = format_news_message_block($toSend);

$ok = send_to_telegram($msg, TAG);
cron_log(TAG, $ok ? "SEND ok; count=" . count($toSend) : "SEND failed");

if ($ok) {
    foreach ($toSend as $item) {
        $history[(string)$item['url']] = $now;
    }
    foreach ($history as $k => $ts) {
        if (!is_int($ts) || ($now - $ts) > $WINDOW_SEC) unset($history[$k]);
    }
    save_json_atomic($historyFile, $history);
}
