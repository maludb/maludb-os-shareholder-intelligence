<?php
declare(strict_types=1);
/**
 * Action `key_rotate` (log `key.rotate`; tokens.own; PEOPLE): **api_key** — a live key of the caller's own. A new key for the same person, label and limits is shown ONCE; the old one keeps working for the overlap
 * (24 hours by default) and then stops, so a script can be moved over without a gap. Through the kernel's relay (an action token) nothing is rotated: a model must never hold a key. record_id = the new key.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
si_handler_begin();
require_human();
require_right('tokens.own');
$pdo = db();
if (is_action_authed()) {
    refuse(422, 'A key is shown once, to you, on the Tokens screen: open Tokens and rotate it there.');
}
$id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id') ?? si_refuse_fields(['api_key' => 'Name the key.']);
$k = find_key($pdo, $id) ?? refuse(404, 'Key not found.');
if ((int) $k['member_id'] !== (int) current_member_id()) {
    refuse(403, 'Only a key\'s own person rotates it.');
}
if ($k['revoked_at'] !== null || ($k['expires_at'] !== null && strtotime((string) $k['expires_at']) <= time())) {
    refuse(422, 'The key has stopped working (revoked, or its overlap is over): mint a new one.');
}
$minted = si_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $m = key_rotate($pdo, $id);
    $pdo->commit();
    return $m;
});
$did = 'Rotated the key "' . $k['label'] . '" — the new key is shown once; the old one works for ' . (int) one_value($pdo, 'SELECT key_rotation_overlap_hours FROM si_settings WHERE id = 1') . ' hours more';
emit_action_status(true, ['did' => $did, 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => $did, 'id' => $minted['id'], 'record_id' => $minted['id'], 'rotated_from' => $id, 'key' => $minted['raw'], 'location' => '/settings/tokens/#key-row-' . $minted['id']]);
}
$_SESSION['minted_token'] = ['raw' => $minted['raw'], 'label' => (string) $k['label'], 'scope' => 'api', 'id' => $minted['id']];
saved_go('/settings/tokens/', 'tokenChanged');
