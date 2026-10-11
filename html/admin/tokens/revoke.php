<?php
declare(strict_types=1);
/** Action `token_revoke_any` (log `token.revoke_any`: the label, the scope and the person — never the token; tokens.manage; PEOPLE; confirm): **token** (anyone's, a key or an MCP token). At once. record_id = the token. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_human();
require_right('tokens.manage');
$pdo = db();
$id = request_integer('token') ?? si_refuse_fields(['token' => 'Name the token.']);
$t = find_token_any($pdo, $id) ?? refuse(404, 'Token not found.');
$r = si_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $r = token_revoke_any($pdo, $id);
    $pdo->commit();
    return $r;
});
si_done('Revoked ' . ($t['scope'] === 'api' ? 'the key' : 'the token') . ' "' . $t['label'] . '" of ' . $t['member_name'], $id, si_land(return_path('/admin/tokens'), 'revoked'), 'tokenChanged');
