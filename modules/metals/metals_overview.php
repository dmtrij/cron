<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_metals.php';

$chartLib = __DIR__ . '/../../lib/chart/imagick_line_chart.php';
if (is_file($chartLib)) {
    require_once $chartLib;
}

date_default_timezone_set(CRON_TIMEZONE);

$historyDays = 30;
$historyFile = __DIR__ . '/metals_history.json';
$imagesDir = __DIR__ . '/images';

function fetch_goldapi_price(string $symbol): array
{
    $url = 'https://api.gold-api.com/price/' . rawurlencode($symbol);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => defined('HTTP_TIMEOUT_SEC') ? HTTP_TIMEOUT_SEC : 12,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: CronMetalsBot/1.0',
        ],
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        return ['ok' => false, 'err' => 'empty' . ($cerr ? ",curl={$cerr}" : '')];
    }

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'err' => "http={$http},body=" . substr((string)$body, 0, 80)];
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json) || !isset($json['price']) || !is_numeric($json['price'])) {
        return ['ok' => false, 'err' => "bad_json,http={$http},body=" . substr((string)$body, 0, 80)];
    }

    return ['ok' => true, 'price' => (float)$json['price']];
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

function update_metals_history(array $history, array $items, string $date, int $days): array
{
    foreach ($items as $item) {
        $code = (string)($item['code'] ?? '');
        $price = $item['price'] ?? null;
        if ($code === '' || !is_numeric($price)) {
            continue;
        }

        if (!isset($history[$code]) || !is_array($history[$code])) {
            $history[$code] = [];
        }

        $history[$code][$date] = round((float)$price, 2);
        $history[$code] = prune_history_dates($history[$code], $days);
    }

    return $history;
}

function xau_series_from_history(array $history, int $days, string $endDate): array
{
    $xau = is_array($history['XAU'] ?? null) ? $history['XAU'] : [];
    ksort($xau);
    $endTs = strtotime($endDate);
    $startTs = strtotime('-' . ($days - 1) . ' days', $endTs);

    $normalized = [];
    $lastValue = null;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $date = date('Y-m-d', $ts);
        if (array_key_exists($date, $xau) && is_numeric($xau[$date])) {
            $lastValue = (float)$xau[$date];
        }

        if ($lastValue !== null) {
            $normalized[$date] = $lastValue;
        }
    }

    if ($normalized === [] && $xau !== []) {
        $last = end($xau);
        if (is_numeric($last)) {
            for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
                $normalized[date('Y-m-d', $ts)] = (float)$last;
            }
        }
    }

    return $normalized;
}

function metals_caption(array $items, array $xauSeries, string $today): string
{
    $xauSeries[$today] = isset($xauSeries[$today]) ? (float)$xauSeries[$today] : (float)end($xauSeries);
    ksort($xauSeries);
    $dates = array_keys($xauSeries);
    $start = $dates[0] ?? date('Y-m-d');
    $end = $dates[count($dates) - 1] ?? date('Y-m-d');

    return "📊 Мониторинг драгоценных металлов\n\n" .
        "<i>Gold/XAU за 30 дней\n" .
        $start . ' - ' . $end . "</i>\n\n" .
        "Курс металлов на сегодня:\n" .
        metals_pre_block($items);
}

if (!function_exists('metals_pre_block')) {
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
}

function render_gold_chart(array $series, string $imagePath, string $today): bool
{
    if (!function_exists('imagick_chart_render_neon_candles_png')) {
        return false;
    }

    $series[$today] = isset($series[$today]) ? (float)$series[$today] : (float)end($series);
    ksort($series);

    return imagick_chart_render_neon_candles_png($series, $imagePath, [
        'bg_top' => '#201806',
        'bg_bottom' => '#070502',
        'grid' => '#34270f',
        'axis' => '#fff1c7',
        'text' => '#fff4d6',
        'muted' => '#d5b56b',
        'current_date' => $today,
    ]);
}

$metals = [
    'XAU' => 'Gold',
    'XAG' => 'Silver',
    'XPT' => 'Platinum',
    'XPD' => 'Palladium',
    'HG' => 'Copper',
];

$items = [];
foreach ($metals as $symbol => $name) {
    $response = fetch_goldapi_price($symbol);

    $items[] = [
        'code' => $symbol,
        'name' => $name,
        'price' => ($response['ok'] ?? false) === true ? (float)$response['price'] : null,
        'err' => ($response['ok'] ?? false) === true ? '' : (string)($response['err'] ?? 'error'),
    ];
}

$now = new DateTimeImmutable('now', new DateTimeZone(CRON_TIMEZONE));
$today = $now->format('Y-m-d');
$history = update_metals_history(load_json_file($historyFile), $items, $today, $historyDays);
save_json_atomic($historyFile, $history);

$message = format_metals_prices_only($items, 'Gold-API.com', date('d.m.Y, H:i:s'));
$xauSeries = xau_series_from_history($history, $historyDays, $today);
$xauCurrent = null;
foreach ($items as $item) {
    if (($item['code'] ?? '') === 'XAU' && is_numeric($item['price'] ?? null)) {
        $xauCurrent = (float)$item['price'];
        break;
    }
}
if ($xauCurrent !== null) {
    $xauSeries[$today] = $xauCurrent;
    ksort($xauSeries);
    if (count($xauSeries) > $historyDays) {
        $xauSeries = array_slice($xauSeries, -$historyDays, null, true);
    }
}
$sent = false;

if (count($xauSeries) >= 2 && ensure_dir($imagesDir)) {
    $imagePath = $imagesDir . '/gold_xau_' . date('Ymd_His') . '.png';

    try {
        if (render_gold_chart($xauSeries, $imagePath, $today)) {
            $sent = send_photo_to_telegram($imagePath, metals_caption($items, $xauSeries, $today), 'metals');
            cron_log('metals', $sent ? 'Chart photo sent to Telegram' : 'Chart photo send failed');
        } else {
            cron_log(
                'metals',
                'Chart render failed; chart_fn=' . (function_exists('imagick_chart_render_neon_candles_png') ? '1' : '0') .
                '; imagick=' . (extension_loaded('imagick') ? '1' : '0') .
                '; points=' . count($xauSeries)
            );
        }
    } finally {
        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }
} else {
    cron_log('metals', 'Chart skipped; points=' . count($xauSeries) . '; images_dir=' . (is_dir($imagesDir) ? '1' : '0'));
}

if (!$sent) {
    send_to_telegram($message, 'metals');
    cron_log('metals', 'Fallback text sent to Telegram');
}
