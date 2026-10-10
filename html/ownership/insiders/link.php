<?php
declare(strict_types=1);
/** Action `insider_holder_link` (log `insider.holder_link` — ids only; matches.decide; PEOPLE): `reporting_owner_cik`, `holder` — the insider's rows linked to the register's holder (the trigger's proposal confirmed or corrected). */
require_once dirname(__DIR__, 3) . '/app/features/ownership/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$cik = (string) req_val('reporting_owner_cik');
$holder = request_integer('holder');
$errors = [];
if (!preg_match('~^\d{10}$~', $cik)) { $errors['reporting_owner_cik'] = 'Name the insider by the ten digits of their CIK.'; }
if ($holder === null) { $errors['holder'] = 'Choose the register\'s holder.'; }
if ($errors !== []) { si_refuse_fields($errors); }
$h = si_guard($pdo, static function () use ($pdo, $cik, $holder): int {
    $pdo->beginTransaction();
    $h = insider_holder_link($pdo, $cik, (int) $holder, (int) current_member_id());
    $pdo->commit();
    return $h;
});
si_done('Linked the insider to the register\'s holder', $h, si_land(return_path('/ownership/insiders'), 'linked'), 'insiderChanged');
