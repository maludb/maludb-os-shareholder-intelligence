# Build spec: uploads and the position reports (design §6.2, §6.4, §0.2b; D6, D7, D12) — read before slice 2

The issuer's three files arrive by a person's upload (or the API's), never by a fetch. This spec fixes how a file becomes rows, how the
rows prove themselves against the file's own totals, and how the DTC positions are drawn, so slice 2 builds the pipeline once and slice 3
(NOBO, the register, Broadridge's share ranges) rides the same pipeline. **The database is the referee** (`si_spr_load`, the tie, the
reconciliation, the absent day — db/007, 012, 017); PHP reads the file, writes the raw rows and calls the database's verb; JavaScript only
draws the charts.

## 1. The upload pipeline (every kind)
1. **`upload_create`** (`html/uploads/save.php`, a person's multipart form or `POST /api/v1/uploads`): `require_right('uploads.write')`,
   `require_visible` on the security's issuer, the size under `si_settings.max_upload_bytes` (and `SI_MAX_UPLOAD_BYTES`), `finfo` decides the
   type (`text/csv`, `text/plain`, `application/vnd.ms-excel`, `…spreadsheetml.sheet`; anything else is refused by name).
2. **The connector** is the form's choice or **detected** from the first cells (`app/si/parse/detect.php`): `Weekly Subscription Security
   Position Report` → `dtc_spr_csv`; `One Time Security Position Report` → `dtc_spr_onetime_csv`; `JOB NUMBER,CUSIP,ISSUER NAME` →
   `broadridge_nobo_csv` (or `_xlsx`); `First name, Middle name, Last name` → `broadridge_nobo_names_xlsx`; the sheets `Share Range - Holder
   Count` + `OBO Count` → `broadridge_share_range_xls`; `"CUSIP","TAX-ID","ACCT#"` → `ta_extract_csv`; `Security Holder List - Position Detail`
   → `stc_position_detail`; else the person picks a template (`generic_mapped`). **The file's CUSIP must be the security's**: a mismatch is
   refused with both CUSIPs named (an SPR for the preferred uploaded against the common).
3. **The file is kept whole** as an `attachments` row (`record_type = 'import'`, sha256, under `SI_STORAGE_DIR/uploads/<aa>/<sha256>`, mode 640
   www-data) — **except `ta_extract_csv`**: the stored copy is rewritten **without** the extract's dropped columns (`TAX-ID`, `TAX-ID 2`, `DATE OF
   BIRTH`, `PHONE NUMBER`, `EMAIL ADDRESS`, the withholding and NRA columns — the template's `drop_columns`); the original never reaches the disk
   beyond PHP's own temporary upload, which is unlinked in the same request. `imports.control_totals.original_sha256` records the original's
   hash so a re-upload of the same file is recognised. **The same sha256 for the same security and kind is refused** ("already uploaded on …").
4. **The parse** runs in the same request when the file is under 5 MB (every real SPR, NOBO job and register the owner holds is), else the
   upload is `queued` and the worker's `uploads` pass parses it; the upload page polls `/uploads/{id}/status` every 2 s until `done`. **A parse is
   one transaction**: the raw rows (`spr_rows`, `nobo_jobs` + `nobo_rows` + `nobo_broker_rows`, `register_rows`, `share_range_reports` +
   `share_range_rows`) with the cells as read in `raw` and the typed columns beside; a cell that fails to type keeps its row with `error` set;
   then the kind's database verb (`si_spr_load`, the NOBO and register ties, the share-range tie) and `imports` `done` with `tied` and
   `tie_note`. A failure rolls the whole parse back and the upload stays `failed` with the sentence (200 characters, never a person's data).
5. **After a tied (or overridden) upload**: `si_match_import` for NOBO and register (slice 3); `si_reconcile` for the security as of the file's
   date (an SPR, a register, a share count); `si_rules_run` for the security (slice 7); the dispatch `upload_done` to the Surveillance Analyst
   (slice 9). Each is its own statement after the commit; a failure of one is logged and never undoes the upload.
6. **`upload_override`** (a manager, pauses for an agent): `override_by`, `override_at`, `override_note` — the rows become usable with the
   difference on record. **`upload_delete`** → `si_import_delete` (the derived rows go, the raw rows stay with the tombstone); **`upload_restore`**
   reloads them. **`upload_reparse`** deletes the raw rows and parses the kept file again (after a template changed).

## 2. The SPR connectors (what the owner's files are — design §0.2b)
- **`dtc_spr_csv`** (`app/si/parse/dtc_spr_csv.php`): skip to the line beginning `Participant,Participant`; the next line's cells 3–8 are the
  Opening date (the previous Friday) and Monday–Friday dates (`MM/DD/YYYY`); rows `^\d{4},` are participants (number, name, six balances,
  share change, percent change); the `Total,,` row is the control: `control_totals.shares_by_date` (every date whose total is a number). **A
  balance of `-9999999999999` or the word `Holiday` is an absent day** — no `spr_rows` row, no control total for that date. The security's
  CUSIP is the line after `Security Description,Cusip`. `control_totals.participants` = the distinct numbers; `share_change_total` kept for
  the page. The port of `tests/fixtures/load_real_spr.py` (which is the reference and stays as the proof's oracle).
- **`dtc_spr_onetime_csv`**: `Position as of: MM/DD/YYYY`; rows after `Number,` are `number,,name,,quantity` with the number unpadded
  (`756` → `0756`); no Total row: the control is the rows' sum (recorded as such, so the tie checks the parse, not the file).
- **`dtc_spr_xlsx`**: the same layouts read through PhpSpreadsheet (`phpoffice/phpspreadsheet`, the cidery's vendored copy, composer) when DTC's
  spreadsheet is uploaded instead of the CSV.
- **`dtc_spr_ccf`**: Extended (no sample exists).
- **The load** is `si_spr_load(import)` (db/007): participants the directory lacks are made from the file's name and classed from it; a file
  whose dates overlap an earlier file replaces those dates (the next week's Opening overlaps the previous Friday — the same figure) and marks
  the earlier upload superseded for them; a tied file writes the Cede share count of its last date (`source = 'spr'`).

## 3. The screens
- **`/uploads`** (`upload-list`): the uploads of the current issuer as a table (a ledger, not cards): kind, file, the date or period, rows,
  **tied** (`badge text-bg-success`) / **untied** (`text-bg-danger`, the note on hover) / **overridden** (`text-bg-warning`), who, when; the
  filters; the "Upload" button. **`/uploads/new`**: the form (kind, security picker, the file, the record date when the kind needs one, the
  template for a generic file); after the post, the upload page. **`/uploads/{id}`**: control totals against derived side by side, the
  difference named, the rows with the failed ones first (an SPR's rows to everyone who sees the issuer; a NOBO's or register's to the PII
  roles — a Viewer sees the counts), Override (managers), Delete, Re-parse, Run the matcher, the matcher's counts.
- **`/positions`** (`positions`): the date picker (the position dates of the security, newest first), the window (`from`, the previous
  date by default), the class filter; the table — rank, number, name (a link to the participant), the class chip, shares, % of the DTC total,
  the change against the previous date (green up, red down, `—` absent), the window's net; the total row = `si_spr_total` and the tie badge
  against the reconciliation's Cede.
- **`/positions/matrix`** (`positions-matrix`): participants (rows, sorted by the window's |net|) × position dates (columns), each cell the
  shares with its background coloured by the day's change (a diverging scale, the dataviz skill's palette, absent = hatched); at 375 one
  column a date with a date chooser. Capped at 40 rows × 30 dates (the rest is "show more").
- **`/positions/flow`** (`positions-flow`): `si_participant_flow` per consecutive date pair as a stacked bar (one colour per class, the
  dataviz palette) and the cumulative net of the retail side as a line; **under the chart, always: "Class buckets are a setting. Movement
  between them is practitioner lore with no published validation."**
- **`/positions/concentration`** (`positions-concentration`): top-5/10/20 % and the index as lines over the window.
- **`/reconciliation`** (`reconciliation`): the latest run as two equations drawn as rows — *Outstanding = Cede + DRS/book + certificated +
  plan + other* and *Cede = the SPR total* — each with its date and source and the difference (zero is `text-bg-success`), the history of runs,
  Run now (`reconciliation_run`).
- Charts are server-rendered SVG from the query (the dataviz skill's rules: one palette, light and dark, the source and date under every chart,
  absent gaps never joined) — no chart library; a `<table>` twin under each chart for screen readers and at 375.

## 4. Files (slice 2)
`html/uploads/{index,form,view,save,reparse,override,delete,restore,status}.php`, `html/positions/{index,matrix,flow,concentration}.php`,
`html/reconciliation/{index,run}.php`; `app/si/parse/{detect,dtc_spr_csv,dtc_spr_onetime_csv,dtc_spr_xlsx,common}.php` (`parse_upload(PDO, int $import): array`
dispatches by connector; `common.php` the cell typing: numbers with commas and zero-padded decimals, the three date forms, the sentinels);
`app/si/storage.php` (`store_upload()`, the rewrite of a TA extract without its dropped columns); `app/features/uploads/{queries,present,write}.php`,
`app/features/positions/{queries,present}.php`, `app/features/reconciliation/{queries,write}.php`, `app/si/svg.php` (bars, lines, the matrix — the
dataviz palette as constants); `app/views/uploads/{index,form,view}.php` + `partials/{status,rows,totals}.php`, `app/views/positions/{index,matrix,flow,concentration}.php`
+ `partials/{date-picker,class-chip,caveat}.php`, `app/views/reconciliation/index.php`; `app/pickers.php` gains `security`, `participant`, `template`.
The worker's `uploads` and `reconciliation` passes are filled in `bin/worker.php`.

## 5. Status vocabulary and ids
Tied `text-bg-success`, untied `text-bg-danger`, overridden `text-bg-warning`, queued/parsing `text-bg-secondary`; the class chips' colours from
`participant_classes` (retail blue, clearing-retail light blue, prime purple, market maker orange, lending red, transfer agent grey, bank custodian teal,
other light grey — the dataviz categorical palette in that order). Ids: `upload-form`, `upload-{id}`, `upload-{id}-totals`, `upload-{id}-rows`, `positions-table`,
`positions-date`, `matrix`, `matrix-cell-{participant}-{date}`, `flow-chart`, `flow-caveat`, `concentration-chart`, `recon-latest`, `recon-run`.

## 6. Proof (slice 2's `tests/phase3/slice2/run.sh`)
**Every one of the owner's 70 real SPR files** (`tests/fixtures/real/*SPR*.csv` — an SPR names only brokers) uploads through `upload_create`
with its connector detected, parses, and **ties to its own Total row on every date** (the one-time reports to the sum of their rows); the
holiday weeks load four or five dates; consecutive weeks overlap on the Opening without a difference; the total on 2024-06-28 equals the
transfer agent's Cede line of 2024-06-30 and `reconciliation_run` ties; the same file twice is refused; an SPR for another CUSIP is refused;
the positions table, the matrix, the flow (with its caveat) and the concentration render from the loaded weeks at 375 and 1280; a Viewer sees
the same positions; a TA extract upload leaves no tax id, date of birth, phone or email in the stored file, the rows or the log (grep of the
storage directory and the database dump); JSON mode on every page and handler; the registry `--check`.
