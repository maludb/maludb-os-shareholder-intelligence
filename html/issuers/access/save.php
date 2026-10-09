<?php
declare(strict_types=1);
/**
 * Action `restriction_save` (log `restriction.save`; restrictions.manage; confirm; an agent's pauses — other): the issuer's restriction list,
 * WHOLE — `members[]` the people (and agents) who may see the issuer besides the managers and the admin; an empty list lifts the restriction.
 * The log carries ids and counts, never names.
 */
require_once dirname(__DIR__, 3) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('restrictions.manage');
$pdo = db();
$i = issuer_from_request($pdo);
$list = request_list('members') ?? [];
$ids = [];
foreach ($list as $m) {
    if (!ctype_digit($m)) {
        si_refuse_fields(['members' => 'Members are named by their ids.']);
    }
    $ids[] = (int) $m;
}
$ids = array_values(array_unique($ids));
if ($ids !== [] && (int) one_value($pdo, 'SELECT count(*) FROM members WHERE id = ANY (CAST(:m AS bigint[])) AND status = \'active\' AND capability IS NOT NULL', ['m' => '{' . implode(',', $ids) . '}']) !== count($ids)) {
    si_refuse_fields(['members' => 'Choose people admitted to Shareholder Intelligence.']);
}
$r = si_guard($pdo, static function () use ($pdo, $i, $ids): array {
    $pdo->beginTransaction();
    $r = restriction_save($pdo, (int) $i['id'], $ids, (int) current_member_id());
    log_activity($pdo, 'restriction.save', 'issuer', (int) $i['id'], ['issuer_id' => (int) $i['id'], 'after' => ['members' => count($ids), 'added' => $r['added'], 'removed' => $r['removed'], 'lifted' => $ids === []]]);
    $pdo->commit();
    return $r;
});
$did = $ids === [] ? 'Lifted the restriction on ' . $i['name'] . ': everyone admitted may see it' : 'Restricted ' . $i['name'] . ' to ' . count($ids) . ' named member' . (count($ids) === 1 ? '' : 's');
si_done($did, (int) $i['id'], si_land('/issuers/' . (int) $i['id'] . '/access', $ids === [] ? 'lifted' : 'restricted'), 'issuerChanged', ['added' => $r['added'], 'removed' => $r['removed']]);
