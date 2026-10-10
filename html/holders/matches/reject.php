<?php
declare(strict_types=1);
/** Action `match_reject` (log `match.reject`; matches.decide; PEOPLE): `proposal` — remembered: the matcher never proposes that pair again. */
require_once dirname(__DIR__, 3) . '/app/features/holders/screen.php';
si_handler_begin();
require_human();
require_right('matches.decide');
$pdo = db();
$p = request_integer('proposal') ?? si_refuse_fields(['proposal' => 'Name the proposal.']);
si_guard($pdo, static function () use ($pdo, $p): void {
    $pdo->beginTransaction();
    match_reject($pdo, (int) $p);
    $pdo->commit();
});
si_done('Rejected the match (remembered)', (int) $p, si_land(return_path('/holders/unmatched'), 'rejected'), 'matchDecided');
