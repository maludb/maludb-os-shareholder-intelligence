<?php
declare(strict_types=1);
/**
 * Action `holder_kind_set` (log `holder.kind_set`; matches.decide; PEOPLE): `holder`, `kind` (person, entity, custodian_account, unknown), `entity_type`, `proposal` (the open kind proposal this decides). Setting the kind an agent proposed accepts the proposal;
 * any other open proposal is closed. With `decision=reject` and the `proposal`, the proposed kind is refused (log `holder.kind_reject`, remembered) and the holder is not changed.
 */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$h = holder_from_request($pdo);
$proposal = request_integer('proposal');
if ((string) (req_val('decision') ?? '') === 'reject') {
    if ($proposal === null) { si_refuse_fields(['proposal' => 'Name the proposal to reject.']); }
    si_guard($pdo, static function () use ($pdo, $h, $proposal): void {
        $pdo->beginTransaction();
        if (holder_kind_reject($pdo, (int) $proposal) !== (int) $h['holder_id']) { throw new DomainException('That proposal is not this holder\'s.'); }
        $pdo->commit();
    });
    si_done('Rejected the proposed kind', (int) $h['holder_id'], si_land(return_path('/holders/' . (int) $h['holder_id']), 'kind_rejected'), 'holderChanged');
}
$kind = (string) req_val('kind');
$type = (string) (req_val('entity_type') ?? '');
$errors = [];
if (!isset(HOLDER_KINDS[$kind])) { $errors['kind'] = 'The kind is person, entity, custodian account or unknown.'; }
if ($type !== '' && !in_array($type, HOLDER_ENTITY_TYPES, true)) { $errors['entity_type'] = 'The entity type is one of ' . implode(', ', HOLDER_ENTITY_TYPES) . '.'; }
if ($errors !== []) { si_refuse_fields($errors); }
si_guard($pdo, static function () use ($pdo, $h, $kind, $type, $proposal): void {
    $pdo->beginTransaction();
    holder_kind_set($pdo, (int) $h['holder_id'], $kind, $type === '' ? null : $type, $proposal);
    $pdo->commit();
});
si_done('Set the kind: ' . strtolower(HOLDER_KINDS[$kind]), (int) $h['holder_id'], si_land(return_path('/holders/' . (int) $h['holder_id']), 'kind_set'), 'holderChanged');
