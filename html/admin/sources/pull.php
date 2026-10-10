<?php
declare(strict_types=1);
/** Action `fetch_now` (log `source.pull_now`; uploads.write): `source`, `issuer` (an EDGAR pull narrowed to one issuer) — a pull now within the source's policy: a paused source is not pulled; a blocked or backed-off one answers when it may be again. */
require_once dirname(__DIR__, 3) . '/app/features/filings/screen.php';
si_handler_begin();
require_right('uploads.write');
$pdo = db();
$src = request_integer('source') ?? si_refuse_fields(['source' => 'Name the source.']);
$iss = request_integer('issuer');
if ($iss !== null && find_issuer($pdo, $iss) === null) { refuse(404, 'Not found.'); }
$r = si_guard($pdo, static fn (): array => fetch_now($pdo, (int) $src, $iss));
$did = match ($r['status']) { 'ok', 'partial' => 'Pulled: ' . $r['seen'] . ' seen, ' . $r['new'] . ' new, ' . $r['changed'] . ' changed', 'blocked' => 'Refused by the host: ' . (string) $r['error'], default => 'The pull failed: ' . (string) $r['error'] };
si_done($did, $r['pull_id'], si_land(return_path('/filings'), 'pulled'), 'sourceChanged', ['status' => $r['status'], 'seen' => $r['seen'], 'new' => $r['new'], 'changed' => $r['changed']]);
