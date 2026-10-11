<?php
declare(strict_types=1);

/**
 * The signals' whitelist presenters (the JSON branch of every screen), the chips the cards and pages share, and each rule's chart of its
 * window (signals.md §2 — the chart reads the window's own rows through the views and the series functions; the evidence table reads only
 * what the rule wrote). A number is a number or null; a date is Y-m-d.
 */

/** The evidence class chip (signals.md §2): the class's colour and its words, printed beside every signal. */
const SIGNAL_CLASS_CHIPS = ['established' => ['text-bg-primary', 'Established'], 'contested' => ['text-bg-warning', 'Contested'],
                            'lore' => ['text-bg-secondary', 'Lore — no published validation'], 'data_defect' => ['text-bg-danger', 'Data defect']];
const SIGNAL_STATUS_CHIPS = ['open' => ['text-bg-dark', 'Open'], 'acknowledged' => ['text-bg-info', 'Acknowledged'], 'dismissed' => ['text-bg-secondary', 'Dismissed'],
                             'escalated' => ['text-bg-danger', 'Escalated'], 'expired' => ['text-bg-light', 'Expired']];
const SIGNAL_SEVERITY_CHIPS = ['high' => 'bg-danger', 'watch' => 'bg-warning text-dark', 'info' => 'bg-info'];
/** Every reason a disposition or a proposal can carry, in words. */
const SIGNAL_REASON_WORDS = ['known' => 'known', 'watching' => 'watching', 'explained_by_event' => 'explained by an event', 'data_defect' => 'a data defect',
                             'false_positive' => 'a false positive', 'other' => 'another reason', 'escalated' => 'escalated', 'escalate' => 'escalate'];

function signal_class_chip(string $class, string $id = 'signal-class'): string
{
    [$cls, $words] = SIGNAL_CLASS_CHIPS[$class] ?? ['text-bg-secondary', $class];
    return '<span class="badge ' . $cls . '" id="' . e($id) . '" data-class="' . e($class) . '">' . e($words) . '</span>';
}

function signal_status_chip(string $status, string $extra = ''): string
{
    [$cls, $words] = SIGNAL_STATUS_CHIPS[$status] ?? ['text-bg-secondary', $status];
    return '<span class="badge ' . $cls . ($cls === 'text-bg-light' ? ' border' : '') . '"' . $extra . '>' . e($words) . '</span>';
}

function signal_severity_chip(string $severity): string
{
    return '<span class="badge ' . (SIGNAL_SEVERITY_CHIPS[$severity] ?? 'bg-secondary') . '">' . e($severity) . '</span>';
}

/** A figure the way the evidence tables print it: whole shares with separators, a decimal to two places, '—' for none. */
function sig_n(mixed $v, int $dec = 0): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    if (!is_numeric($v)) {
        return (string) $v;
    }
    return number_format((float) $v, $dec === 0 && floor((float) $v) != (float) $v ? 2 : $dec);
}

/** A percent figure (already in percent). */
function sig_pct(mixed $v, int $dec = 2): string
{
    return $v === null || $v === '' ? '—' : number_format((float) $v, $dec) . '%';
}

/** A date from the evidence ('—' for none). */
function sig_d(mixed $v): string
{
    return $v === null || $v === '' ? '—' : format_date((string) $v);
}

function present_signal(array $s): array
{
    $n = static fn ($v) => $v === null || $v === '' ? null : (float) $v;
    return ['signal_id' => (int) $s['signal_id'], 'rule_key' => $s['rule_key'], 'rule_name' => $s['rule_name'], 'evidence_class' => $s['evidence_class'], 'basis' => $s['basis'] ?? null,
            'issuer_id' => (int) $s['issuer_id'], 'security_id' => (int) $s['security_id'], 'security_name' => $s['security_name'] ?? null, 'ticker' => $s['ticker'] ?? null,
            'raised_on' => $s['raised_on'], 'window_from' => $s['window_from'], 'window_to' => $s['window_to'], 'severity' => $s['severity'], 'value' => $n($s['value']),
            'threshold' => $n($s['threshold']), 'baseline' => $n($s['baseline']), 'summary' => $s['summary'], 'status' => $s['status'], 'repeat_count' => (int) $s['repeat_count'],
            'expires_on' => $s['expires_on'],
            'proposal' => $s['proposal_reason'] === null ? null : ['reason' => $s['proposal_reason'], 'note' => $s['proposal_note'], 'event_id' => $s['proposal_event_id'] === null ? null : (int) $s['proposal_event_id'],
                'event_title' => $s['proposal_event_title'] ?? null, 'by' => $s['proposal_by'] === null ? null : (int) $s['proposal_by'], 'by_name' => $s['proposal_by_name'] ?? null,
                'by_kind' => $s['proposal_by_kind'] ?? null, 'at' => json_ts($s['proposal_at']), 'fate' => proposal_fate($s)],
            'disposition' => $s['disposed_at'] === null ? null : ['reason' => $s['disposition_reason'], 'note' => $s['disposition_note'], 'event_id' => $s['event_id'] === null ? null : (int) $s['event_id'],
                'event_title' => $s['event_title'] ?? null, 'by' => $s['disposed_by'] === null ? null : (int) $s['disposed_by'], 'by_name' => $s['disposed_by_name'] ?? null, 'at' => json_ts($s['disposed_at'])]];
}

function present_signal_rule(array $r): array
{
    return ['rule_id' => (int) $r['rule_id'], 'key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'], 'evidence_class' => $r['evidence_class'], 'basis' => $r['basis'],
            'severity_default' => $r['severity_default'], 'params' => json_decode((string) $r['params'], true) ?: new stdClass(), 'window_days' => (int) $r['window_days'], 'enabled' => (bool) $r['enabled'],
            'effective_params' => isset($r['effective_params']) && $r['effective_params'] !== null ? (json_decode((string) $r['effective_params'], true) ?: new stdClass()) : null,
            'effective_enabled' => isset($r['effective_enabled']) ? (bool) $r['effective_enabled'] : null,
            'override' => ($r['override_params'] ?? null) === null ? null : ['params' => json_decode((string) $r['override_params'], true) ?: new stdClass(), 'enabled' => $r['override_enabled'] === null ? null : (bool) $r['override_enabled']],
            'last_fired_on' => $r['last_fired_on'], 'open_signals' => isset($r['open_signals']) ? (int) $r['open_signals'] : null];
}

function present_signal_event(array $e): array
{
    return ['event_id' => (int) $e['event_id'], 'on_date' => $e['on_date'], 'kind' => $e['kind'], 'title' => $e['title'], 'severity' => $e['severity'], 'filing_id' => $e['filing_id'] === null ? null : (int) $e['filing_id']];
}

function present_signal_outcome(?array $o): ?array
{
    if ($o === null) {
        return null;
    }
    $n = static fn ($v) => $v === null ? null : (float) $v;
    return ['price_at' => $n($o['price_at']), 'price_5d' => $n($o['price_5d']), 'price_20d' => $n($o['price_20d']), 'price_60d' => $n($o['price_60d']),
            'return_5d' => $n($o['return_5d']), 'return_20d' => $n($o['return_20d']), 'return_60d' => $n($o['return_60d'])];
}

function present_rule_outcome(array $r): array
{
    $n = (int) $r['n'];
    return ['rule_key' => $r['key'], 'rule_name' => $r['name'], 'evidence_class' => $r['evidence_class'], 'signals' => (int) $r['signals'], 'n' => $n,
            'mean_return_20d_pct' => $r['mean_return_20d_pct'] === null ? null : (float) $r['mean_return_20d_pct'],
            'share_negative_20d_pct' => $r['share_negative_20d_pct'] === null ? null : (float) $r['share_negative_20d_pct'], 'too_few_to_say' => $n < OUTCOMES_ENOUGH];
}

/** The days around a date. */
function sig_day(string $d, int $days): string
{
    return (new DateTimeImmutable($d))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/**
 * Each rule's chart of its window (signals.md §2), '' for the rules that have none (the data defects, the 13D and the 5% holder — their
 * evidence is the rows and the filing). Every read is of the security the caller reached through mcp_signals.
 */
function signal_chart(PDO $pdo, array $s, array $ev): string
{
    $sec = (int) $s['security_id'];
    $key = (string) $s['rule_key'];
    $q = static function (string $sql, array $a) use ($pdo): array { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); };
    $from = (string) $s['window_from'];
    $to = max((string) $s['window_to'], (string) $s['raised_on']);
    $legend = static fn (array $l): string => svg_legend($l, 'signal-chart-legend');
    $overlay = static function (string $a, string $b) use ($q, $sec, $s): string {
        $pts = $q('SELECT * FROM si_short_side_overlay(:s, CAST(:a AS date), CAST(:b AS date))', ['s' => $sec, 'a' => $a, 'b' => $b]);
        $evs = $q('SELECT event_id, on_date, kind, title, severity FROM mcp_events WHERE issuer_id = :i AND on_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY on_date', ['i' => (int) $s['issuer_id'], 'a' => $a, 'b' => $b]);
        $o = svg_short_overlay($pts, $evs, 'signal-chart', $a, $b);          // its SVG is signal-chart-svg
        return $o['svg'];
    };
    switch ($key) {
        case 'si_over_float':
            $rows = $q('SELECT settlement_date, pct_of_float, days_to_cover FROM si_short_interest_series(:s, CAST(:a AS date), CAST(:b AS date)) ORDER BY settlement_date', ['s' => $sec, 'a' => sig_day($to, -183), 'b' => $to]);
            $lines = [['label' => 'Short interest, % of float', 'colour' => SI_PALETTE[0], 'points' => array_filter(array_column($rows, 'pct_of_float', 'settlement_date'), static fn ($v) => $v !== null)],
                      ['label' => 'Days to cover', 'colour' => SI_PALETTE[4], 'points' => array_filter(array_column($rows, 'days_to_cover', 'settlement_date'), static fn ($v) => $v !== null)]];
            return svg_lines($lines, 'signal-chart-svg', 'Short interest as a share of the float and days to cover, six months') . $legend(['Short interest, % of float' => SI_PALETTE[0], 'Days to cover' => SI_PALETTE[4]]);
        case 'si_change':
            $rows = $q('SELECT settlement_date, short_shares FROM si_short_interest_series(:s, CAST(:a AS date), CAST(:b AS date)) ORDER BY settlement_date', ['s' => $sec, 'a' => sig_day($to, -183), 'b' => $to]);
            $pair = [(string) ($ev['from'] ?? ''), (string) ($ev['to'] ?? '')];
            $bars = array_map(static fn ($r) => ['label' => $r['settlement_date'], 'values' => [in_array($r['settlement_date'], $pair, true) ? 'The two compared' : 'Short interest' => (float) $r['short_shares']]], $rows);
            return svg_stacked_bars($bars, ['Short interest' => SI_PALETTE[7], 'The two compared' => SI_PALETTE[0]], 'signal-chart-svg', 'Short interest by settlement date, the two compared highlighted') . $legend(['Short interest' => SI_PALETTE[7], 'The two compared' => SI_PALETTE[0]]);
        case 'borrow_fee':
            $rows = $q('SELECT observed_on, fee_rate_percent, utilization_percent FROM mcp_borrow_observations WHERE security_id = :s AND observed_on BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY observed_on, recorded_at', ['s' => $sec, 'a' => sig_day($to, -90), 'b' => $to]);
            $lines = [['label' => 'Fee, %', 'colour' => SI_PALETTE[4], 'points' => array_filter(array_column($rows, 'fee_rate_percent', 'observed_on'), static fn ($v) => $v !== null)],
                      ['label' => 'Utilisation, %', 'colour' => SI_PALETTE[2], 'points' => array_filter(array_column($rows, 'utilization_percent', 'observed_on'), static fn ($v) => $v !== null)]];
            return svg_lines($lines, 'signal-chart-svg', 'The borrow fee and utilisation observed, 90 days') . $legend(['Fee, %' => SI_PALETTE[4], 'Utilisation, %' => SI_PALETTE[2]]);
        case 'ftd_spike':
            $rows = $q('SELECT settlement_date, quantity, median_60 FROM si_fails_series(:s, CAST(:a AS date), CAST(:b AS date)) ORDER BY settlement_date', ['s' => $sec, 'a' => sig_day($from, -40), 'b' => $to]);
            $lines = [['label' => 'Fails', 'colour' => SI_PALETTE[5], 'points' => array_column($rows, 'quantity', 'settlement_date')],
                      ['label' => '60-day median', 'colour' => SI_PALETTE[3], 'points' => array_filter(array_column($rows, 'median_60', 'settlement_date'), static fn ($v) => $v !== null)]];
            return svg_lines($lines, 'signal-chart-svg', 'Fails to deliver by settlement date with the 60-day median') . $legend(['Fails' => SI_PALETTE[5], '60-day median' => SI_PALETTE[3]]);
        case 'threshold_list':
            return $overlay(sig_day((string) ($ev['since'] ?? $from), -20), sig_day($to, 5));
        case 'short_volume_ratio':
            $rows = $q("SELECT trade_date, ratio, mean_5, mean_20 FROM si_daily_short_volume_series(:s, 'CNMS', CAST(:a AS date), CAST(:b AS date)) WHERE trade_date >= CAST(:a2 AS date) ORDER BY trade_date", ['s' => $sec, 'a' => sig_day($from, -40), 'b' => $to, 'a2' => sig_day($from, -10)]);
            $pct = static fn (string $c) => array_map(static fn ($v) => round(100 * (float) $v, 1), array_filter(array_column($rows, $c, 'trade_date'), static fn ($v) => $v !== null));
            $lines = [['label' => 'The day\'s ratio', 'colour' => SI_PALETTE[7], 'points' => $pct('ratio')], ['label' => '5-day mean', 'colour' => SI_PALETTE[0], 'points' => $pct('mean_5')], ['label' => '20-day mean', 'colour' => SI_PALETTE[3], 'points' => $pct('mean_20')]];
            return svg_lines($lines, 'signal-chart-svg', 'FINRA\'s off-exchange short-volume ratio and its means, %', 100.0) . $legend(['The day\'s ratio' => SI_PALETTE[7], '5-day mean' => SI_PALETTE[0], '20-day mean' => SI_PALETTE[3]]);
        case 'prime_migration':
            require_once dirname(__DIR__) . '/positions/queries.php';
            require_once dirname(__DIR__) . '/participants/queries.php';
            $flow = flow_by_date($pdo, $sec, $from, $to);
            if ($flow === []) { return ''; }
            $colours = [];
            foreach (array_keys(participant_classes($pdo)) as $k) { $colours[$k] = class_colour($k); }
            return svg_stacked_bars(array_map(static fn ($f) => ['label' => $f['date'], 'values' => $f['by_class']], $flow), $colours, 'signal-chart-svg', 'Net shares by participant class between position dates', array_column($flow, 'retail_cumulative', 'date'));
        case 'participant_jump':
        case 'nobo_drain':
            $pid = (int) ($ev['participant_id'] ?? 0);
            $rows = $q('SELECT position_date, shares FROM si_participant_series(:s, :p, CAST(:a AS date), CAST(:b AS date))', ['s' => $sec, 'p' => $pid, 'a' => sig_day($from, $key === 'nobo_drain' ? -10 : -30), 'b' => sig_day($to, 3)]);
            return svg_series(array_map(static fn ($r) => ['d' => $r['position_date'], 'v' => (float) $r['shares']], $rows), 'signal-chart-svg', 'Shares held by ' . ($ev['name'] ?? 'the participant') . ' by position date');
        case 'concentration_shift':
            require_once dirname(__DIR__) . '/positions/queries.php';
            $series = concentration_series($pdo, $sec, $from, $to);
            $lines = [['label' => 'Top 5', 'colour' => '#3454d1', 'points' => array_column($series, 'top5_pct', 'position_date')], ['label' => 'Top 10', 'colour' => '#8b5cf6', 'points' => array_column($series, 'top10_pct', 'position_date')]];
            return svg_lines($lines, 'signal-chart-svg', 'The share of the DTC total held by the top 5 and 10 participants', 100.0) . $legend(['Top 5' => '#3454d1', 'Top 10' => '#8b5cf6']);
        case 'top_holder_exit':
            $rows = $q('SELECT period_end, sum(shares) AS shares FROM mcp_institutional_holdings WHERE security_id = :s AND institution_id = :i AND put_call IS NULL GROUP BY period_end ORDER BY period_end', ['s' => $sec, 'i' => (int) ($ev['institution_id'] ?? 0)]);
            $bars = array_map(static fn ($r) => ['label' => $r['period_end'], 'values' => ['Shares' => (float) $r['shares']]], $rows);
            return svg_stacked_bars($bars, ['Shares' => SI_PALETTE[0]], 'signal-chart-svg', ($ev['name'] ?? 'The institution') . '\'s shares at each quarter\'s end');
        case 'insider_sales':
            $rows = $q("SELECT transaction_date, sum(shares) AS shares FROM mcp_insider_transactions WHERE issuer_id = :i AND transaction_code = 'S' AND NOT is_derivative AND transaction_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) GROUP BY transaction_date ORDER BY transaction_date",
                       ['i' => (int) $s['issuer_id'], 'a' => $from, 'b' => $to]);
            return svg_stacked_bars(array_map(static fn ($r) => ['label' => $r['transaction_date'], 'values' => ['Sold' => (float) $r['shares']]], $rows), ['Sold' => SI_PALETTE[4]], 'signal-chart-svg', 'Insider sales (Form 4, code S) by day in the window');
        case 'dilution_overlap':
            return $overlay(sig_day($from, -10), sig_day($to, 5));
        case 'activist_short_report':
            $on = (string) ($ev['on_date'] ?? $from);
            $prices = $q('SELECT trade_date, close FROM mcp_prices WHERE security_id = :s AND trade_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY trade_date', ['s' => $sec, 'a' => sig_day($on, -30), 'b' => sig_day($on, 30)]);
            $evs = $q('SELECT event_id, on_date, kind, title, severity FROM mcp_events WHERE event_id = :e', ['e' => (int) ($ev['event_id'] ?? 0)]);
            return svg_price_overlay($prices, $evs, 'signal-chart-svg');
        case 'price_volume_anomaly':
            return $overlay(sig_day($to, -30), $to);
    }
    return '';
}

/** The evidence as rows (each partial's table): [[label, value]] — values already in words; escaped here. */
function evidence_table(array $rows): string
{
    $h = '<div class="table-responsive"><table class="table table-sm fs-12 mb-0" id="signal-evidence-table"><tbody>';
    foreach ($rows as [$label, $value]) {
        $h .= '<tr><th class="text-muted fw-normal w-50">' . e($label) . '</th><td class="text-end text-break">' . e((string) $value) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}
