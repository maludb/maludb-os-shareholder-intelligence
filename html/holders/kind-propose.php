<?php
declare(strict_types=1);
/** Action `holder_kind_propose` (log `holder.kind_propose`; pii — an agent holding Analyst): `holder`, `kind` (person, entity, custodian_account), `entity_type`, `evidence` (one sentence). A person decides it on the holder's page. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
si_handler_begin();
require_right('holders.read');
$pdo = db();
$h = holder_from_request($pdo);
$kind = (string) req_val('kind');
$type = (string) (req_val('entity_type') ?? '');
$ev = (string) (req_val('evidence') ?? '');
$errors = [];
if (!in_array($kind, ['person', 'entity', 'custodian_account'], true)) { $errors['kind'] = 'Propose person, entity or custodian account.'; }
if ($type !== '' && !in_array($type, HOLDER_ENTITY_TYPES, true)) { $errors['entity_type'] = 'The entity type is one of ' . implode(', ', HOLDER_ENTITY_TYPES) . '.'; }
if (mb_strlen($ev) > 500) { $errors['evidence'] = 'The evidence is one sentence (up to 500 characters).'; }
if ($errors !== []) { si_refuse_fields($errors); }
$id = si_guard($pdo, static function () use ($pdo, $h, $kind, $type, $ev): int {
    $pdo->beginTransaction();
    $id = holder_kind_propose($pdo, (int) $h['holder_id'], $kind, $type === '' ? null : $type, $ev === '' ? null : $ev);
    $pdo->commit();
    return $id;
});
si_done('Proposed the kind: ' . strtolower(HOLDER_KINDS[$kind]), (int) $h['holder_id'], si_land('/holders/' . (int) $h['holder_id'], 'kind_proposed'), 'holderChanged', ['proposal_id' => $id]);
