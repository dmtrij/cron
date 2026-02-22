<?php
declare(strict_types=1);

function news_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cleanup_title(string $title): string
{
    $t = trim($title);

    // Убираем хвосты типа "От Investing.com" / "— Investing.com" / "| Investing.com"
    $t = preg_replace('~\s+(?:от|От)\s+Investing\.com\s*$~u', '', $t);
    $t = preg_replace('~\s*[-—|]\s*Investing\.com\s*$~ui', '', $t);

    return trim($t);
}

/**
 * items[] = [
 *   'title' => string,
 *   'url'   => string,
 *   'src'   => string
 * ]
 */
function format_news_message_block(array $items): string
{
    if ($items === []) {
        // Модуль обычно молчит при пусто, но на всякий случай вернём пустую строку
        return '';
    }

    $chunks = [];

    foreach ($items as $it) {
        $title = news_escape(cleanup_title((string)($it['title'] ?? '')));
        $url   = (string)($it['url'] ?? '');
        $src   = news_escape((string)($it['src'] ?? 'Investing.com'));

        // Ссылка спрятана в источнике
        $srcLink = $url !== ''
            ? '<a href="' . news_escape($url) . '">' . $src . '</a>'
            : $src;

        // Только заголовок + пустая строка + источник
        $block  = "<b>{$title}</b>";
        $block .= "\n\nИсточник: {$srcLink}";

        $chunks[] = $block;
    }

    // Если когда-то будет >1 item — разделяем пустой строкой
    return implode("\n\n", $chunks);
}
