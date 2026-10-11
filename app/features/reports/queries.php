<?php
declare(strict_types=1);

/**
 * The packs' reads (events-reports.md "Query functions", signatures fixed). Every read goes through mcp_reports — the issuer wall, and a DRAFT is seen by
 * reports.run alone (db/016) — so a pack the member may not see does not exist to them. The narrative is the only text stored; the sections are rendered
 * from the other slices' own queries (reports/render.php).
 */
const REPORT_KINDS = ['weekly_pack' => 'Weekly pack', 'monthly_pack' => 'Monthly pack', 'board_pack' => 'Board pack', 'record_date_pack' => 'Record-date pack', 'custom' => 'Custom'];
const REPORT_STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'published' => 'Sent'];
/** The sections a pack can hold, in the order they print (the table's default is all of them). */
const REPORT_SECTIONS = ['movement' => 'Movement of the DTC positions', 'flow' => 'Flow by participant class', 'top_participants' => 'Largest participants', 'holders_ranges' => 'Holders by share range',
    'holders_geography' => 'Holders by country and state', 'thirteen_f' => 'Institutional holders (13F)', 'beneficial' => 'Beneficial owners over 5%', 'insiders' => 'Insider transactions',
    'short_side' => 'The short side', 'fails_threshold' => 'Fails and threshold lists', 'reconciliation' => 'The reconciliation', 'signals' => 'Signals', 'events' => 'Events'];

/** The columns a pack list and a pack page share: the report, who drafted and approved it. */
const REPORT_COLUMNS = "r.report_id, r.issuer_id, r.kind, r.title, r.period_from, r.period_to, r.status, r.narrative, r.sections, r.rendered, r.has_pdf, r.drafted_by, r.approved_by, r.approved_at, r.created_at, r.updated_at,
       (SELECT m.display_name FROM members m WHERE m.id = r.drafted_by) AS drafted_by_name, (SELECT m.member_kind FROM members m WHERE m.id = r.drafted_by) AS drafted_by_kind,
       (SELECT m.display_name FROM members m WHERE m.id = r.approved_by) AS approved_by_name";

/** The sections column (jsonb text) as a list of known section keys, in print order. */
function report_sections_list(mixed $raw): array
{
    $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    return array_values(array_filter(array_keys(REPORT_SECTIONS), static fn (string $k): bool => in_array($k, $list, true)));
}

/** An issuer's packs, the newest period first. $filters: kind, status, q (the title), limit (default 50). */
function find_reports(PDO $pdo, int $issuer, array $filters = []): array
{
    $w = ['r.issuer_id = :i'];
    $args = ['i' => $issuer];
    if (isset(REPORT_KINDS[$filters['kind'] ?? ''])) { $w[] = 'r.kind = :k'; $args['k'] = $filters['kind']; }
    if (isset(REPORT_STATUSES[$filters['status'] ?? ''])) { $w[] = 'r.status = :s'; $args['s'] = $filters['status']; }
    if (is_string($filters['q'] ?? null) && trim($filters['q']) !== '') {
        $w[] = 'r.title ILIKE :q';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])) . '%';
    }
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $st = $pdo->prepare('SELECT ' . REPORT_COLUMNS . ' FROM mcp_reports r WHERE ' . implode(' AND ', $w) . ' ORDER BY r.period_to DESC, r.report_id DESC LIMIT ' . $limit);
    $st->execute($args);
    return $st->fetchAll();
}

/** One pack the caller may see (a tombstoned one, a draft to a Viewer, an issuer behind the wall: not there), or null. */
function find_report(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . REPORT_COLUMNS . ', (SELECT i.name FROM mcp_issuers i WHERE i.issuer_id = r.issuer_id) AS issuer_name FROM mcp_reports r WHERE r.report_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The base row of a pack (the stored paths): only after find_report has let the caller see it. */
function report_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM reports WHERE id = :id AND deleted_at IS NULL');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The base row of a pack for the tombstone's restore (deleted or not), of an issuer the caller may see. */
function report_base_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM reports WHERE id = :id AND issuer_id IN (SELECT si_my_issuers())');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
