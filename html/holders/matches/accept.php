<?php
declare(strict_types=1);
/** Action `match_accept` (log `match.accept`; matches.decide; PEOPLE): `proposal` — the row joins the candidate; the row's other proposals are closed. */
require_once dirname(__DIR__, 3) . '/app/features/holders/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$p = request_integer('proposal') ?? si_refuse_fields(['proposal' => 'Name the proposal.']);
$hid = si_guard($pdo, static function () use ($pdo, $p): int {
    $pdo->beginTransaction();
    $h = match_accept($pdo, (int) $p);
    $pdo->commit();
    return $h;
});
si_done('Matched the row to the holder', $hid, si_land(return_path('/holders/unmatched'), 'accepted'), 'matchDecided', ['holder_id' => $hid]);
