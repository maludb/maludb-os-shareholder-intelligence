<?php
declare(strict_types=1);
/** Action `issuer_restore` (log `issuer.restore`; issuers.manage): bring a tombstoned issuer back with everything under it. */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$id = request_integer('issuer') ?? request_integer('id');
$st = $pdo->prepare('SELECT id, name FROM issuers WHERE id = :id AND deleted_at IS NOT NULL');
$st->execute(['id' => (int) $id]);
$i = $id === null ? false : $st->fetch();
if ($i === false) {
    refuse(404, 'Not found.');
}
$pdo->beginTransaction();
issuer_delete($pdo, (int) $i['id'], true, (int) current_member_id());
log_activity($pdo, 'issuer.restore', 'issuer', (int) $i['id'], ['issuer_id' => (int) $i['id'], 'after' => ['name' => $i['name']]]);
$pdo->commit();
si_done('Restored ' . $i['name'], (int) $i['id'], si_land('/issuers/' . (int) $i['id'], 'restored'), 'issuerChanged');
