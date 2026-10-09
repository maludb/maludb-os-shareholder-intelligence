# Build spec: issuers, securities, share counts and the DTC participants (slice 1 — the CRUD exemplar)

What exists at the end: a manager sets up an issuer from its ticker (the CIK looked up, the EDGAR entity read), adds its securities with their
CUSIPs, records share counts, and restricts it to named members when it is an advisory firm's client; the DTC participant directory is fetched
monthly and every participant has a page; tags and notes work on every record kind. This slice is the pattern every later slice copies: cards
for named things, a full-page form, the picker for every record field, the wall in the view, the database's refusals as field errors.

Schema (db/006, 009, 014, 016, never modified): `issuers`, `issuer_members`, `securities`, `share_counts`, `si_share_count()`, `si_can_see_issuer()`,
`si_my_issuers()`, `participant_classes`, `participants`, `si_participant_upsert()`, `si_participant_find()`, `si_participant_class_from_name()` (db/006);
`sources`, `source_pulls` (db/009 — the `dtcc_participants` and `edgar_submissions` connectors); `tags`, `record_tags`, `notes`, `attachments` (db/014);
`mcp_issuers`, `mcp_issuer_members`, `mcp_securities`, `mcp_share_counts`, `mcp_participants`, `mcp_participant_classes`, `mcp_tags`, `mcp_notes` (db/016).

## Divergences from the design
1. **The ticker → CIK lookup reads the SEC's `company_tickers.json`** (`https://www.sec.gov/files/company_tickers.json`, cached a day under the policy of
   `edgar-and-finra-fetchers.md` §1) — an OTC issuer missing from it is entered by CIK. 2. **`issuer_edgar_sync` is the submissions pull for one issuer**, run on
   save and on the button; the full index is slice 4's. 3. **Tags, notes and the restore of an issuer are one handler per verb across kinds** (`tag_apply`,
   `tag_remove`, `note_add` with `kind` + `record`), built here and used by every later slice.

## Who may do what
issuer_save / security_save / share_count_save (and their deletes) / issuer_edgar_sync / participant_update: `issuers.manage` (an IR Manager; `issuer_save`,
`security_save`, `share_count_save` pause for an agent, `other`; the deletes `deletion`) · restriction_save: `restrictions.manage` (pauses, `other`) ·
issuer_restore: `issuers.manage` · tag_apply / tag_remove: `tags.apply` + `see` · note_add: `notes.write` + `see` (a note on a person-holder: `pii`).

## Screens
`issuer-list` `/issuers?q=` (cards: name, ticker, exchange chip, the primary CUSIP, the last position date, a lock for a restricted issuer; "Add an issuer" for a manager) ·
`issuer-view` `/issuers/{id}?tab=` (header: name, ticker, CIK linked to EDGAR, exchange, transfer agent; tabs: Overview (the EDGAR entity: state, fiscal year end, SIC, addresses,
former names; the share counts' latest by kind with source lines; the last upload per kind), Securities, Share counts, Access (managers), Sources (the fetchers' state for this
issuer), Notes & files) · `issuer-add` `/issuers/new?ticker=&cik=` (the ticker field with **Look up** — the CIK, the name, the exchange filled from the SEC's file; the
transfer agent and its connector; the time zone; the holidays) · `issuer-edit` · `issuer-securities` (the table; an add/edit form on the page: class, name, CUSIP, ISIN, ticker,
FIGI, par value, primary) · `issuer-share-counts` (the dated counts by kind with source; the record form: security, as of, kind, value, source, note) · `issuer-access`
(the restriction list: members by the picker; an empty list lifts it — `hx-confirm` says so) · `participant-list` `/participants?class=&q=` (cards by class: number, name,
aliases, the class chip with its source (seeded, directory name, manual), the latest position in the current security) · `participant-view` `/participants/{id}?security=&from=&to=`
(the series chart for the current security, the latest position and % of total, the dates present, aliases, notes, tags; the NOBO summary panel is slice 3's) ·
`participant-edit` (the class as a select of the eight classes, notes, tags).

## Files (exactly these)
`html/issuers/{index,view,form,save,delete,restore,edgar-sync,securities,share-counts,access}.php`, `html/issuers/securities/{save,delete}.php`,
`html/issuers/share-counts/{save,delete}.php`, `html/issuers/access/save.php`, `html/participants/{index,view,form,save}.php`, `html/tags/{apply,remove}.php`,
`html/notes/add.php`; `app/features/issuers/{queries,present,write}.php`, `app/features/participants/{queries,present,write}.php`,
`app/features/tags/write.php`, `app/features/notes/write.php`; `app/si/sec/tickers.php` (the lookup); `app/si/fetch/{http,calendar,dtcc_participants,edgar_submissions}.php`
(the entity half; the index half is slice 4's); `app/views/issuers/{index,view,form,securities,share-counts,access}.php` + `partials/{card,entity,counts,sources}.php`,
`app/views/participants/{index,view,form}.php` + `partials/{card,series-chart}.php`; `app/pickers.php` gains `issuer` (already), `security`, `participant`, `tag`;
the worker's `fetch` pass for the two connectors; `tests/phase3/slice1/{run.sh,lib.php,issuers.php,participants.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_issuers(PDO, array $filters): array` · `find_issuer(PDO, int $id): ?array` · `issuer_overview(PDO, int $id): array` · `find_securities(PDO, int $issuer): array` ·
`share_counts_latest(PDO, int $security): array` · `share_counts_list(PDO, int $security, ?string $kind): array` · `issuer_access(PDO, int $issuer): array` ·
`find_participants(PDO, array $filters, ?int $security): array` · `find_participant(PDO, int $id, ?int $security): ?array` · `participant_series_points(PDO, int $participant, int $security, string $from, string $to): array` ·
`lookup_ticker(PDO, string $ticker): ?array{cik, name, exchange}` · writes: `issuer_save(PDO, ?int $id, array $f, int $by): int` · `issuer_delete(PDO, int $id, bool $restore)` · `security_save(PDO, ?int $id, array $f): int` ·
`security_delete(PDO, int $id)` · `share_count_save(PDO, array $f, int $by): int` · `share_count_delete(PDO, int $id)` · `restriction_save(PDO, int $issuer, array $members, int $by)` ·
`participant_update(PDO, int $id, array $f)` · `tag_apply(PDO, string $kind, int $record, string $tag, int $by)` · `tag_remove(PDO, string $kind, int $record, int $tag)` · `note_add(PDO, string $kind, int $record, string $body, int $by): int`

## MCP tools this slice makes real
`find_issuers` (the resolver), `get_issuer`, `find_securities` (the resolver), `share_counts`, `issuer_restrictions`, `find_participants` (the resolver), `get_participant`, `record_history`.

## Manifest rows claimed
Screens (10): `issuer-list`, `issuer-view`, `issuer-add`, `issuer-edit`, `issuer-securities`, `issuer-share-counts`, `issuer-access`, `participant-list`, `participant-view`, `participant-edit`
Actions (13): `issuer_save`, `issuer_delete`, `issuer_restore`, `issuer_edgar_sync`, `security_save`, `security_delete`, `share_count_save`, `share_count_delete`, `restriction_save`, `participant_update`, `tag_apply`, `tag_remove`, `note_add`

## Activity log events
`issuer.save|delete|restore|edgar_sync`, `security.save|delete`, `share_count.save|delete`, `restriction.save` (`{members: n}` — ids, never names), `participant.update`,
`participant.directory_sync` (`{inserted, renamed, unchanged}`), `tag.apply|remove`, `note.add`; `source.pull_*` for the two connectors.

## Status vocabulary
The exchange chips (Nasdaq, NYSE, NYSE American, OTC, CSE, TSX, TSXV); the class chips of `position-reports.md` §5; restricted `bi-lock-fill`; the share-count source
chips (filing, transfer agent, DTC letter, register, SPR, manual). Ids: `issuer-card-{id}`, `issuer-lookup`, `issuer-securities`, `issuer-counts`, `issuer-access`,
`participant-card-{id}`, `participant-series`.

## Out of scope for this slice
Uploads (2), holders (3), the filing index and the companyfacts share counts (4), the ownership forms (5), the classes' admin screen (10 — the edit form here sets one).

## Proof (`tests/phase3/slice1/run.sh`: scratch `si_dev31`; Playwright; the stub server of `edgar-and-finra-fetchers.md` §4; the registry `--check`)
A manager sets up SMOKE ISSUER from its ticker against the stub's `company_tickers.json` and submissions JSON (the entity, the former name); adds the common and the
preferred; a second primary is refused as a field error; a CIK of five digits is refused; records an outstanding count; restricts the second issuer to Dana — an
Analyst no longer sees it in the list, the switcher, a URL (404) or the search; the directory pull against the stub's CSV inserts the new number classed `lending`,
renames 0164 keeping the class and the alias; a participant's class set by hand survives the next pull; tags and notes on an issuer and a participant; an agent's
`issuer_save` answers `pending_approval`; a Viewer reads every screen and changes nothing (403 on every POST); 375 and 1280; JSON mode everywhere.

## Built (2026-10-09) — what the build decided within this spec
1. **A CIK is up to ten digits, kept zero-padded** (EDGAR's number: 320193 is 0000320193). The proof's "a CIK of five digits is refused" became:
   letters or eleven digits are refused, a short CIK is padded.
2. **Making a security primary steps the issuer's other primary down** in the same transaction (one primary, always) rather than refusing it;
   an issuer's first security is its primary.
3. **The lookup reads `company_tickers_exchange.json`** (the same list with the exchange beside each ticker), through the one client and its cache;
   its URL is the edgar_submissions source's `settings.tickers_url`. A company with two classes names both tickers.
4. **The directory reads the participant listing only** ("DTC Participant in Alphabetical Listing - MM.DD.YYYY.xlsx", else the numerical one): the
   settling-bank, pledgee and DRS registers number their own entries and could overwrite a participant's name. The file's date comes from its name.
5. **The fetch client is Inventory's `app/sources/http.php`, ported** (`app/si/fetch/http.php`: robots, the per-host rate across processes, the
   ETag cache under `SI_STORAGE_DIR/http-cache`, blocked detection, an injectable transport); `app/si/fetch/source.php` runs a pull end to end
   (the EDGAR user-agent or no request, the pull row, si_pull_finish, the log, the keeper told on a block or a fourth failure); `calendar.php`
   holds EDGAR's market-day window (06:00–20:59 market time); `app/si/xlsx.php` reads a workbook (Inventory's reader, every sheet).
6. **db/020** — `si_record_issuer()`, `si_can_see_record()` (both walls for any record kind), `mcp_notes` re-created behind both walls (a note on a
   restricted issuer was readable by anyone admitted), `si_tag_ensure()`.
7. **The issuer's page has tabs** — Overview · Securities · Share counts · Access (managers) · Sources · Notes; the participant's page shows where the
   NOBO panel goes (slice 3). The participant form's `tags` sets the whole list.
8. **On a phone** a table folds its secondary columns under the first cell (the CUSIP under the security, the source under the kind), so its row's
   buttons stay on the screen; the browser proof checks no control reaches past its card.
9. **The worker's `fetch` and `search_catch_up` passes are filled** (the comments assigned them to slice 1).

Proof: `tests/phase3/slice1/run.sh` (each proof on a fresh scratch database) — fetch 24, issuers 70, participants 29, json 13, browser 42, the live
pulls 5 (DTCC: 906 participants; EDGAR: Bitmine Immersion, CIK 1829311, DE, year end 0831; the ticker file finds BMNR and BMNP), under php -S and a
real Apache (`SI_APP=apache`); Phase 2's 82 + 87 + 46 + 30 + 14, the schema proof 180 and the kit 73 re-run green; the registry 16 screens and 18
actions built; the installer's plan clean.

## Open questions
None.
