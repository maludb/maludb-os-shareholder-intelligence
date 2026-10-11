<?php
declare(strict_types=1);

/**
 * Server-rendered charts (position-reports.md §3): SVG from the query, no chart library, colours from the theme's variables so light and
 * dark both read; a gap in the data is never joined; every chart carries its source line under it and a <table> twin for screen readers
 * and the phone. Phase 2 has the home's price line with the events overlay; slice 2 adds the bars, the stacked flow and the matrix here.
 */

/** The categorical palette, in the order the participant classes and the event kinds use it (the dataviz skill's). */
const SI_PALETTE = ['#3454d1', '#41b2c4', '#8b5cf6', '#f59e0b', '#ef4444', '#64748b', '#14b8a6', '#cbd5e1'];

/** The colour of an event's severity on a chart. */
function svg_severity_colour(string $severity): string
{
    return match ($severity) { 'high' => 'var(--bs-danger, #ef4444)', 'watch' => 'var(--bs-warning, #f59e0b)', default => 'var(--bs-info, #41b2c4)' };
}

/**
 * The close as a line over the window, with the events as markers on the dates they happened (a high one red, a watch amber, the rest
 * blue), the first and last close labelled. $prices: [{trade_date, close}], $events: [{event_id, on_date, kind, title, severity}].
 * Days without a price are a gap (no line joins across more than five calendar days). Answers '' when there is no price.
 */
function svg_price_overlay(array $prices, array $events, string $id, int $width = 720, int $height = 200): string
{
    if ($prices === []) {
        return '';
    }
    $padL = 8; $padR = 8; $padT = 14; $padB = 22;
    $t = static fn (string $d): int => (int) (strtotime($d . ' 00:00:00 UTC') / 86400);
    $first = $t((string) $prices[0]['trade_date']);
    $last = $t((string) $prices[count($prices) - 1]['trade_date']);
    foreach ($events as $e) {
        $first = min($first, $t((string) $e['on_date']));
        $last = max($last, $t((string) $e['on_date']));
    }
    $span = max(1, $last - $first);
    $closes = array_map(static fn (array $p): float => (float) $p['close'], $prices);
    $lo = min($closes); $hi = max($closes);
    if ($hi - $lo < 1e-9) { $lo -= 1; $hi += 1; }
    $x = static fn (int $day): float => $padL + ($day - $first) * ($width - $padL - $padR) / $span;
    $y = static fn (float $v): float => $padT + ($hi - $v) * ($height - $padT - $padB) / ($hi - $lo);
    $segments = []; $seg = []; $prevDay = null;                     // a gap of more than five calendar days starts a new segment
    foreach ($prices as $p) {
        $day = $t((string) $p['trade_date']);
        if ($prevDay !== null && $day - $prevDay > 5 && $seg !== []) {
            $segments[] = $seg;
            $seg = [];
        }
        $seg[] = sprintf('%.1f,%.1f', $x($day), $y((float) $p['close']));
        $prevDay = $day;
    }
    $segments[] = $seg;
    $paths = array_map(static fn (array $pts): string => 'M' . implode(' L', $pts), array_filter($segments, static fn (array $pts): bool => count($pts) > 1));
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="si-chart w-100" role="img" aria-labelledby="' . e($id) . '-title" id="' . e($id) . '" preserveAspectRatio="none" style="height:' . $height . 'px">'
        . '<title id="' . e($id) . '-title">Closing price over ' . (int) $span . ' days with ' . count($events) . ' events</title>'
        . '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . ($height - $padB) . '" y2="' . ($height - $padB) . '" stroke="var(--bs-border-color, #e5e7eb)" />';
    foreach ($events as $e) {
        $ex = $x($t((string) $e['on_date']));
        $c = svg_severity_colour((string) $e['severity']);
        $svg .= '<g class="si-event" id="' . e($id) . '-event-' . (int) $e['event_id'] . '"><title>' . e(date('M j', strtotime((string) $e['on_date'])) . ' · ' . $e['title']) . '</title>'
            . '<line x1="' . sprintf('%.1f', $ex) . '" x2="' . sprintf('%.1f', $ex) . '" y1="' . $padT . '" y2="' . ($height - $padB) . '" stroke="' . $c . '" stroke-dasharray="3 3" stroke-width="1" />'
            . '<circle cx="' . sprintf('%.1f', $ex) . '" cy="' . ($height - $padB + 6) . '" r="4" fill="' . $c . '" /></g>';
    }
    foreach ($paths as $d) {
        $svg .= '<path d="' . e($d) . '" fill="none" stroke="var(--bs-primary, #3454d1)" stroke-width="2" vector-effect="non-scaling-stroke" />';
    }
    $fmt = static fn (float $v): string => number_format($v, 2);
    $svg .= '<text x="' . $padL . '" y="10" font-size="10" fill="var(--bs-secondary-color, #64748b)">' . e($fmt($hi)) . '</text>'
        . '<text x="' . $padL . '" y="' . ($height - $padB - 3) . '" font-size="10" fill="var(--bs-secondary-color, #64748b)">' . e($fmt($lo)) . '</text>'
        . '</svg>';
    return $svg;
}

/**
 * A value over dates as a line with its points (a participant's shares by position date): $points [{d, v}], the first and last values
 * labelled at the axis; an absent date is simply not a point (no line joins across more than ten calendar days). '' when fewer than one point.
 */
function svg_series(array $points, string $id, string $title, int $width = 720, int $height = 180): string
{
    if ($points === []) {
        return '';
    }
    $padL = 8; $padR = 8; $padT = 14; $padB = 18;
    $t = static fn (string $d): int => (int) (strtotime($d . ' 00:00:00 UTC') / 86400);
    $first = $t((string) $points[0]['d']);
    $last = $t((string) $points[count($points) - 1]['d']);
    $span = max(1, $last - $first);
    $vals = array_map(static fn (array $p): float => (float) $p['v'], $points);
    $lo = min(0.0, min($vals)); $hi = max($vals);
    if ($hi - $lo < 1e-9) { $hi = $lo + 1; }
    $x = static fn (int $day): float => count($points) === 1 ? $width / 2 : $padL + ($day - $first) * ($width - $padL - $padR) / $span;
    $y = static fn (float $v): float => $padT + ($hi - $v) * ($height - $padT - $padB) / ($hi - $lo);
    $segments = []; $seg = []; $prev = null;
    foreach ($points as $p) {
        $day = $t((string) $p['d']);
        if ($prev !== null && $day - $prev > 10 && $seg !== []) { $segments[] = $seg; $seg = []; }
        $seg[] = [$x($day), $y((float) $p['v']), $p];
        $prev = $day;
    }
    $segments[] = $seg;
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="si-chart w-100" role="img" aria-labelledby="' . e($id) . '-title" id="' . e($id) . '" preserveAspectRatio="none" style="height:' . $height . 'px">'
        . '<title id="' . e($id) . '-title">' . e($title) . '</title>'
        . '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . sprintf('%.1f', $y($lo)) . '" y2="' . sprintf('%.1f', $y($lo)) . '" stroke="var(--bs-border-color, #e5e7eb)" />';
    foreach ($segments as $s) {
        if (count($s) > 1) {
            $svg .= '<path d="M' . implode(' L', array_map(static fn ($q) => sprintf('%.1f,%.1f', $q[0], $q[1]), $s)) . '" fill="none" stroke="var(--bs-primary, #3454d1)" stroke-width="2" vector-effect="non-scaling-stroke" />';
        }
        foreach ($s as [$px, $py, $p]) {
            $svg .= '<circle cx="' . sprintf('%.1f', $px) . '" cy="' . sprintf('%.1f', $py) . '" r="2.5" fill="var(--bs-primary, #3454d1)"><title>' . e(date('M j, Y', strtotime((string) $p['d'])) . ': ' . number_format((float) $p['v'])) . '</title></circle>';
        }
    }
    $svg .= '<text x="' . $padL . '" y="10" font-size="10" fill="var(--bs-secondary-color, #64748b)">' . e(number_format($hi)) . '</text></svg>';
    return $svg;
}

/**
 * Bars stacked by category around a zero line — the flow by class (position-reports.md §3): $bars [{label, values => [category => v]}], each
 * category's colour in $colours; positive values stack upward, negative downward; an optional line ($line [label => v], its own scale on the
 * right) drawn over them (the retail side's cumulative net). '' when there is nothing to draw.
 */
function svg_stacked_bars(array $bars, array $colours, string $id, string $title, ?array $line = null, int $width = 720, int $height = 220): string
{
    if ($bars === []) {
        return '';
    }
    $padL = 8; $padR = 8; $padT = 12; $padB = 22;
    $hi = 0; $lo = 0;
    foreach ($bars as $b) {
        $pos = array_sum(array_filter($b['values'], static fn ($v) => $v > 0));
        $neg = array_sum(array_filter($b['values'], static fn ($v) => $v < 0));
        $hi = max($hi, $pos); $lo = min($lo, $neg);
    }
    if ($hi === $lo) { $hi = 1; }
    $n = count($bars);
    $slot = ($width - $padL - $padR) / $n;
    $bw = max(2, min(36, $slot * 0.7));
    $y = static fn (float $v): float => $padT + ($hi - $v) * ($height - $padT - $padB) / ($hi - $lo);
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="si-chart w-100" role="img" aria-labelledby="' . e($id) . '-title" id="' . e($id) . '" preserveAspectRatio="none" style="height:' . $height . 'px">'
        . '<title id="' . e($id) . '-title">' . e($title) . '</title>'
        . '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . sprintf('%.1f', $y(0)) . '" y2="' . sprintf('%.1f', $y(0)) . '" stroke="var(--bs-border-color, #e5e7eb)" />';
    foreach ($bars as $k => $b) {
        $x = $padL + $k * $slot + ($slot - $bw) / 2;
        $up = 0.0; $down = 0.0;
        foreach ($b['values'] as $cat => $v) {
            if ($v == 0) { continue; }
            $from = $v > 0 ? $up : $down;
            $to = $from + $v;
            $top = $y(max($from, $to)); $bot = $y(min($from, $to));
            $svg .= '<rect x="' . sprintf('%.1f', $x) . '" y="' . sprintf('%.1f', $top) . '" width="' . sprintf('%.1f', $bw) . '" height="' . sprintf('%.1f', max(0.5, $bot - $top)) . '" fill="' . e($colours[$cat] ?? '#94a3b8') . '">'
                . '<title>' . e($b['label'] . ' · ' . $cat . ': ' . ($v > 0 ? '+' : '') . number_format($v)) . '</title></rect>';
            if ($v > 0) { $up = $to; } else { $down = $to; }
        }
        if ($n <= 12 || $k % max(1, (int) ceil($n / 8)) === 0) {
            $svg .= '<text x="' . sprintf('%.1f', $x + $bw / 2) . '" y="' . ($height - 6) . '" font-size="9" text-anchor="middle" fill="var(--bs-secondary-color, #64748b)">' . e(date('M j', strtotime((string) $b['label']))) . '</text>';
        }
    }
    if ($line !== null && $line !== []) {
        $lh = max(array_map('abs', $line)) ?: 1;
        $ly = static fn (float $v): float => $padT + (1 - ($v + $lh) / (2 * $lh)) * ($height - $padT - $padB);
        $pts = [];
        foreach (array_values($line) as $k => $v) {
            $pts[] = sprintf('%.1f,%.1f', $padL + $k * $slot + $slot / 2, $ly((float) $v));
        }
        $svg .= '<path d="M' . implode(' L', $pts) . '" fill="none" stroke="var(--bs-dark, #1f2937)" stroke-width="1.5" stroke-dasharray="4 3" vector-effect="non-scaling-stroke"><title>The retail side\'s cumulative net</title></path>';
    }
    $svg .= '<text x="' . $padL . '" y="10" font-size="10" fill="var(--bs-secondary-color, #64748b)">+' . e(number_format($hi)) . '</text>'
        . '<text x="' . $padL . '" y="' . ($height - $padB - 3) . '" font-size="10" fill="var(--bs-secondary-color, #64748b)">' . e(number_format($lo)) . '</text></svg>';
    return $svg;
}

/** Several lines over the same dates (the concentration's top-5/10/20): $lines [{label, colour, points => [date => v]}], 0–$max on the axis. */
function svg_lines(array $lines, string $id, string $title, ?float $max = null, int $width = 720, int $height = 200): string
{
    $dates = [];
    foreach ($lines as $l) { $dates = array_merge($dates, array_keys($l['points'])); }
    $dates = array_values(array_unique($dates));
    sort($dates);
    if ($dates === []) {
        return '';
    }
    $padL = 8; $padR = 8; $padT = 12; $padB = 18;
    $t = static fn (string $d): int => (int) (strtotime($d . ' 00:00:00 UTC') / 86400);
    $first = $t($dates[0]); $span = max(1, $t($dates[count($dates) - 1]) - $first);
    $hi = $max ?? max(array_map(static fn ($l) => max($l['points'] ?: [0]), $lines));
    $hi = $hi > 0 ? $hi : 1;
    $x = static fn (string $d): float => count($dates) === 1 ? $width / 2 : $padL + ($t($d) - $first) * ($width - $padL - $padR) / $span;
    $y = static fn (float $v): float => $padT + ($hi - $v) * ($height - $padT - $padB) / $hi;
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="si-chart w-100" role="img" aria-labelledby="' . e($id) . '-title" id="' . e($id) . '" preserveAspectRatio="none" style="height:' . $height . 'px">'
        . '<title id="' . e($id) . '-title">' . e($title) . '</title>'
        . '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . sprintf('%.1f', $y(0)) . '" y2="' . sprintf('%.1f', $y(0)) . '" stroke="var(--bs-border-color, #e5e7eb)" />';
    foreach ($lines as $l) {
        $pts = [];
        foreach ($l['points'] as $d => $v) { $pts[] = sprintf('%.1f,%.1f', $x((string) $d), $y((float) $v)); }
        if (count($pts) > 1) {
            $svg .= '<path d="M' . implode(' L', $pts) . '" fill="none" stroke="' . e($l['colour']) . '" stroke-width="2" vector-effect="non-scaling-stroke"><title>' . e($l['label']) . '</title></path>';
        }
        foreach ($l['points'] as $d => $v) {
            $svg .= '<circle cx="' . sprintf('%.1f', $x((string) $d)) . '" cy="' . sprintf('%.1f', $y((float) $v)) . '" r="2.5" fill="' . e($l['colour']) . '"><title>' . e($l['label'] . ' · ' . date('M j', strtotime((string) $d)) . ': ' . $v) . '</title></circle>';
        }
    }
    return $svg . '<text x="' . $padL . '" y="10" font-size="10" fill="var(--bs-secondary-color, #64748b)">' . e((string) $hi) . '</text></svg>';
}

/** The legend under a chart: one swatch per label. */
function svg_legend(array $items, string $id): string
{
    $h = '<div class="d-flex flex-wrap gap-3 fs-11 mt-2" id="' . e($id) . '">';
    foreach ($items as $label => $colour) {
        $h .= '<span class="d-inline-flex align-items-center gap-1"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' . e($colour) . '"></span>' . e($label) . '</span>';
    }
    return $h . '</div>';
}

/**
 * THE SHORT SIDE'S ONE CHART (short-side.md "Screens"): two panels on one date axis in one SVG, so an event's line crosses both. Above: the close
 * as a line (a gap of more than five calendar days is not joined) over the volume as light bars. Below, each series on its own scale named in
 * the legend: the threshold-list days as shaded bands, the fails as bars, the 5-day off-exchange short-volume ratio as a line (0–100%), short
 * interest as a share of the float as points on its settlement dates, the borrow fee as diamonds. $points: si_short_side_overlay() rows;
 * $events [{event_id, on_date, kind, title, severity}]. Answers ['svg', 'scales' => the legend's maxima] — svg '' without a price.
 */
function svg_short_overlay(array $points, array $events, string $id, string $from, string $to, int $width = 720): array
{
    $scales = ['close' => null, 'fails' => null, 'si' => null, 'fee' => null];
    if ($points === []) {
        return ['svg' => '', 'scales' => $scales];
    }
    $padL = 8; $padR = 8; $top = 14; $h1 = 190; $gap = 18; $h2 = 150; $padB = 22;
    $height = $top + $h1 + $gap + $h2 + $padB;
    $t = static fn (string $d): int => (int) (strtotime($d . ' 00:00:00 UTC') / 86400);
    $first = $t($from); $last = $t($to);
    $span = max(1, $last - $first);
    $x = static fn (string $d): float => $padL + ($t($d) - $first) * ($width - $padL - $padR) / $span;
    $dayW = max(1.5, ($width - $padL - $padR) / $span * 0.7);
    $f = static fn (float $v): string => sprintf('%.1f', $v);
    $vals = static fn (string $k): array => array_values(array_filter(array_map(static fn ($p) => $p[$k] === null ? null : (float) $p[$k], $points), static fn ($v) => $v !== null));
    $closes = $vals('close'); $vols = $vals('volume'); $fails = $vals('fails'); $sis = $vals('short_pct_float'); $fees = $vals('borrow_fee');
    $lo = min($closes); $hi = max($closes);
    if ($hi - $lo < 1e-9) { $lo -= 1; $hi += 1; }
    $vmax = $vols === [] ? 1 : max(max($vols), 1);
    $fmax = $fails === [] ? 1 : max(max($fails), 1);
    $smax = $sis === [] ? 1 : max(max($sis), 0.01);
    $bmax = $fees === [] ? 1 : max(max($fees), 0.01);
    $scales = ['close' => [$lo, $hi], 'fails' => $fails === [] ? null : $fmax, 'si' => $sis === [] ? null : $smax, 'fee' => $fees === [] ? null : $bmax, 'volume' => $vols === [] ? null : $vmax];
    $y1 = static fn (float $v): float => $top + ($hi - $v) * ($h1 * 0.78) / ($hi - $lo);              // the close in the panel's upper 78%
    $b2 = $top + $h1 + $gap + $h2;                                                                       // the lower panel's baseline
    $muted = 'var(--bs-secondary-color, #64748b)';
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="si-chart w-100" role="img" aria-labelledby="' . e($id) . '-title" id="' . e($id) . '-svg" preserveAspectRatio="none" style="height:' . $height . 'px">'
        . '<title id="' . e($id) . '-title">The close and volume, and below them short interest, fails, the off-exchange short ratio, threshold days and borrow, ' . e($from) . ' to ' . e($to) . '</title>'
        . '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . ($top + $h1) . '" y2="' . ($top + $h1) . '" stroke="var(--bs-border-color, #e5e7eb)" />'
        . '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . $b2 . '" y2="' . $b2 . '" stroke="var(--bs-border-color, #e5e7eb)" />';
    // the threshold days: bands across the lower panel
    $svg .= '<g id="' . e($id) . '-threshold">';
    foreach ($points as $p) {
        if ($p['on_threshold']) {
            $svg .= '<rect x="' . $f($x((string) $p['d']) - $dayW / 2) . '" y="' . ($top + $h1 + $gap) . '" width="' . $f($dayW + 1) . '" height="' . $h2 . '" fill="var(--bs-danger-bg-subtle, #fde2e2)"><title>' . e(date('M j', strtotime((string) $p['d']))) . ': on a threshold list</title></rect>';
        }
    }
    $svg .= '</g><g id="' . e($id) . '-volume">';
    foreach ($points as $p) {
        if ($p['volume'] !== null) {
            $bh = (float) $p['volume'] / $vmax * $h1 * 0.2;
            $svg .= '<rect x="' . $f($x((string) $p['d']) - $dayW / 2) . '" y="' . $f($top + $h1 - $bh) . '" width="' . $f($dayW) . '" height="' . $f(max(0.5, $bh)) . '" fill="var(--bs-gray-400, #cbd5e1)"><title>' . e(date('M j', strtotime((string) $p['d'])) . ' volume ' . number_format((float) $p['volume'])) . '</title></rect>';
        }
    }
    $svg .= '</g><g id="' . e($id) . '-fails">';
    foreach ($points as $p) {
        if ($p['fails'] !== null) {
            $bh = (float) $p['fails'] / $fmax * $h2 * 0.85;
            $svg .= '<rect x="' . $f($x((string) $p['d']) - $dayW / 2) . '" y="' . $f($b2 - $bh) . '" width="' . $f($dayW) . '" height="' . $f(max(0.5, $bh)) . '" fill="' . SI_PALETTE[5] . '"><title>' . e(date('M j', strtotime((string) $p['d'])) . ' fails ' . number_format((float) $p['fails'])) . '</title></rect>';
        }
    }
    $svg .= '</g>';
    // the close: a line, gaps kept
    $segs = []; $seg = []; $prev = null;
    foreach ($points as $p) {
        if ($p['close'] === null) { continue; }
        $day = $t((string) $p['d']);
        if ($prev !== null && $day - $prev > 5 && $seg !== []) { $segs[] = $seg; $seg = []; }
        $seg[] = $f($x((string) $p['d'])) . ',' . $f($y1((float) $p['close']));
        $prev = $day;
    }
    $segs[] = $seg;
    $svg .= '<g id="' . e($id) . '-price">';
    foreach ($segs as $s) {
        if (count($s) > 1) { $svg .= '<path d="M' . implode(' L', $s) . '" fill="none" stroke="var(--bs-primary, #3454d1)" stroke-width="2" vector-effect="non-scaling-stroke" />'; }
    }
    $svg .= '</g>';
    // the 5-day ratio: a line on 0–100% of the lower panel
    $segs = []; $seg = []; $prev = null;
    foreach ($points as $p) {
        if ($p['short_volume_ratio_5'] === null) { continue; }
        $day = $t((string) $p['d']);
        if ($prev !== null && $day - $prev > 5 && $seg !== []) { $segs[] = $seg; $seg = []; }
        $seg[] = $f($x((string) $p['d'])) . ',' . $f($b2 - (float) $p['short_volume_ratio_5'] * $h2 * 0.95);
        $prev = $day;
    }
    $segs[] = $seg;
    $svg .= '<g id="' . e($id) . '-ratio">';
    foreach ($segs as $s) {
        if (count($s) > 1) { $svg .= '<path d="M' . implode(' L', $s) . '" fill="none" stroke="' . SI_PALETTE[1] . '" stroke-width="1.5" vector-effect="non-scaling-stroke"><title>The 5-day off-exchange short-volume ratio (FINRA; not short interest)</title></path>'; }
    }
    $svg .= '</g><g id="' . e($id) . '-si">';
    foreach ($points as $p) {
        if ($p['short_pct_float'] !== null) {
            $cy = $b2 - (float) $p['short_pct_float'] / $smax * $h2 * 0.9;
            $svg .= '<circle cx="' . $f($x((string) $p['d'])) . '" cy="' . $f($cy) . '" r="4" fill="' . SI_PALETTE[3] . '" stroke="var(--bs-body-bg, #fff)" stroke-width="1"><title>' . e(date('M j', strtotime((string) $p['d'])) . ' settlement: short interest ' . number_format((float) $p['short_pct_float'], 2) . '% of the float' . ($p['days_to_cover'] !== null ? ', ' . number_format((float) $p['days_to_cover'], 2) . ' days to cover' : '')) . '</title></circle>';
        }
    }
    $svg .= '</g><g id="' . e($id) . '-borrow">';
    foreach ($points as $p) {
        if ($p['borrow_fee'] !== null) {
            $cx = $x((string) $p['d']); $cy = $b2 - (float) $p['borrow_fee'] / $bmax * $h2 * 0.9;
            $svg .= '<path d="M' . $f($cx) . ',' . $f($cy - 5) . ' L' . $f($cx + 5) . ',' . $f($cy) . ' L' . $f($cx) . ',' . $f($cy + 5) . ' L' . $f($cx - 5) . ',' . $f($cy) . 'Z" fill="' . SI_PALETTE[2] . '"><title>' . e(date('M j', strtotime((string) $p['d'])) . ' borrow fee ' . number_format((float) $p['borrow_fee'], 2) . '% (indicative)') . '</title></path>';
        }
    }
    $svg .= '</g><g id="' . e($id) . '-events">';
    foreach ($events as $ev) {
        $ex = $f($x((string) $ev['on_date']));
        $c = svg_severity_colour((string) $ev['severity']);
        $svg .= '<g class="si-event" id="' . e($id) . '-event-' . (int) $ev['event_id'] . '"><title>' . e(date('M j', strtotime((string) $ev['on_date'])) . ' · ' . $ev['title']) . '</title>'
            . '<line x1="' . $ex . '" x2="' . $ex . '" y1="' . $top . '" y2="' . $b2 . '" stroke="' . $c . '" stroke-dasharray="3 3" stroke-width="1" />'
            . '<circle cx="' . $ex . '" cy="' . ($b2 + 7) . '" r="4" fill="' . $c . '" /></g>';
    }
    $svg .= '</g>';
    $svg .= '<text x="' . $padL . '" y="10" font-size="10" fill="' . $muted . '">' . e(number_format($hi, 2)) . '</text>'
        . '<text x="' . $padL . '" y="' . $f($y1($lo) - 3) . '" font-size="10" fill="' . $muted . '">' . e(number_format($lo, 2)) . '</text>'
        . '<text x="' . ($width - $padR) . '" y="' . ($top + $h1 + $gap - 4) . '" font-size="10" text-anchor="end" fill="' . $muted . '">the short side, each on its own scale (the legend)</text>'
        . '<text x="' . $padL . '" y="' . ($height - 3) . '" font-size="9" fill="' . $muted . '">' . e(date('M j, Y', strtotime($from))) . '</text>'
        . '<text x="' . ($width - $padR) . '" y="' . ($height - 3) . '" font-size="9" text-anchor="end" fill="' . $muted . '">' . e(date('M j, Y', strtotime($to))) . '</text></svg>';
    return ['svg' => $svg, 'scales' => $scales];
}
