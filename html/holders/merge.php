<?php
declare(strict_types=1);
/**
 * GET /holders/{id}/merge?into= — the two-column compare page (screen `holder-merge`; holders.merge): this holder and the one the picker chose, every
 * field, the accounts, the positions by date; the survivor by a radio. POST — action `holders_merge` (log `holder.merge`; holders.merge; an
 * agent's pauses — deletion): `survivor`, `loser`. The database refuses a person into an entity and across issuers, in its own sentence.
 */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    si_handler_begin();
    require_right('holders.merge');
    $pdo = db();
    $errors = [];
    $survivor = request_integer('survivor');
    $loser = request_integer('loser');
    $s = $survivor === null ? null : find_holder($pdo, $survivor);
    $l = $loser === null ? null : find_holder($pdo, $loser);
    if ($s === null) { $errors['survivor'] = 'Choose the holder that stays.'; }
    if ($l === null) { $errors['loser'] = 'Choose the holder merged into it.'; }
    if ($errors !== []) { si_refuse_fields($errors); }
    si_guard($pdo, static function () use ($pdo, $survivor, $loser): void {
        try {
            $pdo->beginTransaction();
            holders_merge($pdo, (int) $survivor, (int) $loser);
            $pdo->commit();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23514') {
                throw new DomainException(db_message($e, 'Those holders are not merged.'));
            }
            throw $e;
        }
    });
    si_done('Merged the holders', (int) $survivor, si_land('/holders/' . (int) $survivor, 'merged'), 'holderChanged', ['loser' => (int) $loser]);
}
require_right('holders.merge');
require_human();
$pdo = db();
$h = holder_from_request($pdo, 'id');
$into = request_integer('into');
$cmp = $into !== null && $into !== (int) $h['holder_id'] ? merge_compare($pdo, (int) $h['holder_id'], $into) : null;
if ($cmp !== null && $cmp['b'] === null) { $cmp = null; }
log_screen_view($pdo, 'holder-merge');
if (wants_json()) {
    respond_screen(['holder' => present_holder($h), 'into' => $cmp === null ? null : present_holder($cmp['b']),
        'accounts' => $cmp === null ? null : ['a' => array_map('present_holder_account', $cmp['a_accounts']), 'b' => array_map('present_holder_account', $cmp['b_accounts'])]]);
}
render_screen('Merge holders', view('holders/merge.php', ['h' => $h, 'cmp' => $cmp, 'into' => $into]), ['activeNav' => 'holders', 'screen' => 'holder-merge', 'entity' => 'holder', 'recordId' => (string) $h['holder_id']]);
