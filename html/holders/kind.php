<?php
declare(strict_types=1);
/** Action `holder_kind_set` (log `holder.kind_set`; matches.decide; PEOPLE): `holder`, `kind` (person, entity, custodian_account, unknown), `entity_type`. Any open kind proposal is closed. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$h = holder_from_request($pdo);
$kind = (string) req_val('kind');
$type = (string) (req_val('entity_type') ?? '');
$errors = [];
if (!isset(HOLDER_KINDS[$kind])) { $errors['kind'] = 'The kind is person, entity, custodian account or unknown.'; }
if ($type !== '' && !in_array($type, HOLDER_ENTITY_TYPES, true)) { $errors['entity_type'] = 'The entity type is one of ' . implode(', ', HOLDER_ENTITY_TYPES) . '.'; }
if ($errors !== []) { si_refuse_fields($errors); }
si_guard($pdo, static function () use ($pdo, $h, $kind, $type): void {
    $pdo->beginTransaction();
    holder_kind_set($pdo, (int) $h['holder_id'], $kind, $type === '' ? null : $type);
    $pdo->commit();
});
si_done('Set the kind: ' . strtolower(HOLDER_KINDS[$kind]), (int) $h['holder_id'], si_land(return_path('/holders/' . (int) $h['holder_id']), 'kind_set'), 'holderChanged');
