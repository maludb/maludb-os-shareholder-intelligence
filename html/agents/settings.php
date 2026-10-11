<?php
declare(strict_types=1);
/**
 * Action `agents_settings_save` (log `agents_settings.save` with what changed; agents.settings; PEOPLE; an agent's would pause — other): morning_note_enabled (yes or no), morning_note_hour (0 to 23, market time),
 * analyst_proposals (yes or no), pack_day (1 Monday to 7 Sunday), analyst_issuers[] (the issuers the Analyst covers — empty for every issuer), escalate_high_to (a person told of every high signal — empty for none).
 * A field left out stays as it was. record_id = 1, the settings.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/write.php';
si_handler_begin();
require_human();
require_right('agents.settings');
$pdo = db();
$errors = [];
$f = [];
if (req_has('morning_note_enabled')) { $f['morning_note_enabled'] = si_yes('morning_note_enabled'); }
if (req_has('analyst_proposals')) { $f['analyst_proposals'] = si_yes('analyst_proposals'); }
if (req_has('morning_note_hour')) { $f['morning_note_hour'] = si_int('morning_note_hour', null, 0, 23, 'The morning note\'s hour', $errors); }
if (req_has('pack_day')) { $f['pack_day'] = si_int('pack_day', null, 1, 7, 'The pack\'s day', $errors); }
if (req_has('analyst_issuers')) {
    $ids = request_list('analyst_issuers') ?? [];
    foreach ($ids as $x) {
        if (!ctype_digit($x)) { $errors['analyst_issuers'] = 'Choose the issuers from the list.'; }
    }
    $f['analyst_issuers'] = array_map('intval', $ids);
}
if (req_has('escalate_high_to')) {
    $f['escalate_high_to'] = si_ref($pdo, 'escalate_high_to', null, "SELECT 1 FROM mcp_members WHERE member_id = :id AND member_kind = 'human'", 'the person to tell', $errors) ?? 0;
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$r = si_guard($pdo, static function () use ($pdo, $f): array {
    $pdo->beginTransaction();
    $r = agents_settings_save($pdo, $f);
    $pdo->commit();
    return $r;
});
si_done($r['after'] === [] ? 'Nothing changed in the agents\' settings' : 'Saved the agents\' settings (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($r['after']))) . ')', 1,
    si_land(return_path('/admin/agents'), 'saved', 'agents-settings'), 'agentChanged', ['changed' => array_keys($r['after'])]);
