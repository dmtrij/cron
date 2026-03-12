<?php
declare(strict_types=1);

function crypto_coin_dot(string $ticker): string
{
    return [
        'BTC'  => '🟠',
        'ETH'  => '🟣',
        'BNB'  => '🟡',
        'SOL'  => '🔵',
        'TON'  => '⚪',
        'USDT' => '🟢',
    ][$ticker] ?? '⚫';
}

function crypto_trend_emoji(float $pct): string
{
    if (abs($pct) < 0.01) return '⚖️';
    return $pct > 0 ? '📈' : '📉';
}

function usd_full(float $value): string
{
    return '$' . number_format($value, 2, '.', ',');
}

function usd_compact(float $value): string
{
    $abs = abs($value);
    $suffix = '';
    $n = $value;

    if ($abs >= 1_000_000_000_000) {
        $n = $value / 1_000_000_000_000;
        $suffix = 'T';
    } elseif ($abs >= 1_000_000_000) {
        $n = $value / 1_000_000_000;
        $suffix = 'B';
    } elseif ($abs >= 1_000_000) {
        $n = $value / 1_000_000;
        $suffix = 'M';
    } elseif ($abs >= 1_000) {
        $n = $value / 1_000;
        $suffix = 'K';
    }

    $dec = $suffix === '' ? 2 : 1;
    return '$' . number_format($n, $dec, '.', '') . $suffix;
}

function pct_format(float $pct): string
{
    $sign = $pct > 0 ? '+' : '';
    return $sign . number_format($pct, 2, '.', '') . '%';
}

function format_crypto_message_block(array $coins, array $global): string
{
    // ===== Текст ДО блока =====
    $header  = "";
    // убрали "Свежая сводка:"
    $header .= "📊 Мониторинг криптовалют\n\n";

    // ===== PRE блок =====
    $lines = [];

    foreach ($coins as $index => $c) {
        $ticker = (string)($c['ticker'] ?? '');
        if ($ticker === '') continue;

        $dot   = crypto_coin_dot($ticker);
        $price = (float)($c['price'] ?? 0.0);
        $pct   = (float)($c['pct'] ?? 0.0);
        $vol   = (float)($c['volume'] ?? 0.0);
        $mcap  = (float)($c['mcap'] ?? 0.0);

        $emoji = crypto_trend_emoji($pct);
        $tickerPadded = str_pad($ticker, 5, ' ', STR_PAD_RIGHT);

        $lines[] = "{$dot} {$tickerPadded} {$emoji} " . pct_format($pct);
        $lines[] = "Цена: " . usd_full($price);
        $lines[] = "Объем: " . usd_compact($vol);
        $lines[] = "Капитализация: " . usd_compact($mcap);

        if ($index < count($coins) - 1) {
            $lines[] = "---";
        }
    }

    $preContent = "\n" . implode("\n", $lines) . "\n";
    $preContent .= "\u{200B}";
    $preBlock = "<pre>{$preContent}</pre>";

    // ===== Текст ПОСЛЕ блока =====
    $btcDom = (float)($global['btc_dom'] ?? 0.0);
    $tmcap  = (float)($global['total_mcap'] ?? 0.0);
    $tvol   = (float)($global['total_vol'] ?? 0.0);

    $footer  = "\n\n";
    $footer .= "BTC доминирование: " . number_format($btcDom, 2, '.', '') . "%\n";
    $footer .= "Общая капитализация: " . usd_compact($tmcap) . "\n";
    $footer .= "Объем торгов: " . usd_compact($tvol);
    $footer .= "\n\n";
    // убрали дату/время, спрятали ссылку
    $footer .= "Источник: <a href=\"https://www.coingecko.com/\">coingecko.com</a>";

    return $header . $preBlock . $footer;
}
