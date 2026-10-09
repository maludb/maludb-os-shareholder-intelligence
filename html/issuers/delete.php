<?php
declare(strict_types=1);
/** Action `issuer_delete` (log `issuer.delete`; issuers.manage; confirm; an agent's pauses — deletion; undo issuer_restore): the issuer to the tombstone with everything under it. */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$i = issuer_from_request($pdo);
$pdo->beginTransaction();
issuer_delete($pdo, (int) $i['id'], false, (int) current_member_id());
log_activity($pdo, 'issuer.delete', 'issuer', (int) $i['id'], ['issuer_id' => (int) $i['id'], 'before' => ['name' => $i['name'], 'ticker' => $i['ticker']]]);
$pdo->commit();
si_done('Moved ' . $i['name'] . ' to the tombstone (restore it from the issuers list)', (int) $i['id'], si_land('/issuers', 'deleted'), 'issuerChanged');
