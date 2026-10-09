# Build spec: the short side (slice 6)

Builds the rows `finra_short_interest`, `finra_daily_short_volume`, `sec_ftd`, `threshold_nasdaq`, `threshold_nyse`, `threshold_otc`, `prices_stooq`,
`prices_alphavantage`, `prices_polygon`, `prices_manual` of `edgar-and-finra-fetchers.md` §2. What exists at the end: short interest by settlement with
revisions kept, FINRA's daily off-exchange short volume with its caveat on every screen, the SEC's fails, the three threshold lists, prices, typed borrow
observations, and the short-side page with one chart that aligns them all against the price and the events.

Schema (db/011, never modified): `short_interest`, `si_short_interest_series()`, `si_short_vs_float()`, `daily_short_volume`, `si_daily_short_volume_series()`,
`fails_to_deliver`, `si_fails_series()`, `threshold_listings`, `si_threshold_status()`, `borrow_observations`, `prices`, `si_price_on()`, `si_short_side_overlay()`,
`form_sho_aggregates`, `securities_lending_events` (empty; Extended); `mcp_short_interest`, `mcp_daily_short_volume`, `mcp_fails_to_deliver`, `mcp_threshold_listings`,
`mcp_borrow_observations`, `mcp_prices` (db/016).

## Divergences from the design
1. **FINRA's Query API dataset name and its credential are confirmed on the first live call** (`edgar-and-finra-fetchers.md` §2); until then the fixture is the
documented shape. 2. **A new security back-fills** 60 days of daily volume, a year of fails, and the full price history in its first pulls. 3. **A price upload**
(`prices_csv`) rides slice 2's pipeline for a ticker no provider serves.

## Who may do what
borrow_observation_create: `borrow.write` · borrow_observation_delete: `borrow.write` (pauses, `deletion`) · every screen: `see`.

## Screens
`short` `/short?security=&from=&to=` (**the one chart**: the close and volume above; below, aligned by date, short interest as % of float at settlement dates (points), the
5-day off-exchange ratio (a line), fails (bars), threshold days (shaded bands), borrow fee (points); the events as markers across both panels; under it the source lines
and **FINRA's caveat**; at 375 the panels stack and the window defaults to 90 days) · `short-interest` (the table: settlement, published, short, change, days to cover,
% float, % outstanding, the revision chip with the earlier observation on hover) · `short-volume` `?market=` (the table and a line of the ratio with the 5- and 20-day means;
the caveat in a callout at the top) · `short-fails` (the table and bars with the 60-day median line) · `short-threshold` (the runs: list, from, to, days) · `short-borrow`
(the observations, "indicative" on a quote) · `borrow-add` (the form).

## Files (exactly these)
`html/short/{index,interest,volume,fails,threshold,borrow}.php`, `html/short/borrow/{form,save,delete}.php` (`/short/borrow/new` → `form.php`); `app/features/short/{queries,present,write}.php`;
`app/si/fetch/{finra_short_interest,finra_daily_short_volume,sec_ftd,threshold,prices}.php`; `app/views/short/{index,interest,volume,fails,threshold,borrow,borrow-form}.php` +
`partials/{overlay-chart,caveat,revision-chip}.php`; the worker's `fetch` pass for the ten; `tests/phase3/slice6/{run.sh,lib.php,finra.php,ftd.php,threshold.php,prices.php,overlay.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`short_interest(PDO, int $security, string $from, string $to): array` · `short_vs_float(PDO, int $security, ?string $asOf): ?array` · `short_volume(PDO, int $security, string $market, string $from, string $to): array` ·
`fails(PDO, int $security, string $from, string $to): array` · `threshold_runs(PDO, int $security): array` · `borrow(PDO, int $security, ?string $from, ?string $to): array` ·
`overlay(PDO, int $security, string $from, string $to): array{points, events}` · writes: `borrow_observation_create(PDO, array $f, int $by): int` · `borrow_observation_delete(PDO, int $id)`

## MCP tools this slice makes real
`short_interest_series`, `short_vs_float`, `daily_short_volume_series`, `fails_series`, `threshold_status`, `threshold_history`, `borrow_series`, `short_side_overlay`.

## Manifest rows claimed
Screens (7): `short`, `short-interest`, `short-volume`, `short-fails`, `short-threshold`, `short-borrow`, `borrow-add`
Actions (2): `borrow_observation_create`, `borrow_observation_delete`

## Activity log events
`short.load|revise` (`{security_id, settlement_date, short_shares}`), `volume.load` (`{days}`), `ftd.load` (`{rows, half}`), `threshold.list|delist` (`{security_id, list, list_date}`),
`price.load` (`{days}`), `borrow.record|delete`, `source.pull_*`.

## Status vocabulary
The revision chip `text-bg-warning` "revised"; the threshold band `--bs-danger-bg-subtle`; "indicative" `text-bg-light`; the caveat callout `alert alert-secondary` with
`bi-info-circle`. Ids: `short-chart`, `short-caveat`, `si-table`, `volume-table`, `fails-table`, `threshold-runs`, `borrow-table`, `borrow-form`.

## Out of scope for this slice
The rules reading these series (7); a licensed borrow feed, Form SHO, SLATE (Extended).

## Proof (`tests/phase3/slice6/run.sh`: scratch `si_dev36`; the stub server; Playwright; the registry `--check`)
`edgar-and-finra-fetchers.md` §5 for the ten connectors on the fixtures (`short_interest_api.json`, `CNMSshvol.txt`, `cnsfails.txt` zipped, `nasdaqth.txt`, the NYSE JSON, the
OTC file, `prices_stooq.csv`): the series match the Phase 0 proof's values; a revision appends a row and the chip shows the earlier figure; a holiday's missing volume file is not a
failure; the overlay renders every panel and the caveat at 375 and 1280; a borrow observation recorded and shown "indicative"; a Viewer reads everything and records nothing; JSON mode;
the live pulls of §5 (one FINRA query, one daily file, one fails half, three threshold files, one stooq series).

## Open questions
None.
