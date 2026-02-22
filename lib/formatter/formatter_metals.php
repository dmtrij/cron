<?php
declare(strict_types=1);

function metal_dot(string $code): string
{
    return [
        'XAU' => '🟡', // Gold
        'XAG' => '⚪', // Silver
        'XPT' => '⚫', // Platinum
        'XPD' => '🟤', // Palladium
        'HG'  => '🟠', // Copper
    ][$code] ?? '⚫';
}

function usd_full(float $value): string
{
    return '$' . number_format($value, 2, '.', ',');
}

function metals_source_link(string $sourceLabel): string
{
    $label = htmlspecialchars($sourceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sl = trim($sourceLabel);

    // Если похоже на домен — делаем https://домен
    if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $sl)) {
        $url = 'https://' . $sl . '/';
        $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<a href=\"{$urlEsc}\">{$label}</a>";
    }

    // Иначе просто текст (без ссылки)
    return $label;
}

/**
 * $items[] = [
 *   'code'  => 'XAU',
 *   'name'  => 'Gold',
 *   'price' => 1985.25,     // null if unavailable
 *   'err'   => 'http=403'   // optional error note
 * ]
 */
function format_metals_prices_only(array $items, string $sourceLabel, string $stampHuman): string
{
    // stampHuman больше не используем (убрали дату/время в конце)

    // ===== Текст ДО блока =====
    $header  = "";
    // убрали "Свежая сводка:"
    $header .= "📊 Мониторинг драгоценных металлов\n\n";

    $lines = [];
    $count = count($items);

    foreach ($items as $i => $m) {
        $code  = (string)($m['code'] ?? '');
        $name  = (string)($m['name'] ?? $code);
        $price = $m['price'] ?? null;
        $err   = (string)($m['err'] ?? '');

        $dot = metal_dot($code);
        $namePadded = str_pad($name, 10, ' ', STR_PAD_RIGHT);

        $lines[] = "{$dot} {$namePadded}";
        if (is_numeric($price)) {
            $lines[] = "Цена: " . usd_full((float)$price);
        } else {
            $lines[] = "Цена: н/д" . ($err !== '' ? " ({$err})" : "");
        }

        if ($i < $count - 1) $lines[] = "---";
    }

    $preContent = "\n" . implode("\n", $lines) . "\n\u{200B}";
    $preBlock   = "<pre>{$preContent}</pre>";

    // ===== Текст ПОСЛЕ блока =====
    $footer  = "\n\n";
    $footer .= "Источник: " . metals_source_link($sourceLabel);

    return $header . $preBlock . $footer;
}
