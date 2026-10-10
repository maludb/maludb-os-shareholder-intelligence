<?php
declare(strict_types=1);
/** Action `match_new_holder` (log `match.new_holder`; matches.decide; PEOPLE): `row_kind` (nobo or register), `row` — a new holder whatever the candidates (their proposals rejected and remembered). */
require_once dirname(__DIR__, 3) . '/app/features/holders/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$kind = (string) req_val('row_kind');
$row = request_integer('row');
$errors = [];
if (!in_array($kind, ['nobo', 'register'], true)) { $errors['row_kind'] = 'The row is a nobo row or a register row.'; }
if ($row === null) { $errors['row'] = 'Name the row.'; }
if ($errors !== []) { si_refuse_fields($errors); }
$hid = si_guard($pdo, static function () use ($pdo, $kind, $row): int {
    $pdo->beginTransaction();
    $h = match_new_holder($pdo, $kind, (int) $row);
    $pdo->commit();
    return $h;
});
si_done('Made a new holder from the row', $hid, si_land(return_path('/holders/unmatched'), 'new_holder'), 'matchDecided', ['holder_id' => $hid]);
