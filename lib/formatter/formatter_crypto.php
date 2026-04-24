<?php
declare(strict_types=1);

function crypto_coin_dot(string $ticker): string
{
    return [
        'BTC' => '🟠',
        'ETH' => '🟣',
        'BNB' => '🟡',
        'SOL' => '🔵',
        'TON' => '⚪',
        'USDT' => '🟢',
    ][$ticker] ?? '⚫';
}

function crypto_trend_emoji(float $pct): string
{
    if (abs($pct) <= 0.01) {
        return '⚖️';
    }

    return $pct > 0 ? '📈' : '📉';
}

function crypto_usd_price(float $value): string
{
    if ($value >= 1000) {
        return number_format($value, 2, '.', ',');
    }

    if ($value >= 1) {
        return number_format($value, 4, '.', ',');
    }

    return number_format($value, 6, '.', ',');
}

function crypto_pct(float $pct): string
{
    $sign = $pct > 0 ? '+' : '';
    return $sign . number_format($pct, 2, '.', '');
}

function crypto_usd_compact(float $value): string
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

    $decimals = $suffix === '' ? 2 : 1;
    return '$' . number_format($n, $decimals, '.', '') . $suffix;
}

function crypto_pre_block(array $coins): string
{
    $lines = [];
    $count = count($coins);

    foreach ($coins as $index => $coin) {
        $ticker = (string)($coin['ticker'] ?? '');
        $price = (float)($coin['price'] ?? 0.0);
        $pct = (float)($coin['pct'] ?? 0.0);
        $volume = (float)($coin['volume'] ?? 0.0);
        $mcap = (float)($coin['mcap'] ?? 0.0);

        $lines[] = htmlspecialchars(
            crypto_coin_dot($ticker) . ' ' . str_pad($ticker, 5, ' ', STR_PAD_RIGHT) . ' ' . crypto_trend_emoji($pct) . ' ' . crypto_pct($pct) . '%',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $lines[] = htmlspecialchars('Цена: $' . number_format($price, 2, '.', ','), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines[] = htmlspecialchars('Объем: ' . crypto_usd_compact($volume), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines[] = htmlspecialchars('Капитализация: ' . crypto_usd_compact($mcap), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($index < $count - 1) {
            $lines[] = '---';
        }
    }

    return '<pre>' . implode("\n", $lines) . '</pre>';
}

function format_crypto_message_block(array $coins): string
{
    return "📊 Мониторинг криптовалют\n\n" .
        "Курс криптовалют на сегодня:\n" .
        crypto_pre_block($coins);
}
