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
