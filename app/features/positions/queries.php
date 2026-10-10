<?php
declare(strict_types=1);

/**
 * The DTC positions (slice 2): the position table on a date, the movement matrix, the flow by class, the concentration — every one a read of
 * participant_positions through the db/007 functions, for a security the caller may see (si_can_see_security first; a security behind the
 * wall reads as having no positions).
 */

/** The security's position dates in a window (newest last), or [] behind the wall. */
function position_dates(PDO $pdo, int $security, string $from = '1900-01-01', string $to = '2999-12-31'): array
{
    if (!db_bool($pdo, 'SELECT si_can_see_security(:s)', ['s' => $security])) {
        return [];
    }
    $st = $pdo->prepare('SELECT d FROM si_position_dates(:s, CAST(:f AS date), CAST(:t AS date)) d');
    $st->execute(['s' => $security, 'f' => $from, 't' => $to]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** The positions on a date ranked, with the change against the previous date and the window's net: rows + the total. */
function positions_on(PDO $pdo, int $security, string $date, ?string $prev, ?string $from, ?string $class, ?string $q): array
{
    $st = $pdo->prepare('SELECT pp.participant_id, p.number, p.name, p.class_key, pp.shares,
                                round(100.0 * pp.shares / NULLIF(si_spr_total(:s, CAST(:d AS date)), 0), 2) AS pct,
                                pp.shares - (SELECT x.shares FROM participant_positions x WHERE x.security_id = :s AND x.participant_id = pp.participant_id AND x.position_date = CAST(:prev AS date)) AS change_prev,
                                (CAST(:prev AS date) IS NOT NULL AND NOT EXISTS (SELECT 1 FROM participant_positions x WHERE x.security_id = :s AND x.participant_id = pp.participant_id AND x.position_date = CAST(:prev AS date))) AS is_new,
                                pp.shares - (SELECT x.shares FROM participant_positions x WHERE x.security_id = :s AND x.participant_id = pp.participant_id AND x.position_date = CAST(:from AS date)) AS net_window
                           FROM participant_positions pp JOIN participants p ON p.id = pp.participant_id
                          WHERE pp.security_id = :s AND pp.position_date = CAST(:d AS date) AND si_can_see_security(:s)
                            AND (CAST(:class AS text) IS NULL OR p.class_key = CAST(:class AS text))
                            AND (CAST(:q AS text) IS NULL OR p.number = CAST(:q AS text) OR p.name ILIKE \'%\' || CAST(:q AS text) || \'%\')
                          ORDER BY pp.shares DESC, p.number');
    $st->execute(['s' => $security, 'd' => $date, 'prev' => $prev, 'from' => $from, 'class' => $class, 'q' => $q]);
    $rows = $st->fetchAll();
    $gone = [];
    if ($prev !== null && $class === null && $q === null) {
        $g = $pdo->prepare('SELECT p.number, p.name, p.class_key, x.shares FROM participant_positions x JOIN participants p ON p.id = x.participant_id
                             WHERE x.security_id = :s AND x.position_date = CAST(:prev AS date) AND NOT EXISTS (SELECT 1 FROM participant_positions y WHERE y.security_id = :s AND y.participant_id = x.participant_id AND y.position_date = CAST(:d AS date))');
        $g->execute(['s' => $security, 'prev' => $prev, 'd' => $date]);
        $gone = $g->fetchAll();
    }
    return ['rows' => $rows, 'total' => one_value($pdo, 'SELECT si_spr_total(:s, CAST(:d AS date))', ['s' => $security, 'd' => $date]), 'gone' => $gone];
}

/**
 * The movement matrix: participants (rows, by |net| over the window) × dates (the window's last $maxDates position dates). Answers
 * ['dates' => [...], 'rows' => [{participant_id, number, name, class_key, net, cells => [date => {shares, change}]}], 'more' => bool].
 */
function movement_matrix(PDO $pdo, int $security, string $from, string $to, ?string $class, int $maxRows = 40, int $maxDates = 30): array
{
    $dates = array_slice(position_dates($pdo, $security, $from, $to), -$maxDates);
    if ($dates === []) {
        return ['dates' => [], 'rows' => [], 'more' => false];
    }
    $first = $dates[0];
    $last = $dates[count($dates) - 1];
    $st = $pdo->prepare('SELECT participant_id, number, name, class_key, net FROM si_position_changes(:s, CAST(:f AS date), CAST(:t AS date))
                          WHERE (CAST(:class AS text) IS NULL OR class_key = CAST(:class AS text)) ORDER BY abs(net) DESC NULLS LAST, close_shares DESC NULLS LAST, number LIMIT ' . ($maxRows + 1));
    $st->execute(['s' => $security, 'f' => $first, 't' => $last, 'class' => $class]);
    $people = $st->fetchAll();
    $more = count($people) > $maxRows;
    $people = array_slice($people, 0, $maxRows);
    if ($people === []) {
        return ['dates' => $dates, 'rows' => [], 'more' => false];
    }
    $ids = implode(',', array_map(static fn ($p) => (int) $p['participant_id'], $people));
    $cells = [];
    $st = $pdo->prepare('SELECT participant_id, position_date, shares, shares - lag(shares) OVER (PARTITION BY participant_id ORDER BY position_date) AS change
                           FROM participant_positions WHERE security_id = :s AND participant_id IN (' . $ids . ') AND position_date BETWEEN CAST(:f AS date) AND CAST(:t AS date) ORDER BY participant_id, position_date');
    $st->execute(['s' => $security, 'f' => $first, 't' => $last]);
    foreach ($st->fetchAll() as $c) {
        $cells[(int) $c['participant_id']][$c['position_date']] = ['shares' => $c['shares'], 'change' => $c['change']];
    }
    foreach ($people as &$p) {
        $p['cells'] = $cells[(int) $p['participant_id']] ?? [];
    }
    unset($p);
    return ['dates' => $dates, 'rows' => $people, 'more' => $more];
}

/** The flow by class between consecutive position dates: [{date, prev, by_class => [class => net], retail_net}], and the cumulative retail-side net. */
function flow_by_date(PDO $pdo, int $security, string $from, string $to): array
{
    $dates = position_dates($pdo, $security, $from, $to);
    $out = [];
    $cum = 0;
    $st = $pdo->prepare('SELECT class_key, side, net FROM si_participant_flow(:s, CAST(:f AS date), CAST(:t AS date))');
    for ($k = 1, $n = count($dates); $k < $n; $k++) {
        $st->execute(['s' => $security, 'f' => $dates[$k - 1], 't' => $dates[$k]]);
        $by = [];
        $retail = 0;
        foreach ($st->fetchAll() as $r) {
            $by[$r['class_key']] = (int) $r['net'];
            if ($r['side'] === 'retail') { $retail += (int) $r['net']; }
        }
        $cum += $retail;
        $out[] = ['date' => $dates[$k], 'prev' => $dates[$k - 1], 'by_class' => $by, 'retail_net' => $retail, 'retail_cumulative' => $cum];
    }
    return $out;
}

/** The concentration on every position date of the window: [{position_date, total, participants, top5_pct, top10_pct, top20_pct, hhi}]. */
function concentration_series(PDO $pdo, int $security, string $from, string $to): array
{
    $out = [];
    $st = $pdo->prepare('SELECT * FROM si_concentration(:s, CAST(:d AS date))');
    foreach (position_dates($pdo, $security, $from, $to) as $d) {
        $st->execute(['s' => $security, 'd' => $d]);
        if (($r = $st->fetch()) !== false) { $out[] = $r; }
    }
    return $out;
}

/** The window a positions screen reads: ?from=&to= (dates) or the last $days days of position dates. Answers [from, to]. */
function positions_window(PDO $pdo, int $security, int $days = 30): array
{
    $to = request_date('to') ?: null;
    $from = request_date('from') ?: null;
    $dates = position_dates($pdo, $security);
    $last = $dates === [] ? date('Y-m-d') : $dates[count($dates) - 1];
    $to ??= $last;
    $from ??= date('Y-m-d', strtotime($to . ' -' . $days . ' days'));
    return $from > $to ? [$to, $from] : [$from, $to];
}
