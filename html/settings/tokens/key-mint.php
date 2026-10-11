<?php
declare(strict_types=1);
/**
 * Action `key_mint` (log `key.mint`: the label, never the key; tokens.own; PEOPLE; an agent's would pause — other): **label** (what will use the key). A key acts as its person, sees what they see, is limited
 * to the settings' calls a minute and a day, and is shown ONCE — here, to the person at the screen. `member` (tokens.manage only) mints one for a VIEWER — who cannot mint — and for no one else. Through the kernel's relay
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
    // An admin mints for another person ONLY for a Viewer (who holds no tokens.own and so cannot mint): a Viewer's key reads and never writes, so
    // the admin never holds a key that acts in another person's name (the planning model's restriction at merge, 2026-10-11).
    require_right('tokens.manage');
    if (!db_bool($pdo, 'SELECT si_member_is_viewer(:m)', ['m' => $for])) {
        refuse(403, 'A key acts as its person: an Analyst or a manager mints their own. An admin mints one for a Viewer only.');
    }
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
