<?php
declare(strict_types=1);
/**
 * Action `issuer_save` (log `issuer.save`; issuers.manage; an agent's pauses — other): make an issuer (no `issuer`: name required) or change one
 * (`issuer` + the fields to change; a field left out stays). The CIK is kept zero-padded to ten digits. A new issuer with a CIK, or a changed
 * CIK, is read from EDGAR at once (the entity: issuer_edgar_sync's pull) — a pull that cannot run (no EDGAR contact yet) is said, never a refusal.
 */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('issuer');
$current = $id === null ? null : issuer_from_request($pdo);
$errors = [];
$f = issuer_fields_from_request($errors, $id === null);
if ($errors !== []) {
    si_refuse_fields($errors);
}
$before = $current === null ? [] : array_intersect_key(array_merge($current, ['market_holidays' => pg_text_array((string) $current['market_holidays'])]), $f);
$diff = si_diff($before, $f);
if ($current !== null && $diff['after'] === []) {
    si_done('Nothing to change', $id, si_land('/issuers/' . $id), 'issuerChanged');
}
$newId = si_guard($pdo, static function () use ($pdo, $id, $f, $me, $diff): int {
    $pdo->beginTransaction();
    try {
        $newId = issuer_save($pdo, $id, $f, $me);
        $after = $diff['after'];
        $b = $diff['before'];
        foreach (['notes'] as $k) {
            if (array_key_exists($k, $after)) { $after[$k] = '(changed)'; $b[$k] = '(changed)'; }
        }
        log_activity($pdo, 'issuer.save', 'issuer', $newId, ['issuer_id' => $newId, 'before' => $id === null ? null : $b, 'after' => $after + ($id === null ? ['made' => true] : [])]);
        $pdo->commit();
        return $newId;
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (($s = issuer_constraint_sentence($e)) !== null) {
            throw new DomainException($s);
        }
        throw $e;
    }
});
$notice = $id === null ? 'created' : 'updated';
$sync = null;
if (($f['cik'] ?? null) !== null && ($id === null || array_key_exists('cik', $diff['after']))) {
    require_once dirname(__DIR__, 2) . '/app/si/fetch/source.php';
    $src = si_source_by_connector($pdo, 'edgar_submissions');
    if ($src !== null) {
        $sync = si_run_source($pdo, $src, ['kind' => 'manual', 'by' => $me, 'issuer' => $newId]);
        if ($sync['status'] === 'ok') {
            log_activity($pdo, 'issuer.edgar_sync', 'issuer', $newId, ['issuer_id' => $newId, 'after' => ['pull_id' => $sync['pull_id'], 'filled' => $sync['detail'][$newId]['changed'] ?? []]]);
        } else {
            $notice .= '_unsynced';
        }
    }
}
si_done(($id === null ? 'Added the issuer ' : 'Saved the issuer ') . ($f['name'] ?? $current['name']), $newId, si_land('/issuers/' . $newId, $notice), 'issuerChanged',
    ['edgar' => $sync === null ? null : ['status' => $sync['status'], 'error' => $sync['error']]]);
