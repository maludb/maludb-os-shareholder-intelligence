<?php
declare(strict_types=1);
/** Action `key_revoke` (log `key.revoke`: the label, never the key; confirm; a key's own person, or tokens.manage; PEOPLE): **api_key**. At once, whatever overlap it had. A holder of tokens.manage revokes anyone's; anyone else only their own — whatever role: a leaked key is killed by the person it acts as. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
si_handler_begin();
require_human();
$pdo = db();
$id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id') ?? si_refuse_fields(['api_key' => 'Name the key.']);
$k = find_key($pdo, $id) ?? refuse(404, 'Key not found.');
if ((int) $k['member_id'] !== (int) current_member_id() && !has_right('tokens.manage')) {
    refuse(404, 'Key not found.');
}
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    key_revoke($pdo, $id);
    $pdo->commit();
});
si_done('Revoked the key "' . $k['label'] . '"', $id, si_land(return_path('/settings/tokens/'), 'key_revoked'), 'tokenChanged');
