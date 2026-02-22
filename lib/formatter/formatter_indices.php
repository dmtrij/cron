<?php
declare(strict_types=1);

/**
 * Telegram HTML formatter (no <pre>), grouped by regions with flags.
 * Output: one message (usually), optionally split if exceeds max length.
 */

function idx_html_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function idx_sign_emoji(string $chg, string $pct): string
{
    $s = $chg !== '' ? $chg : $pct;
    $n = str_replace([' ', ','], ['', '.'], $s);

    if (!preg_match('~^[+\-]?\d+(\.\d+)?%?$~', $n)) return '•';
    if (str_starts_with($n, '+')) return '📈';
    if (str_starts_with($n, '-')) return '📉';
    return '⚖️';
}

/**
 * Prefer percent for readability; normalize to "(+0.10%)" style.
 */
function idx_norm_pct(string $pct): string
{
    $pct = trim($pct);
    if ($pct === '') return '';
    if (!str_contains($pct, '%')) $pct .= '%';

    // ensure sign is visible if missing
    $p = str_replace([' ', ','], ['', '.'], $pct);
    if ($p !== '' && $p[0] !== '+' && $p[0] !== '-' && $p[0] !== '0') {
        // leave as is
        return $pct;
    }
    return $pct;
}

/**
 * rows[]: ['group','label','last','chg','chg_pct']
 * returns messages[] (HTML). Usually 1 message under ~3800 chars.
 */
function format_indices_grouped_html(array $rows, int $maxChars = 3800): array
{
    $groups = [
        'US'    => ['flag' => "🇺🇸", 'name' => "США"],
        'EU'    => ['flag' => "🇪🇺", 'name' => "Европа"],
        'CN'    => ['flag' => "🇨🇳", 'name' => "Китай / Гонконг"],
        'RU'    => ['flag' => "🇷🇺", 'name' => "рф"],
        'WORLD' => ['flag' => "🌍", 'name' => "Мир"],
    ];
    $order = ['US','EU','CN','RU','WORLD'];

    $byGroup = [];
    foreach ($rows as $r) {
        $g = (string)($r['group'] ?? 'WORLD');
        $byGroup[$g][] = $r;
    }

    // stable sort inside group by label
    foreach ($byGroup as $g => $list) {
        usort($list, fn($a, $b) => strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? '')));
        $byGroup[$g] = $list;
    }

    // Build one compact HTML message
    $parts = [];
    foreach ($order as $g) {
        if (empty($byGroup[$g])) continue;

        $flag  = $groups[$g]['flag'] ?? "🌍";
        $gname = $groups[$g]['name'] ?? $g;

        $block = [];
        $block[] = "<b>{$flag} {$gname}</b>";

        foreach ($byGroup[$g] as $r) {
            $label = idx_html_escape((string)($r['label'] ?? ''));
            $last  = idx_html_escape((string)($r['last'] ?? ''));
            $chg   = (string)($r['chg'] ?? '');
            $pct   = idx_norm_pct((string)($r['chg_pct'] ?? ''));

            $emoji = idx_sign_emoji($chg, $pct);

            // keep it clean: label — last (pct)
            if ($pct !== '') {
                $block[] = "{$emoji} {$label} — {$last} <b>({$pct})</b>";
            } else {
                $block[] = "{$emoji} {$label} — {$last}";
            }
        }

        $parts[] = implode("\n", $block);
    }

    $msg = implode("\n\n", $parts);

    if (mb_strlen($msg, 'UTF-8') <= $maxChars) return [$msg];

    // split by blocks if needed (unlikely for 15 rows)
    $out = [];
    $cur = "";
    foreach ($parts as $p) {
        $candidate = ($cur === "") ? $p : ($cur . "\n\n" . $p);
        if (mb_strlen($candidate, 'UTF-8') > $maxChars && $cur !== "") {
            $out[] = $cur;
            $cur = $p;
        } else {
            $cur = $candidate;
        }
    }
    if ($cur !== "") $out[] = $cur;

    if (count($out) > 1) {
        $total = count($out);
        foreach ($out as $i => $m) {
            $out[$i] = "Индексы (" . ($i+1) . "/" . $total . ")\n\n" . $m;
        }
    }

    return $out;
}
