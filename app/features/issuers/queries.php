<?php
declare(strict_types=1);

/**
 * Issuers, securities, share counts and the restriction list (slice 1 — the CRUD exemplar; docs/build-specs/issuers-participants.md).
 * THE WALL decides every read: an issuer is reached through si_my_issuers() (db/006) — a restricted issuer answers 404 to anyone off its
 * list, the same words as an issuer that does not exist. Reads of the tables themselves (the EDGAR entity, the holidays) happen only after
 * that test, in the same statement.
 */

const ISSUER_EXCHANGES = ['nasdaq' => 'Nasdaq', 'nyse' => 'NYSE', 'nyse_american' => 'NYSE American', 'otc' => 'OTC', 'cse' => 'CSE', 'tsx' => 'TSX', 'tsxv' => 'TSXV', 'other' => 'Other'];
const ISSUER_TA_CONNECTORS = ['generic' => 'Another agent (a mapped file)', 'stc' => 'Securities Transfer Corporation', 'computershare' => 'Computershare', 'continental' => 'Continental Stock Transfer',
    'vstock' => 'VStock Transfer', 'pacific' => 'Pacific Stock Transfer', 'equiniti' => 'Equiniti'];
const SECURITY_CLASSES = ['common' => 'Common', 'preferred' => 'Preferred', 'warrant' => 'Warrant', 'unit' => 'Unit', 'note' => 'Note', 'other' => 'Other'];
const SHARE_COUNT_KINDS = ['outstanding' => 'Outstanding', 'float' => 'Float', 'authorized' => 'Authorized', 'restricted' => 'Restricted', 'unrestricted' => 'Unrestricted',
    'treasury' => 'Treasury', 'cede' => 'Cede & Co', 'drs' => 'DRS / book', 'certificated' => 'Certificated', 'plan' => 'Plan', 'public_float_value' => 'Public float ($)'];
const SHARE_COUNT_SOURCES = ['filing' => 'EDGAR cover page', 'transfer_agent' => 'the transfer agent', 'dtc_letter' => 'a DTC letter', 'register' => 'the register', 'spr' => 'the DTC SPR', 'manual' => 'entered by a person'];

/** The issuers the caller may see, as cards: [{issuer_id, name, ticker, cik, exchange, restricted, primary_security_id, cusip, last_position_date, securities_count, tags}]. */
function find_issuers(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(i.name ILIKE :q OR coalesce(i.ticker, \'\') ILIKE :q OR coalesce(i.cik, \'\') LIKE :qd)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
        $args['qd'] = '%' . preg_replace('~\D~', '', (string) $filters['q']) . '%';
        if ($args['qd'] === '%%') { $args['qd'] = '%no-digits%'; }
    }
    $st = $pdo->prepare('SELECT i.issuer_id, i.name, i.ticker, i.cik, i.exchange, i.restricted, i.primary_security_id, i.securities_count, i.transfer_agent, i.edgar_synced_at,
                                s.cusip, s.last_position_date, si_tags_of(\'issuer\', i.issuer_id) AS tags
                           FROM mcp_issuers i LEFT JOIN mcp_securities s ON s.security_id = i.primary_security_id'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY i.name');
    $st->execute($args);
    return $st->fetchAll();
}

/** The tombstoned issuers (a manager's): [{id, name, ticker, deleted_at, deleted_by}]. */
function find_deleted_issuers(PDO $pdo): array
{
    if (!db_bool($pdo, 'SELECT si_is_manager()')) {
        return [];
    }
    return $pdo->query('SELECT id, name, ticker, deleted_at, deleted_by FROM issuers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
}

/** One issuer the caller may see, every column, or null (missing, tombstoned, or behind the wall). */
function find_issuer(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT i.*, si_issuer_is_restricted(i.id) AS restricted, si_tags_of(\'issuer\', i.id) AS tags,
                                (SELECT s.id FROM securities s WHERE s.issuer_id = i.id AND s.is_primary) AS primary_security_id
                           FROM issuers i WHERE i.id = :id AND i.deleted_at IS NULL AND i.id IN (SELECT si_my_issuers())');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The issuer a request names (`issuer` or `id`), or a 404 in the same words whether it is missing or behind the wall. */
function issuer_from_request(PDO $pdo, string $field = 'issuer'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $i = $id === null ? null : find_issuer($pdo, $id);
    if ($i === null) {
        refuse(404, 'Not found.');
    }
    return $i;
}

/** The overview of one issuer: its securities, the latest count per kind of the primary, the last upload of each kind, the notes, the sources. */
function issuer_overview(PDO $pdo, int $id): array
{
    $out = ['securities' => find_securities($pdo, $id), 'counts' => [], 'uploads' => [], 'restricted_to' => [], 'notes' => find_notes($pdo, 'issuer', $id)];
    $primary = null;
    foreach ($out['securities'] as $s) {
        if ($s['is_primary']) { $primary = (int) $s['security_id']; }
    }
    if ($primary !== null) {
        $out['counts'] = share_counts_latest($pdo, $primary);
    }
    $st = $pdo->prepare('SELECT DISTINCT ON (kind) import_id, kind, file_name, record_date, period_to, status, tied, override_at, created_at FROM mcp_imports WHERE issuer_id = :i AND NOT deleted ORDER BY kind, created_at DESC');
    $st->execute(['i' => $id]);
    $out['uploads'] = $st->fetchAll();
    $out['restricted_count'] = (int) one_value($pdo, 'SELECT count(*) FROM issuer_members WHERE issuer_id = :i AND :i IN (SELECT si_my_issuers())', ['i' => $id]);
    return $out;
}

/** The issuer's securities: [{security_id, class, name, cusip, isin, ticker, figi, par_value, is_primary, listed_on, delisted_on, active, outstanding, last_position_date}]. */
function find_securities(PDO $pdo, int $issuer): array
{
    $st = $pdo->prepare('SELECT security_id, issuer_id, class, name, cusip, isin, ticker, figi, par_value, is_primary, listed_on, delisted_on, active, outstanding, float_shares, last_position_date
                           FROM mcp_securities WHERE issuer_id = :i ORDER BY is_primary DESC, class, name');
    $st->execute(['i' => $issuer]);
    return $st->fetchAll();
}

/** One security the caller may see, or null. */
function find_security(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_securities WHERE security_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The latest count of each kind for a security, with its date and source: kind => {value, as_of, source, share_count_id}. */
function share_counts_latest(PDO $pdo, int $security): array
{
    $st = $pdo->prepare("SELECT DISTINCT ON (kind) share_count_id, kind, value, as_of, source, filing_id, xbrl_frame FROM mcp_share_counts WHERE security_id = :s AND as_of <= CURRENT_DATE
                          ORDER BY kind, as_of DESC, CASE source WHEN 'filing' THEN 1 WHEN 'transfer_agent' THEN 2 WHEN 'dtc_letter' THEN 3 WHEN 'register' THEN 4 WHEN 'manual' THEN 5 ELSE 6 END, recorded_at DESC");
    $st->execute(['s' => $security]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['kind']] = $r; }
    return $out;
}

/** Every dated count of a security (of one kind when given), newest first. */
function share_counts_list(PDO $pdo, int $security, ?string $kind = null): array
{
    $st = $pdo->prepare('SELECT c.share_count_id, c.kind, c.value, c.as_of, c.source, c.filing_id, c.import_id, c.xbrl_frame, c.note, c.recorded_by, c.recorded_at,
                                (SELECT display_name FROM members WHERE id = c.recorded_by) AS recorded_by_name
                           FROM mcp_share_counts c WHERE c.security_id = :s' . ($kind !== null ? ' AND c.kind = :k' : '') . ' ORDER BY c.as_of DESC, c.kind, c.recorded_at DESC LIMIT 500');
    $st->execute(['s' => $security] + ($kind !== null ? ['k' => $kind] : []));
    return $st->fetchAll();
}

/** The restriction list (a manager's view): [{member_id, display_name, added_by, added_at}]. */
function issuer_access(PDO $pdo, int $issuer): array
{
    $st = $pdo->prepare('SELECT member_id, display_name, added_by, (SELECT display_name FROM members WHERE id = r.added_by) AS added_by_name, added_at FROM mcp_issuer_members r WHERE issuer_id = :i ORDER BY display_name');
    $st->execute(['i' => $issuer]);
    return $st->fetchAll();
}

/** The notes on a record the caller may see (mcp_notes keeps both walls, db/020). */
function find_notes(PDO $pdo, string $kind, int $id): array
{
    $st = $pdo->prepare('SELECT n.note_id, n.member_id, (SELECT display_name FROM members WHERE id = n.member_id) AS author, n.body, n.created_at FROM mcp_notes n
                          WHERE n.record_type = :k AND n.record_id = :id ORDER BY n.created_at DESC LIMIT 100');
    $st->execute(['k' => $kind, 'id' => $id]);
    return $st->fetchAll();
}

/** The tags in use, for the tag field's suggestions. [{tag_id, label, usage_count}] */
function find_tags(PDO $pdo): array
{
    return $pdo->query('SELECT tag_id, label, usage_count FROM mcp_tags ORDER BY usage_count DESC, label LIMIT 200')->fetchAll();
}

/** The SEC lookup for the add form: the company, or ['error' => words]. */
function lookup_ticker(PDO $pdo, string $ref): ?array
{
    require_once dirname(__DIR__, 2) . '/si/sec/tickers.php';
    try {
        return si_sec_lookup($pdo, $ref);
    } catch (SiFetchError $e) {
        return ['error' => $e->getMessage()];
    }
}

/** A CIK as EDGAR keeps it: the digits, zero-padded to ten; null for blank; false when it is not a CIK. */
function normalize_cik(?string $v): string|null|false
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $d = preg_replace('~^CIK~i', '', $v);
    return preg_match('~^\d{1,10}$~', (string) $d) ? str_pad((string) $d, 10, '0', STR_PAD_LEFT) : false;
}
