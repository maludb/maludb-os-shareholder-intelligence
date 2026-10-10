<?php
declare(strict_types=1);
/** Action `filing_event_make` (log `filing.event_make`; events.write — an agent records what a filing says): `filing`, `title` (the form's by default), `severity` (info, watch, high), `body`. One event per filing. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$f = filing_from_request($pdo);
$title = (string) (req_val('title') ?? '') ?: ($f['form'] . ' filed');
$sev = (string) (req_val('severity') ?? 'info') ?: 'info';
$body = req_val('body');
$errors = [];
if (mb_strlen($title) > 300) { $errors['title'] = 'A title is up to 300 characters.'; }
if (!in_array($sev, ['info', 'watch', 'high'], true)) { $errors['severity'] = 'The severity is info, watch or high.'; }
if ($body !== null && mb_strlen($body) > 10000) { $errors['body'] = 'The body is up to 10,000 characters.'; }
if ($errors !== []) { si_refuse_fields($errors); }
$id = si_guard($pdo, static function () use ($pdo, $f, $title, $sev, $body): int {
    $pdo->beginTransaction();
    $id = filing_event_make($pdo, (int) $f['filing_id'], ['title' => $title, 'severity' => $sev, 'body' => $body === '' ? null : $body], (int) current_member_id());
    $pdo->commit();
    return $id;
});
si_done('Made the event', $id, si_land('/filings/' . (int) $f['filing_id'], 'event_made'), 'filingChanged');
