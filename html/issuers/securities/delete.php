<?php
declare(strict_types=1);
/** Action `security_delete` (log `security.delete`; issuers.manage; confirm; an agent's pauses — deletion): remove a security with its positions, counts and series. */
require_once dirname(__DIR__, 3) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$s = find_security($pdo, (int) (request_integer('security') ?? 0));
if ($s === null) {
    refuse(404, 'Not found.');
}
$positions = (int) one_value($pdo, 'SELECT count(*) FROM participant_positions WHERE security_id = :s', ['s' => (int) $s['security_id']]);
$pdo->beginTransaction();
security_delete($pdo, (int) $s['security_id']);
log_activity($pdo, 'security.delete', 'security', (int) $s['security_id'], ['issuer_id' => (int) $s['issuer_id'], 'security_id' => (int) $s['security_id'],
    'before' => ['name' => $s['name'], 'cusip' => $s['cusip'], 'positions' => $positions]]);
$pdo->commit();
si_done('Removed the security ' . $s['name'], (int) $s['security_id'], si_land('/issuers/' . (int) $s['issuer_id'] . '/securities', 'security_deleted'), 'securityChanged');
