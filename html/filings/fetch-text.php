<?php
declare(strict_types=1);
/** Action `filing_fetch_text` (log `filing.fetch_text` — the characters, never the text; events.write): `filing` — the primary document fetched under the EDGAR source's policy and its text kept. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
si_handler_begin();
require_right('events.write');
$pdo = db();
$f = filing_from_request($pdo);
$r = si_guard($pdo, static fn (): array => filing_fetch_text($pdo, (int) $f['filing_id']));
si_done('Read the text: ' . number_format((int) $r['chars']) . ' characters', (int) $f['filing_id'], si_land('/filings/' . (int) $f['filing_id'], 'fetched'), 'filingChanged', ['chars' => (int) $r['chars']]);
