# Build spec: the fetchers — EDGAR, FINRA, the SEC, the exchanges, DTCC, prices (design §0.3, §6.5; D9, D10, D13) — read before slices 1, 4, 5, 6

The public record is read by the worker through Inventory's `sources` model (db/009): one row per fetcher, one `source_pulls` row per
pull, the policy written into the pull. This spec fixes the HTTP client every connector shares, each connector's request and parse, the
calendar each one keeps, and how they are proven — **against the fixtures first (`tests/fixtures/samples/`, the CSV/TSV fixtures), then one
live pull each against the real host under the policy.** Nothing is fetched that the host did not publish for machines; no portal, no
login, no vendor page; the three issuer files are never fetched.

## 1. The client (`app/si/fetch/http.php`, one place — Inventory's client ported, slice 1)
- `si_http_get(PDO $pdo, array $source, string $url, array $opts = []): array{status, headers, body, bytes, ms}` — `curl` with: the
  **user-agent** — for an `edgar_*` connector `si_edgar_user_agent()` (`"<business name> <contact email>"`); when NULL the pull is refused
  before any request (`status = 'failed'`, error "EDGAR needs the business name and a contact email in Settings (the SEC's rule)") and the
  admin is notified once a day; for the others the source's `user_agent` or `"<business name> Shareholder Intelligence (+contact email)"`;
  **the rate** — a token bucket per host in the worker's process at `sources.rate_per_second` (EDGAR 2/s by default, never above 10: the
  SEC's limit; FINRA, the SEC's data pages, the exchanges, DTCC and stooq 1/s); `Accept-Encoding: gzip, deflate`; `If-Modified-Since` /
  `If-None-Match` from the last pull's `policy.last_modified` / `policy.etag` for the same URL (a 304 counts as ok and changes nothing);
  timeouts 10 s connect, 60 s total; **no cookies, no redirects to another host, TLS verified**.
- **Refusals back off** (`si_pull_finish`): a 403 or 429 → `blocked`, an hour, the admin notified ("EDGAR answered 403 — the user-agent or
  the rate"); a 5xx or a timeout → `failed`, the ladder 5, 15, 60, 240 minutes; four failures in a row notify the admin.
- **robots**: checked once a day per host (`robots_state`, `robots_checked_at`); a disallowed path is never requested (`blocked`). The SEC's
  `data.sec.gov` and `efts.sec.gov` and FINRA's `api.finra.org` are APIs; robots applies to `www.sec.gov` files, `nasdaqtrader.com`,
  `nyse.com`, `dtcc.com`, `stooq.com`.
- **Every pull is logged** (`source.pull_start`, `source.pull_done {seen, new, changed, requests, bytes}` or `pull_fail` / `pull_blocked`
  with the 200-character error) and its `policy` records `{user_agent, rate, robots, last_modified, etag, urls: n}`.

## 2. The connectors (`app/si/fetch/<connector>.php`, each `fetch_<connector>(PDO, array $source, DateTimeImmutable $now): array{status, seen, new, changed, requests, bytes, error?}`)
| Connector | Request | Parse into | Calendar | Slice |
|---|---|---|---|---|
| `dtcc_participants` | the participant listing `.xlsx` linked from `https://www.dtcc.com/support/dtc-directories` (the page read for the current file's link; the alphabetical one, else the numerical; never the settling-bank, pledgee or DRS registers — built 2026-10-09) | `si_participant_upsert(number, name, kind, file_date)` per row; renames into aliases; a new number classed from its name | monthly (the file is dated) | 1 |
| `edgar_submissions` | `https://data.sec.gov/submissions/CIK##########.json` per issuer with a CIK (and `…-submissions-001.json` pages when `filings.files[]` names older ones, once) | `issuers.edgar_entity` (the scalars, addresses, former names), `edgar_synced_at`; `filings` upserted by accession from `filings.recent` (the column arrays zipped); the events trigger makes the watched forms' events | 06:00 and 18:30 market time on market days; on `issuer_edgar_sync` | 1 (the entity), 4 (the index) |
| `edgar_companyfacts` | `https://data.sec.gov/api/xbrl/companyfacts/CIK##########.json` | `share_counts` kind `outstanding` from `dei:EntityCommonStockSharesOutstanding` (each `end` → `as_of`, `frame` → `xbrl_frame`, the `accn` → `filing_id`), `public_float_value` from `dei:EntityPublicFloat` — `source = 'filing'`, never overwriting a person's row | daily, after the index | 4 |
| `edgar_fulltext` | `https://efts.sec.gov/LATEST/search-index?q="<term>"&forms=<forms>&startdt=<since>&enddt=<today>` per watch term, for the issuer's name and CIK (`ciks=`), and for **others' filings naming the issuer** (`q="<issuer name>"` without `ciks`) | `watch_hits` (term, filing, snippet from `_source` highlights); a filing not yet indexed is inserted (`about_us = true` when the filer is not the issuer); a dilution term proposes a `dilution_instruments` row (`status = proposed`, `proposed_by = 'watch'`) | hourly on market days, 07:00–20:00 market time | 4 |
| filing text | the primary document's URL (`filings.url`) | `filing_text` (HTML stripped to text with paragraphs kept; an `.xml` primary document as text; a PDF through `pdftotext` when present), `fetched_text_at`, `text_sha256`, `char_count` | the watched forms on arrival (the worker's `fetch` pass after the index); any filing on `filing_fetch_text` | 4 |
| `edgar_13f_dataset` | the quarterly zip from `https://www.sec.gov/files/structureddata/data/form-13f-data-sets/` (the listing read for the newest file) | the zip's `INFOTABLE.tsv` streamed, the rows whose `CUSIP` is a tracked security's kept; `SUBMISSION.tsv` + `COVERPAGE.tsv` for the filer's CIK, name and `PERIODOFREPORT`; `si_institution_upsert`; `institutional_holdings` (VALUE in dollars from 2023-01-03, thousands before — scaled); amendments by `ISAMENDMENT` | the SEC's quarterly publication (the listing checked daily until a new file appears) | 5 |
| `edgar_13dg` | the full-text search for `forms=SC 13D,SC 13D/A,SC 13G,SC 13G/A` naming a tracked CUSIP or the issuer's name; each hit's `primary_doc.xml` (XML since 2024-12-18; an older filing's HTML cover parsed best-effort, `filer_type` NULL) | `si_institution_upsert(filer)`; `beneficial_ownership` (the cover's powers, percent, shares, item 4, the group); the filing `about_us`; the structured mark `structured_at` | with the index (twice a market day) | 5 |
| `edgar_ownership` | Forms 3/4/5 of the issuer from the index; each `form4.xml` / `primary_doc.xml` | `insider_transactions` (non-derivative and derivative tables, `row_no` in order; codes, shares, price, A/D, owned after, D/I, footnotes, `is_10b5_1` from the footnote or the 2023 checkbox) — the trigger marks a matched holder an insider | with the index | 5 |
| `edgar_144` | Form 144 of the issuer from the index (and `about_us` by the full-text search); `primary_doc.xml` | `form144_notices` | with the index | 5 |
| `finra_short_interest` | FINRA's Query API (`https://api.finra.org/data/group/otcMarket/name/equityShortInterest` — **the dataset's exact name and whether it needs FINRA's free developer credential are confirmed on slice 6's first live call**; a credential goes in `source_credentials`), filtered by `symbolCode` for each tracked ticker | `short_interest` — **a new row whenever the figures differ from the latest observation** (a revision), `published_on`, `revision_flag`; the 15th and month-end settlements | the publication days (the seventh business day after each settlement — the calendar computed from FINRA's schedule) and the day after | 6 |
| `finra_daily_short_volume` | `https://cdn.finra.org/equity/regsho/daily/CNMSshvolYYYYMMDD.txt` and `FORFshvol…` | `daily_short_volume` (the tracked symbols' lines), absent on a holiday (404 is not a failure on a non-market day) | 18:30 market time on market days, and a back-fill of 60 days on a new security | 6 |
| `sec_ftd` | `https://www.sec.gov/files/data/fails-deliver-data/cnsfailsYYYYMM[a|b].zip` | `fails_to_deliver` by CUSIP (the tracked securities' lines), `file_half` | the 1st and the 16th + 2 days; a back-fill of a year on a new security | 6 |
| `threshold_nasdaq` / `threshold_nyse` / `threshold_otc` | Nasdaq `https://www.nasdaqtrader.com/dynamic/symdir/regsho/nasdaqthYYYYMMDD.txt`; NYSE `https://www.nyse.com/api/regulatory/threshold-securities/download?selectedDate=YYYY-MM-DD&market=<m>`; FINRA's OTC threshold download | `threshold_listings` for the tracked symbols; a day with no file is no row | 23:00 market time on market days | 6 |
| `prices_stooq` | `https://stooq.com/q/d/l/?s=<ticker>.us&i=d` (the full daily CSV; an OTC ticker `.us` too) | `prices` upserted by date | 18:00 market time on market days | 6 |
| `prices_alphavantage` / `prices_polygon` | the provider's daily series with the key from `source_credentials` | `prices` | the same | 6 |
| `prices_manual` | an upload (`prices_csv`: date, open, high, low, close, volume) | `prices` | — | 6 |

**The worker's `fetch` pass** (`bin/worker.php`, every five minutes): `si_sources_due()`; for each, the connector's own calendar decides
whether today and this hour are its (a source due but off-calendar is skipped without a pull row); one pull per due source per pass; the
issuers a source serves are `settings.issuers` (empty = every issuer the application tracks) — **a restricted issuer is fetched like any
other** (the wall is on reading, not on the public record). After a pull that changed anything: `si_rules_run` for the securities it touched.

## 3. Idempotency and corrections
Filings by `(issuer_id, accession_number)`; 13F rows by the unique index (`institution`, `security`, `period`, `accession`, `put_call`);
13D/G by `(institution, security, filed_on, schedule, amendment_no)`; Form 4 by `(filing, is_derivative, row_no)`; Form 144 by `filing`;
short interest **appends** a new observation only when a figure changed; daily volume, fails, threshold and prices upsert by their
primary keys. A re-run of any pull changes nothing (proven).

## 4. Files
`app/si/fetch/{http,calendar,dtcc_participants,edgar_submissions,edgar_companyfacts,edgar_fulltext,filing_text,edgar_13f_dataset,edgar_13dg,edgar_ownership,edgar_144,finra_short_interest,finra_daily_short_volume,sec_ftd,threshold,prices}.php`
(slices 1, 4, 5, 6 build their rows of the table); `app/si/fetch/html_to_text.php`; `app/si/fetch/xml.php` (the SEC's namespaces stripped; XXE off:
`LIBXML_NONET`, no entity loader); `tests/fetch/stub_server.php` (php -S on 8905: serves `tests/fixtures/samples/` and the CSV/TSV fixtures at the
connectors' paths, answers 304 to a matching `If-None-Match`, 403 on a request without the user-agent, 429 when over the rate); `tests/fetch/run.sh`.

## 5. Proof (`tests/fetch/run.sh`, run by slices 1, 4, 5, 6 for their connectors)
Against the stub: each connector's pull parses its sample into the expected rows (the counts and a field each); the second pull changes
nothing (304 or the same rows); a pull without the EDGAR user-agent set is refused before any request; a 403 backs off an hour, a 429 too, a
5xx climbs the ladder; the bucket never sends a host more than its rate (the stub counts); a disallowed robots path is never requested; a
short-interest revision appends a row; the XML parser refuses an external entity; the full-text hit for "equity purchase agreement" proposes
an ELOC; `about_us` is set on another filer's filing. **Then one live pull each** (the owner's EDGAR user-agent in the scratch settings,
`Kinetic Seas claude@kineticseas.com`, the owner's answer (b)): the submissions of CIK 0001829311 (the reference implementation's), its
companyfacts, one full-text query, the newest 13F data set's listing (not the download — its size), one FINRA short-interest query, one daily
short-volume file, one fails half-month, the three threshold files, DTCC's directory page and one stooq series — each answering 200 (or 304)
within the policy, the counts printed, nothing kept but the scratch database.
