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

## Open questions
None.
