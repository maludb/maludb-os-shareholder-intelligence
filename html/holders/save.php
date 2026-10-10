<?php
declare(strict_types=1);
/** Action `holder_update` (log `holder.update` — the field names, never a value; matches.decide; PEOPLE): any of display_name, kind, entity_type, address_line, city, state, country, zip, notes, is_insider, institution. A field left out stays as it was. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$h = holder_from_request($pdo);
$errors = [];
$f = [];
foreach (['display_name' => 200, 'address_line' => 300, 'city' => 120, 'state' => 40, 'country' => 60, 'zip' => 20, 'notes' => 4000] as $k => $max) {
    if (req_has($k)) {
        $v = (string) req_val($k);
        if (mb_strlen($v) > $max) { $errors[$k] = ucfirst(str_replace('_', ' ', $k)) . ' is up to ' . $max . ' characters.'; continue; }
        $f[$k] = $v === '' ? null : $v;
    }
}
if (array_key_exists('display_name', $f) && $f['display_name'] === null) {
    $errors['display_name'] = 'A holder has a name.';
}
if (req_has('kind')) {
    $k = (string) req_val('kind');
    isset(HOLDER_KINDS[$k]) ? $f['kind'] = $k : $errors['kind'] = 'The kind is person, entity, custodian account or unknown.';
}
if (req_has('entity_type')) {
    $t = (string) req_val('entity_type');
    ($t === '' || in_array($t, HOLDER_ENTITY_TYPES, true)) ? $f['entity_type'] = ($t === '' ? null : $t) : $errors['entity_type'] = 'The entity type is one of ' . implode(', ', HOLDER_ENTITY_TYPES) . '.';
}
if (($f['kind'] ?? $h['kind']) !== 'entity' && array_key_exists('kind', $f)) {
    $f['entity_type'] = null;
}
if (req_has('is_insider')) {
    $f['is_insider'] = si_yes('is_insider');
}
if (req_has('institution')) {
    $f['institution_id'] = si_ref($pdo, 'institution', null, 'SELECT 1 FROM mcp_institutions WHERE institution_id = :id', 'the institution', $errors);
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$changed = si_guard($pdo, static function () use ($pdo, $h, $f): array {
    $pdo->beginTransaction();
    $c = holder_update($pdo, (int) $h['holder_id'], $f);
    $pdo->commit();
    return $c;
});
si_done($changed === [] ? 'Nothing changed' : 'Saved the holder', (int) $h['holder_id'], si_land(return_path('/holders/' . (int) $h['holder_id']), 'updated'), 'holderChanged', ['fields_changed' => $changed]);
