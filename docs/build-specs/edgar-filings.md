# Build spec: EDGAR — the index, the text, the watch and the dilution instruments (slice 4)

Builds the EDGAR rows of `edgar-and-finra-fetchers.md` §2 (`edgar_submissions`' index half, `edgar_companyfacts`, `edgar_fulltext`, filing text). What
exists at the end: every issuer's filing index kept current twice a market day; the outstanding share count from every cover; the text of the watched forms
fetched and searchable; the full-text watch finding dilution terms and others' filings about the issuer; dilution instruments proposed by the watch and confirmed
by a person; the filings, watch and dilution screens; `fetch_now` for any source.

Schema (db/009, 006, 016, 018, never modified): `sources`, `source_pulls`, `si_sources_due()`, `si_pull_finish()`, `filings`, `filing_text`, `si_watched_form()`,
`si_filings_search()`, `events` (the `filings_event` trigger), `dilution_instruments`, `watch_hits` (db/009); `share_counts` (db/006); `mcp_filings`,
`mcp_filing_text`, `mcp_events`, `mcp_dilution_instruments`, `mcp_watch_hits` (db/016); `si_share_filings_index()`, `si_share_filing_text()` (db/018).

## Divergences from the design
1. **`fetch_now` is built here** (its file is `/admin/sources/pull.php`); the sources' admin screens are slice 10's. 2. **A watch hit is confirmed into an instrument by a
person** (`watch_hit_confirm`, `people`); an agent records what a filing says as an event (`filing_event_make`) and leaves the instrument to a person. 3. **A filing's text
over 1 MB is read by window** (`filing_read` offsets); the whole text is kept up to 20 MB and the share sends up to 5 MB.

## Who may do what
filing_fetch_text / filing_event_make / dilution_save: `events.write` + `see` (`dilution_save` is `people`) · watch_hit_confirm / watch_hit_dismiss: `events.write` (`people`) ·
dilution_delete: `events.write` (pauses, `deletion`) · fetch_now: `uploads.write` (within the source's policy; a blocked or backed-off source answers "backed off until …").

## Screens
`filing-list` `/filings?form=&from=&to=&about_us=&q=` (a table — a ledger: date, form chip, items, description, text and structured chips, about-us mark; the form filter as chips
of the watched forms) · `filing-view` `/filings/{id}?q=` (the index row; the text in a reading column with the `q` hits highlighted and "next hit" links; for a 13D, a Form 4 or a 144 the
structured block — slice 5's rows, shown when present; the event it made; Fetch text, Make event) · `filings-search` `/filings/search?q=&form=` (the hits with `ts_headline`, form and date) ·
`watch-hits` `/filings/watch?status=` (cards: the term chip, the filing, the snippet, the proposed instrument; Confirm instrument (a full-page form prefilled), Dismiss) ·
`dilution-list` `/dilution?status=&kind=` (cards: kind, counterparty, facility and remaining with a bar, the price terms, **the floating-price flag** `text-bg-danger`, status) ·
`dilution-view` `/dilution/{id}` (the filing, the terms, remaining, notes, the signals naming it) · `dilution-add` / `dilution-edit` (the form; the filing by picker).

## Files (exactly these)
`html/filings/{index,view,search,watch,fetch-text,event-make}.php`, `html/filings/watch/{confirm,dismiss}.php`, `html/dilution/{index,view,form,save,delete}.php`,
`html/admin/sources/pull.php`; `app/features/filings/{queries,present,write}.php`, `app/features/dilution/{queries,present,write}.php`; `app/si/fetch/{edgar_submissions,edgar_companyfacts,edgar_fulltext,filing_text,html_to_text,xml}.php`
(the index half of `edgar_submissions`); `app/views/filings/{index,view,search,watch}.php` + `partials/{row,text,structured,hit}.php`, `app/views/dilution/{index,view,form}.php` + `partials/card.php`;
`app/pickers.php` gains `filing`, `instrument`; the worker's `fetch` pass for the four; `tests/phase3/slice4/{run.sh,lib.php,index.php,text.php,watch.php,dilution.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_filings(PDO, int $issuer, array $filters, int $page): array` · `find_filing(PDO, int $id): ?array` · `filing_text_window(PDO, int $id, int $offset, int $length, ?string $around): array` ·
`filings_search(PDO, int $issuer, string $q, ?string $form, int $page): array` · `find_watch_hits(PDO, int $issuer, ?string $status, int $page): array` · `find_dilution(PDO, int $issuer, array $filters): array` ·
`find_dilution_one(PDO, int $id): ?array` · writes: `filing_fetch_text(PDO, int $filing): array` · `filing_event_make(PDO, int $filing, array $f, int $by): int` · `watch_hit_confirm(PDO, int $hit, array $f, int $by): int` ·
`watch_hit_dismiss(PDO, int $hit, int $by)` · `dilution_save(PDO, ?int $id, array $f): int` · `dilution_delete(PDO, int $id)` · `fetch_now(PDO, int $source, ?int $issuer): array`

## MCP tools this slice makes real
`find_filings` (the resolver), `get_filing`, `filing_read`, `filings_search`, `watch_hits`, `dilution_instruments` (the resolver), `dilution_proposals`.

## Manifest rows claimed
Screens (8): `filing-list`, `filing-view`, `filings-search`, `watch-hits`, `dilution-list`, `dilution-view`, `dilution-add`, `dilution-edit`
Actions (7): `filing_fetch_text`, `filing_event_make`, `watch_hit_confirm`, `watch_hit_dismiss`, `dilution_save`, `dilution_delete`, `fetch_now`

## Activity log events
`filing.index` (`{new, changed}` per pull), `filing.fetch_text` (`{filing_id, chars}` — never the text), `filing.event_make`, `watch.hit|confirm|dismiss` (`{term, filing_id}`),
`dilution.propose|save|delete`, `share_count.save` (from companyfacts, `source filing`), `source.pull_now`, `source.pull_*`.

## Status vocabulary
Form chips (8-K, 10-K, 10-Q, S-1, S-3, 424B, 13D, 13G, 4, 144, DEF 14A, other); about-us `bi-box-arrow-in-down-left`; text fetched `bi-file-text`, structured `bi-diagram-3`; instrument status
(proposed `text-bg-warning`, active `text-bg-primary`, exhausted/terminated `text-bg-secondary`); floating `text-bg-danger`. Ids: `filing-row-{id}`, `filing-text`, `filing-hit-{n}`, `watch-hit-{id}`, `dilution-card-{id}`.

## Out of scope for this slice
The ownership forms' parsing and screens (5 — the structured block reads their rows when present); the rules reading the instruments (7); the sources' admin (10).

## Proof (`tests/phase3/slice4/run.sh`: scratch `si_dev34`; the stub server; Playwright; the registry `--check`)
`edgar-and-finra-fetchers.md` §5 for the four connectors; the index pull makes the filings and the watched forms' events; companyfacts writes the outstanding count with its frame and
filing; a second pull changes nothing; the 8-K's text is fetched, searched and highlighted; the watch proposes the ELOC, a person confirms it (floating), an agent's confirm is refused
(people) and its `filing_event_make` lands; `fetch_now` on a backed-off source answers when; a Viewer reads every screen; 375 and 1280; JSON mode; the live pulls of §5.

## Built (2026-10-10) — what the build found and decided within this spec
1. **A connector is `si_fetch_<connector>(PDO, array $source, SiHttp $client, array $ctx)`** (slice 1's shape, `si_fetchers()`), not `fetch_<connector>(PDO,
   source, now)`; the index half is `si_edgar_index_apply()` in `edgar_submissions.php`.
2. **The archives' base is a setting** (`archives_url` on the `edgar_submissions` source, default `https://www.sec.gov/Archives/edgar/data/`) — the proofs
   point it at the stub; filings' `url` stays the SEC's.
3. **EDGAR's full-text search answers no highlight**: a hit is `<accession>:<file>`; its snippet is the term in THAT document's own words (one read under the
   policy — an 8-K's exhibit, not the 8-K), else in the filing's text when read, else the hit's form and names.
4. **"About us" is a filing that does not list the issuer's CIK** (a lawsuit, another company's 8-K): a 13D or a Form 4 lists it and is the index's; at most
   `about_limit` (50) new such hits a pull — each costs a document read (Bitmine draws dozens a quarter).
5. **robots.txt does not govern `data.sec.gov`, `efts.sec.gov` or `api.finra.org`** (the spec's §1): `efts.sec.gov` answers its robots.txt with a 403 JSON,
   which the client read as "disallow everything" — found by the live pull; `SiHttp::API_HOSTS`.
6. **A refused full-text query is noted and the others go on** (the pull partial); a refused later page ends that query's paging (EDGAR answered one 500
   on the live run).
7. **Text on arrival: up to `text_per_pull` (12) watched filings a pull, newest first**; a document EDGAR lacks keeps its error on the filing and is read
   again only on request (`filing_fetch_text`). An XML primary document reads as its elements' text (`transactionShares value: 5000`); an entity
   declaration is refused before parsing.
8. **companyfacts writes the PRIMARY security's counts**; of several facts for one date the latest filed wins (an amendment restates it); a person's count
   is another source and never touched.
9. **One instrument per filing and kind**: "equity line" and "equity purchase agreement" in one 8-K propose one ELOC. **The confirm form is `dilution-add`
   with `hit`** (`/dilution/new?hit=`), posting to `watch_hit_confirm`. **`dilution_delete` deletes** (the table has no tombstone).
10. **ts_headline drops a filing's tags itself**; the headline is escaped and its `<b>` markers become `<mark>`.
11. **"EDGAR is waiting for the contact email" is told to the keepers once a day**, whichever of the three EDGAR sources asked first — `si_notify`'s
    dedupe rides on the email outbox, which a keeper without email never gets.

Proof: `tests/phase3/slice4/run.sh` (each proof on a fresh scratch database, the stub on :8905) — the index 30, the text 20, the watch 19, dilution 15,
JSON 3, browser 58, under php -S and a real Apache; **the live pulls**: Bitmine's submissions (346 filings to 2026-10-07, 12 watched ones read as text),
its companyfacts (22 outstanding counts, the latest 603,226,394 shares as of 2026-07-09), one full-text term over 90 days (50 hits in others' filings
— the cap); slices 1–3, Phase 2, the schema proof, the kit and Phase 1 re-run green; the registry 42 screens and 42 actions built.

## Open questions
None.
