<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_indices.php';

$chartLib = __DIR__ . '/../../lib/chart/imagick_line_chart.php';
if (is_file($chartLib)) {
    require_once $chartLib;
}

date_default_timezone_set(CRON_TIMEZONE);

const TAG = 'indices';

$historyDays = 30;
$historyFile = __DIR__ . '/indices_history.json';
$imagesDir = __DIR__ . '/images';

$markets = [
    [
        'code' => 'US',
        'symbol' => '^DJI',
        'flag' => '🇺🇸',
        'country' => 'США',
        'title' => 'Фондовый рынок США',
        'label' => 'Dow Jones',
        'full_name' => 'Dow Jones Industrial Average',
        'description' => 'Крупнейшие американские компании, промышленность и потребительский сектор.',
        'currency' => 'USD',
        'colors' => [
            'bg_top' => '#091b23',
            'bg_bottom' => '#03080a',
            'grid' => '#17333f',
            'axis' => '#d8f6ff',
            'text' => '#ecfbff',
            'muted' => '#8fc5d8',
        ],
    ],
    [
        'code' => 'RU',
        'symbol' => 'IMOEX.ME',
        'flag' => '🇷🇺',
        'country' => 'РФ',
        'title' => 'Фондовый рынок России',
        'label' => 'Индекс МосБиржи',
        'full_name' => 'MOEX Russia Index',
        'description' => 'Крупнейшие российские компании, чувствительность к сырью, ставке и рублю.',
        'currency' => 'RUB',
        'colors' => [
            'bg_top' => '#1b1111',
            'bg_bottom' => '#080303',
            'grid' => '#3b1d1d',
            'axis' => '#ffe2e2',
            'text' => '#fff0f0',
            'muted' => '#d8a0a0',
        ],
    ],
    [
        'code' => 'CN',
        'symbol' => '000001.SS',
        'flag' => '🇨🇳',
        'country' => 'Китай',
        'title' => 'Фондовый рынок Китая',
        'label' => 'Shanghai Composite',
        'full_name' => 'Shanghai Composite Index',
        'description' => 'Широкий китайский рынок: банки, промышленность, инфраструктура и внутренний спрос.',
        'currency' => 'CNY',
        'colors' => [
            'bg_top' => '#201108',
            'bg_bottom' => '#080302',
            'grid' => '#412313',
            'axis' => '#ffe8d4',
            'text' => '#fff2e8',
            'muted' => '#d8a883',
        ],
    ],
    [
        'code' => 'UK',
        'symbol' => '^FTSE',
        'flag' => '🇬🇧',
        'country' => 'Великобритания',
        'title' => 'Фондовый рынок Великобритании',
        'label' => 'FTSE 100',
        'full_name' => 'FTSE 100 Index',
        'description' => 'Крупнейшие британские компании: сырьё, банки, фарма и глобальная выручка.',
        'currency' => 'GBP',
        'colors' => [
            'bg_top' => '#15111f',
            'bg_bottom' => '#05030a',
            'grid' => '#2d2141',
            'axis' => '#ebe1ff',
            'text' => '#f4eeff',
            'muted' => '#b39ad8',
        ],
    ],
    [
        'code' => 'FR',
        'symbol' => '^FCHI',
        'flag' => '🇫🇷',
        'country' => 'Франция',
        'title' => 'Фондовый рынок Франции',
        'label' => 'CAC 40',
        'full_name' => 'CAC 40 Index',
        'description' => 'Крупный французский рынок: финансы, промышленность, luxury и международный экспорт.',
        'currency' => 'EUR',
        'colors' => [
            'bg_top' => '#0f1520',
            'bg_bottom' => '#03060a',
            'grid' => '#1c2e45',
            'axis' => '#dce8ff',
            'text' => '#f0f5ff',
            'muted' => '#96aed9',
        ],
    ],
    [
        'code' => 'DE',
        'symbol' => '^GDAXI',
        'flag' => '🇩🇪',
        'country' => 'Германия',
        'title' => 'Фондовый рынок Германии',
        'label' => 'DAX',
        'full_name' => 'DAX Index',
        'description' => 'Крупнейшие немецкие компании: экспорт, машиностроение, промышленность и финансы.',
        'currency' => 'EUR',
        'colors' => [
            'bg_top' => '#16160b',
            'bg_bottom' => '#060603',
            'grid' => '#343418',
            'axis' => '#f8f0cf',
            'text' => '#fff7dc',
            'muted' => '#cfc07a',
        ],
    ],
    [
        'code' => 'CH',
        'symbol' => '^SSMI',
        'flag' => '🇨🇭',
        'country' => 'Швейцария',
        'title' => 'Фондовый рынок Швейцарии',
        'label' => 'SMI',
        'full_name' => 'Swiss Market Index',
        'description' => 'Крупнейшие швейцарские компании: фарма, финансы и защитные активы.',
        'currency' => 'CHF',
        'colors' => [
            'bg_top' => '#1f1115',
            'bg_bottom' => '#0a0305',
            'grid' => '#45202a',
            'axis' => '#ffe0e7',
            'text' => '#fff1f4',
            'muted' => '#d6a3af',
        ],
    ],
    [
        'code' => 'HK',
        'symbol' => '^HSI',
        'flag' => '🇭🇰',
        'country' => 'Гонконг',
        'title' => 'Фондовый рынок Гонконга',
        'label' => 'Hang Seng',
        'full_name' => 'Hang Seng Index',
        'description' => 'Крупнейшие гонконгские компании и чувствительность к Китаю и глобальному риску.',
        'currency' => 'HKD',
        'colors' => [
            'bg_top' => '#121a20',
            'bg_bottom' => '#03070a',
            'grid' => '#223742',
            'axis' => '#d9eef7',
            'text' => '#eef8fc',
            'muted' => '#94bccc',
        ],
    ],
    [
        'code' => 'IN',
        'symbol' => '^BSESN',
        'flag' => '🇮🇳',
        'country' => 'Индия',
        'title' => 'Фондовый рынок Индии',
        'label' => 'BSE Sensex',
        'full_name' => 'BSE SENSEX',
        'description' => 'Крупнейшие индийские компании, внутренний спрос, банки и быстрорастущая экономика.',
        'currency' => 'INR',
        'colors' => [
            'bg_top' => '#20170d',
            'bg_bottom' => '#080502',
            'grid' => '#4a3319',
            'axis' => '#ffe9c9',
            'text' => '#fff5e4',
            'muted' => '#d8b07d',
        ],
    ],
];

function indices_log(string $message): void
{
    cron_log(TAG, $message);
}

function yahoo_chart_json(string $symbol, string $range = '45d', string $interval = '1d'): ?array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
        . '?range=' . rawurlencode($range)
        . '&interval=' . rawurlencode($interval)
        . '&includePrePost=false';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_SEC,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        indices_log('Yahoo fetch failed symbol=' . $symbol . '; http=' . $http . '; curl=' . $err);
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        indices_log('Yahoo bad JSON symbol=' . $symbol);
        return null;
    }

    return $json;
}

function moex_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_SEC,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        indices_log('MOEX fetch failed; http=' . $http . '; curl=' . $err . '; url=' . $url);
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        indices_log('MOEX bad JSON; url=' . $url);
        return null;
    }

    return $json;
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

function merge_history_points(array $history, string $code, array $timestamps, array $closes, int $days): array
{
    if (!isset($history[$code]) || !is_array($history[$code])) {
        $history[$code] = [];
    }

    $count = min(count($timestamps), count($closes));
    for ($i = 0; $i < $count; $i++) {
        $ts = $timestamps[$i] ?? null;
        $close = $closes[$i] ?? null;

        if (!is_numeric($ts) || !is_numeric($close)) {
            continue;
        }

        $date = gmdate('Y-m-d', (int)$ts);
        $history[$code][$date] = round((float)$close, 4);
    }

    $history[$code] = prune_history_dates($history[$code], $days);
    return $history;
}

function update_today_value(array $history, string $code, string $today, float $value, int $days): array
{
    if (!isset($history[$code]) || !is_array($history[$code])) {
        $history[$code] = [];
    }

    $history[$code][$today] = round($value, 4);
    $history[$code] = prune_history_dates($history[$code], $days);
    return $history;
}

function is_market_closed_day(string $date): bool
{
    $ts = strtotime($date);
    if ($ts === false) {
        return false;
    }

    $weekday = (int)date('N', $ts);
    return $weekday >= 6;
}

function series_from_history(array $history, string $code, int $days, string $endDate): array
{
    $raw = is_array($history[$code] ?? null) ? $history[$code] : [];
    ksort($raw);

    $endTs = strtotime($endDate);
    $startTs = strtotime('-' . ($days - 1) . ' days', $endTs);
    $series = [];
    $closedDates = [];
    $lastValue = null;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $date = date('Y-m-d', $ts);
        if (array_key_exists($date, $raw) && is_numeric($raw[$date])) {
            $lastValue = (float)$raw[$date];
        } elseif ($lastValue !== null) {
            if (is_market_closed_day($date)) {
                $closedDates[] = $date;
            }
        }
        if ($lastValue !== null) {
            $series[$date] = $lastValue;
        }
    }

    if ($series === [] && $raw !== []) {
        $last = end($raw);
        if (is_numeric($last)) {
            for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
                $date = date('Y-m-d', $ts);
                $series[$date] = (float)$last;
                if (is_market_closed_day($date)) {
                    $closedDates[] = $date;
                }
            }
        }
    }

    $closedDates = array_values(array_unique(array_filter($closedDates, static fn(string $date): bool => $date !== $endDate)));

    return [
        'points' => $series,
        'closed_dates' => $closedDates,
    ];
}

function indices_change_stats(array $series, float $currentValue): array
{
    $values = array_values($series);
    $count = count($values);

    $dayBase = $count >= 2 ? (float)$values[$count - 2] : $currentValue;
    $weekBase = $count >= 8 ? (float)$values[$count - 8] : ($count >= 2 ? (float)$values[0] : $currentValue);
    $monthBase = $count >= 2 ? (float)$values[0] : $currentValue;

    $calc = static function (float $base, float $current): array {
        $change = $current - $base;
        $pct = $base != 0.0 ? ($change / $base) * 100.0 : 0.0;
        return [round($change, 4), round($pct, 4)];
    };

    [$dayChange, $dayPct] = $calc($dayBase, $currentValue);
    [$weekChange, $weekPct] = $calc($weekBase, $currentValue);
    [$monthChange, $monthPct] = $calc($monthBase, $currentValue);

    return [
        'change_day' => $dayChange,
        'pct_day' => $dayPct,
        'change_week' => $weekChange,
        'pct_week' => $weekPct,
        'change_month' => $monthChange,
        'pct_month' => $monthPct,
        'max_30' => $values !== [] ? max($values) : $currentValue,
        'min_30' => $values !== [] ? min($values) : $currentValue,
    ];
}

function build_market_item(array $market, array $json): ?array
{
    $result = $json['chart']['result'][0] ?? null;
    if (!is_array($result)) {
        return null;
    }

    $meta = $result['meta'] ?? null;
    if (!is_array($meta)) {
        return null;
    }

    $value = $meta['regularMarketPrice'] ?? null;
    $prev = $meta['chartPreviousClose'] ?? ($meta['previousClose'] ?? null);
    if (!is_numeric($value) || !is_numeric($prev)) {
        return null;
    }

    $value = (float)$value;
    $prev = (float)$prev;
    $change = $value - $prev;
    $pct = $prev != 0.0 ? ($change / $prev) * 100.0 : 0.0;

    $market['value'] = round($value, 4);
    $market['change'] = round($change, 4);
    $market['pct'] = round($pct, 4);

    return $market;
}

function build_ru_market_item(array $market): ?array
{
    $json = moex_json('https://iss.moex.com/iss/engines/stock/markets/index/securities/IMOEX.json');
    if (!is_array($json)) {
        return null;
    }

    $columns = $json['marketdata']['columns'] ?? [];
    $row = $json['marketdata']['data'][0] ?? null;
    if (!is_array($columns) || !is_array($row)) {
        return null;
    }

    $map = [];
    foreach ($columns as $i => $column) {
        $map[(string)$column] = $row[$i] ?? null;
    }

    $value = $map['CURRENTVALUE'] ?? $map['LASTVALUE'] ?? null;
    $change = $map['LASTCHANGE'] ?? null;
    $pct = $map['LASTCHANGEPRC'] ?? null;
    if (!is_numeric($value) || !is_numeric($change) || !is_numeric($pct)) {
        return null;
    }

    $market['value'] = round((float)$value, 4);
    $market['change'] = round((float)$change, 4);
    $market['pct'] = round((float)$pct, 4);

    return $market;
}

function merge_ru_history(array $history, string $code, int $days): array
{
    $from = date('Y-m-d', strtotime('-45 days'));
    $till = date('Y-m-d');
    $url = 'https://iss.moex.com/iss/history/engines/stock/markets/index/securities/IMOEX.json?from='
        . rawurlencode($from) . '&till=' . rawurlencode($till);

    $json = moex_json($url);
    if (!is_array($json)) {
        return $history;
    }

    $columns = $json['history']['columns'] ?? [];
    $rows = $json['history']['data'] ?? [];
    if (!is_array($columns) || !is_array($rows)) {
        return $history;
    }

    $dateIndex = array_search('TRADEDATE', $columns, true);
    $closeIndex = array_search('CLOSE', $columns, true);
    if (!is_int($dateIndex) || !is_int($closeIndex)) {
        return $history;
    }

    if (!isset($history[$code]) || !is_array($history[$code])) {
        $history[$code] = [];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $date = $row[$dateIndex] ?? null;
        $close = $row[$closeIndex] ?? null;
        if (!is_string($date) || !is_numeric($close)) {
            continue;
        }

        $history[$code][$date] = round((float)$close, 4);
    }

    $history[$code] = prune_history_dates($history[$code], $days);
    return $history;
}

function render_index_chart(array $series, string $imagePath, array $colors, array $closedDates, string $currentDate): bool
{
    if (!function_exists('imagick_chart_render_neon_candles_png')) {
        return false;
    }

    $options = $colors;
    $options['closed_dates'] = $closedDates;
    $options['current_date'] = $currentDate;
    return imagick_chart_render_neon_candles_png($series, $imagePath, $options);
}

$now = new DateTimeImmutable('now', new DateTimeZone(CRON_TIMEZONE));
$today = $now->format('Y-m-d');
$history = load_json_file($historyFile);

foreach ($markets as $market) {
    if ((string)$market['code'] === 'RU') {
        $item = build_ru_market_item($market);
        if ($item === null) {
            $text = "⚠️ " . TAG . ': не удалось получить данные по ' . $market['label'];
            send_to_telegram($text, TAG);
            continue;
        }

        $history = merge_ru_history($history, 'RU', $historyDays);
        $history = update_today_value($history, 'RU', $today, (float)$item['value'], $historyDays);
    } else {
        $json = yahoo_chart_json((string)$market['symbol']);
        if (!is_array($json)) {
            $text = "⚠️ " . TAG . ': не удалось получить данные по ' . $market['label'];
            send_to_telegram($text, TAG);
            continue;
        }

        $item = build_market_item($market, $json);
        if ($item === null) {
            $text = "⚠️ " . TAG . ': некорректный ответ по ' . $market['label'];
            send_to_telegram($text, TAG);
            continue;
        }

        $result = $json['chart']['result'][0] ?? [];
        $timestamps = is_array($result['timestamp'] ?? null) ? $result['timestamp'] : [];
        $closes = is_array($result['indicators']['quote'][0]['close'] ?? null) ? $result['indicators']['quote'][0]['close'] : [];

        $history = merge_history_points($history, (string)$market['code'], $timestamps, $closes, $historyDays);
        $history = update_today_value($history, (string)$market['code'], $today, (float)$item['value'], $historyDays);
    }

    $seriesPack = series_from_history($history, (string)$market['code'], $historyDays, $today);
    $series = $seriesPack['points'];
    $closedDates = $seriesPack['closed_dates'];
    $series[$today] = (float)$item['value'];
    ksort($series);
    if (count($series) > $historyDays) {
        $series = array_slice($series, -$historyDays, null, true);
    }
    $closedDates = array_values(array_filter($closedDates, static fn(string $date): bool => $date !== $today));
    $item = array_merge($item, indices_change_stats($series, (float)$item['value']));
    $message = format_indices_fallback_message($item, $series);
    $sent = false;

    if (count($series) >= 2 && ensure_dir($imagesDir)) {
        $imagePath = $imagesDir . '/index_' . strtolower((string)$market['code']) . '_' . date('Ymd_His') . '.png';

        try {
            if (render_index_chart($series, $imagePath, (array)$market['colors'], $closedDates, $today)) {
                $sent = send_photo_to_telegram($imagePath, indices_caption($item, $series), TAG);
                indices_log(($sent ? 'Chart photo sent: ' : 'Chart photo send failed: ') . $market['code']);
            }
        } finally {
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    if (!$sent) {
        send_to_telegram($message, TAG);
        indices_log('Fallback text sent: ' . $market['code']);
    }
}

save_json_atomic($historyFile, $history);
