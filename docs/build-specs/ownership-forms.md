# Build spec: EDGAR — the ownership forms (slice 5)

Builds the rows `edgar_13f_dataset`, `edgar_13dg`, `edgar_ownership`, `edgar_144` of `edgar-and-finra-fetchers.md` §2. What exists at the end: the 13F holders of
every tracked security by quarter from the SEC's data sets, the 5% filers from Schedules 13D and 13G, the insiders' Forms 3/4/5 and Form 144 notices, institutions
with pages and kinds, the insider ledger linked to the register; the ownership screens.

Schema (db/010, 008, 016, never modified): `institutions`, `si_institution_upsert()`, `si_institution_kind_from_name()`, `institutional_holdings`, `si_13f_changes()`,
`si_13f_summary()`, `beneficial_ownership`, `si_beneficial_owners()`, `insider_transactions` (the `si_insider_mark` trigger), `si_insider_summary()`, `form144_notices` (db/010);
`holders.is_insider`, `insider_cik` (db/008); `mcp_institutions`, `mcp_institutional_holdings`, `mcp_beneficial_ownership`, `mcp_insider_transactions`, `mcp_form144_notices` (db/016).

## Divergences from the design
1. **13F comes from the quarterly data sets only** (D9): the zip is streamed and only the tracked CUSIPs' rows are kept; a fresh 13F-HR by accession is Extended. 2. **A 13D/G
filed before 2024-12-18 (HTML)** is parsed best-effort from the cover (percent, shares, the filer); `filer_type` stays NULL. 3. **`insider_holder_link` is a person's** — the
trigger proposes the link by name; a person confirms or corrects it.

## Who may do what
institution_update: `events.write` · insider_holder_link: `matches.decide` (`people`) · every screen: `see`; the register link's holder: `pii`.

## Screens
`institution-list` `/institutions?kind=&q=&activist=` (cards: name, CIK, kind chip, the activist mark, the latest holding in the current security with the quarter) · `institution-view`
`/institutions/{id}?security=` (the holdings by quarter as bars with the source line "13F data set, period …, filed …"; the 13D/13G history; the filings about us; aliases; notes; tags) ·
`institution-edit` (kind, activist, website, notes, tags) · `ownership-13f` `/ownership/13f?period=` (the quarter's holders ranked: name, kind, shares, value, voting sole/shared/none,
% of outstanding; the summary row; the top-10 share; puts and calls apart in a second table) · `ownership-13f-changes` `/ownership/13f/changes?period=&state=` (new, out, up, down with the
shares from → to) · `ownership-beneficial` `/ownership/beneficial` (cards: the filer, 13D or 13G chip, percent as a bar against 5% and 10%, shares, event and filed dates, the purpose in
one paragraph, the filing linked) · `ownership-insiders` `/ownership/insiders?from=&to=&owner=&code=` (the ledger: date, person, relationship, code chip with its meaning, shares, price, A/D,
owned after, D/I, 10b5-1; the person's register link — PII roles) · `ownership-144` `/ownership/144?from=&to=`.

## Files (exactly these)
`html/institutions/{index,view,form,save}.php`, `html/ownership/{13f,13f-changes,beneficial,insiders,144}.php` (the files `13f.php` and `13f-changes.php` answer `/ownership/13f`
and `/ownership/13f/changes` through the vhost's rewrite `^/ownership/13f/changes$ → 13f-changes.php`), `html/ownership/insiders/link.php`; `app/features/institutions/{queries,present,write}.php`,
`app/features/ownership/{queries,present,write}.php`; `app/si/fetch/{edgar_13f_dataset,edgar_13dg,edgar_ownership,edgar_144}.php`; `app/views/institutions/{index,view,form}.php` +
`partials/{card,holdings-chart}.php`, `app/views/ownership/{13f,13f-changes,beneficial,insiders,144}.php` + `partials/{code-chip,beneficial-card}.php`; `app/pickers.php` gains `institution` (already)
and `reporting_owner`; the worker's `fetch` pass for the four; the vhost and dev-router rewrite; `tests/phase3/slice5/{run.sh,lib.php,thirteenf.php,beneficial.php,insiders.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_institutions(PDO, array $filters, ?int $security): array` · `find_institution(PDO, int $id, ?int $security): ?array` · `institution_holdings(PDO, int $id, int $security): array` ·
`thirteen_f(PDO, int $security, ?string $period): array{period, holders, summary, options}` · `thirteen_f_changes(PDO, int $security, ?string $period, ?string $state): array` ·
`beneficial(PDO, int $security): array` · `insiders(PDO, int $issuer, array $filters, int $page): array` · `form144(PDO, int $issuer, array $filters): array` · writes:
`institution_update(PDO, int $id, array $f)` · `insider_holder_link(PDO, string $cik, int $holder, int $by)`

## MCP tools this slice makes real
`find_institutions` (the resolver), `get_institution`, `institution_history`, `institutional_holders`, `holdings_changes`, `holdings_summary`, `holdings_put_call`, `beneficial_owners`,
`get_beneficial_filing`, `insider_transactions`, `insider_holdings`, `form144_notices`.

## Manifest rows claimed
Screens (8): `institution-list`, `institution-view`, `institution-edit`, `ownership-13f`, `ownership-13f-changes`, `ownership-beneficial`, `ownership-insiders`, `ownership-144`
Actions (2): `institution_update`, `insider_holder_link`

## Activity log events
`holding.load` (`{period, rows, institutions}`), `ownership.load` (`{filing_id, schedule, percent}`), `insider.load` (`{filing_id, rows}`), `form144.load`, `institution.create|update`,
`insider.holder_link` (ids only), `source.pull_*`.

## Status vocabulary
Institution kind chips; 13D `text-bg-danger` / 13G `text-bg-secondary`; the transaction codes with their meaning on hover (P purchase, S sale, A grant, M exercise, F tax withholding,
G gift, D to the issuer, C conversion, J other); the change states (new green, out red, up, down). Ids: `institution-card-{id}`, `holdings-chart`, `thirteenf-table`, `thirteenf-options`,
`beneficial-card-{id}`, `insider-row-{id}`.

## Out of scope for this slice
The rules reading these rows (7 — `new_13d`, `holder_5pct`, `top_holder_exit`, `insider_sales`); N-PORT and fresh 13F-HR (Extended).

## Proof (`tests/phase3/slice5/run.sh`: scratch `si_dev35`; the stub server; Playwright; the registry `--check`)
`edgar-and-finra-fetchers.md` §5 for the four connectors on the samples (`13f_infotable.tsv` in a zip the stub serves, `sc13d.xml`, `form4.xml`, `form144.xml`): the institutions, their
kinds, the quarter-over-quarter changes (Alpha down, Beta out with its put apart, Gamma new), Delta's 13D with its purpose, the CEO's two sales and the trigger's proposed register link,
the 144; a second pull changes nothing; an XML with an external entity is refused; a Viewer reads every screen without the register link's holder; 375 and 1280; JSON mode; the live
pull of the newest data set's listing.

## Built (2026-10-10) — what the build found and decided within this spec
1. **The four connectors are `si_fetch_<connector>(PDO, array $source, SiHttp $client, array $ctx)`** on slice 1's `si_run_source()`, registered in `si_fetchers()`;
   what they share is `app/si/fetch/ownership_common.php`. **The 13D/G, Forms 3/4/5 and 144 are read from the issuer's own filing index** (slice 4) — EDGAR
   lists them under the subject company — the filings of their forms not yet `structured_at`, oldest first, `per_pull` (40) a pull, so a new issuer's years of
   Forms 4 clear over a few pulls; each one's RAW document (the index names the rendered page `xslF345X05/form4.xml`; the raw is `form4.xml`). A filing read or
   refused is marked structured and never read again; a refusal keeps its words in the filing's `error`. Their calendar is the index's (market days 06–20 New York).
2. **A document about another company is set aside** ("About another company: …" on the filing, nothing loaded): the issuer's index also carries what the issuer
   itself FILED — found by the live pull: Bitmine's own 13D on Eightco would have made Bitmine a 31.94% holder of itself. The subject is the document's
   `issuerCik`/`issuerCIK`; an older HTML schedule has none and is taken as the index gives it.
3. **The 2024 XML schedules come in two layouts**: the 13D's `reportingPersonInfo` (the person's CIK, `percentOfClass`, `aggregateAmountOwned`, item 4's purpose)
   and the 13G's `coverPageHeaderReportingPersonDetails` (no CIK on the person — the filer's, from `headerData`; `classPercent`;
   `reportingPersonBeneficiallyOwnedAggregateNumberOfShares`; item 4 is the amount, so a 13G has no purpose). The first reporting person is the institution, the
   rest its group; `filer_type` is `active` for a 13D and `qii` / `passive` / `exempt` from a 13G's 13d-1(b)/(c)/(d) designation.
4. **An older HTML schedule is read from its cover** whether the rows are table cells or lines of their own (an older filer's cover): the names, row 11's amount,
   row 13's percent ("15,700,000 Common Shares / 41.72%"), the four powers, the event date, the CUSIP; its filer's CIK is the accession's prefix (a filing agent's,
   at times — the institution is then named but its CIK is the agent's). Live: all eleven of Bitmine's schedules read (nine kept, two about Eightco set aside).
5. **13F: the data sets' page (`listing_url`) is read for its zips; the zips loaded are kept in the source's `loaded`**; with none loaded the newest `backfill` (2)
   are read, after that every newer one, oldest first. The zip is downloaded whole into a temporary file without the HTTP cache (`no_cache`) and its three TSVs
   streamed; only the tracked CUSIPs' rows are kept, a filer's rows for one CUSIP summed per accession and put/call; VALUE in thousands before 2023-01-03; a
   RESTATEMENT amendment replaces the filer's other rows for its period. A data set also carries late filings for earlier quarters, which load as their own
   periods. **fetch_now on the 13F source queues it for the worker** (a data set is ~100 MB). Live, by hand on the scratch database: the newest set
   (01jun2026-31aug2026) in 15 s at a 127 MB peak — 531 rows from 454 institutions of Bitmine, 511 filers holding 254,830,686 shares at 2026-06-30, 63 option rows apart.
6. **A security holds one CUSIP** (slice 1): a 13F row under a FORMER CUSIP is not read — Bitmine's 09175A107 became 09175A206 at its split; the scratch security
   carried the old one and read nothing until it was corrected. The owner's question (open): a security's former CUSIPs.
7. **`si_insider_mark` reads EDGAR's name order** — "SMITH ROBERT A", the family name first and no comma — beside "Smith, Robert" and the name as given (db/022);
   the trigger proposes, a person confirms or corrects: **`insider_holder_link` moves every row of that insider at the issuer**, marks the holder, refuses an
   entity holder (an insider is a person) and another issuer's.
8. **`institution.create` is logged for a filer a schedule makes; a 13F data set's new institutions are counted in its `holding.load`** (`institutions_new`) —
   a set makes hundreds.
9. **`/ownership/13f/changes` and `/ownership/144` are named rewrites** in the vhost, the dev router and the registry's controller lookup — `144` would otherwise
   read as a record id (`/{module}/{id}` → `view.php`).
10. **On a phone the insider ledger puts the code chip and the 10b5-1 mark under the date** (the person column keeps its width; the shares stay on the screen).

Proof: `tests/phase3/slice5/run.sh` (each proof on a fresh scratch database, the stub on :8905 serving the samples `tests/fetch/make_ownership_samples.py` writes —
two 13F zips and their page, a 13D and a 13G in the 2024 layouts, an older HTML 13G/A, a Form 4, a Form 4 about another company, a 144, a schedule declaring an
entity) — the 13F 18, the schedules 14, the insiders and 144 19, JSON 3, browser 76, under php -S and a real Apache; **the live pulls**: Bitmine's index, its
Forms 3/4/5 (40 read, 89 rows), its schedules (11 read, 9 kept, 2 set aside), its Forms 144 (8), the 13F page (11 data sets listed); slices 1–4, Phase 2, the
schema proof, the kit and Phase 1 re-run green; the registry 50 screens and 44 actions built.

## Open questions
None of the build's; the owner's: a security's former CUSIPs (Built 6).
