<?php
declare(strict_types=1);

/**
 * Building an export (events-reports.md "Files"): the CSV of one kind, read through the walls AS THE PERSON WHO ASKED (the request builds it at once; the worker's `exports`
 * pass builds what was left queued, with the database's acting member set to the creator — as_member()). A holders export carries a natural person's name only
 * when si_settings.pii_in_exports admits the creator (analyst, admin or nobody); otherwise a person is "Individual holder" with no street or city, and the
 * file says so. Every cell that could be read as a formula is prefixed so a spreadsheet shows it as text.
 */
require_once dirname(__DIR__) . '/positions/queries.php';
require_once dirname(__DIR__) . '/holders/queries.php';
require_once dirname(__DIR__) . '/ownership/queries.php';
require_once dirname(__DIR__) . '/short/queries.php';
require_once dirname(__DIR__) . '/events/queries.php';
require_once dirname(__DIR__, 2) . '/si/storage.php';
require_once __DIR__ . '/queries.php';

/** The creator's PII allowance, asked of the database each time (never a per-request cache: the worker builds for several people in one pass). */
function export_pii_allowed(PDO $pdo): bool
{
    return match ((string) (one_value($pdo, 'SELECT pii_in_exports FROM mcp_settings') ?: 'analyst')) {
        'analyst' => (bool) one_value($pdo, 'SELECT si_sees_holder_pii()'),
        'admin' => (bool) one_value($pdo, 'SELECT si_is_admin()'),
        default => false,
    };
}

/** A cell safe for a spreadsheet: a text starting with = + - @ (and not a number) gets an apostrophe. */
function export_cell(mixed $v): string
{
    if ($v === null) { return ''; }
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    $s = (string) $v;
    if ($s !== '' && !is_numeric($s) && strpbrk($s[0], "=+-@\t\r") !== false) { return "'" . $s; }
    return $s;
}

/**
 * The dataset of an export row: ['header' => [...], 'rows' => [[...]], 'includes_pii' => bool, 'cut' => bool]. $x is the exports row (kind, security_id, params).
 * The security must be one the creator may see; the rows come from the views and series functions the screens read.
 */
function export_dataset(PDO $pdo, array $x): array
{
    $sec = (int) $x['security_id'];
    $s = $pdo->prepare('SELECT security_id, issuer_id, ticker, cusip FROM mcp_securities WHERE security_id = :s');
    $s->execute(['s' => $sec]);
    $security = $s->fetch() ?: throw new DomainException('The security is not one you may see.');
    $iss = (int) $security['issuer_id'];
    $p = json_decode((string) $x['params'], true) ?: [];
    $today = (new DateTimeImmutable('now', si_market_tz($pdo)))->format('Y-m-d');
    $to = (string) ($p['to'] ?? $today);
    $from = (string) ($p['from'] ?? (new DateTimeImmutable($to))->modify('-90 days')->format('Y-m-d'));
    $cap = EXPORT_MAX_ROWS + 1;
    $rows = [];
    $pii = false;
    switch ($x['kind']) {
        case 'positions_csv':
            $dates = position_dates($pdo, $sec, '1900-01-01', (string) ($p['record_date'] ?? $to));
            if ($dates === []) { return ['header' => ['position_date'], 'rows' => [], 'includes_pii' => false, 'cut' => false]; }
            $d = $p['record_date'] ?? $dates[count($dates) - 1];
            $i = array_search($d, $dates, true);
            $prev = $i === false ? ($dates[count($dates) - 2] ?? null) : ($dates[$i - 1] ?? null);
            $header = ['position_date', 'participant_number', 'participant_name', 'class', 'shares', 'percent_of_dtc', 'change_from_' . ($prev ?? 'previous')];
            foreach (positions_on($pdo, $sec, (string) $d, $prev, null, null, null)['rows'] as $r) {
                $rows[] = [$d, $r['number'], $r['name'], $r['class_key'], $r['shares'], $r['pct'], $r['change_prev']];
            }
            break;
        case 'holders_csv':
            $channel = isset(HOLDER_CHANNELS[$p['channel'] ?? '']) ? $p['channel'] : 'nobo';
            $d = $p['record_date'] ?? (holder_record_dates($pdo, $sec, $channel)[0] ?? null);
            $allow = export_pii_allowed($pdo);
            $header = ['record_date', 'channel', 'holder', 'kind', 'entity_type', 'street', 'city', 'state', 'country', 'zip', 'shares'];
            if ($d === null) { return ['header' => $header, 'rows' => [], 'includes_pii' => false, 'cut' => false]; }
            $st = $pdo->prepare('SELECT h.display_name, h.kind, h.entity_type, h.address_line, h.city, h.state, h.country, h.zip, h.pii_withheld, p.shares
                                   FROM mcp_holders h JOIN (SELECT holder_id, sum(shares) AS shares FROM mcp_holder_positions WHERE security_id = :s AND channel = :c AND record_date = CAST(:d AS date) GROUP BY holder_id) p ON p.holder_id = h.holder_id
                                  WHERE h.issuer_id = :i ORDER BY p.shares DESC, h.holder_id LIMIT ' . $cap);
            $st->execute(['s' => $sec, 'c' => $channel, 'd' => $d, 'i' => $iss]);
            foreach ($st->fetchAll() as $r) {
                $person = $r['kind'] === 'person';
                $named = !$person || ($allow && !$r['pii_withheld']);
                if ($person && $named) { $pii = true; }
                $rows[] = [$d, $channel, $named ? $r['display_name'] : 'Individual holder', $r['kind'], $r['entity_type'], $named ? $r['address_line'] : null, $named ? $r['city'] : null, $r['state'], $r['country'], $named ? $r['zip'] : substr((string) $r['zip'], 0, 3), $r['shares']];
            }
            break;
        case 'institutions_csv':
            $t = thirteen_f($pdo, $sec, isset($p['record_date']) ? (string) $p['record_date'] : null);
            $header = ['period_end', 'institution', 'kind', 'shares', 'prior_shares', 'net', 'value_dollars', 'percent_of_outstanding'];
            foreach ($t['holders'] as $r) { $rows[] = [$t['period'], $r['name'], $r['kind'], $r['shares'], $r['prior_shares'], $r['net'], $r['value_dollars'], $r['pct_of_outstanding']]; }
            break;
        case 'short_csv':
            $header = ['settlement_date', 'published_on', 'short_shares', 'previous_short_shares', 'change_shares', 'days_to_cover', 'avg_daily_volume', 'percent_of_float', 'percent_of_outstanding'];
            foreach (short_interest($pdo, $sec, $from, $to) as $r) { $rows[] = [$r['settlement_date'], $r['published_on'], $r['short_shares'], $r['previous_short_shares'], $r['change_shares'], $r['days_to_cover'], $r['avg_daily_volume'], $r['pct_of_float'], $r['pct_of_outstanding']]; }
            break;
        case 'signals_csv':
            $header = ['signal_id', 'raised_on', 'rule', 'evidence_class', 'severity', 'status', 'value', 'threshold', 'summary', 'disposition_reason'];
            $st = $pdo->prepare('SELECT signal_id, raised_on, rule_key, evidence_class, severity, status, value, threshold, summary, disposition_reason FROM mcp_signals WHERE issuer_id = :i AND raised_on BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY raised_on DESC, signal_id DESC LIMIT ' . $cap);
            $st->execute(['i' => $iss, 'a' => $from, 'b' => $to]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { $rows[] = $r; }
            break;
        case 'events_csv':
            $header = ['event_id', 'on_date', 'kind', 'severity', 'title', 'url', 'filing_id', 'source'];
            $st = $pdo->prepare('SELECT event_id, on_date, kind, severity, title, url, filing_id, source FROM mcp_events WHERE issuer_id = :i AND on_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY on_date DESC, event_id DESC LIMIT ' . $cap);
            $st->execute(['i' => $iss, 'a' => $from, 'b' => $to]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { $rows[] = $r; }
            break;
        case 'filings_csv':
            $header = ['filing_id', 'form', 'filed_on', 'accession_number', 'description', 'about_us', 'url'];
            $st = $pdo->prepare('SELECT filing_id, form, filed_on, accession_number, primary_doc_description, about_us, url FROM mcp_filings WHERE issuer_id = :i AND filed_on BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY filed_on DESC, filing_id DESC LIMIT ' . $cap);
            $st->execute(['i' => $iss, 'a' => $from, 'b' => $to]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { $rows[] = $r; }
            break;
        default:
            throw new DomainException('Unknown kind of export.');
    }
    $cut = count($rows) > EXPORT_MAX_ROWS;
    return ['header' => $header, 'rows' => $cut ? array_slice($rows, 0, EXPORT_MAX_ROWS) : $rows, 'includes_pii' => $pii, 'cut' => $cut];
}

/** The CSV text of a dataset (UTF-8, a header row, cells made safe for a spreadsheet). */
function export_csv(array $ds): string
{
    $fh = fopen('php://memory', 'r+');
    fputcsv($fh, $ds['header'], ',', '"', '');
    foreach ($ds['rows'] as $r) { fputcsv($fh, array_map('export_cell', $r), ',', '"', ''); }
    rewind($fh);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}

/**
 * Build a queued export now, as the database's current acting member: running → done with the file, its size and its row count, or failed with the reason. A holders
 * export is logged with its row count. Answers the exports row after. The worker calls this inside as_member().
 */
function export_build(PDO $pdo, int $id): array
{
    $x = export_row($pdo, $id) ?? throw new DomainException('Not found.');
    $claim = $pdo->prepare("UPDATE exports SET status = 'running' WHERE id = :id AND status = 'queued'");
    $claim->execute(['id' => $id]);
    if ($claim->rowCount() !== 1) { return $x; }                     // someone else (the request, the worker) has it
    try {
        $ds = export_dataset($pdo, $x);
        $csv = export_csv($ds);
        $path = si_store_named('exports', $id, 'csv', $csv);
        $pdo->prepare("UPDATE exports SET status = 'done', storage_path = :p, byte_size = :b, item_count = :n, includes_pii = :pii, finished_at = now(), log = :l WHERE id = :id")
            ->execute(['p' => $path, 'b' => strlen($csv), 'n' => count($ds['rows']), 'pii' => $ds['includes_pii'] ? 'true' : 'false', 'l' => $ds['cut'] ? 'Cut at ' . EXPORT_MAX_ROWS . ' rows.' : null, 'id' => $id]);
        log_activity($pdo, 'export.build', 'export', $id, ['issuer_id' => $x['issuer_id'] === null ? null : (int) $x['issuer_id'], 'security_id' => (int) $x['security_id'],
            'after' => ['export_id' => $id, 'kind' => $x['kind'], 'rows' => count($ds['rows']), 'includes_pii' => $ds['includes_pii'], 'bytes' => strlen($csv), 'cut' => $ds['cut'], 'created_by' => $x['created_by'] === null ? null : (int) $x['created_by']]]);
    } catch (Throwable $e) {
        error_log('export_build ' . $id . ': ' . $e->getMessage());
        $why = $e instanceof DomainException || $e instanceof SiStoreError ? $e->getMessage() : 'The export could not be built.';
        $pdo->prepare("UPDATE exports SET status = 'failed', finished_at = now(), log = :l WHERE id = :id")->execute(['l' => $why, 'id' => $id]);
        log_activity($pdo, 'export.build', 'export', $id, ['issuer_id' => $x['issuer_id'] === null ? null : (int) $x['issuer_id'], 'after' => ['export_id' => $id, 'kind' => $x['kind'], 'failed' => true]]);
    }
    return export_row($pdo, $id);
}
