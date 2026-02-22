<?php
// cron/modules/indices/indices_overview.php
declare(strict_types=1);

/**
 * Pull indices from Investing (ru), select TOP-15 (US/EU/CN/rф + world),
 * format nicely in Telegram HTML (NO <pre>), grouped with flags.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_indices.php';

date_default_timezone_set(CRON_TIMEZONE);

const TAG = 'indices_investing';

// IMPORTANT: use the broader list (not major-indices)
const URL = 'https://ru.investing.com/indices/world-indices';

// Persistent cookie jar (reuse existing pattern)
$cookieJar = __DIR__ . '/../news/cookies_investing.txt';

function cron_log_local(string $tag, string $msg): void
{
    if (function_exists('cron_log')) { cron_log($tag, $msg); return; }
    error_log("[$tag] $msg");
}

function http_get_with_meta(string $url, ?string $cookieJar = null): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => defined('HTTP_TIMEOUT_SEC') ? HTTP_TIMEOUT_SEC : 25,
        CURLOPT_CONNECTTIMEOUT => defined('HTTP_TIMEOUT_SEC') ? HTTP_TIMEOUT_SEC : 25,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.95,en;q=0.6',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Connection: keep-alive',
            'Referer: https://ru.investing.com/',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ]);

    if ($cookieJar) {
        @mkdir(dirname($cookieJar), 0777, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') return [null, $http, $err];
    return [(string)$body, $http, $err];
}

function norm(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('~\s+~u', ' ', $s) ?? '';
    return trim($s);
}

function is_antibot_page(string $html): bool
{
    $probe = mb_strtolower(strip_tags($html), 'UTF-8');
    return str_contains($probe, 'captcha')
        || str_contains($probe, 'cloudflare')
        || str_contains($probe, 'verify you are human')
        || str_contains($probe, 'pardon our interruption')
        || str_contains($probe, 'unusual traffic')
        || str_contains($probe, 'robot');
}

/**
 * DOM parse: accept any table row with >=4 cells (name, last, change, change%).
 * On world-indices it usually works. If they shuffle columns, fallback below helps.
 */
function parse_indices_dom(string $html): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    if (!@$dom->loadHTML($html)) return [];

    $xp = new DOMXPath($dom);
    $trs = $xp->query('//tr[td and count(td) >= 4]');
    if (!$trs) return [];

    $out = [];

    foreach ($trs as $tr) {
        $tds = $xp->query('./td', $tr);
        if (!$tds || $tds->length < 4) continue;

        $name = norm($tds->item(0)->textContent ?? '');
        $last = norm($tds->item(1)->textContent ?? '');
        $chg  = norm($tds->item(2)->textContent ?? '');
        $pct  = norm($tds->item(3)->textContent ?? '');

        if ($name === '' || $last === '' || !preg_match('~\d~u', $last)) continue;
        if ($pct !== '' && !str_contains($pct, '%')) $pct .= '%';

        $out[] = ['name' => $name, 'last' => $last, 'chg' => $chg, 'chg_pct' => $pct];
    }

    // de-dupe by raw name
    $seen = [];
    $uniq = [];
    foreach ($out as $r) {
        $k = mb_strtolower($r['name'], 'UTF-8');
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $uniq[] = $r;
    }

    return $uniq;
}

/**
 * Fallback parser: token heuristic for div-based layouts.
 * Includes sanity check to avoid garbage percents (e.g., "тыс%").
 */
function parse_indices_fallback(string $html): array
{
    $plain = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $html) ?? $html;
    $plain = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $plain) ?? $plain;
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('~<[^>]+>~', "\n", $plain) ?? $plain;
    $plain = preg_replace('~[ \t\r]+~', ' ', $plain) ?? $plain;

    $lines = array_values(array_filter(array_map('trim', explode("\n", $plain)), fn($x) => $x !== ''));

    $is_num = fn(string $s) => (bool)preg_match('~^[+\-]?\d[\d\.\,\s]*$~u', $s);
    $is_pct = fn(string $s) => (bool)preg_match('~^[+\-]?\d[\d\.\,\s]*%$~u', $s);

    $out = [];
    $maxLookahead = 25;

    for ($i = 0; $i < count($lines); $i++) {
        $name = $lines[$i];

        if (mb_strlen($name, 'UTF-8') < 3) continue;
        if (preg_match('~\d~u', $name)) continue; // usually not a name line

        $last = $chg = $pct = '';
        $collected = [];

        for ($j = $i + 1; $j < min(count($lines), $i + $maxLookahead); $j++) {
            $t = trim($lines[$j]);
            if ($t === '') continue;

            $collected[] = $t;

            if ($last === '' && $is_num($t)) $last = $t;
            else if ($last !== '' && $chg === '' && $is_num($t) && $t !== $last) $chg = $t;

            if ($pct === '' && $is_pct($t)) $pct = $t;

            if ($last !== '' && $pct !== '') break;
        }

        if ($last !== '' && $pct !== '') {
            // sanity: percent must be numeric and not insane
            $p = str_replace([' ', ',','%'], ['', '.',''], $pct);
            $pv = is_numeric($p) ? (float)$p : 9999.0;
            if (abs($pv) > 200.0) continue;

            // prefer signed change if present
            foreach ($collected as $t) {
                if (preg_match('~^[+\-]\d~u', $t)) { $chg = $t; break; }
            }

            $out[] = ['name' => norm($name), 'last' => norm($last), 'chg' => norm($chg), 'chg_pct' => norm($pct)];
        }
    }

    // de-dupe
    $seen = [];
    $uniq = [];
    foreach ($out as $r) {
        $k = mb_strtolower($r['name'], 'UTF-8');
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $uniq[] = $r;
    }

    return $uniq;
}

/**
 * Select TOP-15 instruments. If some aliases do not exist on the page, it will pick what it can
 * in target order and fill up to 15 with world backups that are commonly present.
 */
function select_top15(array $rows): array
{
    $targets = [
        // 🇺🇸 US (4)
        ['group'=>'US', 'label'=>'S&P 500',            'aliases'=>['S&P 500','US 500','SPX','S&P500']],
        ['group'=>'US', 'label'=>'Dow Jones',          'aliases'=>['Dow Jones','DJIA']],
        ['group'=>'US', 'label'=>'Nasdaq 100',         'aliases'=>['Nasdaq 100','US Tech 100','NDX']],
        ['group'=>'US', 'label'=>'Russell 2000',       'aliases'=>['Russell 2000','US Small Cap 2000','RUT']],

        // 🇪🇺 Europe (5)
        ['group'=>'EU', 'label'=>'EURO STOXX 50',      'aliases'=>['EURO STOXX 50','Euro Stoxx 50','STOXX50']],
        ['group'=>'EU', 'label'=>'DAX',                'aliases'=>['DAX','Germany 40','GER40']],
        ['group'=>'EU', 'label'=>'CAC 40',             'aliases'=>['CAC 40','France 40','FRA40']],
        ['group'=>'EU', 'label'=>'FTSE 100',           'aliases'=>['FTSE 100','UK 100','Britain 100']],
        ['group'=>'EU', 'label'=>'IBEX 35',            'aliases'=>['IBEX 35','Spain 35']],

        // 🇨🇳 China / HK (3)
        ['group'=>'CN', 'label'=>'Hang Seng',          'aliases'=>['Hang Seng','HSI']],
        ['group'=>'CN', 'label'=>'Shanghai Composite', 'aliases'=>['Shanghai Composite','SSEC','Shanghai']],
        ['group'=>'CN', 'label'=>'SZSE Component',     'aliases'=>['SZSE','Shenzhen','Shenzhen Component']],

        // 🇷🇺 rф (2)
        ['group'=>'RU', 'label'=>'Индекс МосБиржи',    'aliases'=>['Индекс МосБиржи','Индекс Мосбиржи','MOEX','МосБиржи']],
        ['group'=>'RU', 'label'=>'Индекс РТС',         'aliases'=>['Индекс РТС','RTS']],

        // 🌍 World core + backups (we will use to fill to 15)
        ['group'=>'WORLD','label'=>'US Dollar Index (DXY)','aliases'=>['Индекс USD','US Dollar Index','DXY','Dollar Index','USDollar']],
        ['group'=>'WORLD','label'=>'Nikkei 225',        'aliases'=>['Nikkei 225','Japan 225','JP225']],
        ['group'=>'WORLD','label'=>'VIX',               'aliases'=>['VIX','S&P 500 VIX']],
        ['group'=>'WORLD','label'=>'KOSPI',             'aliases'=>['KOSPI','South Korea 200']],
        ['group'=>'WORLD','label'=>'S&P/ASX 200',       'aliases'=>['S&P/ASX 200','ASX 200','Australia 200']],
        ['group'=>'WORLD','label'=>'BSE Sensex',        'aliases'=>['BSE Sensex','Sensex','India 30']],
        ['group'=>'WORLD','label'=>'Nifty 50',          'aliases'=>['Nifty 50','India 50']],
        ['group'=>'WORLD','label'=>'MSCI World',        'aliases'=>['MSCI World']],
    ];

    $picked = [];
    $pickedKeys = [];

    foreach ($targets as $t) {
        foreach ($rows as $r) {
            $name = (string)($r['name'] ?? '');
            foreach ($t['aliases'] as $a) {
                if ($a !== '' && mb_stripos($name, $a, 0, 'UTF-8') !== false) {
                    $key = $t['group'] . '|' . $t['label'];
                    if (isset($pickedKeys[$key])) continue 3;

                    $pickedKeys[$key] = true;
                    $picked[] = [
                        'group'   => $t['group'],
                        'label'   => $t['label'],
                        'last'    => (string)($r['last'] ?? ''),
                        'chg'     => (string)($r['chg'] ?? ''),
                        'chg_pct' => (string)($r['chg_pct'] ?? ''),
                    ];
                    continue 3;
                }
            }
        }

        if (count($picked) >= 15) break;
    }

    // Hard cap to 15
    if (count($picked) > 15) $picked = array_slice($picked, 0, 15);

    return $picked;
}

/* ===================== RUN ===================== */

[$html, $http, $err] = http_get_with_meta(URL, $cookieJar);
cron_log_local(TAG, "GET url=" . URL . "; http={$http}; err={$err}");

if ($html === null || $http >= 400) {
    send_to_telegram("⚠️ " . TAG . ": страница недоступна (http={$http})", TAG);
    exit;
}

if (is_antibot_page($html)) {
    send_to_telegram("⚠️ " . TAG . ": похоже на antibot/captcha (http={$http})", TAG);
    exit;
}

$rows = parse_indices_dom($html);
if ($rows === []) $rows = parse_indices_fallback($html);

if ($rows === []) {
    send_to_telegram("⚠️ " . TAG . ": не смог распарсить индексы (верстка/антибот)", TAG);
    exit;
}

$selected = select_top15($rows);

if ($selected === []) {
    send_to_telegram("⚠️ " . TAG . ": whitelist пустой (проверь aliases/страницу)", TAG);
    exit;
}

// If still less than 15, you can optionally show a tiny debug to tune aliases.
// Commented out to avoid user-facing noise.
// if (count($selected) < 15) {
//     $names = array_slice(array_map(fn($r) => (string)$r['name'], $rows), 0, 25);
//     send_to_telegram("⚠️ " . TAG . ": найдено " . count($selected) . "/15. Примеры: " . implode(', ', $names), TAG);
//     exit;
// }

// Format without <pre>, grouped with flags
$messages = format_indices_grouped_html($selected, 3800);

$allOk = true;
foreach ($messages as $m) {
    $ok = send_to_telegram($m, TAG);
    if (!$ok) $allOk = false;
}

cron_log_local(TAG, $allOk ? "SEND ok" : "SEND failed");
