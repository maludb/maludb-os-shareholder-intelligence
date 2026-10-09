# Build spec: holder identity and the PII wall (design §3.2, §6.3, §0.2b; D4, D8) — read before slice 3

A holder is one person or entity across Broadridge's NOBO list, the transfer agent's register and the insiders' filings. This spec fixes
how a registration becomes a holder, how a person decides what the matcher could not, and **how a natural person's details never reach a
Viewer** on any screen, export, search, share or API read. The database already keeps every rule (db/008, 016, 018: the normaliser, the
scores, the auto-accept, the remembered rejection, the forced new holder, the merge, `si_sees_holder_pii()` in every view); PHP renders the
queue and the compare page; nothing is decided in PHP.

## 1. The three parsers (slice 3; the pipeline of `position-reports.md` §1)
- **`broadridge_nobo_csv` / `_xlsx`**: the header row 2 (`JOB NUMBER`, `CUSIP`, `ISSUER NAME`, `RECORD DATE` as `MMDDYY` — and as an integer
  in the xlsx, `60823` = 06/08/23 — `SORT`, `NUMBER OF NOBOS`); the `SHARES` section from row 5 (shares as written: `92703` or the CSV's
  zero-padded `000461260.00000`; seven name-and-address lines, padded with spaces, trimmed; the zip for sorting; the CUSIP); the summary row
  `# OF NOBOS`; the `CLIENTS` section after the row whose first cell is `CUSIP` (`cusip, client, count, shares`, zero-padded) → `nobo_broker_rows`
  with the participant matched by name or alias (`si_participant_find`). Control: the header's count and the CLIENTS shares' sum against the
  rows (`nobo_count`, `shares`); **a difference is named** ("the header says 855 NOBOs, the SHARES section has 841 rows" — Broadridge's
  suppressions) and the upload is untied until a manager overrides.
- **`broadridge_nobo_names_xlsx`** (the newer export: first, middle, last name, last active date, shares, address, city, state, country, zip):
  the name lines built as `FIRST MIDDLE LAST` in `line1`, the address in `line2`/`line3`; **the shifted rows of the owner's sample** (a street
  number in `ZIP code`, the street in `City`) are re-joined by recognising the state and a five-digit zip; a row that cannot be placed keeps
  its cells in `raw` with `error`. No CLIENTS section: the broker listing is absent and the OBO remainder for that date is not computed.
- **`ta_extract_csv`** (the owner's agent's Shareholder Extract): the header names the columns; `="210"` Excel-quoted cells unwrapped; the
  dropped columns never read past the parser's first line (`position-reports.md` §1.3); **one `register_rows` row per non-zero type column**
  (`CERT` → cert, `BOOK` → book, `DRS` → drs, `BOOKR` → restricted, `PLAN1`–`4` → plan) with `position_type` the column's name and `holder_ref`
  the `ACCT#`; the registration = `HOLDER REG 1`–`6` joined by newlines; the address = `HOLDER ADDRESS 1`–`3`; `OWNERSHIP TYPE` →
  `account_kind` (INDIVIDUAL individual, JOINT TENANTS joint, TRUSTEE - INDIVIDUAL trust, CORPORATION corporate, PARTNERSHIP corporate,
  NOMINEE/DEPOSITORY = Cede); `CEDE & CO (FAST)` is flagged `is_cede`. Control: `TOTAL SHARES` per row against its type columns, and the sum.
- **`stc_position_detail`** (the reference file): sections by title (`Common`, `Common 2` …), the column map of the seeded template.
- **`broadridge_share_range_xls`** (slice 3 too, no person): the two sheets into `share_range_rows` (`all`, `obo`), the footer's job, CUSIP
  prefix and record date; control: the rows against the sheet's `TOTALS`.

## 2. The matcher (database) and the queue (PHP)
After a tied NOBO or register upload, `si_match_import(import)`: per row the lines are parsed (`si_nobo_lines_parse`), the kind proposed
(person or entity — an LLC, Inc, Ltd, trust and fund are entities), the custodian and account kind parsed from the legends, and the
candidates scored (`si_match_candidates`: name and address exact 1.0; name exact with one side lacking an address 0.9; trigram ≥ 0.6 with
the address exact 0.95; trigram ≥ 0.7 with the zip 0.8; the name exact elsewhere 0.6). **≥ `auto_match_threshold` (0.95) is attached and
logged `auto`**; a lower best candidate is a proposal; no candidate makes a new holder. The upload page shows `{auto, proposed, new}`.

**`/holders/unmatched`** (`holders-unmatched`, the PII roles): one card per waiting row — left, the registration as read (the seven lines or
the register's name and address, the shares, the custodian and account kind chips); right, up to three candidates with the score as a bar,
the evidence in words ("same street and zip; the name differs by one letter", "same name, another city"), the candidate's accounts and last
position. Buttons: **Accept** (`match_accept`), **Reject** (`match_reject` — remembered), **New holder** (`match_new_holder`), **Set kind**
(`holder_kind_set`). An agent's proposal (`holder_match_propose`) carries its evidence sentence and an "Analyst's proposal" chip.
Keyboard: `a` accept the first, `r` reject, `n` new — the queue advances. At 375 the two sides stack.

## 3. The merge (a manager's, pauses for an agent)
`/holders/{id}/merge?into=` (`holder-merge`): two columns — this holder and the one the picker chose — every field, the accounts, the
positions by date; the survivor chosen by a radio; **Merge** posts `holders_merge` (`survivor`, `loser`); the database refuses a person into
an entity and across issuers (the sentence shown). After: the survivor's page with "Merged from …" in its notes and the loser's URL
answering 404 with the "merged into" link.

## 4. The PII wall in PHP (the views do the work; PHP must not undo it)
- **Every holder screen reads `mcp_holders` / `mcp_holder_accounts`** (never `holders`): a Viewer's rows say "Individual holder" with no
  address, city or notes, `pii_withheld = true`. The presenter (`app/features/holders/present.php`) whitelists fields and **never** reads
  `display_name` from another source (a search hit, an insider row).
- **The raw rows** (`mcp_nobo_rows`, `mcp_register_rows`) answer nothing to a Viewer; the upload page shows a Viewer the counts.
- **The aggregate screens** (`/holders/ranges`, `/holders/geography`, `/holders/custodians`, `/holders/changes` for a Viewer) read the
  `si_holders_by_*` and `si_holders_changes_summary` functions — counts and shares, never a name.
- **Exports**: `export_create(kind = holders_csv)` is refused unless `si_settings.pii_in_exports` admits the caller's role (`analyst` —
  Analyst and above, the owner's answer (d); `admin`; `nobody`); the export row carries `includes_pii = true` and the log
  `export.create {kind, rows, includes_pii}` — never a name.
- **The search box** uses `si_search` (the wall inside); **the picker** for `holder` (`app/pickers.php`) refuses a Viewer (an empty list and
  "Holders are an Analyst's").
- **Logs**: a holder event's payload is ids, kinds, counts and shares (`holder.update {holder_id, fields_changed: ["kind"]}`) — never the
  name or the lines. `tests/phase3/slice3/` greps the whole `activity_log` after the proof for every fixture name: zero hits.

## 5. Files (slice 3)
`html/holders/{index,view,form,save,kind,kind-propose,merge,delete,restore,unmatched,changes,ranges,geography,custodians,register}.php`,
`html/holders/matches/{accept,reject,new-holder,propose,run}.php`; `app/si/parse/{broadridge_nobo,broadridge_nobo_names,ta_extract,stc_position_detail,broadridge_share_range}.php`;
`app/features/holders/{queries,present,write}.php`; `app/views/holders/{index,view,form,merge,unmatched,changes,ranges,geography,custodians,register}.php` +
`partials/{card,registration,candidate,accounts,history-chart,pii-withheld}.php`; `app/pickers.php` gains `holder` (PII roles) and `institution`.

## 6. Proof (slice 3's `tests/phase3/slice3/run.sh`)
The anonymised NOBO fixture and STC register (`tests/fixtures/`) upload, tie or name their difference, and match (the Phase 0 proof's counts);
**the owner's real NOBO, register and share-range files** (`tests/fixtures/real/`) are parsed in the scratch database for their LAYOUT only —
every file parses, every control ties or names its difference, the counts are printed, **no row, name or address is printed or written outside
the scratch database**, and the scratch database is dropped at the end; the queue accepts, rejects (remembered), makes a new holder; a merge
re-points and refuses a person into an entity; a Viewer sees "Individual holder" on every holder screen, nothing on the queue and the raw rows,
the aggregates in full; a holders export is refused to a Viewer and logged with its count for an Analyst; the activity log carries no fixture
name; 375 and 1280; JSON mode; the registry `--check`.
