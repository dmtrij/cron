<?php
declare(strict_types=1);

function indices_change_emoji(float $pct): string
{
    if (abs($pct) <= 0.01) {
        return '⚖️';
    }

    return $pct > 0 ? '📈' : '📉';
}

function indices_num(float $value, int $decimals = 2): string
{
    return number_format($value, $decimals, '.', ',');
}

function indices_pct(float $pct): string
{
    $sign = $pct > 0 ? '+' : '';
    return $sign . number_format($pct, 2, '.', '') . '%';
}

function indices_signed_num(float $value, int $decimals = 2): string
{
    $sign = $value > 0 ? '+' : '';
    return $sign . number_format($value, $decimals, '.', ',');
}

function indices_pad_left(string $value, int $width): string
{
    $len = mb_strwidth($value, 'UTF-8');
    if ($len >= $width) {
        return $value;
    }

    return str_repeat(' ', $width - $len) . $value;
}

function indices_pad_right(string $value, int $width): string
{
    $len = mb_strwidth($value, 'UTF-8');
    if ($len >= $width) {
        return $value;
    }

    return $value . str_repeat(' ', $width - $len);
}

function indices_pre_block(array $item): string
{
    $label = (string)($item['label'] ?? '');
    $value = (float)($item['value'] ?? 0.0);
    $currency = (string)($item['currency'] ?? '');
    $dayChange = (float)($item['change_day'] ?? 0.0);
    $dayPct = (float)($item['pct_day'] ?? 0.0);
    $weekChange = (float)($item['change_week'] ?? 0.0);
    $weekPct = (float)($item['pct_week'] ?? 0.0);
    $monthChange = (float)($item['change_month'] ?? 0.0);
    $monthPct = (float)($item['pct_month'] ?? 0.0);
    $max30 = (float)($item['max_30'] ?? 0.0);
    $min30 = (float)($item['min_30'] ?? 0.0);

    $valueLine = $label . ' → ' . indices_num($value) . ($currency !== '' ? ' ' . $currency : '');
    $nameWidth = 9;
    $numWidth = 10;

    $buildLine = static function (string $name, float $change, string $currency, float $pct) use ($nameWidth, $numWidth): string {
        $left = indices_pad_right($name . ':', $nameWidth);
        $num = indices_pad_left(indices_signed_num($change), $numWidth);
        $curr = indices_pad_right(substr($currency, 0, 3), 3);
        $marker = indices_change_emoji($pct);
        $pctText = indices_pad_left(indices_pct($pct), 8);

        return $left . ' ' . $num . ' ' . $curr . '   ' . $marker . '   ' . $pctText;
    };

    $dayLine = $buildLine('День', $dayChange, $currency, $dayPct);
    $weekLine = $buildLine('Неделя', $weekChange, $currency, $weekPct);
    $monthLine = $buildLine('Месяц', $monthChange, $currency, $monthPct);
    $rangeLine = '30д: ' . indices_num($min30) . ' - ' . indices_num($max30) . ($currency !== '' ? ' ' . $currency : '');

    return '<pre>' .
        htmlspecialchars($valueLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" .
        htmlspecialchars($dayLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" .
        htmlspecialchars($weekLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" .
        htmlspecialchars($monthLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" .
        htmlspecialchars($rangeLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
        '</pre>';
}

function indices_caption(array $market, array $series): string
{
    $dates = array_keys($series);
    $start = $dates[0] ?? date('Y-m-d');
    $end = $dates[count($dates) - 1] ?? date('Y-m-d');

    return "📊 " . (string)$market['title'] . "\n\n" .
        "<i>" . (string)$market['label'] . " за 30 дней\n" .
        $start . ' - ' . $end . "</i>\n\n" .
        (string)$market['flag'] . ' ' . (string)$market['country'] . "\n" .
        (string)$market['full_name'] . "\n" .
        (string)$market['description'] . "\n\n" .
        "Индекс на сегодня:\n" .
        indices_pre_block($market);
}

function format_indices_fallback_message(array $market, array $series): string
{
    $dates = array_keys($series);
    $start = $dates[0] ?? date('Y-m-d');
    $end = $dates[count($dates) - 1] ?? date('Y-m-d');

    return "📊 " . (string)$market['title'] . "\n\n" .
        "<i>" . (string)$market['label'] . " за 30 дней\n" .
        $start . ' - ' . $end . "</i>\n\n" .
        (string)$market['flag'] . ' ' . (string)$market['country'] . "\n" .
        (string)$market['full_name'] . "\n" .
        (string)$market['description'] . "\n\n" .
        "Индекс на сегодня:\n" .
        indices_pre_block($market);
}
