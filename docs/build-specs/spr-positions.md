# Build spec: uploads and THE SPR (slice 2 — THE FIRST EXEMPLAR)

Builds `position-reports.md`. What exists at the end: the upload pipeline for every kind (this slice wires the SPR's connectors; slice 3 adds the
NOBO, the register and the share range on the same pipeline), the upload page with its tie, the positions table, the movement matrix, the flow by
class with its caveat, the concentration, and the reconciliation page. **Every one of the owner's 70 real SPR files loads and ties.**

Schema (db/007, 012, 014, 017, never modified): `imports`, `spr_rows`, `participant_positions`, `si_spr_load()`, `si_import_usable()`, `si_spr_total()`,
`si_position_dates()`, `si_position_changes()`, `si_participant_flow()`, `si_participants_new_lost()`, `si_concentration()`, `si_participant_series()` (db/007);
`share_reconciliations`, `si_reconcile()`, `si_reconciliation_latest()` (db/012); `attachments`, `si_import_delete()` (db/014); the SPR templates (db/014, 017);
`mcp_imports`, `mcp_participant_positions`, `mcp_share_reconciliations` (db/016).

## Divergences from the design
1. **A file under 5 MB is parsed in the upload's request**; a larger one is queued for the worker and the page polls. 2. **The same file (sha256) for the same
security and kind is refused.** 3. **The CUSIP in the file must be the security's.** 4. **The stored copy of a transfer agent's extract has its personal columns
removed** (built here in `app/si/storage.php`; slice 3 uses it). 5. **The charts are server-rendered SVG** with a table twin (no chart library).

## Who may do what
upload_create / upload_reparse / reconciliation_run: `uploads.write` + `see` (`upload_create` is `people` — a file does not travel over the Actions MCP; the API's
`POST /api/v1/uploads` is a person's key) · upload_override: `issuers.manage` (pauses, `other`) · upload_delete / upload_restore: `uploads.delete` (the delete pauses,
`deletion`) · every screen: `see` (an SPR names brokers only — a Viewer reads it whole).

## Screens
`upload-list`, `upload-add`, `upload-view`, `positions`, `positions-matrix`, `positions-flow`, `positions-concentration`, `reconciliation` — `position-reports.md` §3.

## Files (exactly these)
`position-reports.md` §4, plus `tests/phase3/slice2/{run.sh,lib.php,uploads.php,real_spr.php,positions.php,reconciliation.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_uploads(PDO, int $issuer, array $filters, int $page): array` · `find_upload(PDO, int $id): ?array` · `upload_rows(PDO, int $id, bool $failedOnly, int $page): array` ·
`detect_connector(string $path, string $filename): ?string` · `parse_upload(PDO, int $import): array{tied, tie_note, rows, failed}` · `store_upload(array $file, string $connector): array{path, sha256, original_sha256, bytes}` ·
`positions_on(PDO, int $security, ?string $date, ?string $class, int $top): array` · `position_changes(PDO, int $security, string $from, string $to, ?string $class): array` ·
`movement_matrix(PDO, int $security, string $from, string $to, ?string $class, int $maxRows, int $maxDates): array` · `flow_by_date(PDO, int $security, string $from, string $to): array` ·
`concentration_series(PDO, int $security, string $from, string $to): array` · `reconciliation_page(PDO, int $security): array{latest, history}` · writes: `upload_create(PDO, array $f, array $file, int $by): int` ·
`upload_reparse(PDO, int $id)` · `upload_override(PDO, int $id, string $note, int $by)` · `upload_delete(PDO, int $id, bool $restore)` · `reconciliation_run(PDO, int $security, ?string $asOf, ?string $note): int`

## MCP tools this slice makes real
`find_imports` (the resolver), `get_import`, `import_rows`, `positions_on`, `position_changes`, `participants_new_lost`, `participant_flow`, `concentration`, `participant_series`, `spr_total`,
`reconciliation_latest`, `reconciliation_history`.

## Manifest rows claimed
Screens (8): `upload-list`, `upload-add`, `upload-view`, `positions`, `positions-matrix`, `positions-flow`, `positions-concentration`, `reconciliation`
Actions (6): `upload_create`, `upload_reparse`, `upload_override`, `upload_delete`, `upload_restore`, `reconciliation_run`

## Activity log events
`upload.create|parse|tie|untie|override|reparse|delete|restore` (with `import_id`, `security_id`, `issuer_id`; `{kind, connector, rows, failed, tied, dates}`),
`position.load` (`{positions, participants_new, dates}`), `reconciliation.run|tie|untie` (`{difference_registered, difference_spr}`).

## Status vocabulary
`position-reports.md` §5.

## Out of scope for this slice
The NOBO, register and share-range parsers and the matcher (3); the rules after an upload (7 — the call is wired and answers nothing until slice 7); the
dispatch to the Analyst (9).

## Proof (`tests/phase3/slice2/run.sh`: scratch `si_dev32`; Playwright; the registry `--check`)
`position-reports.md` §6, plus: an Analyst uploads, a Viewer reads the same positions and is refused the upload (403); an agent's `upload_override` answers
`pending_approval`; a deleted upload's positions are gone and come back on restore; 8 screens built, 6 actions, 2 approvals in step.

## Built (2026-10-10) — what the build found and decided within this spec
1. **The owner's folder holds 70 SPR CSVs but 60 distinct files** — ten are the same bytes under two names — and **seven are one-time
   reports**, not one. The same file a second time is refused, naming the upload it already is; the proof uploads all 70 and expects the
   ten refusals. Every one of the 60 ties to its own totals; on each of 339 file-dates the database's DTC total equals the Python oracle's.
2. **Some weeks arrive in more than one file** (an auto-import and a manual download): the one loaded last owns the shared dates and the
   other is marked overlapped (`superseded_by`); a delete reloads what it had overlapped (`upload_reclaim_superseded()`).
3. **The owner's analysis workbook** (a firm per row, no participant numbers) is refused by the detector in words — upload DTC's own report.
4. **A reconciliation with nothing to compare** (an SPR but no Cede figure from a register, a DTC letter or the transfer agent) is
   `incomplete` and said so — "not enough to compare" — on the page and the home's tile, never "does not tie" (the generated `tied` reads
   false there). The rules already ignore it.
5. **The detector reads only the file's head** (60 rows) and a blank CSV row is kept as an empty row: a 5.5 MB report padded with blank lines
   exhausted Apache's 256 MB memory limit when read whole (php -S has none) — found by the Apache run.
6. **An empty control map is a JSON object** (a file with no Total row): `si_spr_load` iterates it.
7. **The rules do not run after an upload yet** — slice 7 wires them (the call point is `upload_ingest`'s end); the dispatch to the Analyst
   is slice 9's. The worker's `uploads` pass tells the uploader of a queued file (`upload_done` / `upload_untied`).
8. **The security on the upload form** is a select of the current issuer's securities (one to four), not the record picker.
9. **On a phone** the position table puts the change under the shares; the matrix is one date at a time; every table fits its card.

Proof: `tests/phase3/slice2/run.sh` (each proof on a fresh scratch database) — the real files 17, the pipeline 48, the positions 28, the real
reconciliation 17 (Cede & Co 3,397,800 on the transfer agent's extract of 2024-06-30 = the SPR total of 2024-06-28, to the share), JSON 7,
browser 34 — under php -S and a real Apache; slice 1, Phase 2, the schema proof 180, the kit 73 and Phase 1's 60 re-run green; the registry
24 screens and 24 actions built; the installer's plan clean.

## Open questions
None.
