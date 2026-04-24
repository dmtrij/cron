<?php
declare(strict_types=1);

function metal_dot(string $code): string
{
    return [
        'XAU' => '🟡',
        'XAG' => '⚪',
        'XPT' => '⚫',
        'XPD' => '🟤',
        'HG' => '🟠',
    ][$code] ?? '⚫';
}

function usd_full(float $value): string
{
    return '$' . number_format($value, 2, '.', ',');
}

function metals_source_link(string $sourceLabel): string
{
    $label = htmlspecialchars($sourceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sourceLabel = trim($sourceLabel);

    if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $sourceLabel)) {
        $url = 'https://' . $sourceLabel . '/';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a>';
    }

    return $label;
}

function metals_pre_block(array $items): string
{
    $lines = [];
    $count = count($items);

    foreach ($items as $i => $metal) {
        $code = (string)($metal['code'] ?? '');
        $name = (string)($metal['name'] ?? $code);
        $price = $metal['price'] ?? null;
        $err = (string)($metal['err'] ?? '');

        $lines[] = htmlspecialchars(metal_dot($code) . ' ' . str_pad($name, 10, ' ', STR_PAD_RIGHT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (is_numeric($price)) {
            $lines[] = htmlspecialchars('Цена: ' . usd_full((float)$price), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            $text = 'Цена: н/д' . ($err !== '' ? " ({$err})" : '');
            $lines[] = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($i < $count - 1) {
            $lines[] = '---';
        }
    }

    return '<pre>' . implode("\n", $lines) . '</pre>';
}

function format_metals_prices_only(array $items, string $sourceLabel, string $stampHuman): string
{
    return "📊 Мониторинг драгоценных металлов\n\n" .
        metals_pre_block($items) .
        "\n\nИсточник: " . metals_source_link($sourceLabel);
}
