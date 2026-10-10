<?php
declare(strict_types=1);

/**
 * Holders (slice 3; holder-identity.md §2–§4). EVERY read goes through the mcp_* views and the si_holders_* functions: the issuer wall and the
 * PII wall are the database's (a Viewer's row says "Individual holder", the raw rows and the queue answer nothing to a Viewer). The aggregate
 * functions are SECURITY DEFINER without a wall of their own, so every caller of them here first proves the security is visible
 * (holders_visible_security()). Nothing here decides a match.
 */

const HOLDERS_PAGE = 48;
const HOLDER_KINDS = ['person' => 'Person', 'entity' => 'Entity', 'custodian_account' => 'Custodian account', 'unknown' => 'Unknown'];
const HOLDER_ENTITY_TYPES = ['trust', 'corporation', 'fund', 'partnership', 'estate', 'bank', 'broker', 'government', 'nonprofit', 'other'];
const HOLDER_CHANNELS = ['nobo' => 'NOBO list', 'register' => 'Register'];

/** A security the caller may see (the issuer wall), or null. */
function holders_visible_security(PDO $pdo, ?int $security): ?array
{
    if ($security === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM mcp_securities WHERE security_id = :s');
    $st->execute(['s' => $security]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The record dates loaded for a security in a channel (NOBO lists, registers), newest first. */
function holder_record_dates(PDO $pdo, int $security, string $channel): array
{
    $sql = $channel === 'nobo'
        ? 'SELECT DISTINCT record_date FROM mcp_nobo_jobs WHERE security_id = :s ORDER BY record_date DESC'
        : "SELECT DISTINCT record_date FROM mcp_imports WHERE security_id = :s AND kind = 'register' AND status = 'done' AND NOT deleted ORDER BY record_date DESC";
    $st = $pdo->prepare($sql);
    $st->execute(['s' => $security]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * An issuer's holders as cards: filters channel, record_date (on that date's list), kind, custodian (a participant), q (the name a PII role
 * may search — a Viewer's search reads entity names only, as the view nulls the rest), tag, deleted (a manager's tombstone).
 */
function find_holders(PDO $pdo, int $issuer, array $filters, int $page): array
{
    $where = ['h.issuer_id = :i'];
    $args = ['i' => $issuer];
    $rd = $filters['record_date'] ?? null;
    $channel = isset(HOLDER_CHANNELS[$filters['channel'] ?? '']) ? $filters['channel'] : null;
    if ($rd !== null || $channel !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM mcp_holder_positions hp WHERE hp.holder_id = h.holder_id' . ($channel !== null ? ' AND hp.channel = :ch' : '') . ($rd !== null ? ' AND hp.record_date = :rd' : '') . ')';
        if ($channel !== null) { $args['ch'] = $channel; }
        if ($rd !== null) { $args['rd'] = $rd; }
    }
    if (isset(HOLDER_KINDS[$filters['kind'] ?? ''])) {
        $where[] = 'h.kind = :k';
        $args['k'] = $filters['kind'];
    }
    if (($filters['custodian'] ?? null) !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM mcp_holder_accounts a WHERE a.holder_id = h.holder_id AND a.custodian_participant_id = :cu)';
        $args['cu'] = (int) $filters['custodian'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(h.display_name ILIKE :q OR coalesce(h.city, \'\') ILIKE :q)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (($filters['tag'] ?? '') !== '') {
        $where[] = ':tag = ANY (h.tags)';
        $args['tag'] = (string) $filters['tag'];
    }
    $shares = $rd !== null
        ? '(SELECT sum(hp.shares) FROM mcp_holder_positions hp WHERE hp.holder_id = h.holder_id AND hp.record_date = :rd2' . ($channel !== null ? ' AND hp.channel = :ch2' : '') . ')'
        : 'h.last_shares';
    if ($rd !== null) { $args['rd2'] = $rd; }
    if ($rd !== null && $channel !== null) { $args['ch2'] = $channel; }
    $st = $pdo->prepare('SELECT h.*, ' . $shares . ' AS shares, (SELECT string_agg(DISTINCT a.channel, \',\') FROM mcp_holder_accounts a WHERE a.holder_id = h.holder_id) AS channels,
                                (SELECT string_agg(DISTINCT a.account_kind, \',\') FROM mcp_holder_accounts a WHERE a.holder_id = h.holder_id) AS account_kinds,
                                (SELECT string_agg(DISTINCT a.custodian_name, \'; \') FROM mcp_holder_accounts a WHERE a.holder_id = h.holder_id AND a.custodian_name IS NOT NULL) AS custodians,
                                count(*) OVER () AS total
                           FROM mcp_holders h WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $shares . ' DESC NULLS LAST, h.holder_id
                          LIMIT ' . HOLDERS_PAGE . ' OFFSET ' . (max(1, $page) - 1) * HOLDERS_PAGE);
    $st->execute($args);
    return $st->fetchAll();
}

/** One holder the caller may see (through the walls). */
function find_holder(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT h.*, (SELECT name FROM mcp_issuers WHERE issuer_id = h.issuer_id) AS issuer_name FROM mcp_holders h WHERE h.holder_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A merged or tombstoned holder: where it went (the "merged into" link a 404 carries) — only for an issuer the caller may see. */
function holder_tombstone(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT h.id, h.merged_into_id, h.deleted_at FROM holders h WHERE h.id = :id AND h.issuer_id IN (SELECT si_my_issuers()) AND h.deleted_at IS NOT NULL');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A holder's accounts (the registration lines for the PII roles; a Viewer's are nulled by the view). */
function holder_accounts(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT a.*, (SELECT number FROM participants WHERE id = a.custodian_participant_id) AS custodian_number,
                                (SELECT hp.shares FROM mcp_holder_positions hp WHERE hp.holder_account_id = a.holder_account_id ORDER BY hp.record_date DESC LIMIT 1) AS last_shares,
                                (SELECT hp.record_date FROM mcp_holder_positions hp WHERE hp.holder_account_id = a.holder_account_id ORDER BY hp.record_date DESC LIMIT 1) AS last_date
                           FROM mcp_holder_accounts a WHERE a.holder_id = :id ORDER BY a.channel, a.last_seen_on DESC NULLS LAST, a.holder_account_id');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** A holder's positions by record date and channel (summed over its accounts): [{record_date, channel, shares, accounts}]. */
function holder_history_points(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT record_date, channel, sum(shares) AS shares, count(*) AS accounts FROM mcp_holder_positions WHERE holder_id = :id GROUP BY record_date, channel ORDER BY record_date, channel');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/**
 * The queue (holders-unmatched): the rows waiting for an Analyst — a NOBO row or a register row with an open proposal, or none matched and no
 * proposal (failed to parse) — each with up to three candidates. PII roles only: mcp_nobo_rows / mcp_register_rows / mcp_holder_match_proposals
 * answer nothing to anyone else.
 */
function unmatched_queue(PDO $pdo, int $issuer, ?int $import, int $page, ?string $kind = null): array
{
    $args = ['i' => $issuer];
    $imp = '';
    if ($import !== null) {
        $imp = ' AND x.import_id = :imp';
        $args['imp'] = $import;
    }
    $parts = [];
    if ($kind === null || $kind === 'nobo') {
        $parts[] = "SELECT 'nobo' AS row_kind, n.nobo_row_id AS row_id, n.import_id, j.record_date, n.row_no, n.shares, concat_ws(E'\\n', n.line1, n.line2, n.line3, n.line4, n.line5, n.line6, n.line7) AS registration,
                           NULL::text AS address, n.account_kind, n.custodian_participant_id, (SELECT name FROM participants WHERE id = n.custodian_participant_id) AS custodian_name, n.error
                      FROM mcp_nobo_rows n JOIN mcp_nobo_jobs j ON j.nobo_job_id = n.nobo_job_id JOIN mcp_imports x ON x.import_id = n.import_id
                     WHERE n.holder_id IS NULL AND x.issuer_id = :i AND NOT x.deleted AND (x.tied OR x.override_at IS NOT NULL) AND n.error IS NULL$imp";
    }
    if ($kind === null || $kind === 'register') {
        $parts[] = "SELECT 'register', g.register_row_id, g.import_id, g.record_date, g.row_no, g.shares, g.registration_name, concat_ws(', ', g.address_line, concat_ws(' ', g.city, g.state, g.zip)),
                           lower(coalesce(g.position_type, 'unknown')), NULL::bigint, NULL::text, g.error
                      FROM mcp_register_rows g JOIN mcp_imports x ON x.import_id = g.import_id
                     WHERE g.holder_id IS NULL AND NOT g.is_cede AND x.issuer_id = :i AND NOT x.deleted AND (x.tied OR x.override_at IS NOT NULL) AND g.error IS NULL$imp";
    }
    $st = $pdo->prepare('SELECT q.*, count(*) OVER () AS total FROM (' . implode(' UNION ALL ', $parts) . ') q
                          ORDER BY (SELECT max(p.score) FROM mcp_holder_match_proposals p WHERE p.row_kind = q.row_kind AND p.row_id = q.row_id AND p.status = \'proposed\') DESC NULLS LAST, q.shares DESC NULLS LAST, q.row_id
                          LIMIT 20 OFFSET ' . (max(1, $page) - 1) * 20);
    $st->execute($args);
    $rows = $st->fetchAll();
    $cand = $pdo->prepare("SELECT p.proposal_id, p.holder_id, p.score, p.evidence, p.proposed_by, (SELECT display_name FROM members WHERE id = p.proposed_by) AS proposed_by_name, h.display_name, h.kind, h.address_line, h.city, h.state, h.zip,
                                  h.last_seen_on, h.last_shares, h.accounts_count
                             FROM mcp_holder_match_proposals p JOIN mcp_holders h ON h.holder_id = p.holder_id WHERE p.row_kind = :k AND p.row_id = :r AND p.status = 'proposed' ORDER BY p.score DESC, p.proposal_id LIMIT 3");
    foreach ($rows as &$r) {
        $cand->execute(['k' => $r['row_kind'], 'r' => (int) $r['row_id']]);
        $r['candidates'] = $cand->fetchAll();
    }
    return $rows;
}

/** The evidence of a score in words ("same street and zip; the name differs by a letter or two"). */
function match_evidence_words(array $evidence, float $score): string
{
    $name = (string) ($evidence['name'] ?? '');
    $addr = (string) ($evidence['address'] ?? '');
    $n = $name === 'exact' ? 'the same name' : ((float) $name >= 0.8 ? 'the name differs by a letter or two' : 'a similar name (' . $name . ')');
    $a = match ($addr) { 'exact' => 'the same street and zip', 'zip' => 'the same zip, another street', default => 'another address' };
    if (isset($evidence['agent'])) {
        return (string) $evidence['agent'];
    }
    return ucfirst($a . '; ' . $n) . '.';
}

/** The merge compare page: both holders, their accounts, their positions by date. */
function merge_compare(PDO $pdo, int $a, int $b): array
{
    return ['a' => find_holder($pdo, $a), 'b' => find_holder($pdo, $b), 'a_accounts' => holder_accounts($pdo, $a), 'b_accounts' => holder_accounts($pdo, $b),
            'a_history' => holder_history_points($pdo, $a), 'b_history' => holder_history_points($pdo, $b)];
}

/**
 * Holders between two record dates in a channel: new, lost, up, down, same. The PII roles get the rows (names through mcp_holders); everyone
 * gets the counts (si_holders_changes_summary — a Viewer's whole answer).
 */
function holders_changes(PDO $pdo, int $security, string $channel, string $a, string $b, ?string $state, int $page): array
{
    $summary = $pdo->prepare('SELECT * FROM si_holders_changes_summary(:s, :c, :a, :b)');
    $summary->execute(['s' => $security, 'c' => $channel, 'a' => $a, 'b' => $b]);
    $out = ['summary' => $summary->fetchAll(), 'rows' => [], 'total' => 0];
    if (!si_sees_pii()) {
        $st = $pdo->prepare("SELECT c.holder_id, c.state, c.shares_a, c.shares_b, c.net, h.display_name, h.kind FROM si_holders_changes(:s, :c, :a, :b) c JOIN mcp_holders h ON h.holder_id = c.holder_id
                              WHERE h.kind <> 'person'" . ($state !== null ? ' AND c.state = :st' : '') . ' ORDER BY abs(c.net) DESC LIMIT 50');
        $st->execute(['s' => $security, 'c' => $channel, 'a' => $a, 'b' => $b] + ($state !== null ? ['st' => $state] : []));
        $out['rows'] = $st->fetchAll();                                 // the entities, by name; never a person
        return $out;
    }
    $st = $pdo->prepare('SELECT c.holder_id, c.state, c.shares_a, c.shares_b, c.net, h.display_name, h.kind, count(*) OVER () AS total FROM si_holders_changes(:s, :c, :a, :b) c JOIN mcp_holders h ON h.holder_id = c.holder_id'
        . ($state !== null ? ' WHERE c.state = :st' : '') . ' ORDER BY abs(c.net) DESC, c.holder_id LIMIT 50 OFFSET ' . (max(1, $page) - 1) * 50);
    $st->execute(['s' => $security, 'c' => $channel, 'a' => $a, 'b' => $b] + ($state !== null ? ['st' => $state] : []));
    $out['rows'] = $st->fetchAll();
    $out['total'] = (int) ($out['rows'][0]['total'] ?? 0);
    return $out;
}

/** Holders by share range on a record date: the list's (si_holders_by_range) and Broadridge's (si_share_ranges) beside it. */
function holders_ranges(PDO $pdo, int $security, string $channel, ?string $date): array
{
    $list = [];
    if ($date !== null) {
        $st = $pdo->prepare('SELECT * FROM si_holders_by_range(:s, :c, :d)');
        $st->execute(['s' => $security, 'c' => $channel, 'd' => $date]);
        $list = $st->fetchAll();
    }
    $st = $pdo->prepare('SELECT * FROM si_share_ranges(:s, CAST(:d AS date))');
    $st->execute(['s' => $security, 'd' => $date]);
    $obo = $pdo->prepare('SELECT * FROM si_obo_totals(:s)');
    $obo->execute(['s' => $security]);
    return ['list' => $list, 'broadridge' => $st->fetchAll(), 'obo_totals' => $obo->fetchAll()];
}

/** Holders by country and state on a record date. */
function holders_geography(PDO $pdo, int $security, string $channel, ?string $date): array
{
    if ($date === null) {
        return [];
    }
    $st = $pdo->prepare('SELECT * FROM si_holders_by_geography(:s, :c, :d)');
    $st->execute(['s' => $security, 'c' => $channel, 'd' => $date]);
    return $st->fetchAll();
}

/** NOBO holders by custodian and account kind, and the OBO remainder per participant beside it. */
function holders_custodians(PDO $pdo, int $security, ?string $date): array
{
    if ($date === null) {
        return ['nobo' => [], 'obo' => []];
    }
    $st = $pdo->prepare('SELECT * FROM si_holders_by_custodian(:s, :d)');
    $st->execute(['s' => $security, 'd' => $date]);
    $o = $pdo->prepare('SELECT * FROM si_obo_remainder(:s, :d)');
    $o->execute(['s' => $security, 'd' => $date]);
    return ['nobo' => $st->fetchAll(), 'obo' => $o->fetchAll()];
}

/**
 * The register on a record date: its components (the reconciliation's reading — counts and shares by position type, Cede flagged) for
 * everyone, the rows for the PII roles.
 */
function register_on(PDO $pdo, int $security, ?string $date): array
{
    if ($date === null) {
        return ['components' => [], 'rows' => [], 'import' => null];
    }
    $im = $pdo->prepare("SELECT * FROM mcp_imports WHERE security_id = :s AND kind = 'register' AND record_date = :d AND NOT deleted AND status = 'done' ORDER BY import_id DESC LIMIT 1");
    $im->execute(['s' => $security, 'd' => $date]);
    $import = $im->fetch() ?: null;
    $rows = [];
    if ($import !== null && si_sees_pii()) {
        $st = $pdo->prepare('SELECT register_row_id, row_no, section, position_type, holder_ref, registration_name, city, state, shares, restriction_text, is_cede, holder_id, error FROM mcp_register_rows
                              WHERE import_id = :i ORDER BY is_cede DESC, shares DESC NULLS LAST, row_no LIMIT 500');
        $st->execute(['i' => (int) $import['import_id']]);
        $rows = $st->fetchAll();
    }
    $d = $import === null ? [] : (json_decode((string) $import['derived_totals'], true) ?: []);
    return ['components' => ['cede' => $d['cede'] ?? null, 'by_type' => $d['by_type'] ?? [], 'holders' => $d['holders'] ?? null, 'shares' => $d['shares'] ?? null], 'rows' => $rows, 'import' => $import];
}

/** Holders by position type on the register (si_holders_by_type). */
function holders_by_type(PDO $pdo, int $security, ?string $date): array
{
    if ($date === null) {
        return [];
    }
    $st = $pdo->prepare('SELECT * FROM si_holders_by_type(:s, :d)');
    $st->execute(['s' => $security, 'd' => $date]);
    return $st->fetchAll();
}

/** A holders export may carry names for the caller? si_settings.pii_in_exports: analyst (Analyst and above), admin, nobody (the owner's answer (d)). */
function pii_export_allowed(PDO $pdo): bool
{
    $rule = (string) (one_value($pdo, 'SELECT pii_in_exports FROM mcp_settings') ?: 'analyst');
    return match ($rule) {
        'analyst' => si_sees_pii(),
        'admin' => is_si_admin(),
        default => false,
    };
}

/** The NOBO side of a participant (its page): per record date the listing's count and shares, and the OBO remainder beside its SPR position. */
function participant_nobo_summary(PDO $pdo, int $participant, int $security): array
{
    $st = $pdo->prepare('SELECT b.record_date, sum(b.nobo_count) AS nobo_count, sum(b.shares) AS nobo_shares FROM mcp_nobo_broker_rows b WHERE b.participant_id = :p AND b.security_id = :s GROUP BY b.record_date ORDER BY b.record_date DESC LIMIT 12');
    $st->execute(['p' => $participant, 's' => $security]);
    $out = [];
    $o = $pdo->prepare('SELECT spr_shares, spr_date, obo_remainder FROM si_obo_remainder(:s, :d) WHERE participant_id = :p');
    foreach ($st->fetchAll() as $r) {
        $o->execute(['s' => $security, 'd' => $r['record_date'], 'p' => $participant]);
        $out[] = $r + ($o->fetch() ?: ['spr_shares' => null, 'spr_date' => null, 'obo_remainder' => null]);
    }
    return $out;
}
