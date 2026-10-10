<?php
declare(strict_types=1);

/** Institutions (slice 5): the 13F and 13D/13G filers. Through mcp_institutions (anyone who belongs here) and the holdings views (the issuer wall). */

const INSTITUTION_KINDS = ['investment_adviser' => 'Investment adviser', 'hedge_fund' => 'Hedge fund', 'bank' => 'Bank', 'insurance' => 'Insurance', 'pension' => 'Pension',
    'mutual_fund' => 'Mutual fund', 'etf_sponsor' => 'ETF sponsor', 'activist' => 'Activist', 'individual' => 'Individual', 'corporation' => 'Corporation', 'family_office' => 'Family office', 'other' => 'Other'];

/** Institutions as cards: filters kind, q (name, CIK, an old name), activist; with the latest holding in a security. */
function find_institutions(PDO $pdo, array $filters, ?int $security): array
{
    $where = ['true'];
    $args = [];
    if (isset(INSTITUTION_KINDS[$filters['kind'] ?? ''])) { $where[] = 'i.kind = :k'; $args['k'] = $filters['kind']; }
    if (!empty($filters['activist'])) { $where[] = 'i.is_activist'; }
    if (($filters['q'] ?? '') !== '') {
        $where[] = "(i.name ILIKE :q OR i.cik LIKE :qc OR EXISTS (SELECT 1 FROM unnest(i.aliases) a WHERE a ILIKE :q))";
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
        $args['qc'] = '%' . preg_replace('~\D~', '', (string) $filters['q']) . '%';
    }
    if ($security !== null) {
        $args['s'] = $security;
        $where[] = '(EXISTS (SELECT 1 FROM mcp_institutional_holdings h WHERE h.institution_id = i.institution_id AND h.security_id = :s) OR EXISTS (SELECT 1 FROM mcp_beneficial_ownership b WHERE b.institution_id = i.institution_id AND b.security_id = :s))';
    }
    $latest = $security === null ? 'NULL::numeric AS shares, NULL::date AS period_end' :
        '(SELECT sum(h.shares) FROM mcp_institutional_holdings h WHERE h.institution_id = i.institution_id AND h.security_id = :s AND h.put_call IS NULL AND h.period_end = (SELECT max(period_end) FROM mcp_institutional_holdings x WHERE x.institution_id = i.institution_id AND x.security_id = :s)) AS shares,
         (SELECT max(period_end) FROM mcp_institutional_holdings x WHERE x.institution_id = i.institution_id AND x.security_id = :s) AS period_end,
         (SELECT percent_of_class FROM mcp_beneficial_ownership b WHERE b.institution_id = i.institution_id AND b.security_id = :s ORDER BY b.filed_on DESC LIMIT 1) AS percent_of_class';
    $st = $pdo->prepare('SELECT i.*, ' . $latest . ' FROM mcp_institutions i WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($security === null ? 'i.name' : 'shares DESC NULLS LAST, i.name') . ' LIMIT 200');
    $st->execute($args);
    return $st->fetchAll();
}

function find_institution(PDO $pdo, int $id, ?int $security): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_institutions WHERE institution_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** An institution's holdings in a security by quarter (puts and calls apart), and its 13D/13G history. */
function institution_holdings(PDO $pdo, int $id, int $security): array
{
    $st = $pdo->prepare('SELECT period_end, put_call, sum(shares) AS shares, sum(value_dollars) AS value, max(accession_number) AS accession, bool_or(is_amendment) AS amended FROM mcp_institutional_holdings
                          WHERE institution_id = :i AND security_id = :s GROUP BY period_end, put_call ORDER BY period_end, put_call NULLS FIRST');
    $st->execute(['i' => $id, 's' => $security]);
    $b = $pdo->prepare('SELECT * FROM mcp_beneficial_ownership WHERE institution_id = :i AND security_id = :s ORDER BY filed_on DESC');
    $b->execute(['i' => $id, 's' => $security]);
    return ['holdings' => $st->fetchAll(), 'beneficial' => $b->fetchAll()];
}

require_once __DIR__ . '/present.php';
