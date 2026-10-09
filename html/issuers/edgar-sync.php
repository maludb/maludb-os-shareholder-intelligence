<?php
declare(strict_types=1);
/**
 * Action `issuer_edgar_sync` (log `issuer.edgar_sync`; issuers.manage): read the issuer's submissions JSON from EDGAR now — the entity
 * (slice 1) and, from slice 4, the filing index. Refused in words when the issuer has no CIK or the EDGAR contact is not set.
 */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_once dirname(__DIR__, 2) . '/app/si/fetch/source.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$i = issuer_from_request($pdo);
if ($i['cik'] === null) {
    refuse(422, 'This issuer has no CIK yet: add it to read the issuer from EDGAR.');
}
$src = si_source_by_connector($pdo, 'edgar_submissions');
if ($src === null) {
    refuse(503, 'The EDGAR source is missing from this installation.');
}
$r = si_run_source($pdo, $src, ['kind' => 'manual', 'by' => (int) current_member_id(), 'issuer' => (int) $i['id']]);
$d = $r['detail'][(int) $i['id']] ?? [];
if (($d['error'] ?? null) === 'not_found') {
    refuse(422, 'EDGAR does not know CIK ' . ltrim((string) $i['cik'], '0') . '.');
}
if (!in_array($r['status'], ['ok', 'partial'], true)) {
    refuse($r['status'] === 'blocked' ? 503 : 422, (string) $r['error']);
}
log_activity($pdo, 'issuer.edgar_sync', 'issuer', (int) $i['id'], ['issuer_id' => (int) $i['id'], 'after' => ['pull_id' => $r['pull_id'], 'filled' => $d['changed'] ?? [], 'entity_changed' => $d['entity_changed'] ?? false]]);
si_done('Read ' . $i['name'] . ' from EDGAR' . (($d['changed'] ?? []) !== [] ? ' and filled ' . implode(', ', str_replace('_', ' ', $d['changed'])) : ''), (int) $i['id'],
    si_land(return_path('/issuers/' . (int) $i['id']), 'synced'), 'issuerChanged', ['pull_id' => $r['pull_id'], 'filled' => $d['changed'] ?? []]);
