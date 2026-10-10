<?php
declare(strict_types=1);

/** Dilution instruments (slice 4): what the watch proposed and a person confirmed, or a person recorded. Through mcp_dilution_instruments (the issuer wall). */

const DILUTION_KINDS = ['atm' => 'At-the-market offering', 'eloc' => 'Equity line of credit', 'convertible_note' => 'Convertible note', 'convertible_preferred' => 'Convertible preferred',
    'warrant' => 'Warrants', 'rights_offering' => 'Rights offering', 'registered_direct' => 'Registered direct', 'pipe' => 'PIPE', 'other' => 'Other'];
const DILUTION_STATUSES = ['proposed' => 'Proposed', 'active' => 'Active', 'exhausted' => 'Exhausted', 'terminated' => 'Terminated', 'dismissed' => 'Dismissed'];

function find_dilution(PDO $pdo, int $issuer, array $filters): array
{
    $where = ['d.issuer_id = :i'];
    $args = ['i' => $issuer];
    $status = $filters['status'] ?? null;
    if (isset(DILUTION_STATUSES[$status ?? ''])) { $where[] = 'd.status = :s'; $args['s'] = $status; } else { $where[] = "d.status <> 'dismissed'"; }
    if (isset(DILUTION_KINDS[$filters['kind'] ?? ''])) { $where[] = 'd.kind = :k'; $args['k'] = $filters['kind']; }
    $st = $pdo->prepare('SELECT d.*, f.form, f.accession_number FROM mcp_dilution_instruments d LEFT JOIN mcp_filings f ON f.filing_id = d.filing_id WHERE ' . implode(' AND ', $where)
        . " ORDER BY CASE d.status WHEN 'proposed' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, d.filed_on DESC NULLS LAST, d.instrument_id DESC");
    $st->execute($args);
    return $st->fetchAll();
}

function find_dilution_one(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT d.*, f.form, f.accession_number, f.filed_on AS filing_filed_on, (SELECT display_name FROM members WHERE id = d.confirmed_by) AS confirmed_by_name
                           FROM mcp_dilution_instruments d LEFT JOIN mcp_filings f ON f.filing_id = d.filing_id WHERE d.instrument_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function present_instrument(array $d): array
{
    return ['instrument_id' => (int) $d['instrument_id'], 'issuer_id' => (int) $d['issuer_id'], 'security_id' => $d['security_id'] === null ? null : (int) $d['security_id'], 'kind' => $d['kind'],
            'counterparty' => $d['counterparty'], 'filed_on' => $d['filed_on'], 'filing_id' => $d['filing_id'] === null ? null : (int) $d['filing_id'], 'form' => $d['form'] ?? null,
            'facility_amount' => $d['facility_amount'] === null ? null : (float) $d['facility_amount'], 'currency' => $d['currency'], 'remaining_amount' => $d['remaining_amount'] === null ? null : (float) $d['remaining_amount'],
            'price_terms' => $d['price_terms'], 'floating_price' => (bool) $d['floating_price'], 'shares_issuable_max' => $d['shares_issuable_max'] === null ? null : (float) $d['shares_issuable_max'],
            'expires_on' => $d['expires_on'], 'status' => $d['status'], 'proposed_by' => $d['proposed_by'], 'watch_term' => $d['watch_term'], 'confirmed_at' => json_ts($d['confirmed_at']), 'notes' => $d['notes']];
}

function dilution_status_chip(string $s): string
{
    $c = match ($s) { 'proposed' => 'text-bg-warning', 'active' => 'text-bg-primary', default => 'text-bg-secondary' };
    return '<span class="badge ' . $c . '">' . e(DILUTION_STATUSES[$s] ?? $s) . '</span>';
}
