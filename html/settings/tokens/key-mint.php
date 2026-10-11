<?php
declare(strict_types=1);
/**
 * Action `key_mint` (log `key.mint`: the label, never the key; tokens.own; PEOPLE; an agent's would pause — other): **label** (what will use the key). A key acts as its person, sees what they see, is limited
 * to the settings' calls a minute and a day, and is shown ONCE — here, to the person at the screen. `member` (tokens.manage only) mints one for another person. Through the kernel's relay
 * (an action token) the key is not minted: a model must never hold it. record_id = the key.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
si_handler_begin();
require_human();
require_right('tokens.own');
$pdo = db();
if (is_action_authed()) {
    refuse(422, 'A key is shown once, to you, on the Tokens screen: open Tokens and mint it there.');
}
$me = (int) current_member_id();
$label = (string) (req_val('label') ?? '');
if ($label === '' || mb_strlen($label) > 80) {
    si_refuse_fields(['label' => $label === '' ? 'Say what will use the key (such as "the transfer agent\'s nightly drop").' : 'The label is up to 80 characters.']);
}
$for = request_integer('member') ?? $me;
if ($for !== $me) {
    require_right('tokens.manage');
}
$minted = si_guard($pdo, static function () use ($pdo, $for, $label): array {
    $pdo->beginTransaction();
    $m = key_mint($pdo, $for, $label);
    $pdo->commit();
    return $m;
});
$did = 'Minted the key "' . $label . '" — shown once';
emit_action_status(true, ['did' => $did, 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => $did, 'id' => $minted['id'], 'record_id' => $minted['id'], 'key' => $minted['raw'], 'location' => '/settings/tokens/#key-row-' . $minted['id']]);   // the value, once
}
$_SESSION['minted_token'] = ['raw' => $minted['raw'], 'label' => $label, 'scope' => 'api', 'id' => $minted['id']];
saved_go('/settings/tokens/', 'tokenChanged');
