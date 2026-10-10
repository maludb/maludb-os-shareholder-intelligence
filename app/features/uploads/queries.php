<?php
declare(strict_types=1);

/** Uploads (slice 2): the list of an issuer's uploads, one upload with its totals, its rows. Read through mcp_imports (the issuer wall). */

const UPLOADS_PAGE = 50;
const UPLOAD_ROWS_PAGE = 100;

/** An issuer's uploads, newest first: filters kind, security, status (queued|running|done|failed|tied|untied|overridden|deleted). */
function find_uploads(PDO $pdo, int $issuer, array $filters = [], int $page = 1): array
{
    $where = ['u.issuer_id = :i'];
    $args = ['i' => $issuer];
    if (isset(UPLOAD_KINDS[$filters['kind'] ?? ''])) { $where[] = 'u.kind = :k'; $args['k'] = $filters['kind']; }
    if (($filters['security'] ?? null) !== null) { $where[] = 'u.security_id = :s'; $args['s'] = (int) $filters['security']; }
    $where[] = match ($filters['status'] ?? '') {
        'tied' => 'u.tied AND NOT u.deleted', 'untied' => 'u.tied = false AND u.override_at IS NULL AND NOT u.deleted', 'overridden' => 'u.override_at IS NOT NULL AND NOT u.deleted',
        'queued', 'running', 'failed' => "u.status = '" . $filters['status'] . "' AND NOT u.deleted", 'deleted' => 'u.deleted', default => 'NOT u.deleted',
    };
    $st = $pdo->prepare('SELECT u.import_id, u.kind, u.connector, u.file_name, u.security_id, (SELECT name FROM securities WHERE id = u.security_id) AS security_name, u.record_date, u.period_from, u.period_to,
                                u.status, u.rows_made, u.rows_failed, u.tied, u.tie_note, u.override_at, u.superseded_by, u.created_by, (SELECT display_name FROM members WHERE id = u.created_by) AS created_by_name,
                                u.created_at, u.finished_at, u.deleted, count(*) OVER () AS total
                           FROM mcp_imports u WHERE ' . implode(' AND ', $where) . ' ORDER BY coalesce(u.period_to, u.record_date) DESC NULLS LAST, u.created_at DESC
                          LIMIT ' . UPLOADS_PAGE . ' OFFSET ' . (max(1, $page) - 1) * UPLOADS_PAGE);
    $st->execute($args);
    return $st->fetchAll();
}

/** One upload the caller may see (a tombstoned one only for a manager — the view decides), with its file. */
function find_upload(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT u.*, (SELECT name FROM securities WHERE id = u.security_id) AS security_name, (SELECT cusip FROM securities WHERE id = u.security_id) AS cusip,
                                (SELECT display_name FROM members WHERE id = u.created_by) AS created_by_name, (SELECT display_name FROM members WHERE id = u.override_by) AS override_by_name,
                                a.byte_size, a.sha256 FROM mcp_imports u LEFT JOIN mcp_attachments a ON a.attachment_id = u.attachment_id WHERE u.import_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The upload a request names (`upload` or `id`), or a 404 in the same words whether missing or behind the wall. */
function upload_from_request(PDO $pdo, string $field = 'upload'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $u = $id === null ? null : find_upload($pdo, $id);
    if ($u === null) {
        refuse(404, 'Not found.');
    }
    return $u;
}

/** The control totals against the derived, per date: [{date, file, rows, difference}] plus the absent dates. */
function upload_totals(array $u): array
{
    $c = json_decode((string) $u['control_totals'], true) ?: [];
    $d = json_decode((string) $u['derived_totals'], true) ?: [];
    $dates = array_unique(array_merge(array_keys($c['shares_by_date'] ?? []), array_keys($d['shares_by_date'] ?? [])));
    sort($dates);
    $out = [];
    foreach ($dates as $date) {
        $f = isset($c['shares_by_date'][$date]) ? (string) $c['shares_by_date'][$date] : null;
        $r = isset($d['shares_by_date'][$date]) ? (string) $d['shares_by_date'][$date] : null;
        $out[] = ['date' => $date, 'file' => $f, 'rows' => $r, 'difference' => $f !== null && $r !== null ? (string) ((int) $r - (int) $f) : null];
    }
    return ['by_date' => $out, 'absent' => $c['absent_dates'] ?? [], 'control' => $c, 'derived' => $d];
}

/** An SPR upload's rows, the ones that failed to type first: [{row_no, participant_number, participant_name, position_date, shares, raw, error}]. */
function upload_rows(PDO $pdo, int $id, bool $failedOnly = false, int $page = 1): array
{
    $st = $pdo->prepare('SELECT row_no, participant_number, participant_name, position_date, shares, raw, error, count(*) OVER () AS total FROM spr_rows WHERE import_id = :i'
        . ($failedOnly ? ' AND error IS NOT NULL' : '') . ' ORDER BY (error IS NULL), row_no, position_date LIMIT ' . UPLOAD_ROWS_PAGE . ' OFFSET ' . (max(1, $page) - 1) * UPLOAD_ROWS_PAGE);
    $st->execute(['i' => $id]);
    return $st->fetchAll();
}

/** The state of an upload in words and colour: [colour, label] (position-reports.md §5). */
function upload_state(array $u): array
{
    if (!empty($u['deleted'])) { return ['secondary', 'in the tombstone']; }
    if ($u['override_at'] !== null) { return ['warning', 'overridden']; }
    if ($u['status'] === 'failed') { return ['danger', 'failed']; }
    if (in_array($u['status'], ['queued', 'running'], true)) { return ['secondary', $u['status']]; }
    if ($u['tied'] === true) { return ['success', 'tied']; }
    if ($u['tied'] === false) { return ['danger', 'untied']; }
    return ['secondary', $u['status']];
}

function present_upload(array $u): array
{
    [, $state] = upload_state($u);
    return ['import_id' => (int) $u['import_id'], 'kind' => $u['kind'], 'connector' => $u['connector'], 'file_name' => $u['file_name'], 'security_id' => (int) $u['security_id'],
            'record_date' => $u['record_date'], 'period_from' => $u['period_from'], 'period_to' => $u['period_to'], 'status' => $u['status'], 'state' => $state, 'tied' => $u['tied'],
            'tie_note' => $u['tie_note'], 'rows_made' => (int) $u['rows_made'], 'rows_failed' => (int) $u['rows_failed'], 'overridden' => $u['override_at'] !== null,
            'superseded_by' => $u['superseded_by'] === null ? null : (int) $u['superseded_by'], 'deleted' => (bool) $u['deleted'], 'created_at' => json_ts($u['created_at'])];
}

/**
 * What the upload page shows of a NOBO list, a register or a share range (slice 3): the job's header facts and the Bank/Broker listing
 * (brokers, never a person — a Viewer reads them), the rows that failed to type (the PII roles only: mcp_nobo_rows / mcp_register_rows answer
 * nothing to a Viewer), the rows still waiting for an Analyst, a share range's two populations.
 */
function upload_holder_facts(PDO $pdo, array $u): array
{
    $id = (int) $u['import_id'];
    $out = ['job' => null, 'clients' => [], 'failed' => [], 'waiting' => 0, 'ranges' => []];
    if ($u['kind'] === 'nobo') {
        $out['job'] = $pdo->query('SELECT * FROM mcp_nobo_jobs WHERE import_id = ' . $id)->fetch() ?: null;
        if ($out['job']) {
            $out['clients'] = $pdo->query('SELECT b.nobo_broker_row_id, b.client, b.participant_id, p.number, b.nobo_count, b.shares FROM mcp_nobo_broker_rows b LEFT JOIN participants p ON p.id = b.participant_id
                                            WHERE b.nobo_job_id = ' . (int) $out['job']['nobo_job_id'] . ' ORDER BY b.shares DESC, b.nobo_broker_row_id')->fetchAll();
        }
        $out['failed'] = $pdo->query('SELECT row_no, shares, line1, line2, line3, error FROM mcp_nobo_rows WHERE import_id = ' . $id . ' AND error IS NOT NULL ORDER BY row_no LIMIT 50')->fetchAll();
        $out['waiting'] = (int) one_value($pdo, 'SELECT count(*) FROM mcp_nobo_rows WHERE import_id = ' . $id . ' AND holder_id IS NULL AND error IS NULL');
    } elseif ($u['kind'] === 'register') {
        $out['failed'] = $pdo->query("SELECT row_no, shares, registration_name AS line1, position_type AS line2, NULL AS line3, error FROM mcp_register_rows WHERE import_id = $id AND error IS NOT NULL ORDER BY row_no LIMIT 50")->fetchAll();
        $out['waiting'] = (int) one_value($pdo, "SELECT count(*) FROM mcp_register_rows WHERE import_id = $id AND holder_id IS NULL AND error IS NULL AND NOT is_cede");
    } elseif ($u['kind'] === 'share_range') {
        $out['ranges'] = $pdo->query('SELECT x.population, x.range_label, x.range_from, x.accounts, x.shares FROM mcp_share_range_rows x JOIN mcp_share_range_reports r ON r.share_range_report_id = x.share_range_report_id
                                       WHERE r.import_id = ' . $id . ' ORDER BY x.range_from, x.population')->fetchAll();
    }
    return $out;
}
