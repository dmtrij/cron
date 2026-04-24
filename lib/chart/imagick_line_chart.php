<?php
declare(strict_types=1);

function imagick_chart_available(): bool
{
    return extension_loaded('imagick') && class_exists('Imagick') && class_exists('ImagickDraw') && class_exists('ImagickPixel');
}

function imagick_chart_color(string $color): ImagickPixel
{
    return new ImagickPixel($color);
}

function imagick_chart_draw_text(
    ImagickDraw $draw,
    float $x,
    float $y,
    string $text,
    int $size,
    ImagickPixel $color,
    string $align = 'left',
    ?string $font = null
): void {
    if ($font !== null && is_file($font)) {
        $draw->setFont($font);
    }

    $draw->setFillColor($color);
    $draw->setFontSize($size);
    $draw->setFontWeight(400);
    $draw->setTextAntialias(true);
    $draw->setTextAlignment(match ($align) {
        'center' => Imagick::ALIGN_CENTER,
        'right' => Imagick::ALIGN_RIGHT,
        default => Imagick::ALIGN_LEFT,
    });
    $draw->annotation($x, $y, $text);
}

function imagick_chart_nice_step(float $rawStep): float
{
    if ($rawStep <= 0) {
        return 1.0;
    }

    $power = floor(log10($rawStep));
    $base = 10 ** $power;
    $normalized = $rawStep / $base;

    $candidates = [1, 1.25, 1.5, 1.75, 2, 2.25, 2.5, 3, 4, 5, 6, 7.5, 8, 10];
    $nice = 10;
    foreach ($candidates as $candidate) {
        if ($normalized <= $candidate) {
            $nice = $candidate;
            break;
        }
    }

    return $nice * $base;
}

function imagick_chart_axis_bounds(array $values, ?float $preferredStep = null, int $levels = 7): array
{
    $min = min($values);
    $max = max($values);
    $levels = max(2, $levels);

    if ($max <= $min) {
        $step = $preferredStep !== null ? max(0.0001, $preferredStep) : 1.0;
        $half = (($levels - 1) / 2) * $step;
        return [$min - $half, $min + $half, $step];
    }

    $intervals = $levels - 1;

    if ($preferredStep !== null) {
        $step = max(0.0001, $preferredStep);
        $axisMin = floor($min / $step) * $step;
        $axisMax = $axisMin + ($intervals * $step);
        while ($axisMax < $max) {
            $axisMin += $step;
            $axisMax += $step;
        }
        while ($axisMin > $min) {
            $axisMin -= $step;
            $axisMax -= $step;
        }

        return [$axisMin, $axisMax, $step];
    }

    // Keep at least half a grid step above and below the data whenever possible.
    $rawStep = ($max - $min) / max(1, ($intervals - 1));
    $baseStep = imagick_chart_nice_step($rawStep);
    $stepCandidates = array_values(array_unique([
        imagick_chart_nice_step($baseStep * 0.5),
        imagick_chart_nice_step($baseStep * 0.8),
        $baseStep,
        imagick_chart_nice_step($baseStep * 1.25),
        imagick_chart_nice_step($baseStep * 1.5),
        imagick_chart_nice_step($baseStep * 2.0),
    ]));
    sort($stepCandidates);

    $best = null;

    foreach ($stepCandidates as $step) {
        $start = floor($min / $step) * $step;

        for ($shift = -$intervals; $shift <= $intervals; $shift++) {
            $axisMin = $start + ($shift * $step);
            $axisMax = $axisMin + ($intervals * $step);

            if ($axisMin > $min || $axisMax < $max) {
                continue;
            }

            $bottomGap = $min - $axisMin;
            $topGap = $axisMax - $max;
            $requiredGap = $step / 2;
            $underPadding = max(0.0, $requiredGap - $bottomGap) + max(0.0, $requiredGap - $topGap);
            $waste = $bottomGap + $topGap;
            $imbalance = abs($topGap - $bottomGap);

            $score = ($underPadding * 1000000) + ($waste * 1000) + $imbalance;
            if ($best === null || $score < $best['score']) {
                $best = [
                    'axisMin' => $axisMin,
                    'axisMax' => $axisMax,
                    'step' => $step,
                    'score' => $score,
                ];
            }
        }
    }

    if ($best !== null) {
        return [$best['axisMin'], $best['axisMax'], $best['step']];
    }

    $fallbackStep = $baseStep;
    $axisMin = floor($min / $fallbackStep) * $fallbackStep;
    $axisMax = $axisMin + ($intervals * $fallbackStep);
    while ($axisMax < $max) {
        $axisMin += $fallbackStep;
        $axisMax += $fallbackStep;
    }

    return [$axisMin, $axisMax, $fallbackStep];
}

function imagick_chart_month_label(array $dates): string
{
    $months = [
        '01' => 'январь',
        '02' => 'февраль',
        '03' => 'март',
        '04' => 'апрель',
        '05' => 'май',
        '06' => 'июнь',
        '07' => 'июль',
        '08' => 'август',
        '09' => 'сентябрь',
        '10' => 'октябрь',
        '11' => 'ноябрь',
        '12' => 'декабрь',
    ];

    $seen = [];
    foreach ($dates as $date) {
        $month = substr((string)$date, 5, 2);
        if (isset($months[$month])) {
            $seen[$month] = $months[$month];
        }
    }

    return implode('/', array_values($seen));
}

function imagick_chart_series_flags(array $series): array
{
    $values = array_values($series);
    $flags = [];

    foreach ($values as $i => $value) {
        $flags[$i] = [
            'is_closed' => $i > 0 && abs((float)$value - (float)$values[$i - 1]) < 0.0000001,
            'is_last' => $i === (count($values) - 1),
        ];
    }

    return $flags;
}

function imagick_chart_draw_axes(
    ImagickDraw $draw,
    array $series,
    int $width,
    int $height,
    int $padLeft,
    int $padRight,
    int $padTop,
    int $padBottom,
    ImagickPixel $grid,
    ImagickPixel $axis,
    ImagickPixel $text,
    ImagickPixel $muted,
    float $axisMin,
    float $axisMax,
    float $axisStep
): void {
    $count = count($series);
    $plotW = $width - $padLeft - $padRight;
    $plotH = $height - $padTop - $padBottom;
    $baseline = $height - $padBottom;
    $dates = array_keys($series);
    $lightFont = 'C:/Windows/Fonts/segoeuil.ttf';

    imagick_chart_draw_text($draw, $padLeft, 50, imagick_chart_month_label($dates), 24, $muted);

    for ($tick = $axisMin; $tick <= $axisMax + 0.0001; $tick += $axisStep) {
        $y = (int)round($padTop + (($axisMax - $tick) / ($axisMax - $axisMin) * $plotH));

        $draw->setStrokeColor($grid);
        $draw->setStrokeWidth(1);
        $draw->line($padLeft, $y, $width - $padRight, $y);

        $draw->setStrokeColor($axis);
        $draw->line($padLeft - 8, $y, $padLeft, $y);

        imagick_chart_draw_text(
            $draw,
            $padLeft - 24,
            $y + 8,
            $axisStep >= 1 ? number_format($tick, 0, '.', '') : number_format($tick, 2, '.', ''),
            24,
            $text,
            'right',
            $lightFont
        );
    }

    $draw->setStrokeColor($axis);
    $draw->setStrokeWidth(1);
    $draw->setFillOpacity(0);
    $draw->rectangle($padLeft, $padTop, $width - $padRight, $baseline);
    $draw->setFillOpacity(1);

    foreach ($dates as $i => $date) {
        if ($i !== 0 && $i !== ($count - 1) && ($i % 5) !== 0) {
            continue;
        }

        $x = (int)round($padLeft + ($plotW * $i / max(1, $count - 1)));
        $day = ltrim(substr((string)$date, 8, 2), '0');

        $draw->setStrokeColor($axis);
        $draw->line($x, $baseline, $x, $baseline + 8);
        imagick_chart_draw_text($draw, $x, $baseline + 40, $day, 22, $text, 'center');
    }
}

function imagick_chart_render_neon_candles_png(array $series, string $path, array $options = []): bool
{
    if (!imagick_chart_available() || count($series) < 2) {
        return false;
    }

    $width = (int)($options['width'] ?? 1200);
    $height = (int)($options['height'] ?? 680);
    $padLeft = (int)($options['pad_left'] ?? 98);
    $padRight = (int)($options['pad_right'] ?? 42);
    $padTop = (int)($options['pad_top'] ?? 82);
    $padBottom = (int)($options['pad_bottom'] ?? 98);

    $grid = imagick_chart_color((string)($options['grid'] ?? '#14302d'));
    $axis = imagick_chart_color((string)($options['axis'] ?? '#d8fff6'));
    $text = imagick_chart_color((string)($options['text'] ?? '#e8fff9'));
    $muted = imagick_chart_color((string)($options['muted'] ?? '#8cc9bd'));
    $up = imagick_chart_color((string)($options['up'] ?? '#27d7b0'));
    $down = imagick_chart_color((string)($options['down'] ?? '#ff3b6b'));
    $closed = imagick_chart_color((string)($options['closed'] ?? '#ffd400'));
    $current = imagick_chart_color((string)($options['current'] ?? '#ffffff'));

    $values = array_values($series);
    $dates = array_keys($series);
    $flags = imagick_chart_series_flags($series);
    $closedDates = array_fill_keys((array)($options['closed_dates'] ?? []), true);
    $currentDate = (string)($options['current_date'] ?? '');
    [$axisMin, $axisMax, $axisStep] = imagick_chart_axis_bounds(
        $values,
        isset($options['axis_step']) ? (float)$options['axis_step'] : null,
        (int)($options['axis_levels'] ?? 7)
    );
    $count = count($values);
    $plotW = $width - $padLeft - $padRight;
    $plotH = $height - $padTop - $padBottom;

    $image = new Imagick();
    $image->newPseudoImage(
        $width,
        $height,
        'gradient:' . (string)($options['bg_top'] ?? '#071817') . '-' . (string)($options['bg_bottom'] ?? '#020707')
    );
    $image->setImageFormat('png');
    $image->setImageDepth(8);

    $draw = new ImagickDraw();
    $draw->setStrokeAntialias(true);
    $draw->setTextAntialias(true);

    imagick_chart_draw_axes(
        $draw,
        $series,
        $width,
        $height,
        $padLeft,
        $padRight,
        $padTop,
        $padBottom,
        $grid,
        $axis,
        $text,
        $muted,
        $axisMin,
        $axisMax,
        $axisStep
    );

    $slot = $plotW / $count;
    $bodyWidth = max(8, min(24, floor($slot * 0.48)));
    $toY = static fn(float $value): int => (int)round($padTop + (($axisMax - $value) / ($axisMax - $axisMin) * $plotH));

    for ($i = 0; $i < $count; $i++) {
        $open = $i === 0 ? $values[$i] : $values[$i - 1];
        $close = $values[$i];
        $date = (string)($dates[$i] ?? '');
        $isClosed = isset($closedDates[$date]) || (bool)($flags[$i]['is_closed'] ?? false);
        $isCurrent = $currentDate !== '' && $date === $currentDate;
        $isLast = (bool)($flags[$i]['is_last'] ?? false);
        $color = $isCurrent
            ? $current
            : ($isClosed ? $closed : ($close >= $open ? $up : $down));

        $x = (int)round($padLeft + ($slot * $i) + ($slot / 2));
        $x1 = (int)round($x - ($bodyWidth / 2));
        $x2 = (int)round($x + ($bodyWidth / 2));
        $bodyTop = min($toY($open), $toY($close));
        $bodyBottom = max($toY($open), $toY($close));
        $minBodyHeight = $isCurrent ? 6 : ($isClosed ? 4 : 3);
        if (($bodyBottom - $bodyTop) < $minBodyHeight) {
            $bodyBottom = $bodyTop + $minBodyHeight;
        }

        $draw->setStrokeOpacity(1);
        $draw->setFillOpacity(1);
        $draw->setStrokeColor($color);
        $draw->setStrokeWidth($isCurrent ? 3 : (($isClosed || $isLast) ? 2 : 1));
        $draw->setFillColor($color);
        $draw->rectangle($x1, $bodyTop, $x2, $bodyBottom);

    }

    $image->drawImage($draw);
    $ok = $image->writeImage($path);
    $draw->destroy();
    $image->destroy();

    return $ok;
}
