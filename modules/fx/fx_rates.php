<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_fx.php';

$chart_lib = __DIR__ . '/../../lib/chart/imagick_line_chart.php';
if (is_file($chart_lib)) {
    require_once $chart_lib;
}

date_default_timezone_set(CRON_TIMEZONE);

$currencies = ['USD', 'EUR', 'CNY', 'GBP', 'JPY'];
$history_days = 30;
$history_file = __DIR__ . '/fx_rates_history.json';
$images_dir = __DIR__ . '/images';

function log_message(string $tag, string $message): void
{
    cron_log($tag, $message);
}

function http_get_json(string $url, string $tag): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => HTTP_TIMEOUT_SEC,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        log_message($tag, "Не удалось получить данные по URL: $url");
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        log_message($tag, "Ошибка декодирования JSON (первые 200 символов): " . substr($raw, 0, 200));
        return null;
    }

    return $json;
}

function load_history(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function atomic_write_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $tmp = $file . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return false;
    }

    try {
        flock($fp, LOCK_EX);
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }
        fwrite($fp, $payload);
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return @rename($tmp, $file);
}

function rate_to_pips(float $rate): int
{
    return (int) round($rate * 10000, 0);
}

function pips_to_rate(int $pips): float
{
    return $pips / 10000;
}

function prune_history(array $historyByCurrency, int $historyDays): array
{
    $threshold = strtotime('-' . ($historyDays - 1) . ' days');
    return array_filter(
        $historyByCurrency,
        static fn($date) => strtotime((string)$date) >= $threshold,
        ARRAY_FILTER_USE_KEY
    );
}

function ensure_dir(string $path): bool
{
    return is_dir($path) || @mkdir($path, 0775, true);
}

function usd_series_from_history(array $history, int $days, string $endDate): array
{
    $usdRaw = is_array($history['USD'] ?? null) ? $history['USD'] : [];
    ksort($usdRaw);
    $endTs = strtotime($endDate);
    $startTs = strtotime('-' . ($days - 1) . ' days', $endTs);

    $normalized = [];
    $lastValue = null;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $date = date('Y-m-d', $ts);
        if (array_key_exists($date, $usdRaw)) {
            $value = $usdRaw[$date];
            if (is_int($value) || ctype_digit((string)$value)) {
                $lastValue = ((int)$value) / 10000;
            } elseif (is_numeric($value)) {
                $lastValue = (float)$value;
            }
        }

        if ($lastValue !== null) {
            $normalized[$date] = $lastValue;
        }
    }

    if ($normalized === [] && $usdRaw !== []) {
        $last = end($usdRaw);
        $lastValue = is_numeric($last) ? ((is_int($last) || ctype_digit((string)$last)) ? ((int)$last / 10000) : (float)$last) : null;
        if ($lastValue !== null) {
            for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
                $normalized[date('Y-m-d', $ts)] = $lastValue;
            }
        }
    }

    return $normalized;
}

function find_prev_rate_before_last_history_date(array $historyByCurrency, float $current): float
{
    if ($historyByCurrency === []) {
        return $current;
    }

    $dates = array_keys($historyByCurrency);
    sort($dates);
    $lastDate = end($dates);
    if (!is_string($lastDate) || $lastDate === '') {
        return $current;
    }

    rsort($dates);
    foreach ($dates as $date) {
        if ($date >= $lastDate) {
            continue;
        }

        $value = $historyByCurrency[$date] ?? null;
        if (is_int($value) || ctype_digit((string)$value)) {
            return ((int)$value) / 10000;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
    }

    return $current;
}

function fx_daily_lines(array $rates, array $history): string
{
    $sequence = ['USD', 'EUR', 'CNY', 'GBP', 'JPY'];
    $lines = [];

    foreach ($sequence as $cur) {
        if (!isset($rates[$cur])) {
            continue;
        }

        $currentRate = (float)$rates[$cur];
        $prev = isset($history[$cur]) && is_array($history[$cur])
            ? find_prev_rate_before_last_history_date($history[$cur], $currentRate)
            : $currentRate;

        $lines[] = htmlspecialchars(format_fx_message($cur, $currentRate, $prev), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return '<pre>' . implode("\n\n", $lines) . '</pre>';
}

function fx_photo_caption(array $rates, array $history, array $usdSeries, string $today): string
{
    $usdSeries[$today] = isset($usdSeries[$today]) ? (float)$usdSeries[$today] : (float)end($usdSeries);
    ksort($usdSeries);
    $dates = array_keys($usdSeries);
    $start = $dates[0] ?? date('Y-m-d');
    $end = $dates[count($dates) - 1] ?? date('Y-m-d');

    return "📊 Мониторинг валют\n\n" .
        "<i>USD/UAH за 30 дней\n" .
        $start . ' - ' . $end . "</i>" .
        "\n\n" .
        "Курс валют на сегодня:\n" .
        fx_daily_lines($rates, $history);
}

function render_usd_chart(array $series, string $imagePath, string $today): bool
{
    if (!function_exists('imagick_chart_render_neon_candles_png')) {
        return false;
    }

    $series[$today] = isset($series[$today]) ? (float)$series[$today] : (float)end($series);
    ksort($series);

    return imagick_chart_render_neon_candles_png($series, $imagePath, [
        'bg_top' => '#09201d',
        'bg_bottom' => '#020707',
        'grid' => '#14302d',
        'axis' => '#d8fff6',
        'text' => '#e8fff9',
        'muted' => '#8cc9bd',
        'current_date' => $today,
    ]);
}

$base_url = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';
$data = http_get_json($base_url, 'fx_rates');

if ($data === null) {
    send_to_telegram("⚠️ Ошибка fx_rates: не удалось получить/распарсить данные НБУ", 'fx_rates');
    exit;
}

$rates = [];
$rates_pips = [];

foreach ($data as $item) {
    $cc = (string)($item['cc'] ?? '');
    if ($cc !== '' && in_array($cc, $currencies, true)) {
        $rate = (float)($item['rate'] ?? 0);
        $pips = rate_to_pips($rate);

        $rates[$cc] = pips_to_rate($pips);
        $rates_pips[$cc] = $pips;
    }
}

if (count($rates) !== count($currencies)) {
    log_message('fx_rates', 'Не все валюты получены с НБУ: ' . json_encode(array_keys($rates)));
}

$history = load_history($history_file);
$now = new DateTimeImmutable('now', new DateTimeZone(CRON_TIMEZONE));
$today = $now->format('Y-m-d');

foreach ($currencies as $cur) {
    if (!isset($history[$cur]) || !is_array($history[$cur])) {
        $history[$cur] = [];
    }

    foreach ($history[$cur] as $d => $v) {
        if (is_int($v)) {
            continue;
        }

        if (is_numeric($v)) {
            $history[$cur][$d] = rate_to_pips((float)$v);
        } else {
            unset($history[$cur][$d]);
        }
    }

    $history[$cur] = prune_history($history[$cur], $history_days);

    if (isset($rates_pips[$cur])) {
        $history[$cur][$today] = $rates_pips[$cur];
    }
}

$message_text = format_fx_message_block($rates, $history);

if (!atomic_write_json($history_file, $history)) {
    log_message('fx_rates', 'Не удалось сохранить history JSON (atomic write)');
}

$usdSeries = usd_series_from_history($history, $history_days, $today);
$usdSeries[$today] = (float)($rates['USD'] ?? 0.0);
ksort($usdSeries);
if (count($usdSeries) > $history_days) {
    $usdSeries = array_slice($usdSeries, -$history_days, null, true);
}
$ok = false;

if (count($usdSeries) >= 2 && ensure_dir($images_dir)) {
    $imagePath = $images_dir . '/usd_uah_' . date('Ymd_His') . '.png';

    try {
        if (render_usd_chart($usdSeries, $imagePath, $today)) {
            $caption = fx_photo_caption($rates, $history, $usdSeries, $today);
            $ok = send_photo_to_telegram($imagePath, $caption, 'fx_rates');
        }
    } finally {
        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }
}

if ($ok) {
    log_message('fx_rates', 'Chart photo sent to Telegram');
} else {
    $ok = send_to_telegram($message_text, 'fx_rates');
    log_message('fx_rates', $ok ? 'Fallback text sent to Telegram' : 'Fallback text NOT sent to Telegram');
}
