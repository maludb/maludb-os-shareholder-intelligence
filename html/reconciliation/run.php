<?php
declare(strict_types=1);
/** Action `reconciliation_run` (log `reconciliation.run`|`.tie`|`.untie`; uploads.write): run the reconciliation for a security as of a date (today when omitted), with a note. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
si_handler_begin();
require_right('uploads.write');
$pdo = db();
$sec = find_security($pdo, (int) (request_integer('security') ?? 0));
if ($sec === null) {
    refuse(404, 'Not found.');
}
$asOf = request_date('as_of');
if ($asOf === false) {
    si_refuse_fields(['as_of' => 'A date such as 2026-09-30.']);
}
$note = req_val('note');
if ($note !== null && mb_strlen($note) > 500) {
    si_refuse_fields(['note' => 'A note is up to 500 characters.']);
}
$rid = reconciliation_after($pdo, (int) $sec['security_id'], $asOf ?? date('Y-m-d'), $note === '' ? null : $note);
$state = reconciliation_state($pdo->query('SELECT * FROM share_reconciliations WHERE id = ' . $rid)->fetch());
si_done(match ($state) { 'tied' => 'It ties', 'untied' => 'It does not tie: the differences are named', default => 'Not enough to compare yet' }, $rid,
    si_land(return_path('/reconciliation?security=' . (int) $sec['security_id']), 'ran_' . $state), 'reconciliationChanged', ['state' => $state]);
