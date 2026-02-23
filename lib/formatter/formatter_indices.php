<?php
declare(strict_types=1);

/**
 * Telegram HTML formatter for indices grouped by regions.
 * Format per item:
 * 📈 Name (Country) — Last (+0,47%)
 * Description
 */

function idx_html_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function idx_norm_pct(string $pct): string
{
    $pct = trim($pct);
    if ($pct === '') {
        return '';
    }

    if (!str_contains($pct, '%')) {
        $pct .= '%';
    }

    return $pct;
}

function idx_parse_signed_value(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $normalized = str_replace([' ', '%', ','], ['', '', '.'], $value);
    if (!preg_match('~^[+\-]?\d+(\.\d+)?$~', $normalized)) {
        return null;
    }

    return (float)$normalized;
}

function idx_sign_emoji(string $chg, string $pct): string
{
    $probe = $chg !== '' ? $chg : $pct;
    $n = idx_parse_signed_value($probe);

    if ($n === null) {
        return '⚖️';
    }
    if ($n > 0) {
        return '📈';
    }
    if ($n < 0) {
        return '📉';
    }

    return '⚖️';
}

/**
 * rows[]: ['group','label','country','desc','last','chg','chg_pct']
 * returns messages[] (HTML)
 */
function format_indices_grouped_html(array $rows, int $maxChars = 3800): array
{
    $groups = [
        'US' => ['flag' => '🇺🇸', 'name' => 'США'],
        'RU' => ['flag' => '🇷🇺', 'name' => 'РФ'],
        'EU' => ['flag' => '🇪🇺', 'name' => 'Европа'],
        'ASIA' => ['flag' => '🇨🇳', 'name' => 'Азия'],
    ];
    $order = ['US', 'RU', 'EU', 'ASIA'];

    $byGroup = [];
    foreach ($rows as $r) {
        $g = (string)($r['group'] ?? 'ASIA');
        $byGroup[$g][] = $r;
    }

    $parts = [];
    foreach ($order as $g) {
        if (empty($byGroup[$g])) {
            continue;
        }

        $flag = $groups[$g]['flag'] ?? '🌍';
        $gname = $groups[$g]['name'] ?? $g;

        $items = [];
        foreach ($byGroup[$g] as $r) {
            $label = idx_html_escape((string)($r['label'] ?? ''));
            $country = idx_html_escape((string)($r['country'] ?? ''));
            $desc = idx_html_escape((string)($r['desc'] ?? ''));
            $last = idx_html_escape((string)($r['last'] ?? ''));
            $chg = (string)($r['chg'] ?? '');
            $pct = idx_norm_pct((string)($r['chg_pct'] ?? ''));

            $emoji = idx_sign_emoji($chg, $pct);

            $line = "{$emoji} {$label}";
            if ($country !== '') {
                $line .= " ({$country})";
            }
            $line .= " — {$last}";
            if ($pct !== '') {
                $line .= " ({$pct})";
            }

            $item = $line;
            if ($desc !== '') {
                $item .= "\n{$desc}";
            }
            $items[] = $item;
        }

        // Header + divider line + one empty line before the first index
        $section = "{$flag} {$gname}\n────────────";
        if ($items !== []) {
            $section .= "\n" . implode("\n\n", $items);
        }

        $parts[] = $section;
    }

    // Two empty lines between region blocks.
    $msg = implode("\n\n\n", $parts);

    if (mb_strlen($msg, 'UTF-8') <= $maxChars) {
        return [$msg];
    }

    $out = [];
    $cur = '';
    foreach ($parts as $p) {
        $candidate = ($cur === '') ? $p : ($cur . "\n\n" . $p);
        if (mb_strlen($candidate, 'UTF-8') > $maxChars && $cur !== '') {
            $out[] = $cur;
            $cur = $p;
        } else {
            $cur = $candidate;
        }
    }
    if ($cur !== '') {
        $out[] = $cur;
    }

    if (count($out) > 1) {
        $total = count($out);
        foreach ($out as $i => $m) {
            $out[$i] = 'Индексы (' . ($i + 1) . '/' . $total . ")\n\n" . $m;
        }
    }

    return $out;
}
