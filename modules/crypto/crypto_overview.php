<?php
// modules/crypto/crypto_overview.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_crypto.php';

date_default_timezone_set(CRON_TIMEZONE);

// CoinGecko IDs -> тикеры (важно: TON = the-open-network)
$coinMap = [
    'bitcoin'         => 'BTC',
    'ethereum'        => 'ETH',
    'binancecoin'     => 'BNB',
    'solana'          => 'SOL',
    'the-open-network'=> 'TON',
    'tether'          => 'USDT',
];

function crypto_log(string $tag, string $message): void
{
    cron_log($tag, $message);
}

/**
 * CoinGecko API: поддерживаем Demo API key через env COINGECKO_API_KEY.
 * Если ключ задан — передаем его как заголовок x-cg-demo-api-key.
 * (Без ключа на некоторых сетях возможны 401/403/капча.)
 */
function crypto_http_get_json(string $url, string $tag): ?array
{
    $apiKey = (string)(getenv('COINGECKO_API_KEY') ?: '');

    $headers = [
        "Accept: application/json",
        "User-Agent: CronFXCryptoBot/1.0",
    ];

    if ($apiKey !== '') {
        $headers[] = "x-cg-demo-api-key: " . $apiKey;
    } else {
        crypto_log($tag, "COINGECKO_API_KEY не задан — запрос без ключа может быть ограничен");
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => HTTP_TIMEOUT_SEC,
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers) . "\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        // попробуем вытащить HTTP код
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('~HTTP/\S+\s+(\d{3})~', $h, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }
        crypto_log($tag, "Не удалось получить данные (HTTP $httpCode) URL: $url");
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        crypto_log($tag, "Ошибка JSON (первые 200 символов): " . substr($raw, 0, 200));
        return null;
    }

    return $json;
}

$ids = implode(',', array_keys($coinMap));

// markets: цена, объем, капитализация, 24h %
$marketsUrl = 'https://api.coingecko.com/api/v3/coins/markets'
    . '?vs_currency=usd'
    . '&ids=' . rawurlencode($ids)
    . '&order=market_cap_desc'
    . '&per_page=50&page=1'
    . '&sparkline=false'
    . '&price_change_percentage=24h';

$markets = crypto_http_get_json($marketsUrl, 'crypto');
if ($markets === null) {
    send_to_telegram("⚠️ Ошибка crypto_overview: не удалось получить/распарсить markets CoinGecko", 'crypto');
    exit;
}

// global: total_market_cap, total_volume, btc dominance
$globalUrl = 'https://api.coingecko.com/api/v3/global';
$globalRaw = crypto_http_get_json($globalUrl, 'crypto');
if ($globalRaw === null) {
    send_to_telegram("⚠️ Ошибка crypto_overview: не удалось получить/распарсить global CoinGecko", 'crypto');
    exit;
}

$globalData = $globalRaw['data'] ?? null;
if (!is_array($globalData)) {
    crypto_log('crypto', 'global.data отсутствует или не массив');
    send_to_telegram("⚠️ Ошибка crypto_overview: некорректный ответ global CoinGecko", 'crypto');
    exit;
}

// Собираем монеты в нужном порядке (как в ТЗ)
$orderTickers = ['BTC','ETH','BNB','SOL','TON','USDT'];

$byTicker = [];
foreach ($markets as $item) {
    if (!is_array($item)) continue;

    $id = (string)($item['id'] ?? '');
    if ($id === '' || !isset($coinMap[$id])) continue;

    $ticker = $coinMap[$id];

    $byTicker[$ticker] = [
        'ticker' => $ticker,
        'price'  => (float)($item['current_price'] ?? 0.0),
        'pct'    => (float)($item['price_change_percentage_24h'] ?? 0.0),
        'volume' => (float)($item['total_volume'] ?? 0.0),
        'mcap'   => (float)($item['market_cap'] ?? 0.0),
    ];
}

$coins = [];
foreach ($orderTickers as $t) {
    if (!isset($byTicker[$t])) {
        crypto_log('crypto', "Не получены данные по монете: $t");
        continue;
    }
    $coins[] = $byTicker[$t];
}

// Глобальные показатели
$btcDom = (float)($globalData['market_cap_percentage']['btc'] ?? 0.0);
$totalMcap = (float)($globalData['total_market_cap']['usd'] ?? 0.0);
$totalVol  = (float)($globalData['total_volume']['usd'] ?? 0.0);

$message = format_crypto_message_block($coins, [
    'btc_dom'     => $btcDom,
    'total_mcap'  => $totalMcap,
    'total_vol'   => $totalVol,
]);

$ok = send_to_telegram($message, 'crypto');
crypto_log('crypto', $ok ? 'Сообщение отправлено в Telegram' : 'Сообщение НЕ отправлено в Telegram');
