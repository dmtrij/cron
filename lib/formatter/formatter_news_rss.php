<?php
declare(strict_types=1);

function rss_news_fix_mojibake(string $text): string
{
    $text = trim($text);
    if ($text === '' || !preg_match('~[РСЃÑ]~u', $text)) {
        return $text;
    }

    $converted = @iconv('UTF-8', 'Windows-1251//IGNORE', $text);
    if (!is_string($converted) || $converted === '') {
        return $text;
    }

    $restored = @iconv('Windows-1251', 'UTF-8//IGNORE', $converted);
    if (!is_string($restored) || $restored === '') {
        return $text;
    }

    $originalBad = preg_match_all('~[РСЃÑ]~u', $text);
    $restoredBad = preg_match_all('~[РСЃÑ]~u', $restored);

    return $restoredBad < $originalBad ? trim($restored) : $text;
}

function rss_news_escape(string $s): string
{
    return htmlspecialchars(rss_news_fix_mojibake($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rss_news_host_root_label(string $host): string
{
    $host = mb_strtolower(trim($host), 'UTF-8');
    if ($host === '') {
        return '';
    }

    $host = preg_replace('~^www\d*\.~i', '', $host) ?? $host;
    $host = preg_replace('~^(m|amp|mobile|ru|russian)\.~i', '', $host) ?? $host;

    $parts = array_values(array_filter(explode('.', $host), static fn(string $p): bool => $p !== ''));
    if ($parts === []) {
        return '';
    }

    $publicSuffix2 = implode('.', array_slice($parts, -2));
    $publicSuffix3 = implode('.', array_slice($parts, -3));
    $multiPart = [
        'co.uk',
        'org.uk',
        'gov.uk',
        'ac.uk',
        'com.ua',
        'org.ua',
        'net.ua',
        'co.jp',
        'com.au',
        'com.br',
    ];

    if (in_array($publicSuffix2, $multiPart, true) && count($parts) >= 3) {
        $root = $parts[count($parts) - 3];
    } elseif (in_array($publicSuffix3, $multiPart, true) && count($parts) >= 4) {
        $root = $parts[count($parts) - 4];
    } elseif (count($parts) >= 2) {
        $root = $parts[count($parts) - 2];
    } else {
        $root = $parts[0];
    }

    $root = rss_news_fix_mojibake($root);
    $root = preg_replace('~[^a-z0-9а-яёіїєґ]+~iu', '', $root) ?? $root;
    return trim($root);
}

function rss_news_source_label(array $item): string
{
    $url = trim((string)($item['url'] ?? ''));
    if ($url !== '') {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        $root = rss_news_host_root_label($host);
        if ($root !== '') {
            if (mb_strlen($root, 'UTF-8') <= 4) {
                return mb_strtoupper($root, 'UTF-8');
            }
            return mb_convert_case($root, MB_CASE_TITLE, 'UTF-8');
        }
    }

    $src = trim((string)($item['src'] ?? ''));
    if ($src !== '') {
        $src = rss_news_fix_mojibake($src);
        $src = preg_replace('~\b(RU|UA|Russian|Russia|News|Service|Служба)\b~iu', '', $src) ?? $src;
        $src = preg_replace('~\s+~u', ' ', $src) ?? $src;
        $src = trim($src);
        if ($src !== '') {
            return $src;
        }
    }

    return 'Источник';
}

function rss_news_cleanup_title(string $title): string
{
    $title = rss_news_fix_mojibake(trim($title));
    $title = preg_replace('~\s*[-|]\s*(Reuters|BBC News|The New York Times|The Guardian)\s*$~i', '', $title);
    return trim((string)$title);
}

function rss_news_source_line(array $item): string
{
    $url = trim((string)($item['url'] ?? ''));
    $src = rss_news_source_label($item);
    $pubTs = (int)($item['pubTs'] ?? 0);

    $line = $url !== ''
        ? 'Источник: <a href="' . rss_news_escape($url) . '">' . rss_news_escape($src) . '</a>'
        : 'Источник: ' . rss_news_escape($src);

    if ($pubTs > 0) {
        $line .= ' • ' . rss_news_escape(date('d.m.Y H:i', $pubTs));
    }

    return $line;
}

function format_rss_news_message_block(array $items): string
{
    if ($items === []) {
        return '';
    }

    $chunks = [];

    foreach ($items as $item) {
        $title = rss_news_cleanup_title((string)($item['title'] ?? ''));
        if ($title === '') {
            continue;
        }

        $chunks[] = '<b>' . rss_news_escape($title) . "</b>\n\n" . rss_news_source_line($item);
    }

    return implode("\n\n", $chunks);
}
