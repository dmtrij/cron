<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_metals.php';

date_default_timezone_set(CRON_TIMEZONE);

/**
 * cURL fetch to get HTTP code + body snippet for diagnostics.
 */
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

    $j = json_decode((string)$body, true);
    if (!is_array($j) || !isset($j['price']) || !is_numeric($j['price'])) {
        return ['ok' => false, 'err' => "bad_json,http={$http},body=" . substr((string)$body, 0, 80)];
    }

    return ['ok' => true, 'price' => (float)$j['price']];
}

// “Все металлы” которые они декларируют
$metals = [
    'XAU' => 'Gold',
    'XAG' => 'Silver',
    'XPT' => 'Platinum',
    'XPD' => 'Palladium',
    'HG'  => 'Copper',
];

$items = [];

foreach ($metals as $sym => $name) {
    $r = fetch_goldapi_price($sym);

    if (($r['ok'] ?? false) === true) {
        $items[] = [
            'code'  => $sym,
            'name'  => $name,
            'price' => (float)$r['price'],
        ];
    } else {
        $items[] = [
            'code'  => $sym,
            'name'  => $name,
            'price' => null,
            'err'   => (string)($r['err'] ?? 'error'),
        ];
    }
}

$stamp = date('d.m.Y, H:i:s');
$message = format_metals_prices_only($items, 'Gold-API.com', $stamp);

send_to_telegram($message, 'metals');
