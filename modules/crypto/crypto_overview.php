<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_crypto.php';

$chartLib = __DIR__ . '/../../lib/chart/imagick_line_chart.php';
if (is_file($chartLib)) {
    require_once $chartLib;
}

date_default_timezone_set(CRON_TIMEZONE);

$coinMap = [
    'bitcoin' => 'BTC',
    'ethereum' => 'ETH',
    'binancecoin' => 'BNB',
    'solana' => 'SOL',
    'the-open-network' => 'TON',
    'tether' => 'USDT',
];

$historyDays = 30;
$historyFile = __DIR__ . '/crypto_history.json';
$imagesDir = __DIR__ . '/images';

function crypto_log(string $tag, string $message): void
{
    cron_log($tag, $message);
}

function crypto_http_get_json(string $url, string $tag): ?array
{
    $apiKey = (string)(getenv('COINGECKO_API_KEY') ?: '');

    $headers = [
        'Accept: application/json',
        'User-Agent: CronFXCryptoBot/1.0',
    ];

    if ($apiKey !== '') {
        $headers[] = 'x-cg-demo-api-key: ' . $apiKey;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => HTTP_TIMEOUT_SEC,
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
        ],
    ]);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }

            crypto_log($tag, 'Bad JSON attempt=' . $attempt . ': ' . substr($raw, 0, 200));
        } else {
            $httpCode = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('~HTTP/\S+\s+(\d{3})~', $h, $m)) {
                        $httpCode = (int)$m[1];
                        break;
                    }
                }
            }

            crypto_log($tag, 'HTTP error attempt=' . $attempt . '; http=' . $httpCode . '; url=' . $url);
        }

        usleep(500000);
    }

    return null;
}

function load_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function save_json_atomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return false;
    }

    $tmp = $path . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return false;
    }

    try {
        flock($fp, LOCK_EX);
        fwrite($fp, $payload);
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return @rename($tmp, $path);
}

function ensure_dir(string $path): bool
{
    return is_dir($path) || @mkdir($path, 0775, true);
}

function prune_history_dates(array $historyByCode, int $days): array
{
    ksort($historyByCode);
    return array_slice($historyByCode, -$days, null, true);
}

function merge_market_chart_history(array $history, string $ticker, array $prices, int $days): array
{
    if (!isset($history[$ticker]) || !is_array($history[$ticker])) {
        $history[$ticker] = [];
    }

    foreach ($prices as $point) {
        if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
            continue;
        }

        $date = gmdate('Y-m-d', (int)floor(((float)$point[0]) / 1000));
        $history[$ticker][$date] = round((float)$point[1], 8);
    }

    $history[$ticker] = prune_history_dates($history[$ticker], $days);
    return $history;
}

function history_points_count(array $history, string $ticker): int
{
    return isset($history[$ticker]) && is_array($history[$ticker]) ? count($history[$ticker]) : 0;
}

function update_today_prices(array $history, array $coins, string $today, int $days): array
{
    foreach ($coins as $coin) {
        $ticker = (string)($coin['ticker'] ?? '');
        $price = $coin['price'] ?? null;
        if ($ticker === '' || !is_numeric($price)) {
            continue;
        }

        if (!isset($history[$ticker]) || !is_array($history[$ticker])) {
            $history[$ticker] = [];
        }

        $history[$ticker][$today] = round((float)$price, 8);
        $history[$ticker] = prune_history_dates($history[$ticker], $days);
    }

    return $history;
}

function btc_series_from_history(array $history, int $days, string $endDate): array
{
    $btc = is_array($history['BTC'] ?? null) ? $history['BTC'] : [];
    ksort($btc);
    $endTs = strtotime($endDate);
    $startTs = strtotime('-' . ($days - 1) . ' days', $endTs);

    $normalized = [];
    $lastValue = null;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $date = date('Y-m-d', $ts);
        if (array_key_exists($date, $btc) && is_numeric($btc[$date])) {
            $lastValue = (float)$btc[$date];
        }

        if ($lastValue !== null) {
            $normalized[$date] = $lastValue;
        }
    }

    if ($normalized === [] && $btc !== []) {
        $last = end($btc);
        if (is_numeric($last)) {
            for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
                $normalized[date('Y-m-d', $ts)] = (float)$last;
            }
        }
    }

    return $normalized;
}

function crypto_caption(array $coins, array $btcSeries, string $today): string
{
    $btcSeries[$today] = isset($btcSeries[$today]) ? (float)$btcSeries[$today] : (float)end($btcSeries);
    ksort($btcSeries);
    $dates = array_keys($btcSeries);
    $start = $dates[0] ?? date('Y-m-d');
    $end = $dates[count($dates) - 1] ?? date('Y-m-d');

    return "📊 Мониторинг криптовалют\n\n" .
        "<i>BTC/USD за 30 дней\n" .
        $start . ' - ' . $end . "</i>\n\n" .
        "Курс криптовалют на сегодня:\n" .
        crypto_pre_block($coins);
}

function render_btc_chart(array $series, string $imagePath, string $today): bool
{
    if (!function_exists('imagick_chart_render_neon_candles_png')) {
        return false;
    }

    $series[$today] = isset($series[$today]) ? (float)$series[$today] : (float)end($series);
    ksort($series);

    return imagick_chart_render_neon_candles_png($series, $imagePath, [
        'bg_top' => '#1d1405',
        'bg_bottom' => '#060403',
        'grid' => '#3a2810',
        'axis' => '#fff0cf',
        'text' => '#fff4de',
        'muted' => '#d7b36f',
        'current_date' => $today,
    ]);
}

$ids = implode(',', array_keys($coinMap));
$now = new DateTimeImmutable('now', new DateTimeZone(CRON_TIMEZONE));
$today = $now->format('Y-m-d');

$marketsUrl = 'https://api.coingecko.com/api/v3/coins/markets'
    . '?vs_currency=usd'
    . '&ids=' . rawurlencode($ids)
    . '&order=market_cap_desc'
    . '&per_page=50&page=1'
    . '&sparkline=false'
    . '&price_change_percentage=24h';

$markets = crypto_http_get_json($marketsUrl, 'crypto');
if ($markets === null) {
    send_to_telegram('⚠️ Ошибка crypto_overview: не удалось получить markets CoinGecko', 'crypto');
    exit;
}

$byTicker = [];
foreach ($markets as $item) {
    if (!is_array($item)) {
        continue;
    }

    $id = (string)($item['id'] ?? '');
    if ($id === '' || !isset($coinMap[$id])) {
        continue;
    }

    $ticker = $coinMap[$id];
    $byTicker[$ticker] = [
        'ticker' => $ticker,
        'price' => (float)($item['current_price'] ?? 0.0),
        'pct' => (float)($item['price_change_percentage_24h'] ?? 0.0),
        'volume' => (float)($item['total_volume'] ?? 0.0),
        'mcap' => (float)($item['market_cap'] ?? 0.0),
    ];
}

$orderTickers = ['BTC', 'ETH', 'BNB', 'SOL', 'TON', 'USDT'];
$coins = [];
foreach ($orderTickers as $ticker) {
    if (!isset($byTicker[$ticker])) {
        crypto_log('crypto', 'Missing market data for ' . $ticker);
        continue;
    }
    $coins[] = $byTicker[$ticker];
}

$history = load_json_file($historyFile);

foreach ($coinMap as $coinId => $ticker) {
    if (history_points_count($history, $ticker) >= $historyDays) {
        continue;
    }

    $historyUrl = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($coinId) . '/market_chart'
        . '?vs_currency=usd&days=45&interval=daily&precision=full';

    $chart = crypto_http_get_json($historyUrl, 'crypto');
    if (!is_array($chart) || !is_array($chart['prices'] ?? null)) {
        crypto_log('crypto', 'Missing history for ' . $ticker);
        continue;
    }

    $history = merge_market_chart_history($history, $ticker, $chart['prices'], $historyDays);
    usleep(350000);
}

$history = update_today_prices($history, $coins, $today, $historyDays);
save_json_atomic($historyFile, $history);

$message = format_crypto_message_block($coins);
$btcSeries = btc_series_from_history($history, $historyDays, $today);
$btcCurrent = isset($byTicker['BTC']['price']) ? (float)$byTicker['BTC']['price'] : 0.0;
$btcSeries[$today] = $btcCurrent;
ksort($btcSeries);
if (count($btcSeries) > $historyDays) {
    $btcSeries = array_slice($btcSeries, -$historyDays, null, true);
}
$sent = false;

if (count($btcSeries) >= 2 && ensure_dir($imagesDir)) {
    $imagePath = $imagesDir . '/btc_usd_' . date('Ymd_His') . '.png';

    try {
        if (render_btc_chart($btcSeries, $imagePath, $today)) {
            $sent = send_photo_to_telegram($imagePath, crypto_caption($coins, $btcSeries, $today), 'crypto');
            crypto_log('crypto', $sent ? 'Chart photo sent to Telegram' : 'Chart photo send failed');
        } else {
            crypto_log(
                'crypto',
                'Chart render failed; chart_fn=' . (function_exists('imagick_chart_render_neon_candles_png') ? '1' : '0') .
                '; imagick=' . (extension_loaded('imagick') ? '1' : '0') .
                '; points=' . count($btcSeries)
            );
        }
    } finally {
        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }
}

if (!$sent) {
    send_to_telegram($message, 'crypto');
    crypto_log('crypto', 'Fallback text sent to Telegram');
}
