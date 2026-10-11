<?php
declare(strict_types=1);

/** The proposals' whitelist presenter. A holder's registration lines appear only in a match proposal, which the PII roles alone are ever given (the view). */
function present_proposal(array $p): array
{
    $d = $p['detail'];
    $out = ['kind' => $p['kind'], 'proposal_id' => (int) $p['proposal_id'], 'issuer_id' => (int) $p['issuer_id'], 'issuer_name' => $p['issuer_name'], 'record_type' => $p['record_type'], 'record_id' => (int) $p['record_id'],
            'title' => $p['title'], 'reason' => $p['reason'], 'note' => $p['note'], 'proposed_by' => $p['proposed_by'] === null ? null : (int) $p['proposed_by'], 'proposed_by_name' => $p['proposed_by_name'],
            'proposed_at' => json_ts($p['proposed_at'])];
    $out['detail'] = match ($p['kind']) {
        'signal' => $d === [] ? null : ['signal_id' => (int) $d['signal_id'], 'rule_key' => $d['rule_key'], 'rule_name' => $d['rule_name'], 'evidence_class' => $d['evidence_class'], 'severity' => $d['severity'], 'raised_on' => $d['raised_on'],
            'summary' => $d['summary'], 'security_name' => $d['security_name'], 'ticker' => $d['ticker'], 'proposal_reason' => $d['proposal_reason'], 'event_id' => $d['proposal_event_id'] === null ? null : (int) $d['proposal_event_id'],
            'event_title' => $d['event_title'], 'take_it' => $d['action']],
        'holder_match' => $d === [] ? null : ['holder_id' => (int) $d['holder_id'], 'holder_name' => $d['display_name'], 'holder_kind' => $d['holder_kind'], 'score' => (float) $d['score'], 'evidence' => $d['evidence_words'],
            'row_kind' => $d['row_kind'], 'row_id' => (int) $d['row_id'], 'registration' => $d['row']['registration'] ?? null, 'address' => $d['row']['address'] ?? null,
            'shares' => isset($d['row']['shares']) ? (float) $d['row']['shares'] : null, 'record_date' => $d['row']['record_date'] ?? null],
        default => $d === [] ? null : ['holder_id' => (int) $d['holder_id'], 'holder_name' => $d['display_name'], 'current_kind' => $d['holder_kind'], 'proposed_kind' => $d['kind'], 'entity_type' => $d['entity_type'], 'evidence' => $d['evidence']],
    };
    return $out;
}
