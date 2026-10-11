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

## Built (2026-10-11) — what the build found and decided within this spec
1. **FINRA's short interest is the Query API's `consolidatedShortInterest`** (group otcMarket, every market, keyless — the first live call, divergence 1):
   `equityShortInterest` is the OTC-only dataset with other field names. One POST a pull with the tracked tickers as a domain filter returns every settlement
   FINRA keeps (Bitmine: 76 since 2022), so a missed publication is caught by the next; db/023 corrects the seeded URL. **A revision is a new row** when any
   figure differs from the date's latest observation (`revision_flag` FINRA's flag or an earlier observation); `short.load` / `short.revise` with the figures;
   `published_on` only when FINRA sends a date (the screen shows the day first read). Calendar: market days from 17:00 in the window of the sixth to ninth
   market day after each settlement (the 15th or before, the month's last market day), and any time for a source never read.
2. **FINRA's daily files are read a market day a file** (`<MARKET>shvolYYYYMMDD.txt`, CNMS and FORF): every day after the last file read (`done`), and the last
   `backfill_days` (60) for a security never loaded; a 404 is a day without a file (a holiday, or before FINRA posts at about 18:00), never a failure. **FINRA
   writes fractional volumes since 2026** (3,493,384.390661): kept to the whole share. `cdn.finra.org` is an S3 bucket whose robots.txt answers S3's 403 for a
   missing object — RFC 9309 reads a 4xx robots answer as no rules; it joins `SiHttp::API_HOSTS` (found by the live pull).
3. **The SEC's fails are its half-month zips listed on the fails page** (`listing_url`, like the 13F data sets; `loaded`, `backfill_halves` 24 — a year), and
   **each file ends with its own trailer** — the record count and the total quantity: a file that does not tie is refused in words and nothing written. The
   fails host is the SEC's, so `sec_ftd` sends the SEC's declared user-agent and is refused before any request without the contact email, as EDGAR is.
4. **The threshold lists: a DEFECT of db/011 fixed in db/023.** `si_threshold_status()` counted consecutive days among the dates that have a ROW, but a row is
   written only for a tracked security on a list — a day off every list left no date, and two runs merged (the proof: five days where three are true). Every
   list file read is now a `threshold_list_days` row (its entry count, tracked or not), the calendar per list; `si_threshold_runs()` answers every run. Nasdaq's
   file a day; NYSE's three markets a request each (NYSE answers 406 to `Accept: text/plain` alone — `*/*` added); FINRA's OTC list is the Query API's
   `thresholdList` (Reg SHO's list, or Rule 4320's for a non-reporting OTC security — `flag` 'Y' or 'R4320'); the days after the last read, from 22:00 the
   same day; an empty Nasdaq or OTC answer is not published yet, an empty NYSE market a real day. `threshold.list` / `threshold.delist` per change.
5. **stooq refuses machines** (found live 2026-10-10): its robots.txt disallows every agent but Google's and Bing's, and its pages answer a JavaScript
   proof-of-work. Under the policy no machine reads it: db/023 pauses the source with that reason, the client reads the proof-of-work page as a bot wall
   (`bot_wall:js_challenge`), and the live check proves the CSV is never asked. **Prices now come from Alpha Vantage or Polygon with a key** (seeded, paused
   "Needs a key"; Alpha Vantage's free plan answers `compact` — 100 days — and refuses `full` as premium; Polygon's key as a bearer header) **or an upload**
   (`prices_csv` on slice 2's pipeline under the source `prices_manual`; tied when every line reads). D13 named stooq the keyless default — **the owner's
   decision: which keyed provider, or uploads.**
6. **`app/si/fetch/credentials.php` opens a source's credential** (secretbox under SI_SECRETS_KEY; slice 10 builds the form on `si_credential_seal()`); the
   keys never reach a pull's policy, an error, the trail or the log (proven).
7. **The client's Imperva rule was too broad** (Inventory's, ported verbatim): `_Incapsula_Resource` is injected into EVERY page of an Imperva site, so
   Nasdaq's ordinary "Page Not Available" (its robots.txt) read as a wall; now a challenge is "Pardon Our Interruption", an incident id, or a page under 8 KB
   carrying only the script. **Inventory's `app/sources/http.php` has the same line.** Live, Nasdaq served the list file to the first probe, then
   challenged this host after a handful of requests (the client answered blocked, backed off an hour, told the admin) and cleared within the hour.
9. **Live, 2026-10-11, for Bitmine (BMNR, CUSIP 09175A206)**: FINRA's 76 settlements (the latest Sep 30: 34,721,295 short, 1.00 day to cover, NYSE); the
   CNMS file of Oct 9 (3,493,384 short of 8,808,583 off-exchange); the fails half-month 2026-09a tied to its trailer (three days, the most 23,875); the
   lists of Oct 9 — Nasdaq 72 securities, NYSE 2, NYSE American 1, NYSE Arca 17, FINRA OTC 17, Bitmine on none; stooq blocked without asking the CSV.
   **Tests**: `tests/phase3/slice6/run.sh` — finra 21, ftd 11, threshold 15, prices 16, overlay 28, json 7, browser 110 — under php -S and Apache, then the
   live pulls 13. The schema proof's source counts follow db/023 (eighteen sources, fourteen due); Phase 2's HTMX placeholder check moved to `/signals`.
8. **The one chart is one SVG of two panels on one date axis** (`svg_short_overlay()` in `app/si/svg.php`), each lower series on its own scale named in the
   legend; the window 180 days, 90 on a phone (the page asks once by its width). `app/features/short/screen.php` is the screens' prelude (as slice 5's).
10. **Alpha Vantage became the default price source (the owner, 2026-10-11; db/025)** — paused until the admin sets its key through the credential door
   built ahead of slice 10 (`/admin/sources/{id}/credential`; the key's arrival resumes the source). The connector counts the free plan's 25 requests a
   market day (`settings.used`) and defers the rest (`settings.deferred`; the pull ok, no back-off), treats the host's "rate limit … per day" the same way,
   skips an unknown ticker (`settings.unknown_tickers`), answers blocked on a refused key, and sends the documented URL exactly — the public `demo` key
   answers only that (live: IBM's 6,776 days with `demo`; Bitmine refused in the host's words). `tests/phase3/slice6/alphavantage.php` 26 checks;
   `run.sh live` runs the live pulls alone.
