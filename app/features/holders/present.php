<?php
declare(strict_types=1);

/**
 * The holders' JSON presenters (slice 3): whitelists over the mcp_* rows. A Viewer's row comes from the view already withheld — the name
 * "Individual holder", no address, city, notes or registration — and these presenters never read a name from anywhere else.
 */

function present_holder(array $h): array
{
    return ['holder_id' => (int) $h['holder_id'], 'issuer_id' => (int) $h['issuer_id'], 'display_name' => $h['display_name'], 'kind' => $h['kind'], 'entity_type' => $h['entity_type'],
            'address_line' => $h['address_line'], 'city' => $h['city'], 'state' => $h['state'], 'country' => $h['country'], 'zip' => $h['zip'], 'is_insider' => (bool) $h['is_insider'],
            'institution_id' => $h['institution_id'] === null ? null : (int) $h['institution_id'], 'first_seen_on' => $h['first_seen_on'], 'last_seen_on' => $h['last_seen_on'],
            'accounts' => (int) $h['accounts_count'], 'last_shares' => $h['last_shares'] === null ? null : (float) $h['last_shares'],
            'shares' => array_key_exists('shares', $h) && $h['shares'] !== null ? (float) $h['shares'] : null, 'notes' => $h['notes'], 'tags' => pg_text_array((string) $h['tags']),
            'pii_withheld' => (bool) $h['pii_withheld']];
}

function present_holder_account(array $a): array
{
    return ['holder_account_id' => (int) $a['holder_account_id'], 'channel' => $a['channel'], 'account_kind' => $a['account_kind'], 'custodian_participant_id' => $a['custodian_participant_id'] === null ? null : (int) $a['custodian_participant_id'],
            'custodian_name' => $a['custodian_name'], 'registration_text' => $a['registration_text'], 'holder_ref' => $a['registration_text'] === null ? null : $a['holder_ref'],
            'first_seen_on' => $a['first_seen_on'], 'last_seen_on' => $a['last_seen_on'], 'last_shares' => $a['last_shares'] === null ? null : (float) $a['last_shares']];
}

function present_queue_row(array $r): array
{
    return ['row_kind' => $r['row_kind'], 'row_id' => (int) $r['row_id'], 'import_id' => (int) $r['import_id'], 'record_date' => $r['record_date'], 'row_no' => (int) $r['row_no'],
            'shares' => $r['shares'] === null ? null : (float) $r['shares'], 'registration' => $r['registration'], 'address' => $r['address'], 'account_kind' => $r['account_kind'],
            'custodian_name' => $r['custodian_name'],
            'candidates' => array_map(static fn (array $c): array => ['proposal_id' => (int) $c['proposal_id'], 'holder_id' => (int) $c['holder_id'], 'display_name' => $c['display_name'], 'kind' => $c['kind'],
                'score' => (float) $c['score'], 'evidence' => match_evidence_words(json_decode((string) $c['evidence'], true) ?: [], (float) $c['score']), 'by_agent' => $c['proposed_by'] !== null,
                'last_seen_on' => $c['last_seen_on'], 'last_shares' => $c['last_shares'] === null ? null : (float) $c['last_shares']], $r['candidates'])];
}

/** The kind's chip (holders.md "Status vocabulary"). */
function holder_kind_chip(string $kind): string
{
    [$cls, $label] = match ($kind) {
        'person' => ['text-bg-light', 'Person'], 'entity' => ['text-bg-info', 'Entity'], 'custodian_account' => ['text-bg-secondary', 'Custodian account'], default => ['text-bg-warning', 'Unknown'],
    };
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** An account kind's chip. */
function account_kind_chip(string $kind): string
{
    $label = ['ira' => 'IRA', 'tod' => 'TOD', 'trust' => 'Trust', 'joint' => 'Joint', 'custodial' => 'Custodial', 'corporate' => 'Corporate', 'individual' => 'Individual', 'cert' => 'Certificate',
              'book' => 'Book', 'drs' => 'DRS', 'plan' => 'Plan', 'restricted' => 'Restricted', 'unknown' => 'Unknown'][$kind] ?? $kind;
    return '<span class="badge bg-soft-primary text-primary">' . e($label) . '</span>';
}

/** The score as a bar: ≥ 0.95 green, ≥ 0.8 amber, below grey. */
function score_bar(float $score, string $id): string
{
    $c = $score >= 0.95 ? 'bg-success' : ($score >= 0.8 ? 'bg-warning' : 'bg-secondary');
    return '<div class="d-flex align-items-center gap-2" id="' . e($id) . '"><div class="progress flex-grow-1" style="height:.5rem" role="progressbar" aria-label="Score" aria-valuenow="' . e((string) round($score * 100)) . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar ' . $c . '" style="width:' . e((string) round($score * 100)) . '%"></div></div><span class="fs-11 fw-semibold">' . e(number_format($score, 2)) . '</span></div>';
}
