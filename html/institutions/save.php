<?php
declare(strict_types=1);
/** Action `institution_update` (log `institution.update` — the field names; events.write): `institution`, kind (manual from then on), is_activist (yes/no), website, notes, tags[]. A field left out stays. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$i = find_institution($pdo, (int) (request_integer('institution') ?? 0), null) ?? refuse(404, 'Not found.');
$errors = [];
$f = [];
if (req_has('kind')) { $k = (string) req_val('kind'); isset(INSTITUTION_KINDS[$k]) ? $f['kind'] = $k : $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(INSTITUTION_KINDS)) . '.'; }
if (req_has('is_activist')) { $f['is_activist'] = si_yes('is_activist'); }
if (req_has('website')) { $w = (string) req_val('website'); ($w === '' || (filter_var($w, FILTER_VALIDATE_URL) && preg_match('~^https?://~', $w) && mb_strlen($w) <= 300)) ? $f['website'] = ($w === '' ? null : $w) : $errors['website'] = 'A website is an http(s) address.'; }
if (req_has('notes')) { $n = (string) req_val('notes'); mb_strlen($n) <= 4000 ? $f['notes'] = ($n === '' ? null : $n) : $errors['notes'] = 'Notes are up to 4,000 characters.'; }
if ($errors !== []) { si_refuse_fields($errors); }
$tags = request_list('tags');
$changed = si_guard($pdo, static function () use ($pdo, $i, $f, $tags): array {
    $pdo->beginTransaction();
    $c = institution_update($pdo, (int) $i['institution_id'], $f);
    if ($tags !== null) { tags_set($pdo, 'institution', (int) $i['institution_id'], $tags, (int) current_member_id()); }
    $pdo->commit();
    return $c;
});
si_done($changed === [] ? 'Nothing changed' : 'Saved the institution', (int) $i['institution_id'], si_land('/institutions/' . (int) $i['institution_id'], 'updated'), 'institutionChanged', ['fields_changed' => $changed]);
