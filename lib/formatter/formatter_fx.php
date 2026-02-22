<?php
declare(strict_types=1);

function get_currency_flag(string $currency): string
{
    return [
        'USD' => '🇺🇸',
        'EUR' => '🇪🇺',
        'CNY' => '🇨🇳',
        'GBP' => '🇬🇧',
        'JPY' => '🇯🇵',
    ][$currency] ?? '';
}

function format_rate(float $value): string
{
    return sprintf('%8.4f', $value);
}

function format_fx_message(string $currency, float $rate, float $prev): string
{
    $diff = round($rate - $prev, 4);

    if ($diff > 0) {
        $emoji = '📈';
        $diff_text = '+' . number_format($diff, 4, '.', '');
    } elseif ($diff < 0) {
        $emoji = '📉';
        $diff_text = number_format($diff, 4, '.', '');
    } else {
        $emoji = '⚖️';
        $diff_text = '0.0000';
    }

    $flag = get_currency_flag($currency);
    $rate_str = format_rate($rate);

    $line = "$flag $currency → UAH: $rate_str";
    $line .= "  $emoji " . sprintf('%9s', $diff_text);

    return $line;
}

function find_prev_rate(array $historyByCurrency, string $todayYmd, float $current): float
{
    if ($historyByCurrency === []) {
        return $current;
    }

    $dates = array_keys($historyByCurrency);
    rsort($dates);

    foreach ($dates as $d) {
        if ($d < $todayYmd) {
            $v = $historyByCurrency[$d];

            if (is_int($v)) {
                return $v / 10000;
            }

            if (is_numeric($v)) {
                return (float)$v;
            }

            break;
        }
    }

    return $current;
}

function format_fx_message_block(array $rates, array $history): string
{
    $sequence = ['USD', 'EUR', 'CNY', 'GBP', 'JPY'];
    $todayYmd = date('Y-m-d');

    // ===== Текст ДО блока =====
    $header  = "";
    // убрали "Свежая сводка:"
    $header .= "📊 Мониторинг валют\n\n";

    // ===== PRE блок =====
    $currencyLines = [];

    foreach ($sequence as $cur) {
        if (!isset($rates[$cur])) {
            continue;
        }

        $current_rate = (float)$rates[$cur];
        $prev = isset($history[$cur]) && is_array($history[$cur])
            ? find_prev_rate($history[$cur], $todayYmd, $current_rate)
            : $current_rate;

        $currencyLines[] = format_fx_message($cur, $current_rate, $prev);
    }

    $preContent = "\n" . implode("\n\n", $currencyLines) . "\n";
    $preContent .= "\u{200B}";

    $preBlock = "<pre>$preContent</pre>";

    // ===== Текст ПОСЛЕ блока =====
    // убрали дату/время, спрятали ссылку в источнике
    $footer  = "\n\n";
    $footer .= "Источник: <a href=\"https://bank.gov.ua/\">bank.gov.ua</a>";

    return $header . $preBlock . $footer;
}
