<?php
declare(strict_types=1);
/**
 * Action `security_save` (log `security.save`; issuers.manage; an agent's pauses — other): add a security to an issuer (`issuer` + name …) or
 * change one (`security` + the fields to change). Making one primary steps the issuer's other primary down in the same transaction.
 */
require_once dirname(__DIR__, 3) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$secId = request_integer('security');
$current = null;
if ($secId !== null) {
    $current = find_security($pdo, $secId);
    if ($current === null) {
        refuse(404, 'Not found.');
    }
    $issuer = find_issuer($pdo, (int) $current['issuer_id']) ?? refuse(404, 'Not found.');
} else {
    $issuer = issuer_from_request($pdo);
}
$iid = (int) $issuer['id'];
$errors = [];
$f = security_fields_from_request($errors, $secId === null);
if ($errors !== []) {
    si_refuse_fields($errors);
}
if ($secId === null && !array_key_exists('is_primary', $f) && find_securities($pdo, $iid) === []) {
    $f['is_primary'] = true;                                                 // an issuer's first security is its primary
}
$before = $current === null ? [] : array_intersect_key(array_merge($current, ['is_primary' => (bool) $current['is_primary']]), $f);
$diff = si_diff($before, $f);
$newId = si_guard($pdo, static function () use ($pdo, $secId, $iid, $f, $diff): int {
    $pdo->beginTransaction();
    try {
        $newId = security_save($pdo, $secId, $iid, $f);
        if ($diff['after'] !== [] || $secId === null) {
            log_activity($pdo, 'security.save', 'security', $newId, ['issuer_id' => $iid, 'security_id' => $newId, 'before' => $secId === null ? null : $diff['before'], 'after' => $diff['after'] + ($secId === null ? ['made' => true] : [])]);
        }
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
si_done(($secId === null ? 'Added the security ' : 'Saved the security ') . ($f['name'] ?? $current['name']), $newId, si_land(return_path('/issuers/' . $iid . '/securities'), $secId === null ? 'security_added' : 'security_saved', 'security-' . $newId), 'securityChanged');
