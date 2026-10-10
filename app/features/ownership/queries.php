<?php
declare(strict_types=1);

/**
 * The ownership screens (slice 5): the 13F holders by quarter, the changes, the 5% filers, the insider ledger, the Forms 144. Every read proves
 * the security visible first (the si_13f_* and si_beneficial_owners functions are SECURITY DEFINER without a wall) or reads the mcp_* views.
 */

const INSIDER_CODES = ['P' => 'Open-market purchase', 'S' => 'Open-market sale', 'A' => 'Grant or award', 'M' => 'Option exercise', 'F' => 'Tax withholding', 'G' => 'Gift',
    'D' => 'Disposition to the issuer', 'C' => 'Conversion', 'X' => 'Exercise of an in-the-money option', 'W' => 'Will or inheritance', 'J' => 'Other'];

/** The quarters a security's 13F rows cover, newest first. */
function thirteen_f_periods(PDO $pdo, int $security): array
{
    $st = $pdo->prepare('SELECT DISTINCT period_end FROM mcp_institutional_holdings WHERE security_id = :s ORDER BY period_end DESC');
    $st->execute(['s' => $security]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** A quarter's 13F: the holders ranked (si_13f_changes, put/call aside), the summary, the options apart. */
function thirteen_f(PDO $pdo, int $security, ?string $period): array
{
    $periods = thirteen_f_periods($pdo, $security);
    $p = $period !== null && in_array($period, $periods, true) ? $period : ($periods[0] ?? null);
    if ($p === null) {
        return ['period' => null, 'periods' => [], 'holders' => [], 'summary' => null, 'options' => []];
    }
    $h = $pdo->prepare("SELECT * FROM si_13f_changes(:s, :p) WHERE state <> 'out'");
    $h->execute(['s' => $security, 'p' => $p]);
    $sum = $pdo->prepare('SELECT * FROM si_13f_summary(:s, :p)');
    $sum->execute(['s' => $security, 'p' => $p]);
    $o = $pdo->prepare('SELECT h.institution_id, i.name, h.put_call, sum(h.shares) AS shares, sum(h.value_dollars) AS value FROM mcp_institutional_holdings h JOIN mcp_institutions i ON i.institution_id = h.institution_id
                         WHERE h.security_id = :s AND h.period_end = :p AND h.put_call IS NOT NULL GROUP BY h.institution_id, i.name, h.put_call ORDER BY sum(h.shares) DESC');
    $o->execute(['s' => $security, 'p' => $p]);
    $vote = $pdo->prepare('SELECT institution_id, sum(voting_shared) AS vsh, sum(voting_none) AS vn FROM mcp_institutional_holdings WHERE security_id = :s AND period_end = :p AND put_call IS NULL GROUP BY institution_id');
    $vote->execute(['s' => $security, 'p' => $p]);
    $v = [];
    foreach ($vote->fetchAll() as $r) { $v[(int) $r['institution_id']] = $r; }
    $holders = array_map(static fn ($r) => $r + ['voting_shared' => $v[(int) $r['institution_id']]['vsh'] ?? null, 'voting_none' => $v[(int) $r['institution_id']]['vn'] ?? null], $h->fetchAll());
    return ['period' => $p, 'periods' => $periods, 'holders' => $holders, 'summary' => $sum->fetch() ?: null, 'options' => $o->fetchAll()];
}

/** The quarter against the prior: new, out, up, down (and same), the shares from → to. */
function thirteen_f_changes(PDO $pdo, int $security, ?string $period, ?string $state): array
{
    $periods = thirteen_f_periods($pdo, $security);
    $p = $period !== null && in_array($period, $periods, true) ? $period : ($periods[0] ?? null);
    if ($p === null) {
        return ['period' => null, 'prior' => null, 'periods' => [], 'rows' => [], 'counts' => []];
    }
    $st = $pdo->prepare('SELECT * FROM si_13f_changes(:s, :p)');
    $st->execute(['s' => $security, 'p' => $p]);
    $rows = $st->fetchAll();
    $counts = array_count_values(array_column($rows, 'state'));
    if ($state !== null) {
        $rows = array_values(array_filter($rows, static fn ($r) => $r['state'] === $state));
    }
    $prior = $periods[array_search($p, $periods, true) + 1] ?? null;
    return ['period' => $p, 'prior' => $prior, 'periods' => $periods, 'rows' => $rows, 'counts' => $counts];
}

/** The 5% filers: the latest 13D or 13G per filer. */
function beneficial(PDO $pdo, int $security): array
{
    $st = $pdo->prepare('SELECT b.*, f.accession_number, f.form FROM si_beneficial_owners(:s) b LEFT JOIN mcp_filings f ON f.filing_id = b.filing_id ORDER BY b.percent_of_class DESC NULLS LAST, b.filed_on DESC');
    $st->execute(['s' => $security]);
    return $st->fetchAll();
}

/** The insider ledger: filters from, to, owner (a CIK), code; newest first. The register link's holder is the PII roles' (mcp_holders' wall). */
function insiders(PDO $pdo, int $issuer, array $filters, int $page): array
{
    $where = ['t.issuer_id = :i'];
    $args = ['i' => $issuer];
    if (($filters['from'] ?? null) !== null) { $where[] = 't.transaction_date >= :f'; $args['f'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 't.transaction_date <= :t'; $args['t'] = $filters['to']; }
    if (($filters['owner'] ?? '') !== '') { $where[] = 't.reporting_owner_cik = :o'; $args['o'] = $filters['owner']; }
    if (($filters['code'] ?? '') !== '') { $where[] = 't.transaction_code = :c'; $args['c'] = $filters['code']; }
    $st = $pdo->prepare('SELECT t.*, (SELECT display_name FROM mcp_holders h WHERE h.holder_id = t.holder_id AND NOT h.pii_withheld) AS holder_name, f.accession_number, count(*) OVER () AS total
                           FROM mcp_insider_transactions t LEFT JOIN mcp_filings f ON f.filing_id = t.filing_id WHERE ' . implode(' AND ', $where) . '
                          ORDER BY t.transaction_date DESC NULLS LAST, t.transaction_id DESC LIMIT 100 OFFSET ' . (max(1, $page) - 1) * 100);
    $st->execute($args);
    return $st->fetchAll();
}

/** The Forms 144: filters from, to (filed). */
function form144(PDO $pdo, int $issuer, array $filters): array
{
    $where = ['n.issuer_id = :i'];
    $args = ['i' => $issuer];
    if (($filters['from'] ?? null) !== null) { $where[] = 'n.filed_on >= :f'; $args['f'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 'n.filed_on <= :t'; $args['t'] = $filters['to']; }
    $st = $pdo->prepare('SELECT n.* FROM mcp_form144_notices n WHERE ' . implode(' AND ', $where) . ' ORDER BY n.filed_on DESC, n.notice_id DESC');
    $st->execute($args);
    return $st->fetchAll();
}

require_once __DIR__ . '/present.php';
