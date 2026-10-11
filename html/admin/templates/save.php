<?php
declare(strict_types=1);
/**
 * Action `template_save` (log `template.save`; settings.manage): **name**, **kind** (spr, nobo, register, prices_csv, holders_csv or share_range), connector, **mapping** (an object), template (to change an existing one).
 * The transfer agent's extract keeps naming the columns the application never stores. record_id = the template.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('template');
$cur = $id === null ? null : (find_template($pdo, $id) ?? refuse(404, 'Template not found.'));
$errors = [];
$name = (string) (req_val('name') ?? ($cur['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 160) { $errors['name'] = 'Name the template in up to 160 characters.'; }
$kind = (string) (req_val('kind') ?? ($cur['kind'] ?? ''));
if (!isset(TEMPLATE_KINDS[$kind])) { $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(TEMPLATE_KINDS)) . '.'; }
$connector = (string) (req_val('connector') ?? ($cur['connector'] ?? 'generic_mapped'));
if ($connector === '') { $connector = 'generic_mapped'; }
if (!preg_match('~^[a-z][a-z0-9_]{0,59}$~', $connector)) { $errors['connector'] = 'The connector is a lower-case word (letters, digits and underscores).'; }
[$mapping, $jsonError] = request_json_object('mapping');
if ($jsonError !== null) {
    $errors['mapping'] = $jsonError;
} elseif ($mapping === null) {
    if ($cur === null) { $errors['mapping'] = 'Give the mapping as a JSON object.'; } else { $mapping = json_decode((string) $cur['mapping'], true) ?: []; }
}
if ($errors === [] && ($p = template_mapping_problem($mapping, $connector)) !== null) { $errors['mapping'] = $p; }
if ($errors !== []) {
    si_refuse_fields($errors);
}
$tid = si_guard($pdo, static function () use ($pdo, $id, $name, $kind, $connector, $mapping): int {
    $pdo->beginTransaction();
    $tid = template_save($pdo, $id, ['name' => $name, 'kind' => $kind, 'connector' => $connector, 'mapping' => $mapping], (int) current_member_id());
    $pdo->commit();
    return $tid;
});
si_done(($id === null ? 'Added the template ' : 'Saved the template ') . $name, $tid, si_land(return_path('/admin/templates'), 'saved'), 'templateChanged');
