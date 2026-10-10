<?php
declare(strict_types=1);
/** Action `holder_match_propose` (log `match.propose`; matches.decide or pii — the Analyst agent): `row_kind`, `row`, `holder` (the candidate), `evidence` (one sentence). A person accepts or rejects it in the queue. */
require_once dirname(__DIR__, 3) . '/app/features/holders/screen.php';
si_handler_begin();
require_any_right('matches.decide|holders.read');
$pdo = db();
$kind = (string) req_val('row_kind');
$row = request_integer('row');
$holder = request_integer('holder');
$ev = (string) (req_val('evidence') ?? '');
$errors = [];
if (!in_array($kind, ['nobo', 'register'], true)) { $errors['row_kind'] = 'The row is a nobo row or a register row.'; }
if ($row === null) { $errors['row'] = 'Name the row.'; }
if ($holder === null) { $errors['holder'] = 'Name the candidate holder.'; }
if ($ev === '' || mb_strlen($ev) > 500) { $errors['evidence'] = $ev === '' ? 'Say why, in one sentence.' : 'The evidence is one sentence (up to 500 characters).'; }
if ($errors !== []) { si_refuse_fields($errors); }
$id = si_guard($pdo, static function () use ($pdo, $kind, $row, $holder, $ev): int {
    $pdo->beginTransaction();
    $id = holder_match_propose($pdo, $kind, (int) $row, (int) $holder, $ev);
    $pdo->commit();
    return $id;
});
si_done('Proposed the match', $id, si_land('/holders/unmatched', 'proposed'), 'matchDecided', ['proposal_id' => $id]);
