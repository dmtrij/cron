<?php
declare(strict_types=1);

function rss_news_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    $root = preg_replace('~[^a-z0-9а-яё]+~iu', '', $root) ?? $root;
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
    $title = trim($title);
    $title = preg_replace('~\s*[-|]\s*(Reuters|BBC News|The New York Times|The Guardian)\s*$~i', '', $title);
    return trim((string)$title);
}

/**
 * items[] = [
 *   'title' => string,
 *   'url'   => string,
 *   'src'   => string,
 *   'pubTs' => int
 * ]
 */
function format_rss_news_message_block(array $items): string
{
    if ($items === []) {
        return '';
    }

    $chunks = [];

    foreach ($items as $item) {
        $title = rss_news_cleanup_title((string)($item['title'] ?? ''));
        $url   = trim((string)($item['url'] ?? ''));
        $src   = rss_news_source_label($item);
        if ($title === '') {
            continue;
        }

        $titleEsc = rss_news_escape($title);
        $srcEsc   = rss_news_escape($src);

        $sourceLine = ($url !== '')
            ? 'Источник: <a href="' . rss_news_escape($url) . '">' . $srcEsc . '</a>'
            : 'Источник: ' . $srcEsc;

        $block = "<b>{$titleEsc}</b>\n\n{$sourceLine}";

        $chunks[] = $block;
    }

    return implode("\n\n", $chunks);
}
