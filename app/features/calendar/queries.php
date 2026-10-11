<?php
declare(strict_types=1);

/**
 * The calendar's reads (events-reports.md "Query functions"). The month grid shows what happened (stored events, chips by kind) and what is
 * coming: record dates and earnings a person recorded, a dilution instrument's expiry, and two COMPUTED days no row stores — FINRA's short-interest
 * publication (about the seventh market day after each settlement date: the 15th and the month's last, each moved back to a market day) and the 13F
 * deadline (45 days after each calendar quarter, moved on to a market day). The computed days are muted and say they are expected.
 */
require_once dirname(__DIR__, 2) . '/si/fetch/calendar.php';
require_once dirname(__DIR__, 2) . '/si/fetch/source.php';
require_once dirname(__DIR__) . '/events/queries.php';

/** The kinds of a computed day, in words. */
const CALENDAR_COMPUTED_KINDS = ['short_interest_publication' => 'FINRA short interest (expected)', 'thirteen_f_deadline' => '13F deadline'];

/** FINRA's expected publication days and the 13F deadlines that fall in [$from, $to]: rows {on_date, kind, title, source: computed, severity: info}. */
function calendar_computed(PDO $pdo, string $from, string $to): array
{
    $rows = [];
    // FINRA: a settlement date is the 15th (or the market day before) and the month's last market day; publication is the 7th market day after.
    $start = (new DateTimeImmutable($from))->modify('-45 days')->modify('first day of this month')->format('Y-m-d');
    $days = si_market_days($pdo, $start, $to);
    $settlements = [];
    for ($m = new DateTimeImmutable($start); $m->format('Y-m-d') <= $to; $m = $m->modify('first day of next month')) {
        $s1 = null; $s2 = null;
        foreach ($days as $d) {
            if ($d < $m->format('Y-m-01') || $d > $m->format('Y-m-t')) { continue; }
            if ($d <= $m->format('Y-m-15')) { $s1 = $d; }
            $s2 = $d;
        }
        foreach (array_filter([$s1, $s2]) as $s) { $settlements[$s] = true; }
    }
    foreach (array_keys($settlements) as $s) {
        $i = array_search($s, $days, true);
        $pub = $i === false ? null : ($days[$i + 7] ?? null);
        if ($pub !== null && $pub >= $from && $pub <= $to) {
            $rows[] = ['on_date' => $pub, 'kind' => 'short_interest_publication', 'title' => 'FINRA short interest for ' . date('M j', strtotime($s)) . ' expected', 'source' => 'computed', 'severity' => 'info', 'event_id' => null, 'url' => null];
        }
    }
    // The 13F: 45 days after each calendar quarter end, on to the next market day.
    for ($y = (int) substr($from, 0, 4) - 1; $y <= (int) substr($to, 0, 4); $y++) {
        foreach (['03-31', '06-30', '09-30', '12-31'] as $qe) {
            $due = (new DateTimeImmutable($y . '-' . $qe))->modify('+45 days');
            $md = si_market_days($pdo, $due->format('Y-m-d'), $due->modify('+7 days')->format('Y-m-d'));
            $dd = $md[0] ?? $due->format('Y-m-d');
            if ($dd >= $from && $dd <= $to) {
                $rows[] = ['on_date' => $dd, 'kind' => 'thirteen_f_deadline', 'title' => '13F filings due for the quarter ending ' . date('M j', strtotime($y . '-' . $qe)), 'source' => 'computed', 'severity' => 'info', 'event_id' => null, 'url' => null];
            }
        }
    }
    usort($rows, static fn (array $a, array $b): int => [$a['on_date'], $a['title']] <=> [$b['on_date'], $b['title']]);
    return $rows;
}

/** The expiries of an issuer's live dilution instruments in [$from, $to] (an ATM or ELOC running out is a day to know): rows as an event, source `instrument`. */
function calendar_expiries(PDO $pdo, int $issuer, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT instrument_id, expires_on, kind, counterparty FROM mcp_dilution_instruments WHERE issuer_id = :i AND status IN ('proposed', 'active') AND expires_on BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY expires_on, instrument_id");
    $st->execute(['i' => $issuer, 'a' => $from, 'b' => $to]);
    return array_map(static fn (array $r): array => ['on_date' => $r['expires_on'], 'kind' => 'dilution_expiry', 'title' => ucfirst(str_replace('_', ' ', (string) $r['kind'])) . ($r['counterparty'] ? ' with ' . $r['counterparty'] : '') . ' expires',
        'source' => 'instrument', 'severity' => 'watch', 'event_id' => null, 'url' => '/dilution/' . (int) $r['instrument_id']], $st->fetchAll());
}

/**
 * One month of the grid. $month is YYYY-MM (the current month in market time when blank or malformed); $kinds narrows the stored events.
 * Answers ['month', 'label', 'prev', 'next', 'today', 'weeks' => [[ {date, in_month, items[]} x7 ]], 'items' => [date => items], 'agenda' => [items of the month by date]]
 * where an item is {type: event|expiry|computed, when: past|coming, kind, title, severity, event_id?, url?, source}.
 */
function calendar_month(PDO $pdo, int $issuer, string $month, array $kinds = []): array
{
    $tz = si_market_tz($pdo);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = substr($today, 0, 7);
    }
    $first = new DateTimeImmutable($month . '-01');
    $gridStart = $first->modify('monday this week');
    if ($first->format('N') === '1') { $gridStart = $first; }
    $last = $first->modify('last day of this month');
    $gridEnd = $last->format('N') === '7' ? $last : $last->modify('sunday this week');
    $a = $gridStart->format('Y-m-d');
    $b = $gridEnd->format('Y-m-d');
    $kinds = array_values(array_filter($kinds, static fn ($k): bool => isset(EVENT_KINDS[$k])));
    $items = [];
    $args = [];
    $where = events_where($issuer, ['kind' => $kinds, 'from' => $a, 'to' => $b], $args);
    $st = $pdo->prepare("SELECT e.event_id, e.on_date, e.kind, e.title, e.severity, e.source FROM mcp_events e WHERE $where ORDER BY e.on_date, e.event_id");
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $items[$r['on_date']][] = ['type' => 'event', 'when' => $r['on_date'] < $today ? 'past' : 'coming', 'kind' => $r['kind'], 'title' => $r['title'], 'severity' => $r['severity'],
            'event_id' => (int) $r['event_id'], 'url' => '/events/' . (int) $r['event_id'], 'source' => $r['source']];
    }
    if ($kinds === []) {
        foreach (calendar_expiries($pdo, $issuer, $a, $b) as $r) {
            $items[$r['on_date']][] = ['type' => 'expiry', 'when' => $r['on_date'] < $today ? 'past' : 'coming', 'kind' => 'dilution_expiry', 'title' => $r['title'], 'severity' => 'watch', 'url' => $r['url'], 'source' => 'instrument'];
        }
        foreach (calendar_computed($pdo, $a, $b) as $r) {
            $items[$r['on_date']][] = ['type' => 'computed', 'when' => $r['on_date'] < $today ? 'past' : 'coming', 'kind' => $r['kind'], 'title' => $r['title'], 'severity' => 'info', 'url' => null, 'source' => 'computed'];
        }
    }
    $weeks = [];
    $agenda = [];
    for ($d = $gridStart, $w = 0; $d <= $gridEnd; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $weeks[intdiv($w++, 7)][] = ['date' => $key, 'in_month' => $d->format('Y-m') === $month, 'items' => $items[$key] ?? []];
        if ($d->format('Y-m') === $month && isset($items[$key])) { $agenda[$key] = $items[$key]; }
    }
    return ['month' => $month, 'label' => $first->format('F Y'), 'prev' => $first->modify('-1 month')->format('Y-m'), 'next' => $first->modify('+1 month')->format('Y-m'),
            'today' => $today, 'weeks' => $weeks, 'items' => $items, 'agenda' => $agenda, 'kinds' => $kinds];
}
