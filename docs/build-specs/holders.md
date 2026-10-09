# Build spec: NOBO, the register and HOLDERS (slice 3)

Builds `holder-identity.md`. What exists at the end: the NOBO list (both of Broadridge's layouts), the transfer agent's register (the owner's agent's
extract and STC's position detail) and Broadridge's Share Range Analysis upload on slice 2's pipeline and tie; the matcher runs after each and the
unmatched queue lets an Analyst finish it; holders have pages, a merge, kinds; the aggregate screens serve a Viewer; the reconciliation's register half
lights up. **The PII wall holds on every screen, export and log line.**

Schema (db/007, 008, 017, 018, never modified): `nobo_jobs`, `nobo_rows`, `nobo_broker_rows`, `register_rows`, `si_obo_remainder()` (db/007); `holders`,
`holder_accounts`, `holder_positions`, `holder_match_proposals`, the normaliser, `si_match_row()`, `si_match_import()`, `si_match_decide()`, `si_match_new_holder()`,
`si_holders_merge()`, the `si_holders_by_*` and `si_holders_changes()` functions (db/008); `share_range_reports`, `share_range_rows`, `si_share_ranges()`,
`si_obo_totals()` (db/017); `holder_kind_proposals`, `si_holder_kind_propose()`, `si_holder_kind_decide()`, `si_holders_changes_summary()` (db/018);
`mcp_holders`, `mcp_holder_accounts`, `mcp_holder_positions`, `mcp_holder_match_proposals`, `mcp_nobo_rows`, `mcp_register_rows`, `mcp_nobo_jobs`,
`mcp_nobo_broker_rows`, `mcp_share_range_reports`, `mcp_share_range_rows`, `mcp_holder_kind_proposals` (db/016–018).

## Divergences from the design
1. **The names export (first/middle/last) carries no CLIENTS section**: the OBO remainder for its record date is not computed (the page says so).
2. **`holder_kind_propose` lands in `holder_kind_proposals`** (db/018), decided on the holder's page and the proposals screen. 3. **A holders export is
gated by `si_settings.pii_in_exports`** (the owner's answer (d): Analyst and above, logged with its count) — the export itself is slice 8's handler; this slice
builds the gate function `pii_export_allowed()`.

## Who may do what
match_accept / match_reject / match_new_holder / holder_kind_set / holder_update: `matches.decide` (`people`) · holder_match_propose: `matches.decide` or `pii`
(an agent holding Analyst) · holder_kind_propose: `pii` · holders_merge / holder_delete / holder_restore: `holders.merge` (merge and delete pause for an agent,
`deletion`) · matcher_run: `uploads.write` · the holder screens: `see`, a person's details `pii`; the queue and the merge page `pii`.

## Screens
`holder-list`, `holder-view`, `holder-edit`, `holder-merge`, `holders-unmatched`, `holders-changes`, `holders-ranges`, `holders-geography`, `holders-custodians`,
`holders-register` — `holder-identity.md` §2–§4; `holders-ranges` shows the list's ranges and, when a Share Range Analysis is loaded for the date, Broadridge's all/OBO/NOBO
ranges beside them with the OBO share of accounts.

## Files (exactly these)
`holder-identity.md` §5, plus `tests/phase3/slice3/{run.sh,lib.php,nobo.php,register.php,share_range.php,real_layouts.php,queue.php,merge.php,pii.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_holders(PDO, int $issuer, array $filters, int $page): array` · `find_holder(PDO, int $id): ?array` · `holder_accounts(PDO, int $id): array` · `holder_history_points(PDO, int $id): array` ·
`unmatched_queue(PDO, int $issuer, ?int $import, int $page): array` · `merge_compare(PDO, int $a, int $b): array` · `holders_changes(PDO, int $security, string $channel, string $a, string $b, ?string $state, int $page): array` ·
`holders_ranges(PDO, int $security, string $channel, ?string $date): array{list, broadridge}` · `holders_geography(PDO, int $security, string $channel, ?string $date): array` ·
`holders_custodians(PDO, int $security, ?string $date): array{nobo, obo}` · `register_on(PDO, int $security, ?string $date): array` · `pii_export_allowed(PDO): bool` · writes: `match_accept(PDO, int $proposal)` ·
`match_reject(PDO, int $proposal)` · `match_new_holder(PDO, string $rowKind, int $row): int` · `holder_match_propose(PDO, string $rowKind, int $row, int $holder, string $evidence): int` ·
`holder_kind_propose(PDO, int $holder, string $kind, ?string $type, ?string $evidence): int` · `holder_kind_set(PDO, int $holder, string $kind, ?string $type)` · `holder_update(PDO, int $id, array $f)` ·
`holders_merge(PDO, int $survivor, int $loser)` · `holder_delete(PDO, int $id, bool $restore)` · `matcher_run(PDO, int $import): array`; the parsers `parse_broadridge_nobo()`, `parse_broadridge_nobo_names()`,
`parse_ta_extract()`, `parse_stc_position_detail()`, `parse_broadridge_share_range()` registered in `parse_upload()`.

## MCP tools this slice makes real
`find_holders` (the resolver), `get_holder`, `holder_history`, `holders_changes`, `holders_by_range`, `holders_by_geography`, `holders_by_custodian`, `holders_by_type`, `holders_unmatched`,
`match_proposals`, `obo_remainder`, `participant_nobo_summary`, `share_ranges`, `obo_totals`.

## Manifest rows claimed
Screens (10): `holder-list`, `holder-view`, `holder-edit`, `holder-merge`, `holders-unmatched`, `holders-changes`, `holders-ranges`, `holders-geography`, `holders-custodians`, `holders-register`
Actions (11): `match_accept`, `match_reject`, `match_new_holder`, `holder_match_propose`, `holder_kind_propose`, `holder_kind_set`, `holder_update`, `holders_merge`, `holder_delete`, `holder_restore`, `matcher_run`

## Activity log events
`match.auto|propose|accept|reject|new_holder` (`{row_kind, row_id, holder_id, score}`), `matcher.run` (`{auto, proposed, new, failed}`), `holder.kind_propose|kind_set|update|merge|delete|restore`
(ids and changed field names only — **never a name, an address or a registration line**), `upload.*` for the new connectors (slice 2's events).

## Status vocabulary
Kind chips: person `text-bg-light`, entity `text-bg-info`, custodian account `text-bg-secondary`, unknown `text-bg-warning`; account-kind chips (IRA, TOD, trust, joint, custodial,
corporate, individual, certificate, book, DRS, plan, restricted); the score bar (≥ 0.95 green, ≥ 0.8 amber, below grey); "Individual holder" in `text-muted` with `bi-shield-lock`.
Ids: `holder-card-{id}`, `holder-accounts`, `holder-history`, `queue-row-{kind}-{id}`, `queue-candidate-{proposal}`, `merge-compare`, `ranges-list`, `ranges-broadridge`, `custodians-table`, `register-table`.

## Out of scope for this slice
The insiders' link to the register (5), the holders export's handler (8), the proposals screen across kinds (9).

## Proof (`tests/phase3/slice3/run.sh`: scratch `si_dev33`; Playwright; the registry `--check`)
`holder-identity.md` §6, plus: the register upload lights the reconciliation's register half (tied on the fixture); a Viewer's holder list renders "Individual holder" for every
person and the entity by name, the queue answers 403, the aggregates render; an agent's `holders_merge` answers `pending_approval`; 10 screens built, 11 actions, 2 approvals in step.

## Open questions
None.
