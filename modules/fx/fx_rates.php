<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_fx.php';

date_default_timezone_set(CRON_TIMEZONE);

$currencies = ['USD', 'EUR', 'CNY', 'GBP', 'JPY'];
$history_days = 30;
$history_file = __DIR__ . '/fx_rates_history.json';

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
    $threshold = strtotime("-$historyDays days");
    return array_filter(
        $historyByCurrency,
        static fn($date) => strtotime((string)$date) >= $threshold,
        ARRAY_FILTER_USE_KEY
    );
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
$today = date('Y-m-d');

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

$ok = send_to_telegram($message_text, 'fx_rates');
log_message('fx_rates', $ok ? 'Сообщение отправлено в Telegram' : 'Сообщение НЕ отправлено в Telegram');
