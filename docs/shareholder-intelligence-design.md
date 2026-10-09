# Shareholder Intelligence — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-09 · an application for a **publicly traded company** (and for an advisory firm that serves several of them) that
**tracks who holds its shares and how that changes, and looks for the shape of a short situation**: the weekly DTC
**Security Position Report** (every broker's and bank's position, every day), Broadridge's **NOBO list** (the retail holders
who let the company know them), the transfer agent's **register** (Cede & Co., DRS, certificates and plan holders), every
filing on **SEC EDGAR** that names an owner (13F, 13D/13G, Forms 3/4/5, 144) or dilutes (8-K 3.02, S-1/S-3/424B ATM and
equity-line terms), FINRA's **short interest**, daily short-sale volume, the SEC's **fails-to-deliver** and the Reg SHO
**threshold lists** — reconciled against each other and against the share count, charted against the price, and watched
by rules that raise **signals** a person judges. The owner's brief (2026-10-09): *a new application,
`maludb-os-shareholder-intelligence`, a tool for publicly traded companies to track shareholder movements and look for
potential short situations; a reference implementation in Angular 18 at `github.com/KineticGuides/KineticIntel2` for the
formats of DTC position reports, NOBO lists, OBO lists and transfer agent records; it must also get filings and reports from
SEC.gov.* Its own repository `/srv/apps/shareholder_intelligence` (origin `github.com/maludb/maludb-os-shareholder-intelligence`,
private — created 2026-10-09), served as `shareholders.<domain>`, fitted to `maludb-os-integration` 0.9.0 and
`htmx-php-builder` 0.9.0 (the record picker, the application switcher), exactly as HR, Projects, Help Desk, Spaces, Knowledge,
Inventory, ProcessCore, DocCloud and Sales CRM were. `/srv/apps/inventory` (sources read under a crawl policy, snapshots on
change, connectors against fixtures) is the nearest built exemplar for the fetchers; `/srv/apps/knowledge` (a file in, cut
deterministically, every row proven against a fixture) for the uploads; `/srv/apps/gl` (periods, statements, a tie-out) for
the reconciliation; `/srv/apps/consultant_tracking` and `/srv/apps/doccloud` for the kit.

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. Phase 0's first half is
> this document and the owner's answers (§13, recorded in §15); the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and
> `deploy/` complete it. Phase 1 adds `docs/shareholder-intelligence-mcp-tool-surface.md`, `docs/shareholder-intelligence-action-manifest.md`,
> `docs/build-specs/position-reports.md`, `docs/build-specs/holder-identity.md`, `docs/build-specs/edgar-and-finra-fetchers.md`,
> `docs/build-specs/signals.md` and the slice specs. **No feature PHP is written until the owner approves them together.**

## 0. The research — what the field does, what the owner already built, and what the estate gives today

### 0.1 The field (surveyed 2026-10-09; every claim checked against a live page or a primary file unless marked unverified)

**Who sells this today.** Stock surveillance and shareholder identification is sold as a **service with an analyst attached**,
priced for mid and large caps: **Q4 Inc** (a dedicated analyst, DTC settlement data, activist alerts, "Surveillance Agents"
in 2025; its one public case study is AMD), **Nasdaq IR Intelligence** (consultant-led; a Nasdaq rule filing valued the bundled
surveillance perk at roughly $51–56k a year and said it was offered to issuers with a market cap of $750M or more and "may not
be an appropriate fit" for low-liquidity names), **Broadridge Investor Insights** (it already sees beneficial positions as the
street's proxy agent — and it is the NOBO vendor), **Georgeson/Computershare**, **Alliance Advisors** (the one that serves
micro caps, on the CSE), **Morrow Sodali**, **Okapi** — advisory practices, proxy-event centric. The software analogues:
**Irwin** (an IR CRM that ingests the NOBO list to find retail and family-office holders beside 13F and a surveillance
partner — the nearest thing to this plan), **S&P Capital IQ** (ownership + surveillance + securities-finance short data, enterprise
priced), **Sequire/SRAX** (a small-cap investor-intelligence SaaS that added S3 short data in 2021; SRAX appears defunct in 2026 —
unverified — a vacated niche). **Short-data vendors**: **Ortex** (a daily short-interest estimate from securities-lending data,
cost to borrow, utilisation, days to cover; from about $39 a month, unverified), **S3 Partners** (real-time, via Bloomberg
since 2025; tens of thousands a year, unverified), **Fintel** (exchange short interest, FINRA off-exchange volume; about
$1,000 a year, unverified), **Breakout Point** (activist-short campaign tracking, €79–299 a month — the cheapest licensed
activist-short feed), **Diligent/Insightia Activist Shorts** (the campaign database), **Hazeltree** (a crowdedness score from
700 hedge funds). **Open source**: `edgartools` (MIT; parses 13F-HR into holdings with CUSIP, 13D/G, Forms 3/4/5; a
CUSIP→ticker map), `sec-edgar-downloader` (bulk download, enforces the User-Agent), `holdingsparser`; `sec-api.io` (commercial
13F/13D/13G datasets and a CUSIP→CIK API, $49–239 a month). **No open-source parser of a DTC Security Position Report or a
Broadridge NOBO list exists** — those files reach only the issuer and its agents; this is greenfield, and the owner's
reference implementation is the only code we have seen that reads them.

**The gap this application fills.** Everything above is either (a) an analyst retainer a small cap cannot afford, (b) a
short-data terminal that knows nothing of the issuer's own files, or (c) an IR CRM that treats the NOBO list as a mailing
list. Nothing puts **the issuer's own three files** (SPR, NOBO, register) beside **the public record** (EDGAR, FINRA, the SEC's
fails) on the issuer's own server, reconciles them, and watches them with rules the issuer sets — for the cost of the files
themselves. That is the product. The analyst the incumbents attach is the OS's **agent**.

**What is evidence and what is lore** (§6.6 builds the rules on this; the signal explains which it is): short interest over
float and **days to cover** predict negative returns (Hong et al., NBER w21166); **cost to borrow and utilisation** do (the
shorting premium, Drechsler); **activist short reports** move a stock −11% in three days and −14% in two months with half the
targets later bankrupt, delisted or suspended (Paugam et al.), with wide dispersion; **floating-price convertibles** ("death
spirals") −34% over a year (Hillion & Vermaelen, JFE 2004) — causality with short selling unresolved; ATM and equity-line
evidence is thin. **Contested or weak**: the **daily short-volume ratio** (FINRA and OTC Markets both warn it conflates
market-maker shorts, omits the offsetting buys, is off-exchange only and must never be compared with short interest);
**fails-to-deliver as manipulation** (Fotak, Raman & Yadav, JFE 2014: FTD spikes *followed* the 2008 failures; the GAO: abuse
"may occur") — a Rule 204 compliance flag, not proof; **social-media chatter** (moves small-cap volume; causality mixed);
**options open interest** (a sentiment heuristic, untested as a detector); **participant-concentration shifts** on the SPR
(shares migrating from the retail boxes — Schwab 0164, National Financial 0226, Robinhood 6769, Apex 0158 — toward the
prime and lending-rich boxes — Goldman 0005, Morgan Stanley 0050, BofA 0161/0551, Citadel 0395, Pershing 0443): **practitioner
lore the surveillance firms sell and nobody has published a validation of**. This application records every signal with its
evidence class, and the Auditor can test the lore against what happened — it may be the first to.

**The regulatory clock (confirmed from the SEC's order of 2025-12-03, Release 34-104303):** Rule 13f-2 / **Form SHO** (monthly
institutional gross short positions, published by the SEC per security in aggregate) is exempt until **2028-01-02** (first
filings February 2028); Rule 10c-1a securities-lending reporting (**SLATE**) reports from **2028-09-28**, public from
2029-03-29. Both are designed into §6.5 as sources that arrive later — the schema holds their shape, the fetcher is Extended.
The 13F threshold stays $100M. CAT is regulators-only; nothing of it reaches an issuer. Schedule 13D/13G have been **XML since
2024-12-18** (13D in 5 business days, 13G passive amendments in 2 after a 5% move), Forms 3/4/5 and 144 are XML, 13F-HR has a
structured information table and a quarterly bulk data set (seven tab-delimited tables; `INFOTABLE` carries CUSIP, value in
dollars since 2023, shares, put/call, discretion, voting authority).

### 0.2 The owner's reference implementation (KineticIntel2, Angular 18 + PHP, 2025) — what is kept

Read in full (2026-10-09). What it established, and what this plan takes from it:

- **The three files and their shapes.** (1) **The DTC position report** as a weekly grid: one row per participant number with
  the five daily positions (`monday`…`friday`) and the five dates (`d1`…`d5`); daily rows derived from it with the change
  against the previous day, the Friday-to-Monday change bridging weeks (`ksa_dtc_upload` → `ksa_daily_participant`). (2) **The
  NOBO list as Broadridge delivers it** (the fixture `NOBOLIST.xlsx`): row 1–2 a header — JOB NUMBER, CUSIP, ISSUER NAME,
  RECORD DATE (`MMDDYY`), SORT (D)escending/(Z)ip, NUMBER OF NOBOS — then a `SHARES` section of one row per account: shares,
  **seven lines of name and address** (the account registration is in the lines: "BRIAN POLAN / CHARLES SCHWAB & CO INC CUST /
  IRA CONTRIBUTORY / 5274 VILLA DANTE AVE / LAS VEGAS NV 89141"), a zip for sorting, the CUSIP; then `# OF NOBOS` totals; then
  a `CLIENTS` section — CUSIP, client (the bank or broker), number of NOBOs, shares — **the per-participant breakdown**.
  (3) **The transfer agent's register** as Securities Transfer Corporation exports it (`STCCommon.xlsx`, "Security Holder List —
  Position Detail"): sections per class ("Common", "Common 2", "Common 3"), columns Position ID, Position Type, Holder ID,
  Registration Name(s), Mailing Address, City, State, Country, Zip, Issue Date, # of Securities, Cost Basis per Security,
  Description of Restriction, % Owned, Employee, Officer/Dir., US Citizen; a control block (company id, first and last row,
  share type, record date) typed into the first cells by hand. **Kept verbatim as the Phase 0 fixtures** (anonymised, §14).
- **The entities**: company → securities; participants (number, name) with per-company totals (DTC shares, NOBO shares,
  NOBO holder count, the OBO remainder = DTC − NOBO, first and last date seen); shareholders matched **by exact name on line 1**
  with address lines split by "does line 2 contain a digit"; daily share series per shareholder with the price of the day;
  activity rows NEW / TA-PURCHASED / TA-SOLD / LOST; snapshots over weekly, 30, 60, 90-day and year-to-date windows (buyers,
  sellers, new, lost, participants in and out); EDGAR submissions JSON into `edgar_entity`, `edgar_address`,
  `edgar_former_name`, `edgar_filing`; Alpha Vantage daily prices. The screens: participants, shareholders, analytics (static
  positions, active positions, buyers, sellers, new and lost positions, active participants, participant increases and
  decreases, new and lost participants), insights, documents, filings, the transfer agent, companies, company setup.
- **What this plan changes**: identity matching becomes a **normalised holder key with a custodian parsed out of the lines**
  and a proposal-and-accept merge (exact name on line 1 made "Brian Polan" two holders — a TOD account and a Schwab IRA — and
  will make "ROBERT SMITH" one); every derived series becomes a **SQL function over the positions**, never a stored snapshot
  table per window; the DTC grid becomes **one row per participant per date** with the file kept; the company becomes an
  **issuer** with several securities, each a CUSIP; EDGAR goes beyond the index to the **structured ownership forms**; the
  short-side data is new; and the **signals** are new. Nothing of the Angular UI is kept — the OS's React is the kernel's,
  the application's UI is HTMX + PHP + Bootstrap 5.3 as every sibling's.

### 0.3 The data an issuer can obtain — the inventory, with what it costs and what we could not verify

| Source | What it is | How it arrives | Cadence | Cost | Verified |
|---|---|---|---|---|---|
| **DTC Security Position Report (SPR)** | Every DTC participant's position in a CUSIP on a date, with the participant's contact details | A spreadsheet, the SPR web browser or a CCF file from DTC Issuer Services; the issuer, its trustee or an authorised agent (transfer agent, solicitor) subscribes — **an upload here** | weekly (five daily closes), monthly, dividend/record date, special request, daily (since 2011) | the 2009 schedule (the last public one): weekly $1,950/yr the first issue + $575 each more; monthly $450 + $225; record date $150; special $120 a date; current card not public | the report's existence, kinds and 2009 fees (SEC 34-59618, 34-65721); **the column headers are not public** (the user guide is login-gated) — the owner's real file is the fixture |
| **NOBO list** (Broadridge) | Name, mailing address and shares of every non-objecting beneficial owner at a record date; no email, no phone | The Broadridge NOBO Request Form signed by an officer, record date ≥ 2 business days out, delivered ~5 business days after; electronic — **an upload here**; the same request can add the **Bank/Broker listing**, Geographic, Share Range, Voted/Unvoted | per request (a proxy season, a campaign, a quarter) | $0.065 a name (NYSE 451.92 / FINRA 2251.01) + the agent's $0.10 the first 10,000, $0.05 to 100,000, $0.04 above, $100 minimum | the rule, the form, the fees (FINRA RN 14-03); the layout from the owner's fixture |
| **OBO** | Objecting beneficial owners — **cannot be identified**; only the aggregate per participant is inferable: the SPR position minus the NOBO shares at that participant | derived | — | — | the 2010 proxy-plumbing concept release; nothing changed |
| **Transfer agent register** | The record holders: **Cede & Co.** (one line = everything at DTC), DRS/book-entry, certificated, plan (DSPP/DRIP) positions, restrictions, insider flags | An export from Computershare, Equiniti (now Computershare in the US), Continental, VStock, Pacific, STC — **an upload with a saved column mapping** | on request, a record date | the agent's fee | the concept and GameStop's 10-K as a worked example (66.2M registered, 177,522 holders of record); **layouts not public** — STC's from the owner's fixture, the others by mapping |
| **DTC participant directory** | Participant number → name; alphabetical and numerical, settling banks, pledgees, DRS report | **Fetched** monthly from `dtcc.com/support/dtc-directories` (a public `.xlsx`) | monthly | free | parsed 2026-10-09: Goldman 0005, MS 0050/0015, IBKR 0017/0534, Vanguard 0062, Virtu 0063/0295, Wedbush 0103, Wells 0141, Apex 0158, **BofA Securities 0161** (safekeeping 5198, prime 0551), Merrill 0671, Schwab 0164, JPMS 0187/0352, UBS 0221, National Financial 0226, Barclays 0229, Citadel Clearing 0395, Citadel Inst. 2963, Pershing 0443, StoneX 0750, DriveWealth 2402, Jane Street 3492/2820, Clear Street 9132, **Robinhood 6769** (lending 3691, custody 4031), Computershare 2415/2586/2330 |
| **SEC EDGAR** | The issuer's filing index and documents; the structured ownership forms (13F-HR + the quarterly data sets, SC 13D/13G XML, Forms 3/4/5 XML, Form 144 XML); the XBRL facts (`dei:EntityCommonStockSharesOutstanding` from every 10-K/10-Q cover); full-text search for ATM, equity line, convertible and warrant terms | **Fetched**: `data.sec.gov/submissions/CIK##########.json`, `/api/xbrl/companyfacts/`, `efts.sec.gov/LATEST/search-index` (community-documented, no official doc), the quarterly 13F data-set zips, per-filing XML; `company_tickers.json` for ticker→CIK; the quarterly 13(f) list for CUSIP→issuer name; CUSIP→CIK only through 13F holdings | every market day (the index), quarterly (13F sets) | free; **10 requests a second, a declared `User-Agent "Company Name admin@domain"`** or a 403 | the APIs, the specs (13F v2.0 Sept 2026, 13D/G v2.3 Apr 2026, ownership v5.5, 144 v2.0, SHO v1.2 dormant, N-PORT v1.13), the data-set tables and columns (the SEC's readme) |
| **FINRA short interest** | Per security at the 15th and month-end settlement: short, previous, change, average daily volume, days to cover, a revision flag | **Fetched** from the FINRA Query API (`developer.finra.org`, dataset `equity_short_interest`), five rolling years | twice a month, **published the 7th business day after settlement** | free (a developer account) | the rule (4560), the schedule, the fields; the exact dataset name inferred |
| **FINRA daily short-sale volume** | Date, symbol, short volume, short-exempt volume, total volume, market — consolidated NMS (`CNMS`) and OTC (`FORF`) | **Fetched** from `cdn.finra.org/equity/regsho/daily/<file>shvolYYYYMMDD.txt` | every market day by 18:00 ET | free | the layout (FINRA's PDF); **off-exchange only** — the exchanges' own daily short volume is a paid feed |
| **SEC fails-to-deliver** | Settlement date, CUSIP, symbol, quantity failed, description, price; every non-zero fail since 2008 | **Fetched** from `sec.gov/files/data/fails-deliver-data/cnsfailsYYYYMM[a|b].zip` | twice a month (days 1–15 at month end; 16–end mid next month) | free | the layout |
| **Reg SHO threshold lists** | Securities with ≥10,000 shares failed and ≥0.5% of outstanding for five settlement days | **Fetched**: Nasdaq `nasdaqtrader.com/dynamic/symdir/regsho/nasdaqthYYYYMMDD.txt` (pipe-delimited), NYSE group `nyse.com/api/regulatory/threshold-securities/download?selectedDate=…&market=…`, OTC FINRA `otce.finra.org` + its download API | nightly | free | the URLs and formats |
| **Prices** | Daily OHLCV | **Fetched** through a connector: `stooq` (keyless daily CSV — the default), `alphavantage` (a key; the reference implementation's), `polygon` (a key), `manual` CSV | daily | free to paid | the connector interface is Inventory's |
| **Cost to borrow, utilisation, lending supply** | The securities-finance view | **Extended**: Ortex (an SDK with credits), S3, S&P — a licensed key in `source_credentials`; until then a person **types** the broker's indicative rate into `borrow_observations` | daily | $39/mo to tens of thousands | vendors' claims unverified |
| **Activist short campaigns** | Who published what about whom, when | a person records a report (the URL, the firm, the date, the allegations) — the day is an **event**; Breakout Point's feed **Extended** | as they happen | €79/mo+ | — |
| **Form SHO aggregates, SLATE** | Institutional gross short positions per security; securities-lending transactions | **Extended**: fetchers written when the SEC publishes (2028); the schema holds the shape now (§6.5) | monthly / daily | free | the order 34-104303 |

**The reconciliation the whole thing hangs on** (a fact, not lore): **shares outstanding (the cover of the latest 10-Q/10-K, or the
transfer agent's control total) = Cede & Co.'s registered position (= the SPR's total across participants on that date) +
DRS/book-entry + certificated + plan shares.** The NOBO shares at a participant ≤ that participant's SPR position; the
difference is the OBO remainder. An SPR total that exceeds Cede's position, or short interest that exceeds the float, is
not a signal — it is a **data defect** the application names before it computes anything.

### 0.4 What the estate gives (the survey of the siblings' `db/*.sql`, 2026-10-09)

| Need | The estate has | Used here as |
|---|---|---|
| The directory, sign-on, roles, tokens, the activity log, settings | the kit (Sales CRM `db/001`–`005`, DocCloud `001`–`005`): `members` (+ `is_agent`, `is_external`, `roles text[]`), `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state`, `<x>_rights/_roles/_role_rights`, `mcp_access_tokens` (+ Knowledge's rate columns), `key_usage`, `activity_log`, `activity_ingest_state`, the `app_settings` skeleton | **reuse** verbatim (`si_`) |
| A place data is read from, under a policy, with every pull recorded | **Inventory `sources`, `source_pulls`, `source_credentials`** (connector, settings jsonb, schedule, rate per second, user-agent, robots state, back-off, pause; a pull with counts, bytes, an error of 200 chars, the policy followed; a credential as libsodium ciphertext with last4) | **reuse** verbatim — the canonical "a place read from"; the connector list is ours (§6.5); `supplier_id` kept nullable with no FK (the Inventory `income_account_id` precedent); `role` widened to `public_data|market_data|licensed|manual` |
| A file uploaded, cut into rows, every row kept and proven | Knowledge `sources` (a document ingested — a **name collision** with Inventory's `sources`, recorded in the catalogue; we take Inventory's meaning), `source_text`; Spaces `imports` (file, status, counts, log) | **reuse** `imports` for every upload (`kind` widened: `spr`, `nobo`, `register`, `prices_csv`, `holders_csv`); the raw rows are this application's own tables (§6.2–6.3); a filing's text takes the shape of `source_text` as **`filing_text`** |
| A snapshot written on change, a series read from it | Inventory `offer_snapshots` (written when anything changed, and once a day as a heartbeat) | the shape of **`participant_positions`** is simpler — one row per participant per date is the file's own grain; the **snapshot-on-change** idea is taken for `short_interest` revisions and `borrow_observations` |
| Numbered periods, a statement, a tie-out | GL `fiscal_periods`, the statements, the cash tie-out (`bank_reconciliations`) | **the shape of the reconciliation** (§6.4): a dated **`share_reconciliations`** row with the four components, the difference, and `tied` or not |
| Files, notes, notifications, dispatches, worker passes, exports, search | GL `attachments`, `notes`; GL `notifications`/`_prefs`/`_outbox`; GL `agent_dispatches`; Spaces `worker_passes`, `exports`, `search_index` | **reuse** (`record_type` widened; `kind` ours) |
| Tags, custom fields, saved views | Help Desk `tags`/`record_tags` (DocCloud's shape), `custom_field_defs`, `saved_views` | **reuse** `tags` + `record_tags` (holders, institutions, participants, events); custom fields and saved views **not** in v1 |
| A company we deal with, a person at it | Sales CRM `accounts`/`contacts` (new canonical, planned 2026-10-09) | **not the same concept**: a **holder** is an account registration on a list — a name and address at a custodian, a fund's CIK, an insider — most of them never a relationship; `holders` and `institutions` are **new canonical** (§6.3). The IR relationship (a fund's analyst we call) is the CRM's — Extended, through K7 `sales_crm.contacts_index` |
| A party with a CIK, a filing | nothing in the estate reads EDGAR | **new canonical**: `filings`, `filing_text`, `institutional_holdings`, `beneficial_ownership`, `insider_transactions`, `form144_notices` (§6.5) — the next application that reads EDGAR (a compliance calendar, a board portal) copies these |
| A security, a price series | nothing (the GL's `accounts` are the chart) | **new canonical**: `issuers`, `securities`, `share_counts`, `prices` |

Free prefix: **`si_`** (taken: `hr_ projects_ hd_ sp_ kn_ inv_ gl_ ct_ ts_ dc_ pc_ crm_`). Ports after Sales CRM's 8191/8843/8844:
**8192 / 8845 / 8846**, scratch 8901–8907. The kernel's category check (db/175) admits no investor-relations category — **K34**
widens both checks with **`investor_relations`** and seeds the catalog row (§12); the business area is **Finance** (the GL's).

### 0.5 What MaluDB gives, and what this application asks of it

Shareholder Intelligence has **no memory database of its own**: it is a system of record whose questions are record questions
(positions, holders, filings, short data, signals — answered from PostgreSQL through the records MCP) and activity questions
("when did we first flag the Citadel build-up?", "who dismissed that signal and why?") answered from the tenant's one memory where
every `activity_log` row lands tagged `shareholder_intelligence`. **No model call happens inside the application**: every
signal is a deterministic rule over the data with its evidence attached; the words — the morning note, the board pack's
narrative, "what does this 13D mean" — are the agents' through the kernel. A knowledge base over the issuer's filings (ask the
10-K a question with citations) is **Knowledge's**, over the K7 shares `filings_index` and `filing_text` (§8).

## 1. What Shareholder Intelligence is, and is not

Shareholder Intelligence **owns the issuer's picture of its own shareholder base and of the short side of its stock**: who holds
the shares (by participant, by beneficial owner where the law lets the issuer know, by record holder, by institution and
insider as filed), how that changed day by day and record date by record date, whether the numbers tie to the share count,
what the public record says about borrowing, shorting and failing to deliver, what the company itself did that could move
any of it (financings, dilution instruments, record dates), and which combinations of those look like a situation a person
should judge — **so an IR officer, a CFO, a general counsel and the board see the same facts on the same day the data arrives,
and never buy a terminal to see them.**

- **The issuer's files are the core.** The SPR, the NOBO list and the register are **uploaded** (never fetched — DTC and
  Broadridge give them only to the issuer and its agents), parsed by a connector that knows the vendor's layout, kept whole,
  cut into rows that are proven against the file's own totals, and reconciled against each other and the share count.
- **The public record is fetched under a policy.** EDGAR, FINRA, the SEC's fails, the threshold lists, the DTC directory and
  prices are pulled by the worker through Inventory's source model: a declared user-agent, the rate the host asks for, every
  pull recorded, a block honoured, nothing scraped that was not published for machines.
- **Identity is proposed, then decided.** A holder on the NOBO list, on the register and in a Form 4 is the same person only
  when a person (or the Analyst agent, as a proposal) says so; the matcher offers, with its evidence; nothing merges itself.
- **Every number has a date and a source.** A position is on a date from a file; a short-interest figure is at a settlement
  date from FINRA with a revision flag; a share count is as of a cover date from a filing or as of a record date from the
  agent. A chart point is never an interpolation unless it says so.
- **Signals advise.** A rule over the data raises a signal with its severity, its evidence (the rows it saw), its **evidence
  class** (established / contested / lore) and the paragraph of §0.1 it rests on; a person acknowledges, dismisses or
  escalates it; nothing is sent, published or filed. The application takes no position, trades nothing, contacts nobody.
- **Reg FD is a wall, not a feature.** Nothing here reaches outside the business: no email to holders, no press, no post. A
  board pack is a document a person downloads or files to DocCloud; an outside advisor is an external member the admin
  restricts to an issuer.
- **One issuer, or several.** The business tracking itself has one issuer; an advisory firm has one per client, each
  restrictable to the people (and the client's own external members) who may see it.
- **Agents watch beside people.** The expert answers the command bar; the Surveillance Analyst writes the morning note on
  market days, proposes holder matches and signal dispositions, and drafts the weekly pack — a person approves it.
- **Nothing of identity, books or correspondence lives here.** The kernel signs people in; the GL keeps the books; the CRM
  keeps the IR relationships; DocCloud keeps the packs once filed; Knowledge answers questions over the filings' text.
- **Full MCP coverage** is a requirement (`maludb-os-integration`, `mcp-and-api.md`).
- **The easy path is three steps**: set up the issuer (ticker → CIK, the securities and their CUSIPs, the transfer agent),
  upload this week's SPR, and read the morning note tomorrow — the public record was fetched overnight.

Not in version 1: the securities-finance feeds (cost to borrow, utilisation — Extended through a licensed vendor's key;
typed observations until then), the exchanges' paid daily short-volume files, options data, social-media monitoring
(Extended, and only through a licensed API, never scraping), proxy tabulation and vote projection (Georgeson's job; the
NOBO's Voted/Unvoted report is an upload the schema admits), an IR CRM (the Sales CRM's), investor targeting lists for
purchase (never), N-PORT fund holdings (Extended), Form SHO and SLATE fetchers (when the SEC publishes, 2028), Canadian and
UK equivalents (Extended: SEDAR+, the TSX's NOBO equivalent), a model-written narrative inside the application (the agents'),
and any action that reaches outside the business.

## 2. Who uses it (the actors, in the OS's words)

"Agent" always means an OS AI agent. An **issuer** is a company whose shares are tracked; a **security** one of its classes
(a CUSIP); a **participant** a DTC member (a broker, bank, clearing firm) with a number; a **position** a participant's shares on
a date; a **holder** one registration on a NOBO list or the register (a person, an entity, a custodial account); an
**institution** a 13F filer or a 13D/13G filer, by CIK; an **insider** a Form 3/4/5 reporting person; a **filing** one EDGAR
document; a **record date** the date a list speaks of; a **signal** what a rule raised; an **event** something that happened
on a day (a filing, a financing, a short report, a record date, a note).

| Actor | Who | How they reach the application |
|---|---|---|
| **Viewer** | The board, the CFO's staff, an outside advisor, the advisory firm's client (an external member restricted to their issuer): dashboards, charts, filings, signals, the packs; **never an individual retail holder's name or address** | The launcher → `shareholders.<domain>`; the phone (375 px first: today's note, the signals, a chart); the command bar |
| **Analyst** (`user`) | The IR officer or the advisory firm's analyst: uploads the files, decides holder matches, records events and borrow observations, works the signals, runs the reports, sees every holder | The application; the command bar; the phone for the morning's signals |
| **IR Manager** | Owns the issuers and securities, the share counts, the signal rules and thresholds, the restrictions, merges and purges, the weekly pack's approval | The application, the admin screens |
| **SI admin** (`is_admin`) | Settings, the sources and their credentials, the EDGAR user-agent, tokens, the agents' settings, purge. A super-admin holds it | The admin area |
| **An agent** | The shipped **expert** and **Surveillance Analyst**, and any hired agent granted a role: reads through the records MCP under its run token, acts through the kernel's Actions MCP, pauses where §5 says | Records MCP · Actions MCP (relayed) · a chat turn · a duty |
| **An outside system** | The issuer's data warehouse, a board portal, a spreadsheet holding a key: reads what the key's person may | `/api/v1/*` (§8) |
| **A sibling application** | Knowledge (a base over the filings), Spaces (a signal or an event unfurled, the morning note in a channel), DocCloud (the pack filed), the GL (nothing — the ledger needs nothing of this), the Sales CRM (an institution's contacts, Extended) | K7 `filings_index` / `filing_text` / `ownership_summary` / `signals_open` / `events_index` over an approved connection |
| **The kernel** | Sign-on, the directory, the agents, approvals, the command bar (chat endpoint), the siblings' reads (K7) | — |
| **System** | The worker: every five minutes the fetch schedule (EDGAR's index every market day at 06:00 and 18:30 ET, the full-text watch hourly, FINRA's daily file at 18:30 ET, short interest on publication days, the fails on the 1st and 16th, the threshold lists nightly, the DTC directory monthly, prices daily), the upload queue, the matcher's proposals, the rules, the reconciliation, the notifications, the Analyst's wake | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one record database; the tenant's one memory
receives this application's activity episodes.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered). The base role's key is `user` so the
installer's `--grant-standing-departments` makes an insider an Analyst where the super-admin grants the department (Finance,
Front Office).

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `viewer` | Viewer | read | `issuers.read`, `positions.read` (participants and their positions, aggregates), `institutions.read` (13F, 13D/G, insiders — public record), `filings.read`, `short.read`, `signals.read`, `events.read`, `reports.read`, `holders.read_aggregate` (counts, share ranges, geography — **never a name or address of a natural person**) |
| `user` | Analyst | write | Viewer's + `holders.read` (every holder, the lines, the address), `uploads.write` (SPR, NOBO, register, prices), `matches.decide`, `events.write`, `borrow.write`, `signals.work` (acknowledge, dismiss with a reason, escalate), `notes.write`, `tags.apply`, `reports.run`, `export.own`, `tokens.own` |
| `manager` | IR Manager | write | Analyst's + `issuers.manage` (issuers, securities, share counts, the transfer agent, the CIK), `rules.manage` (signal rules and thresholds), `restrictions.manage`, `holders.merge`, `uploads.delete` (to the tombstone), `reports.approve` (the weekly pack), `agents.settings`, `export.shared` |
| `admin` | SI admin (is_admin) | admin | every right + `settings.manage`, `sources.manage` (the fetchers, their schedule, the EDGAR user-agent), `credentials.manage`, `tokens.manage`, `api.manage`, `records.purge`, `costs.read` |

**The walls** (`si_can_see_issuer(id)`, `si_sees_holder_pii()`, STABLE, the principals once per statement — the kernel's db/160 lesson):

1. **An issuer is open to every role holder unless restricted.** `issuer_members` (issuer, member) is the restriction list: when
   any row exists for an issuer, only those members, managers and the admin see it and everything under it (securities,
   positions, holders, filings, signals, events, reports). The advisory firm restricts each client's issuer to its team and to
   the client's own external members. `si_can_see_issuer()` is `NOT restricted OR listed OR manager OR admin`.
2. **A natural person's name and address are the Analyst's and above.** The NOBO list and the register name real people. A
   Viewer sees holders **aggregated** (how many, in what share ranges, in which states, at which custodians) and sees **entities
   and institutions by name** (a fund, a trust company, a corporation — `holders.kind = 'entity'`), never a person. The flag
   `holder_kind person|entity|custodian_account|unknown` is set by the matcher and corrected by an Analyst. `si_sees_holder_pii()`
   is `role ≥ user`; the `mcp_holders` view nulls the lines and address for a Viewer and names a person "Individual holder".
3. **A person's own data leaves only by export, logged.** Every export of holders carries the exporter, the time and the row
   count in the activity log; the K7 shares and the API's Viewer-level reads carry no person.
4. **Writes follow the role**: an Analyst uploads, decides matches, records events and works signals; a manager changes what
   the data is measured against (share counts, rules) and merges; the admin changes how data arrives.
5. **An upload is never edited**: a wrong file is deleted to the tombstone (30 days) by a manager and re-uploaded; the rows
   derived from it go with it; the activity log keeps that it existed.
6. **An agent sees what its member id may see** — the same functions, no special case; `is_eval` refuses every write.
7. **Scope: none.** Issuers with a restriction list do the work; the kernel's `location`/`department` scopes fit neither a
   company nor a client. **Department scope** (one issuer per department) is Extended if an advisory firm asks.

## 4. Doors for those who are not inside — the files, the public record, keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the application's own*;
for a reach with no account, **a token is the authority**.

**Nobody outside signs in, and nothing goes out.** There is no holder portal, no investor page, no email to holders, no filing
made from here. A holder, an institution and an insider are rows about people and firms, never people who reach the
application. The only outsiders are **DTC, Broadridge and the transfer agent** (their files come in by a person's upload) and
**the public hosts** (EDGAR, FINRA, the SEC's data pages, the exchanges' threshold pages, DTCC's directory page, a price
host), reached by the worker alone, each a `sources` row with its policy (§6.5).

**A key** (`mcp_access_tokens`, `scope = 'api'`, Knowledge's rate columns) acts **as its person** (60/min, 5,000/day), minted on
*My settings*; the API (§8) answers what that person sees — a Viewer's key never carries a name.

**A sibling application** reads through **K7**: `filings_index`, `filing_text` (`timeout_seconds` 60), `ownership_summary`,
`signals_open`, `events_index` are `shares[]`; the kernel calls them over an approved connection and names the consumer
(`X-OS-Consumer`); none carries a natural person's name or address.

**Rejected:** fetching the SPR or the NOBO list by automation against DTC's or Broadridge's portals (credentials of the
issuer's agent, terms of use, and no API — an upload, always); scraping a short-data vendor's site; a public "shareholder
dashboard"; email to holders or institutions of any kind.

## 5. Agents and the application — the OS requirement, in detail

**Looking, computing, proposing and annotating inside the business are free; anything that leaves the business, destroys a
file or a holder, merges identities, or changes what the data is measured against is a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `report_send` (a pack by email to the board's members through MaluMail), `report_file_to_doccloud` (K7 write — Extended), `notification_external` | `external_send` | Words leave the application — and with Reg FD in view, nothing of this ever reaches the market |
| `upload_delete`, `holder_delete`, `holders_merge`, `event_delete`, `signal_delete`, `issuer_delete`, `security_delete`, `records_purge` | `deletion` | |
| `issuer_save`, `security_save`, `share_count_save`, `rule_save`, `rule_threshold_set`, `restriction_save`, `source_save`, `source_schedule_set`, `credential_set`, `settings_save`, `agents_settings_save`, `report_approve` | `other` | The measure, the doors, the rules, the pack's approval |
| `holder_match_propose`, `holder_kind_propose`, `signal_acknowledge` (own proposals only — a person decides), `signal_note`, `event_create` (a filing-derived event, a note), `borrow_observation_create`, `note_add`, `tag_apply/remove`, `report_draft`, `fetch_now` (a source's manual pull within its policy), `reconciliation_run`, `rules_run`, `export_own` (a Viewer-level export) | — | An agent must do these alone — this is what makes it the analyst the incumbents attach |

**Working.** (a) **The command bar**: a person's turn on the expert through the kernel's chat endpoint — *who were the top ten
participants on Friday and how did they move; how much of the float is short; did anything fail to deliver last month; show me
the 13F holders that cut us in Q2; what did the 8-K on the 3rd say; is the SPR total tied to Cede; who at Schwab holds more than
fifty thousand* — the expert answers with the records tools and acts through the registry; a draft pack is a draft a person
approves. (b) **A duty** (cron) for the Surveillance Analyst. (c) **A dispatch** (`agent_dispatches`): a new 13D, a new activist
short report recorded as an event, a signal of severity high, a threshold-list appearance, an SPR upload finished — each wakes
the Analyst for one turn (K8 when it lands; the chat-turn dispatch until then).

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install**):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Shareholder Intelligence Expert | The command bar and the house name: answers any question of §7 — positions and movements by participant, holders and their history, the institutions and insiders as filed, the short side, the reconciliation, the signals and their evidence, what a filing says (from `filing_text`); records an event or a note a person dictates; proposes a holder match; drafts the pack; **never** sends, deletes, merges or changes a rule | every records tool, `search`, `timeline`, `filing_read` | `event_create`, `note_add`, `tag_apply`, `holder_match_propose`, `signal_note`, `report_draft`, `fetch_now` | every `external_send`, `deletion`, `other` |
| `analyst` — Surveillance Analyst | A duty every market day 07:00 ET (`0 7 * * 1-5`, the holidays from `settings`): one **morning note** per issuer — what the SPR showed since the last note (the largest participant moves, net retail-box vs prime-box flow, new and lost participants), the overnight public record (new filings by form, a new 13D/13G or Form 4, the 13F changes when a quarter lands, short interest when published with days to cover, yesterday's short-volume ratio with FINRA's caveat, new fails, the threshold list), the open signals by severity, the reconciliation's state, the events of the week ahead (a record date, an earnings date); **proposes** on every new signal (acknowledge with a reason, dismiss as explained by an event, escalate to the manager), on every unmatched holder with a candidate (the match with its evidence), on every holder whose kind is unknown; every Friday drafts the **weekly pack** (the narrative over the standard charts and tables) for the manager's approval; posts the note as a kernel message to the person's assistant when they have one, else as an in-app notification and an email to the members who asked | the report tools of §7, `signals_open`, `holders_unmatched`, `reconciliation_latest`, `events_upcoming` | `signal_acknowledge` (a proposal), `holder_match_propose`, `holder_kind_propose`, `event_create` (from filings), `report_draft`, `notification`, `message_send` (the kernel's) | nothing (it never sends outside, deletes, merges or changes a rule) |

**Skills** (`skills/`): `shareholder-intelligence-basics` (what an issuer, security, participant, position, holder, institution,
insider, filing, record date, signal and event *are*; which tool answers what; the PII wall; a draft is never sent by an agent),
`reading-the-spr` (the grain, the daily change, the Friday-to-Monday bridge, the retail / prime / lending / market-maker buckets
and why the migration between them is lore, the tie to Cede), `reading-the-short-side` (short interest vs float and days to
cover, FINRA's caveat on daily short volume, fails and the threshold rule, Rule 204, what Form SHO will add), `reading-ownership-filings`
(13F quarterly lag and the $100M threshold, 13D vs 13G and their deadlines, Form 4 codes, Form 144, what an ATM, an ELOC and a
floating-price convertible look like in a filing), `holder-identity` (how the matcher scores, what to accept, what never to
merge — a TOD and an IRA of the same person are two accounts and one holder); runbooks (`kind: runbook`): `morning-note`,
`work-the-signals`, `propose-holder-matches`, `draft-the-weekly-pack`, `set-up-an-issuer` (ticker → CIK → securities →
transfer agent → the first SPR).

## 6. Memory model — what Shareholder Intelligence remembers

One store: **record memory** (PostgreSQL 17, `<tenant>_shareholder_intelligence`) holds issuers and securities, share counts,
participants and their classes, the uploads and their rows, positions, holders and their positions, institutions and
their holdings, beneficial ownership, insider transactions, filings and their text, the short-side series, prices, events,
borrow observations, reconciliations, signal rules and signals, reports, sources and pulls, and the kit; files on disk under
the canonical store rules; the tenant's memory receives the activity episodes. Every record carries `issuer_id` (the wall),
`created_by`, `created_at`, `updated_at`, `deleted_at` where it may be tombstoned, and a `search_index` row by trigger where
it is searchable (holders, institutions, participants, filings, events, signals).

### 6.1 Issuers, securities, share counts, participants (`app/features/issuers/`, `participants/`)

**`issuers`** (**new canonical**): `id`, `name`, `cik` (10 digits, unique when present), `ticker` (primary), `exchange
nasdaq|nyse|nyse_american|otc|cse|tsx|tsxv|other`, `state_of_incorporation`, `fiscal_year_end` (MMDD), `sic`, `ein`,
`transfer_agent` (name), `transfer_agent_connector` (`stc|computershare|continental|vstock|pacific|equiniti|generic`),
`edgar_entity jsonb` (the submissions JSON's scalars and addresses, the former names — the reference implementation's three
tables as one document), `edgar_synced_at`, `restricted` (generated: any `issuer_members` row), `timezone`, `market_holidays
date[]` (the exchange's, seeded for the year), the common columns. **`issuer_members`** (issuer, member, added_by, added_at).
**`securities`**: `id`, `issuer_id`, `class` (`common|preferred|warrant|unit|note|other`), `name` ("Common Stock"), `cusip` (9,
unique), `isin`, `ticker` (this class's), `figi`, `par_value`, `is_primary`, `listed_on`, `delisted_on`, `active`.
**`share_counts`**: `id`, `security_id`, `as_of` date, `kind outstanding|float|authorized|restricted|unrestricted|treasury|
cede|drs|certificated|plan|public_float_value`, `value` numeric(20,0) (or dollars for `public_float_value`), `source
filing|transfer_agent|dtc_letter|manual|spr`, `filing_id` nullable, `note`, `recorded_by`; **the outstanding count is written by the
worker from the XBRL cover fact `dei:EntityCommonStockSharesOutstanding` of every 10-K/10-Q** (companyfacts, with its
`frame` and the filing), by a person from the agent's control total, and by the reconciliation from an SPR total as
`cede`. `si_share_count(security, kind, on_date)` answers the latest value at or before a date.

**`participants`** (**new canonical** — the DTC directory): `id`, `number` (4 characters, unique; "0005"), `name` (the directory's),
`aliases text[]` (the names the SPR files used — the directory renames: 0161 was Merrill, is BofA Securities), `class_id` →
**`participant_classes`** (seeded: `retail` — Schwab 0164, National Financial 0226, Robinhood 6769, Apex 0158, DriveWealth 2402,
IBKR 0534, Vanguard 0062, Wells Clearing 0141, Wedbush 0103, StoneX 0750, Pershing 0443 as `clearing_retail`; `prime` — Goldman
0005, Morgan Stanley 0050, BofA Securities 0161 and 0551, JPMS 0187/0352, UBS 0221, Barclays 0229, Clear Street 9132;
`market_maker` — Citadel 0395/2963, Virtu 0063/0295, Jane Street 3492/2820, Instinet 0067; `lending` — 3691, 5198 and the
"/LENDING", "/STOCK LOAN", "/SAFEKEEPING" names; `transfer_agent` — Computershare 2415/2586/2330, the others by name;
`bank_custodian` — BNY, State Street, Northern Trust, Citibank; `other`), `class_source seeded|directory_name|manual`, `directory_kind
participant|settling_bank|pledgee|drs`, `first_seen_in_directory`, `last_seen_in_directory`, `active`, `notes`. The directory
fetch (monthly) upserts by number, records renames into `aliases`, proposes a class from the name's words for a new number
(a person confirms); **the bucketing is a setting, not a fact** — the signal that reads it says so.

### 6.2 Uploads: the SPR, the NOBO list, the register (`app/features/uploads/`, the parsers in `app/si/parse/`)

**`imports`** (Spaces' table, reused; `kind` widened `spr|nobo|register|prices_csv|holders_csv|voted_unvoted`; **appended**
`issuer_id`, `security_id`, `connector` (the vendor's layout: `dtc_spr_xlsx`, `dtc_spr_ccf`, `broadridge_nobo_xlsx`,
`broadridge_nobo_csv`, `stc_position_detail`, `computershare_holder_list`, `generic_mapped`), `mapping jsonb` (a generic upload's
column mapping, saved as `import_templates`), `record_date`, `period_from`, `period_to`, `attachment_id` (the file, kept
whole under `attachments`, sha256), `control_totals jsonb` (what the file says of itself: the NOBO header's count, the SPR's
total line, the register's control block), `derived_totals jsonb` (what the rows sum to), `tied` boolean, `superseded_by`,
`deleted_at`). **A parse is one transaction**: the rows land in the raw table with the row number and the cell text as read,
the typed columns beside them; the control total is compared; a mismatch is reported on the import and **the rows are kept**
(an Analyst sees exactly which rows failed to type); nothing downstream is computed from an import that is not `tied` unless a
manager overrides with a note.

**SPR**: **`spr_rows`** (import, row_no, `participant_number` text, `participant_name` text as read, `position_date`, `shares`
numeric(20,0), `raw jsonb`) — the weekly grid is **unpivoted on load** (one row per participant per day; the five dates come
from the header, the reference implementation's `d1…d5`); the CCF form is the same rows in DTC's fixed-width record. →
**`participant_positions`** (**new canonical**): `security_id`, `participant_id`, `position_date`, `shares`, `import_id`,
UNIQUE (security, participant, date). The series functions: `si_position_changes(security, from, to)` (per participant: open,
close, net, the largest daily move, days present), `si_participant_flow(security, from, to, by = class)` (net shares by class
— the retail / prime / market-maker / lending buckets), `si_participants_new_lost(security, from, to)`, `si_spr_total(security,
date)` (the Cede figure the SPR implies), `si_concentration(security, date)` (top 5/10/20 share of the total, a Herfindahl).
A position date with no row for a participant that had one the day before is **absent, not zero** — the chart breaks the line;
a participant at zero in the file is zero.

**NOBO**: **`nobo_jobs`** (import, `job_number` (Broadridge's), `cusip`, `issuer_name_as_filed`, `record_date`, `sort_order`,
`nobo_count_header`, `nobo_count_rows`, `shares_total`), **`nobo_rows`** (job, row_no, `shares`, `line1`…`line7` text as read,
`zip_sort`, `cusip`, `holder_id` nullable (the match), `custodian_participant_id` nullable (parsed from the lines: "CHARLES SCHWAB
& CO INC CUST", "FMT CO TTEE" → National Financial, "TD AMERITRADE" → Schwab), `account_kind ira|tod|trust|joint|custodial|
corporate|individual|unknown` (from the lines' legends: IRA CONTRIBUTORY, DESIGNATED BENE PLAN/TOD, TTEE, JT TEN, CUST), `raw
jsonb`), **`nobo_broker_rows`** (job, `client` text as read, `participant_id` nullable (matched by name alias), `nobo_count`,
`shares`) — the Bank/Broker listing; **the OBO remainder per participant** = `participant_positions.shares` on the record date −
`nobo_broker_rows.shares`, a function (`si_obo_remainder(security, record_date)`), never a table.

**Register**: **`register_rows`** (import, row_no, `section` ("Common 2" — the class as the agent labels it), `position_id`,
`position_type` (the agent's: cert, book, DRS, plan, restricted), `holder_ref` (the agent's holder id), `registration_name`,
`address_line`, `city`, `state`, `country`, `zip`, `issue_date`, `shares`, `cost_basis`, `restriction_text`, `percent_owned`,
`is_employee`, `is_officer_director`, `is_us_citizen`, `holder_id` nullable, `is_cede` (generated: the name matches "CEDE"),
`raw jsonb`); the generic connector maps columns by a saved template; Computershare's and Continental's layouts are
templates seeded from the first real file the owner provides.

### 6.3 Holders — the identity the files share (`app/features/holders/`; `docs/build-specs/holder-identity.md`)

**`holders`** (**new canonical**): `id`, `issuer_id`, `display_name`, `name_key` (normalised: upper, punctuation and legends
stripped, the custodian removed — "BRIAN POLAN"), `kind person|entity|custodian_account|unknown`, `entity_type` (trust,
corporation, fund, partnership, estate — for an entity), `address_key` (normalised street + zip), `city`, `state`, `country`,
`zip`, `is_insider` (a Form 3/4/5 reporting person matched), `insider_cik`, `institution_id` nullable (a 13F or 13D filer
matched), `first_seen_on`, `last_seen_on`, `merged_into_id`, `tags` through `record_tags`, `notes`, the common columns;
**`holder_accounts`** (holder, `channel nobo|register`, `custodian_participant_id`, `account_kind`, `account_key` (the lines
normalised — the exact registration), first/last seen) — **one holder, many accounts**: the TOD account and the Schwab IRA are two
`holder_accounts` of one holder; **`holder_positions`** (**new canonical**): `holder_id`, `holder_account_id`, `security_id`,
`record_date`, `shares`, `channel nobo|register`, `import_id`, UNIQUE (account, security, date, channel). The series:
`si_holder_history(holder)` (shares by record date, by account, by channel), `si_holders_changes(security, date_a, date_b)` (new,
lost, up, down — the reference implementation's buyers/sellers/new/lost over record dates), `si_holders_by_range(security, date)`
(share ranges, counts, shares — the Viewer's aggregate), `si_holders_by_geography(security, date)` (state, counts, shares),
`si_holders_by_custodian(security, date)`, `si_insider_holdings(issuer)`.

**The matcher** (`app/si/match.php`, run after every NOBO or register parse and nightly): for every raw row without a holder it
scores candidates — `name_key` exact with `address_key` exact (1.0), `name_key` exact with one side lacking an address (0.9),
`name_key` trigram ≥ 0.6 with `address_key` exact (0.95 — a short name's one-letter typo scores about 0.65 on trigrams; the same
street and zip is the anchor; Phase 0 found 0.85 never matches a real typo), `name_key` trigram ≥ 0.7 with the same zip (0.8),
`name_key` exact with a different address (0.6 — a move, or a namesake); an entity name against `institutions.name` is slice 5's — and
writes **`holder_match_proposals`** (row kind and id, `holder_id` candidate, `score`, `evidence
jsonb` (which keys matched), `proposed_by` (the matcher or an agent), `status proposed|accepted|rejected|auto`, decided_by/at);
**≥ 0.95 is accepted automatically and logged as `auto`** (the setting `auto_match_threshold`, a manager's), below it an
Analyst decides on the *Unmatched* screen with the two registrations side by side; a rejected pair is remembered (never
proposed again); **a merge** (`holders_merge`, a manager's) re-points every account and position and leaves `merged_into_id`.
A person is never matched to an institution; a custodian's own omnibus line ("CHARLES SCHWAB & CO INC" with no FBO) is a
`custodian_account` holder of kind entity.

### 6.4 The reconciliation, prices, events and borrow observations (`app/features/reconciliation/`, `events/`)

**`share_reconciliations`**: `id`, `security_id`, `as_of`, `outstanding` (and its `share_count_id`), `cede_registered` (the
register's Cede line, or the DTC confirmation letter typed), `spr_total` (that date's `si_spr_total`), `drs_book`,
`certificated`, `plan`, `registered_other`, `difference_registered` (outstanding − (cede + drs + cert + plan + other)),
`difference_spr` (cede − spr_total), `tied` (both within `settings.reconciliation_tolerance_shares`), `nobo_shares`,
`obo_remainder`, `note`, `ran_by`, `ran_at` — one row per run, the latest per security on the home page; **a `difference_spr` that
is not zero is a data question before it is anything else** (a missing participant row, a stale directory, a day the file
skipped), and the morning note says so.

**`prices`** (**new canonical**): `security_id`, `trade_date`, `open`, `high`, `low`, `close`, `volume`, `source_id`, UNIQUE
(security, date); **`events`**: `id`, `issuer_id`, `security_id` nullable, `on_date`, `kind filing|financing|dilution_instrument|
record_date|earnings|press|activist_short_report|lawsuit|exchange_notice|insider_transaction|share_count|note|other`, `title`,
`body` (Markdown), `url`, `filing_id` nullable, `severity info|watch|high`, `source worker|person|agent`, `created_by`, tags —
the overlay on every chart and the "what happened that day" of every signal; the worker makes one from every filing of a
watched form (8-K with its items, 13D, 13D/A, 13G, 4 with a sale, 144, S-1, S-3, 424B, DEF 14A, 10-K, 10-Q) and from every
threshold-list appearance; **`dilution_instruments`**: `issuer_id`, `kind atm|eloc|convertible_note|convertible_preferred|
warrant|rights_offering|registered_direct|pipe`, `counterparty`, `filed_on`, `filing_id`, `facility_amount`, `currency`,
`remaining_amount` (a person's update), `price_terms` (text: "95% of the lowest VWAP of the 5 days before conversion"),
`floating_price` boolean (the death-spiral flag of §0.1), `shares_issuable_max`, `expires_on`, `status proposed|active|exhausted|
terminated`, `proposed_by` (the full-text watch, confirmed by a person), `notes`; **`borrow_observations`**: `security_id`,
`observed_on`, `source vendor|broker_quote|manual`, `source_id` nullable, `fee_rate_percent`, `utilization_percent`,
`shares_on_loan`, `shares_available`, `note`, `recorded_by` — the typed observation today, the vendor's row when a key exists.

### 6.5 The public record — sources, filings, ownership forms, the short side (`app/features/filings/`, `ownership/`, `short/`; `docs/build-specs/edgar-and-finra-fetchers.md`)

**`sources`, `source_pulls`, `source_credentials`** (Inventory's, verbatim; §0.4): the **connectors** (`si_connectors()`):
`edgar_submissions` (the issuer's index; every market day at 06:00 and 18:30 ET; the delta since `edgar_synced_at`),
`edgar_companyfacts` (the share-count facts), `edgar_fulltext` (hourly: the watch terms against new filings — "at-the-market",
"equity line", "equity purchase agreement", "convertible", "warrant", "reverse split", the issuer's name in others' filings
— every hit an `events` row of severity `watch` and, for the dilution terms, a `dilution_instruments` proposal),
`edgar_13f_dataset` (the quarterly zip; the `INFOTABLE` rows whose CUSIP is one of ours; by the SEC's publication calendar),
`edgar_13f_filing` (a fresh 13F-HR's XML by accession for the days before the set lands — Extended; v1 reads the sets),
`edgar_13dg` (every SC 13D/13G/-A naming our CUSIP: the XML's filer, percent, shares, event date, item 4 purpose; full-text search
finds them, the XML types them), `edgar_ownership` (Forms 3/4/5 of the issuer: the XML's reporting owner, relationship,
transactions with code, date, shares, price, holdings after, direct/indirect, footnotes), `edgar_144` (Form 144 XML: seller,
shares, approximate sale date, broker), `finra_short_interest` (the Query API on publication days; the revision flag re-writes a
row as a new snapshot), `finra_daily_short_volume` (`CNMS` and `FORF` files at 18:30 ET), `sec_ftd` (the half-month zips on the
1st and 16th + 2 days), `threshold_nasdaq`, `threshold_nyse`, `threshold_otc` (nightly), `dtcc_participants` (monthly),
`prices_stooq`, `prices_alphavantage`, `prices_polygon`, `prices_manual`, and the Extended shapes `edgar_nport`,
`edgar_form_sho`, `finra_slate`, `ortex`, `breakout_point`. **The policy**: EDGAR's declared user-agent from `settings`
(`"<business name> <contact email>"` — the admin fills it at install; the installer's `plan` warns when empty), 10 requests a
second at most and **2 a second by default**, `If-Modified-Since`/`ETag` honoured, a 403 or 429 backs the source off for an hour
and the admin is told; the SEC's and FINRA's robots honoured; every request's host, status and bytes in `source_pulls`; nothing
fetched that the host did not publish for machines; **no portal, no login, no vendor page is ever scraped**.

**`filings`** (**new canonical**): `id`, `issuer_id`, `filer_cik` (the issuer's, or another filer's when the filing is *about*
us — a 13D by a fund), `accession_number` (unique), `form` ("8-K", "SC 13D/A"), `filed_on`, `report_date`, `accepted_at`,
`act`, `file_number`, `film_number`, `items text[]` (8-K items), `size`, `is_xbrl`, `is_inline_xbrl`, `primary_document`,
`primary_doc_description`, `url` (generated), `about_us` boolean (another filer's filing naming our CUSIP), `fetched_text_at`,
`text_sha256`, `char_count`, `structured_at` (the XML parsed into the tables below), `error`; **`filing_text`** (filing_id PK,
`text`, `extractor html|xml|pdf|txt`, `extracted_at`) — the primary document's text, HTML stripped, **kept for the full-text
index and for Knowledge's K7 read**, fetched for the watched forms and on demand for any other (`filing_fetch`).

**`institutions`** (**new canonical**): `id`, `cik` (unique), `name`, `kind investment_adviser|hedge_fund|bank|insurance|pension|
mutual_fund|etf_sponsor|activist|individual|corporation|other` (proposed from the 13F cover and the name; a person corrects),
`is_activist`, `aliases text[]`, `website`, `notes`, tags; **`institutional_holdings`** (**new canonical**, the 13F grain):
`institution_id`, `security_id`, `period_end` (the quarter), `filing_id`, `value_dollars`, `shares`, `shares_type SH|PRN`,
`put_call` nullable, `discretion SOLE|DEFINED|OTHER`, `other_managers text`, `voting_sole`, `voting_shared`, `voting_none`,
`is_amendment`, UNIQUE (institution, security, period_end, filing); `si_13f_changes(security, period)` (new, closed, up, down vs the
prior quarter; the top holders; the concentration), `si_13f_put_call(security, period)`; **`beneficial_ownership`** (**new
canonical**, 13D/13G): `institution_id` (or a person as an institution of kind `individual`), `security_id`, `filing_id`,
`schedule 13D|13G`, `is_amendment`, `amendment_no`, `event_date`, `percent_of_class`, `shares_beneficially_owned`, `sole_voting`,
`shared_voting`, `sole_dispositive`, `shared_dispositive`, `purpose_text` (item 4, 13D), `group_members text[]`, `filer_type`
(13G: QII, passive, exempt), `raw_xml_sha`; **`insider_transactions`** (**new canonical**, Forms 3/4/5): `filing_id`,
`reporting_owner_cik`, `reporting_owner_name`, `holder_id` nullable (matched to the register), `relationship director|officer|
ten_percent_owner|other`, `officer_title`, `transaction_date`, `transaction_code` (P, S, A, M, F, G, …), `shares`, `price`,
`acquired_disposed A|D`, `shares_owned_after`, `ownership direct|indirect`, `indirect_nature`, `security_title`, `is_derivative`,
`exercise_price`, `expiration_date`, `footnotes text[]`, `is_10b5_1`; **`form144_notices`**: `filing_id`, `seller_name`,
`seller_cik`, `relationship`, `shares_to_sell`, `aggregate_market_value`, `approx_sale_date`, `broker`, `shares_outstanding_stated`.

**The short side** (**new canonical**): **`short_interest`** (`security_id`, `settlement_date`, `published_on`, `short_shares`,
`previous_short_shares`, `change_shares`, `change_percent`, `avg_daily_volume`, `days_to_cover`, `revision_flag`, `source_id`,
`observed_at`, UNIQUE (security, settlement_date, observed_at) — a revision is a new row); `si_short_vs_float(security, date)` (short
÷ float, short ÷ outstanding, days to cover, the series); **`daily_short_volume`** (`security_id`, `trade_date`, `market CNMS|FORF|
FNRA|FNYX|FNSQ|FNQC|FORF`, `short_volume`, `short_exempt_volume`, `total_volume`, `ratio` generated, `source_id`); **`fails_to_deliver`**
(`security_id`, `settlement_date`, `quantity`, `price`, `source_id`, `file_half a|b`); **`threshold_listings`** (`security_id`,
`list_date`, `list nasdaq|nyse|nyse_american|nyse_arca|otc`, `flag`, `rule_3210`, `source_id`; a run of consecutive dates is a
function); the Extended shapes **`form_sho_aggregates`** (`security_id`, `month`, `gross_short_position`, `daily_net_activity jsonb`)
and **`securities_lending_events`** (SLATE's shape) are created now, empty, so the fetchers of 2028 are columns, not migrations.

### 6.6 Signals — the rules, their evidence class, and what a person does (`app/features/signals/`; `docs/build-specs/signals.md`)

**`signal_rules`**: `id`, `key` (unique), `name`, `description`, `evidence_class established|contested|lore` (§0.1 — **shown on every
signal**), `basis` (the paragraph), `severity_default info|watch|high`, `params jsonb` (the thresholds, with defaults), `window_days`,
`applies_to text[]` (security classes), `enabled`, `issuer_id` nullable (an override per issuer), `updated_by`. **Seeded rules
(the v1 set — every one a SQL function over the tables above, run by the worker after every relevant pull or upload, and by
`rules_run` on demand):**

| Key | Rule (defaults) | Class |
|---|---|---|
| `si_over_float` | short interest ≥ 10% of float (watch), ≥ 20% (high); or days to cover ≥ 5 (watch), ≥ 10 (high) | established |
| `si_change` | short interest up ≥ 25% settlement over settlement with volume not up as much | established |
| `borrow_fee` | fee ≥ 20% or utilisation ≥ 80% in the latest observation (vendor or typed) | established |
| `ftd_spike` | fails ≥ 0.5% of outstanding on any day (high), or ≥ 3× the 60-day median (watch) | contested — a Rule 204 flag, not proof |
| `threshold_list` | the security appears on a threshold list (high); stays 5+ days (high, repeated weekly) | contested |
| `short_volume_ratio` | the 5-day mean of FINRA's off-exchange short-volume ratio ≥ 60% **and** rising 10 points over 20 days — **always worded with FINRA's caveat**, never alone severity high | contested |
| `prime_migration` | net flow from the retail and clearing-retail classes to prime + lending + market-maker classes ≥ 2% of the SPR total over 10 position days | **lore** (the signal says so) |
| `participant_jump` | one participant's position moves ≥ 1% of the SPR total in a day, or a participant new to the file holds ≥ 0.5% | lore / info |
| `concentration_shift` | the top-5 share of the SPR total moves ≥ 5 points over 20 position days | lore |
| `nobo_drain` | NOBO shares at a participant fall ≥ 20% between record dates while the participant's SPR position did not (OBO or lent) | lore |
| `spr_untied` | the SPR total differs from Cede's registered position by more than the tolerance | **data defect** (severity high until explained) |
| `register_untied` | outstanding − the register's components differs by more than the tolerance | data defect |
| `new_13d` | a new 13D or 13D/A with purpose text, or a 13G converting to 13D | established (activism) |
| `holder_5pct` | any filer's 13D/G percent ≥ 5% new, or ≥ 10% | info |
| `top_holder_exit` | a top-20 13F holder closed or cut ≥ 50% in the quarter | established (as information) |
| `insider_sales` | insider sales (code S) ≥ 3 in 30 days, or ≥ 1% of outstanding, or a Form 144 ≥ 0.5% | established |
| `dilution_overlap` | an active `dilution_instrument` with `floating_price` **and** `si_over_float` or `ftd_spike` open in the same window | established (the death-spiral literature) + the overlap |
| `activist_short_report` | an event of kind `activist_short_report` recorded (high for 10 days) | established |
| `price_volume_anomaly` | the close down ≥ 15% in 3 days on volume ≥ 3× the 30-day median with no event recorded (asks "what happened?") | info |

**`signals`**: `id`, `rule_id`, `issuer_id`, `security_id`, `raised_on`, `window_from`, `window_to`, `severity`, `value` numeric,
`threshold` numeric, `baseline` numeric, `evidence jsonb` (the row ids and figures the rule saw — a chart reads them), `summary`
(one sentence the rule writes from a template, no model), `status open|acknowledged|dismissed|escalated|expired`, `disposition_reason`
(explained by event X, data defect, known, watching, false positive), `disposed_by`, `disposed_at`, `event_id` nullable (the
explanation), `proposal_by` (an agent's proposed disposition), `repeat_of` (the same rule in an overlapping window — one open
signal per rule per security), `expires_on` (the window's end + the rule's grace). A signal **never** sends anything; the
in-app notification and the morning note carry it; a `high` one wakes the Analyst (a dispatch) and, when `settings.escalate_high_to`
names a member, notifies them.

### 6.7 Reports, exports and settings (`app/features/reports/`, `settings/`)

**`reports`**: `id`, `issuer_id`, `kind weekly_pack|monthly_pack|board_pack|record_date_pack|custom`, `period_from`, `period_to`,
`status draft|approved|published`, `narrative` (Markdown — the agent's draft, the person's edit), `sections jsonb` (which standard
sections and charts: the SPR movement table, participant flow by class, the top-20 participants, holders by range and
geography, 13F changes, 13D/G, insider activity, the short side, fails and threshold, the reconciliation, the signals, the
events), `rendered_path` (the HTML and the PDF under storage — `wkhtmltopdf` when present, else HTML only), `drafted_by`,
`approved_by`, `approved_at`, `attachment_id`; **`exports`** (Spaces', `kind` widened: `positions_csv`, `holders_csv`
(Analyst+, logged with the row count), `13f_csv`, `short_csv`, `signals_csv`, `report_pdf`); **`si_settings`** (the singleton:
the business's name and contact for EDGAR's user-agent, the default exchange and timezone, `market_holidays` (the year's, by
exchange), the fetch schedule overrides, `reconciliation_tolerance_shares`, `auto_match_threshold`, `pii_in_exports` (on/off
beyond the admin), `escalate_high_to`, the morning note's hour, the Analyst's reach (which issuers), the weekly pack's day,
`retain_raw_rows_days` (default forever), `price_source_default`), **`import_templates`** (a generic upload's saved mapping:
name, connector, `mapping jsonb`, `sample jsonb`).

### 6.8 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | Sales CRM `db/001` (= DocCloud/CT); `members` with `is_agent`, `is_external`, `roles text[]` |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `issuer_id`, `security_id`, `import_id`, `signal_id` |
| `mcp_access_tokens`, `key_usage` | **reuse** | HR `db/003` + Knowledge's rate columns |
| `si_rights`, `si_roles`, `si_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `si_`) |
| `si_settings` | **reuse** (skeleton) | the `app_settings` singleton |
| `sources`, `source_pulls`, `source_credentials` | **reuse** | **Inventory `db/009` canonical**, verbatim; `connector` check = `si_connectors()`; `role` widened; `supplier_id` kept, nullable, no FK (recorded, the `income_account_id` precedent) |
| `imports`, `exports`, `worker_passes` | **reuse** | Spaces `db/016`; `imports` **appends** the upload columns of §6.2; `kind` lists ours |
| `attachments`, `notes` | **reuse** | GL `db/009`; `record_type` widened to `import|holder|institution|participant|event|signal|report|issuer|dilution_instrument` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | GL `db/014` canonical (Spaces' member-only form); `kind` ours; prefs **append** `morning_note`, `morning_hour`, `signals_min_severity` |
| `agent_dispatches` | **reuse** | GL `db/014` = CT `db/013`; `kind` ours (`signal`, `filing`, `upload_done`, `match`, `pack`, `ask`); **appends** `signal_id`, `filing_id`, `import_id` |
| `tags`, `record_tags` | **reuse** | Help Desk `db/009` `tags` verbatim; `record_tags` DocCloud's shape with `record_type` |
| `search_index` | **reuse** | Spaces `db/013` canonical; **appends** `issuer_id`; `entity_kind` widened with `holder|institution|participant|filing|event|signal` — a holder's row is indexed only for the PII-seeing roles (the view nulls it) |
| `filing_text` | **reuse** (shape) | Knowledge `source_text` (`source_id` → `filing_id`, the external-key substitution) |
| `issuers`, `issuer_members`, `securities`, `share_counts`, `prices` | **new canonical** | compared: GL `accounts` (a chart), Inventory `products` (goods), CRM `accounts` (a relationship) — none is a listed company with CUSIPs and a price; the next application that prices a security copies `prices` |
| `participants`, `participant_classes`, `participant_positions` | **new canonical** | nothing in the estate knows DTC |
| `holders`, `holder_accounts`, `holder_positions`, `holder_match_proposals` | **new canonical** | compared: CRM `contacts` (a relationship with a person who may reach us), Help Desk `requesters`; a holder is a registration on a list — the shared columns keep CRM's names (`display_name`→ kept as `display_name`, `city`, `state`→`region` is CRM's; recorded: we keep the list's words `state`, `zip`), `merged_into_id` from `requesters` |
| `imports`'s raw tables `spr_rows`, `nobo_rows`, `nobo_jobs`, `nobo_broker_rows`, `register_rows` | **new** | the shape of Knowledge's `sections` (row number, the text as read, typed beside) applied to tabular files |
| `institutions`, `institutional_holdings`, `beneficial_ownership`, `insider_transactions`, `form144_notices`, `filings` | **new canonical** | nothing in the estate reads EDGAR; the column names follow the SEC's data-set and XML field names where one exists (`SSHPRNAMT` → `shares`, `PUTCALL` → `put_call`, `VOTING_AUTH_SOLE` → `voting_sole`) so a reader of the SEC's readme recognises them |
| `short_interest`, `daily_short_volume`, `fails_to_deliver`, `threshold_listings`, `borrow_observations`, `form_sho_aggregates`, `securities_lending_events` | **new canonical** | nothing close; FINRA's and the SEC's field names kept |
| `share_reconciliations` | **new** | the shape of GL `bank_reconciliations` (components, a difference, tied) applied to shares |
| `events`, `dilution_instruments`, `signal_rules`, `signals`, `reports`, `import_templates` | **new canonical** | compared: Projects' issues (work), Help Desk's tickets (a conversation), Inventory's `watches` (a price threshold on one variant — the nearest to a rule; ours carries an evidence class and a window and is not reused: a different concept); `reports` compared with Spaces' `exports` (a file, not an approved document) |
| HR's employment, the GL's books, the CRM's accounts and contacts, Help Desk's tickets, DocCloud's documents, Knowledge's bases, the kernel's directory | **read** | another application's data; `sales_crm.contacts_index` (Extended, an institution's people), `doccloud.document_link` (Extended, the pack filed) — each degrades to an empty panel on `no_connection` |

New canonical tables go into the plugin's `shared-schema.md` catalogue in the same change (§12, the plugin item); the
`sources` name collision between Inventory's meaning (taken here) and Knowledge's is recorded there already.

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped to the tenant's one MaluDB as
`activity` episodes tagged `shareholder_intelligence`. **There is no audit table**: a record's history is `mcp_activity_log WHERE
entity_type = … AND entity_id = …`; **a position's history is the positions; a signal's history is its dispositions.** A
holder's name, address or lines, a filing's text and a credential are **never in a log payload** (ids, counts, dates, shares,
severities and rule keys are). Events (the manifest names them): `issuer.create|update|restrict|unrestrict|delete`,
`security.create|update|delete`, `share_count.record|delete`, `participant.directory_sync|class_set|alias_add`, `upload.start|
parse|tie|untie|override|delete|restore`, `position.load` (counts), `holder.create|kind_set|merge|delete|export` (the count),
`match.propose|accept|reject|auto`, `filing.index|fetch_text|structure|fail`, `institution.create|update|kind_set`, `holding.load`,
`ownership.load`, `insider.load`, `short.load|revise`, `ftd.load`, `threshold.list|delist`, `price.load`, `borrow.record`,
`event.create|update|delete`, `dilution.propose|confirm|update|terminate`, `reconciliation.run|tie|untie`, `rule.save|enable|
disable|run`, `signal.raise|acknowledge|dismiss|escalate|expire|propose`, `report.draft|approve|render|download|send`,
`source.create|update|credential_set|schedule_set|pause|resume|pull_start|pull_done|pull_fail|pull_blocked`, `agent.dispatch|
reply|fail`, `share.read`, `settings.save`, `token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`.

## 7. The question inventory — what Shareholder Intelligence exists to answer

Record questions are tools on the records MCP (`si_` views and functions over `<tenant>_shareholder_intelligence` as
`shareholder_intelligence_records_ro`); activity questions are the activity MCP over the tenant's memory filtered to
`application = shareholder_intelligence`. **A question with no tool is unfinished design.** Every holder-level answer is
subject to the PII wall (§3.2): a Viewer's token gets the aggregate forms.

**The issuer and the share count**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| 1 | Which issuers and securities do we track, with ticker, CUSIP, CIK, exchange, transfer agent? | record | all | `find_issuers`, `get_issuer`, `find_securities` |
| 2 | How many shares are outstanding, in the float, restricted, at Cede, in DRS, certificated, in plans — as of when, from where? | record | all | `share_counts` (security, kind, as_of) |
| 3 | Does the share count tie — outstanding against the register, Cede against the SPR? What is the difference and since when? | record | all, Analyst | `reconciliation_latest`, `reconciliation_history` |

**Participants and positions (the SPR)**
| 4 | Who held our shares at DTC on this date, by participant, ranked? | record | all | `positions_on` (security, date, top) |
| 5 | How did each participant's position change over this window — open, close, net, the largest day? | record | all | `position_changes` (security, from, to) |
| 6 | Which participants are new to the file, which left, which went to zero? | record | all | `participants_new_lost` |
| 7 | Where did the shares flow — retail boxes, prime brokers, market makers, lending accounts — net over the window? | record | all | `participant_flow` (by class), with the lore caveat |
| 8 | How concentrated is the DTC position — top 5, top 10, the index — and how has that moved? | record | all | `concentration` |
| 9 | Everything about this participant: its class, aliases, its position series, its NOBO holders and count, the OBO remainder | record | all (holders: Analyst) | `get_participant`, `participant_series`, `participant_nobo_summary` |
| 10 | What does the SPR imply Cede holds on this date, and does it match? | record | all | `spr_total`, `reconciliation_latest` |

**Holders (NOBO and the register)**
| 11 | Who are our registered holders on the latest list — Cede, DRS, certificates, plans — by position type? | record | Analyst (Viewer: aggregates) | `find_holders` (channel register), `holders_by_type` |
| 12 | Who are the NOBOs on this record date, ranked by shares; at which custodian; what kind of account? | record | Analyst | `find_holders` (channel nobo, record_date), `holders_by_custodian` |
| 13 | How did holders change between two record dates — new, lost, up, down — and by how much? | record | Analyst (Viewer: counts) | `holders_changes` |
| 14 | How are holders distributed by share range and by state? | record | all | `holders_by_range`, `holders_by_geography` |
| 15 | Everything about this holder: their accounts, their positions over time, whether they are an insider or an entity we know, notes and tags | record | Analyst | `get_holder`, `holder_history` |
| 16 | Which rows are unmatched or have a pending match proposal, and what is the evidence? | record | Analyst, agents | `holders_unmatched`, `match_proposals` |
| 17 | How much of each participant's position is NOBO and how much the OBO remainder? | record | all | `obo_remainder` (security, record_date) |
| 18 | Which holders are insiders, and what do they hold on the register versus their Form 4s? | record | Analyst | `insider_holdings` |

**Institutions, beneficial owners, insiders (EDGAR)**
| 19 | Which 13F filers hold us this quarter, with value, shares, voting authority; who is new, out, up, down? | record | all | `institutional_holders` (period), `13f_changes` |
| 20 | Who holds more than 5% — the 13D and 13G filers, their percent, the event date, the purpose? | record | all | `beneficial_owners`, `get_beneficial_filing` |
| 21 | What did insiders do — the Form 4 transactions by code, the Form 144 notices — in this window? | record | all | `insider_transactions`, `form144_notices` |
| 22 | Everything about this institution: its CIK, kind, its holdings in us by quarter, its 13D/G history, its other filings about us | record | all | `get_institution`, `institution_history` |
| 23 | Are any put options reported on us in 13Fs? | record | all | `13f_put_call` |

**Filings**
| 24 | What has the company filed, by form and date; what did the 8-K's items say? | record | all | `find_filings`, `get_filing` |
| 25 | What does this filing say — the text, a passage matching a term? | record | all | `filing_read` (a 6,000-character window), `filings_search` (the full-text index) |
| 26 | Which filings by others name us — 13D/G, 13F, lawsuits, an activist's exhibit? | record | all | `find_filings` (about_us) |
| 27 | What dilution instruments are active — ATMs, equity lines, convertibles, warrants — with what remaining and what price terms? | record | all | `dilution_instruments` |
| 28 | What did the full-text watch find since yesterday, and which proposals await confirmation? | record | Analyst | `watch_hits`, `dilution_proposals` |

**The short side**
| 29 | What is the short interest, as a share of float and of outstanding, and the days to cover — now and as a series? | record | all | `short_interest_series`, `short_vs_float` |
| 30 | What was yesterday's off-exchange short-volume ratio and its 5- and 20-day means — with FINRA's caveat? | record | all | `daily_short_volume_series` |
| 31 | How many shares failed to deliver, by settlement date; is the security on a threshold list, since when? | record | all | `fails_series`, `threshold_status`, `threshold_history` |
| 32 | What is the cost to borrow and utilisation — the latest observation and its source? | record | all | `borrow_series` |
| 33 | How does the short side line up with the price and volume over the window — one table a chart reads? | record | all | `short_side_overlay` (security, from, to) |

**Signals and events**
| 34 | Which signals are open, by severity, rule and security; what is each one's evidence and evidence class? | record | all | `signals_open`, `get_signal` |
| 35 | What happened on this date — filings, financings, record dates, reports, notes? | record | all | `events_on`, `find_events` |
| 36 | What is coming — record dates, earnings, expiries — in the next 30 days? | record | all | `events_upcoming` |
| 37 | Which signals did we dismiss and why; which did an agent propose and a person reject? | record | manager | `signals_history`, `signal_proposals` |
| 38 | What are the rules, their thresholds, their evidence class, and when did each last fire? | record | all | `rules_catalogue` |

**Reports, the business and the admin**
| 39 | Which packs exist — drafted, approved — and what is in the latest? | record | all | `find_reports`, `get_report` |
| 40 | What did each upload contain, did it tie, what failed to type, who uploaded it? | record | Analyst | `find_imports`, `get_import`, `import_rows` |
| 41 | Are the sources healthy — the last pull, the failures, a block, the EDGAR user-agent set? | record | admin | `source_health` |
| 42 | Who holds which role; which issuers are restricted to whom; which agents work here? | record | admin | `app_roles`, `members_with_roles`, `issuer_restrictions` |
| 43 | Which keys exist and how much have they been used? | record | admin | `find_tokens`, `key_usage_summary` |
| 44 | What did the Analyst propose this week, and what was accepted? | record | manager | `analyst_proposals` |

**Shares for the siblings (K7)**
| 45 | Which filings exist for this issuer — form, date, title, url? | share | Knowledge, Spaces | `filings_index` |
| 46 | The text of one filing | share | Knowledge (a base over the filings) | `filing_text` (`timeout_seconds` 60) |
| 47 | The ownership picture in aggregate — outstanding, float, short, 13F total, 13D/G holders, retail vs institutional vs insider shares, the concentration | share | Spaces, a board portal | `ownership_summary` (no holder names) |
| 48 | The open signals with severity and summary | share | Spaces (a channel card), Help Desk (Extended) | `signals_open` |
| 49 | The events of a window | share | Spaces | `events_index` |

**Activity memory (MaluDB, the tenant's)**
| 50 | When did we first flag the build-up at Goldman, and who looked at it? | activity | expert | `activity_search` |
| 51 | Who dismissed the threshold signal last month and what reason did they give? | activity | manager | `actor_history`, `record_episodes` |
| 52 | What did the agents do here this week — and what did they propose that nobody took? | activity | manager, Auditor | `agent_activity` |
| 53 | Did the lore rules (prime migration, concentration) precede the price moves they claim to — over every window we have? | activity + record | Auditor | `signal_outcomes` (record: each signal with the price 5/20/60 days after), `activity_timeline` |

Guarded search: `search` (the one box — `search_index` over holders (PII roles), institutions, participants, filings, events
and signals, visibility as a per-row gate) and `activity_search` (the activity server's). Every `find_*` resolver answers
`{"rows": [...]}` for the kernel's registry wrapper.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from Sales CRM/DocCloud; on sign-on and every sync a
  member's `notification_prefs` row exists with the defaults.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and the `shares[]` alone (reading
  `X-OS-Consumer` / `X-OS-Consumer-Agent`, K26).
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry
  (`deploy/kernel-registry-shareholder_intelligence.json`, a `find_<entity>` resolver per entity: `find_issuers`,
  `find_securities`, `find_participants`, `find_holders`, `find_institutions`, `find_filings`, `find_events`, `find_signals`,
  `find_rules`, `find_imports`, `find_reports`, `find_sources`, `find_members`); the approval hook before every action with a
  category; the chat endpoint for the command bar and dispatches; the duty for the Analyst.
- **Mail**: the installer's `mail` step fills `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` — used for the morning note and
  the notifications **to members only**; `report_send` (a pack to board members who are members) pauses for an agent.
- **The API** (bearer = a key acting as its person; allow-listed in the vhost): `GET /api/v1/issuers|securities|participants|
  positions|holders|institutions|holdings|beneficial|insiders|filings|short-interest|short-volume|fails|threshold|signals|
  events|reports` (+ `/{id}`, the `find_*` filters; the PII wall by the key's role), `POST /api/v1/uploads` (a file with
  `kind`, `security`, `record_date` — the same parser), `POST /api/v1/events`, `POST /api/v1/borrow-observations`, `GET
  /api/v1/health`. Every call logged with the key's label; 60/min, 5,000/day per key; a holders export counts its rows.
- **Vhost allow-list (public)**: `/api/v1/`, `/mcp/records`, `/mcp/activity`. Nothing `auth_kind none` but health and the UI.

## 9. Screens (the nxl look; the phone screens — the morning note, the signals, a chart, a holder — are read at 375 px first; the
position tables, the movement matrix and the pack are designed at 1280 px and must still work at 375 px, one column at a time)

| Screen | Questions | Notes |
|---|---|---|
| `/` **home** (per issuer; the issuer switcher in the header when more than one) | 3, 10, 29, 31, 34, 36 | the morning note's last text; the reconciliation tile (tied / untied, the difference); the short-side tiles (SI % float, days to cover, last fails, threshold yes/no — each with its date and source); open signals by severity as cards; the price with the events overlay (the dataviz skill's line + markers, 90 days); the next 30 days' events; the latest upload per kind with its tie state |
| `/issuers`, `/issuers/{id}`, `/issuers/new`, `/issuers/{id}/edit`, `/issuers/{id}/securities`, `/issuers/{id}/share-counts`, `/issuers/{id}/access` | 1, 2 | cards (the owner's rule); setup walks ticker → CIK lookup (`company_tickers.json`) → the EDGAR entity → securities with CUSIPs (the 13(f) list confirms the CUSIP→name) → the transfer agent and its connector → the restriction list (record pickers of members) |
| `/positions` (`?security=&from=&to=`) · `/positions/matrix` · `/positions/flow` · `/positions/concentration` | 4–8, 10 | **the position table** (participants ranked on a date, with the class chip, the change against the previous day and the window's open); **the movement matrix** (participants × dates, the cells coloured by change, sortable by net — one column a day, scrolls sideways at 375 px); **the flow** (net by class as a stacked bar per day and a cumulative line — with the lore caveat printed under it); concentration as a line |
| `/participants`, `/participants/{id}`, `/participants/{id}/edit` | 9 | cards by class; the page: the series chart, the NOBO summary on each record date, the OBO remainder, aliases, the class (a select of classes with the source shown), notes and tags |
| `/uploads`, `/uploads/new`, `/uploads/{id}` | 40 | upload: kind, security, the connector (auto-detected from the file's first cells where it can be — the NOBO header, STC's title row), record date or period; the page: control totals vs derived, **tied** or the difference, the rows with the ones that failed to type first, *Override* (manager, a note), *Delete* (to the tombstone), *Re-run matcher* |
| `/holders` (`?channel=&record_date=&q=`) · `/holders/{id}` · `/holders/{id}/edit` · `/holders/changes` · `/holders/ranges` · `/holders/geography` · `/holders/custodians` | 11–15, 17, 18 | cards for the PII roles, the aggregate tables for a Viewer; the holder page: accounts (the registration lines as read), positions by record date and channel as a chart, the insider and institution links, *Merge into…* (manager, a record picker, a two-column compare page — never a modal) |
| `/holders/unmatched` | 16 | the queue: the raw registration on the left, the candidate(s) with score and evidence on the right, *Accept* / *Reject* / *New holder* / *Set kind*; the Analyst's proposals marked |
| `/institutions`, `/institutions/{id}`, `/institutions/{id}/edit` · `/ownership/13f` (`?period=`) · `/ownership/13f/changes` · `/ownership/beneficial` · `/ownership/insiders` · `/ownership/144` | 19–23 | cards; the 13F table by quarter with the prior-quarter delta and the concentration; the changes view (new / out / up / down); 13D/G as cards with percent and purpose; the insider ledger by person with codes and the register link; Form 144 notices |
| `/filings` (`?form=&from=&about_us=`) · `/filings/{id}` · `/filings/search?q=` · `/filings/watch` · `/dilution` · `/dilution/{id}/edit` | 24–28 | the index as a table (form, date, items, title, a chip for structured/text fetched); a filing's page: the text with the search hits highlighted, the structured block (a 13D's percent and purpose, a Form 4's transactions), *Fetch text*, *Make event*; the watch's hits with *Confirm instrument* / *Dismiss*; dilution instruments as cards with remaining and terms |
| `/short` · `/short/interest` · `/short/volume` · `/short/fails` · `/short/threshold` · `/short/borrow` · `/short/borrow/new` | 29–33 | **the short-side page**: one chart (price and volume above; SI % float at settlement dates, fails, the 5-day short-volume ratio, threshold days shaded, borrow fee points below — the dataviz skill's small multiples) with the events overlay; each sub-page the series as a table with its source and date; the borrow form (date, source, fee, utilisation, note) |
| `/signals` (`?status=&severity=&rule=`) · `/signals/{id}` · `/signals/history` | 34, 37 | cards by severity with the evidence-class chip (established / contested / lore / data defect); the signal page: the summary, the basis paragraph, the evidence rendered (the rows and a chart of the window), the related events, *Acknowledge* / *Dismiss* (reason, the explaining event by picker) / *Escalate*; the Analyst's proposed disposition with *Take it* |
| `/events` · `/events/{id}` · `/events/new` · `/events/{id}/edit` · `/calendar` (month, the cidery's grid) | 35, 36 | the timeline (filings, financings, reports, record dates, notes) with filters by kind; the month grid of what happened and what is coming |
| `/reports` · `/reports/{id}` · `/reports/new` · `/reports/{id}/edit` · `/reports/{id}/approve` | 39 | cards; the pack page: the narrative (the agent's draft, editable), the sections toggled, the rendered preview (HTML), *Approve*, *Download PDF*, *Send to…* (members only, a record picker; pauses for an agent); the standard pack is the board's weekly |
| `/rules` · `/rules/{id}/edit` | 38 | the catalogue: key, class, severity, thresholds as form fields, last fired, enable/disable; a per-issuer override |
| `/search?q=` | all | the one box in the header; results grouped by kind with highlights; holders only for the PII roles |
| `/settings` (the person's) | — | notification prefs (the morning note and its hour, the minimum signal severity, email), keys |
| `/admin/sources`, `/admin/sources/{id}`, `/admin/sources/{id}/edit`, `/admin/sources/{id}/pulls` · `/admin/participant-classes` · `/admin/templates` · `/admin/agents` · `/admin/tokens` · `/admin/settings` · `/admin/purge` | 41–44 | the fetchers with their schedule, the last pull, *Pull now*, *Pause*; a source's pulls as a table; the credential form (never shown back; last4); the class vocabulary; import templates; the Analyst's settings (issuers, hour, the pack's day); keys; the tombstones to purge |

About 55 screens; every one works as a full page and as an HTMX partial; forms carry `page-header-form` with the pinned Save;
record fields that name a record (issuer, security, participant, holder, institution, event, member, rule, template) are
**record pickers**, never selects of every row; `hx-confirm` on destructive controls only; the record picker is the one modal.
Every chart follows the dataviz skill (one palette, light and dark, the source and the date under every chart, "absent" gaps
never interpolated).

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers; the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2 and both walls (a restricted issuer invisible to a second Analyst; a Viewer's `mcp_holders` row with the lines and address nulled and the name "Individual holder"; an entity's name visible to the Viewer); an issuer with two securities; share counts by kind and `si_share_count()` at a date; the participant directory upserted with a rename into `aliases` and a class proposed; **the SPR fixture** (the owner's real weekly file, anonymised) parsed into `spr_rows` and `participant_positions` with the control total tied, the daily changes and the Friday-to-Monday bridge, `si_participant_flow()` by class, new/lost, concentration; **the NOBO fixture** (`NOBOLIST.xlsx`, anonymised) parsed — header, 855 rows, the CLIENTS section — the custodian and account kind parsed from the lines, the matcher's scores on the known cases (one holder with a TOD and an IRA; two "ROBERT SMITH"s at different addresses kept apart), an auto-accept at ≥ 0.95, a proposal below, a reject remembered; **the register fixture** (`STCCommon.xlsx`, anonymised) parsed by section with Cede flagged; the OBO remainder; a reconciliation tied and one untied by a known amount; **EDGAR fixtures** (a submissions JSON, a companyfacts JSON with the cover fact, one 13F data-set `INFOTABLE` slice, one SC 13D XML, one Form 4 XML, one Form 144 XML, one 8-K HTML) parsed into the tables with the SEC's field names; **FINRA and SEC fixtures** (a short-interest API page, a `CNMSshvol` day file, a `cnsfails` half-month file, a Nasdaq and an NYSE threshold file) loaded; a revision of short interest as a second row; prices from a stooq CSV; every seeded rule fired on a crafted window and silent on a quiet one, the signal's evidence and class recorded, one open signal per rule per security; a dismissal with an explaining event; `signal_outcomes` with prices after; `search_index` honouring both walls; the reused tables verbatim against their siblings; the fetchers' HTTP layer proven against a local stub for the user-agent, the rate, `If-Modified-Since`, a 403 backing off); the kit proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json`, `os/{expert,analyst}.md`, ten skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (ports, the EDGAR user-agent note); the installer's `plan` clean | the owner's go — **and the owner's three real files (an SPR week, a NOBO job, a register export), anonymised, as the fixtures** (§13, open question (a)) |
| 1 | `docs/shareholder-intelligence-mcp-tool-surface.md`, `docs/shareholder-intelligence-action-manifest.md`, `mcp/action_registry.json`, the four build specs (`position-reports.md`, `holder-identity.md`, `edgar-and-finra-fetchers.md`, `signals.md`), and the slice specs (`sso-shell` and nine slices; **the SPR and positions (slice 2) and the signals (slice 7) are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `si_has_right()`, the ingest bridge, the shell with the record picker and the application switcher, the issuer switcher, the one search box, `/api/v1/health`, the vhost allow-list, the worker's empty pass with the fetch schedule's clock | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) issuers, securities, share counts, participants and the directory fetch** (the CRUD exemplar: cards, the setup walk with the CIK lookup, the restriction list, the participant directory source and its first pull, the class vocabulary) → **(2) uploads and THE SPR — THE FIRST EXEMPLAR** (`imports` with the connectors `dtc_spr_xlsx`/`dtc_spr_ccf`, the parse transaction, control totals and tie, the positions, the position table, the movement matrix, the flow by class, concentration, new/lost, the reconciliation's SPR half) → **(3) NOBO, the register and holders** (the two parsers and the generic mapped connector with templates, holders and accounts, the matcher and the unmatched queue, merge, the holder page, ranges, geography, custodians, changes between record dates, the OBO remainder, the reconciliation's register half, the PII wall on every screen and export) → **(4) EDGAR — the index, the text, the watch** (the `edgar_submissions`, `edgar_companyfacts`, `edgar_fulltext` sources, `filings` and `filing_text`, the full-text index, the filing page, events from filings, dilution proposals and instruments) → **(5) EDGAR — the ownership forms** (13F data sets, 13D/G XML, Forms 3/4/5, Form 144; institutions; the 13F changes; beneficial owners; the insider ledger with the register link) → **(6) the short side** (FINRA short interest and daily volume, the SEC's fails, the three threshold lists, prices through the connectors, borrow observations, the short-side page and its chart) → **(7) SIGNALS — THE SECOND EXEMPLAR** (`signal_rules` seeded with the nineteen rules as SQL functions, the runner after every pull and upload, signals with evidence and class, the signal page, dispositions with reasons and explaining events, `signal_outcomes`, the home page's tiles) → **(8) events, the calendar, reports and the pack** (events CRUD and the month grid, the weekly pack with the standard sections rendered by the dataviz rules, HTML and PDF, approval, download, send to members) → **(9) agents, notifications, K7 and the API** (the two agents' tools and actions, dispatches on 13D / signal / upload, the morning note, the Analyst's proposals and acceptance, notifications and prefs, the five shares, the API and keys) → **(10) admin, settings and tokens** (sources and credentials, the schedule, import templates, the agents' settings, keys, purge) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database; **slices 4–6 against fixtures first, then one live pull each against the real hosts under the policy** |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the five `shares[]` admitted to the kernel's token (K26 headers), the registry wrapper, the two agents with their grants and the duty; the Actions-MCP contract proved against the kernel's `application_actions.register()` | the command bar answers "who were the top ten on Friday and how did they move" from one sentence; an agent's `report_send` pauses; the Analyst sends nothing outside, deletes nothing, changes no rule |
| 5 | the owner's `app_install.php apply` (ports pinned first; the EDGAR user-agent in settings), DNS and TLS, the hires, the first issuer set up, the first real SPR, NOBO and register uploaded, the sources' first live pulls | **an Analyst sets up the issuer from its ticker, uploads this week's SPR and last quarter's NOBO list and register; the reconciliation ties (or names the difference); overnight the worker fetches the index, the facts, the short interest, yesterday's short volume, the fails and the threshold lists; at 07:00 the morning note names the largest participant moves, the new 13F changes, the SI as a share of float and one open signal with its evidence class; the Analyst dismisses it with the explaining 8-K in one click; a Viewer sees the chart and the signal and no holder's name; Friday's draft pack renders and the manager approves it; Knowledge builds a base over `filings_index` + `filing_text` and answers a question about the 10-K with a citation** |

**Extended** (§11). Size: eleven specs (sso-shell + ten slices), about 55 screens, 95 actions, 80 records tools, 7 activity tools,
eighteen connectors (eleven live in v1, four price/manual, three Extended shapes). **Four to five days is the honest estimate**:
issuers, the SPR and holders one and a half (the parsers against real files are the risk — hence the fixtures gate); EDGAR two
(the ownership forms are three XML specs and a data set); the short side, signals and the pack one; the rest and Phase 5 with the
owner the remainder — the Spaces division (D16).

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **securities-finance feeds** (Ortex, S3, S&P through a licensed key —
`borrow_observations` from a vendor row) · **Form SHO aggregates and SLATE** (the fetchers when the SEC and FINRA publish, 2028;
the tables exist) · **N-PORT** (fund-level monthly holdings, 60-day lag) · **fresh 13F-HR XML by accession** (before the
quarterly set lands) · **the exchanges' daily short volume** (a paid feed) · **activist-short feeds** (Breakout Point,
Insightia) · **social and news monitoring** through a licensed API (never scraping) · **the NOBO Voted/Unvoted and Geographic
reports** as uploads with their own screens (proxy season) · **vote projection** (a model over holder mix and proxy-advisor
history — Georgeson's craft) · **IR CRM links** (an institution's people through `sales_crm.contacts_index`; a meeting logged
there) · **the pack filed to DocCloud** (a K7 write when the kernel has one) · **department scope** (one issuer per
department) · **Canada and the UK** (SEDAR+, the TSX's and the LSE's equivalents) · **evals**: the Auditor testing the lore
rules against `signal_outcomes` — the first published validation of the participant-migration signal would be this
installation's.

**Later**: a board portal (Nasdaq Boardvantage's job); proxy tabulation; a public investor page (never from here).

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K34** | **Catalog entry `shareholder_intelligence`** (kind `ours`, Finance, `feather-trending-down`); **category `investor_relations` widened into both checks** (`application_catalog` and `applications`, db/172 and db/175's lists) — the K30 lesson: widen both or the apply fails at register; on request, not a default (D15) | **built** 2026-10-09: kernel db/178 (both checks widened, `APPLICATION_CATEGORIES` too), applied on the build server |
| K26 | K7 names the consumer; `timeout_seconds` | **built** (db/173) — `filing_text` declares 60 |
| K6 | texts to a member | **built** — a `high` signal's text when the member asked |
| K8 | wake an agent from an application | owed to every sibling; the chat-turn dispatch until then |
| **K35** | **the installer's `plan` warns on an empty `settings` value the manifest marks `required_at_install`** (the EDGAR user-agent: without it every EDGAR call is refused) — today `env.required` covers `.env` only | owed: the kernel's installer; `ROOT_STEPS.sh` prints the reminder until then |
| **KN2** | Knowledge's **feed over `shareholder_intelligence.filings_index` + `filing_text`** (a base per issuer over its filings; the sectioner on a 10-K's items) — Knowledge's `feeds` already consume K7 reads; the provider is this plan | owed by Knowledge's `reads[]` |
| SP5 | Spaces unfurls a signal, an event or the ownership summary; the morning note posted to a channel through the kernel's agent-as-member | owed by Spaces (`reads[]`) |
| HD6 | Help Desk raises a ticket from an escalated signal | Extended, owed by Help Desk |
| DC2 | DocCloud's `document_link` read for a filed pack | Extended |
| CRM1 | `sales_crm.contacts_index` for an institution's people | Extended |
| plugin | `shared-schema.md` gains `issuers`, `securities`, `share_counts`, `prices`, `participants`, `participant_positions`, `holders`, `holder_accounts`, `holder_positions`, `filings`, `filing_text`, `institutions`, `institutional_holdings`, `beneficial_ownership`, `insider_transactions`, `short_interest`, `daily_short_volume`, `fails_to_deliver`, `threshold_listings`, `events`, `signal_rules`/`signals` as canonical; records the `sources` reuse with `supplier_id` kept and `role` widened | the planning model, in Phase 0 |
| the owner | **three real files, anonymised** (§13 (a)), and the EDGAR contact email for the user-agent | at Phase 0 |

## 13. The decisions (recommendations; the owner's answers are §15 — every one taken, 2026-10-09)

| # | Decision | Recommendation | Alternative |
|---|---|---|---|
| D1 | Name, key, label | **"Shareholder Intelligence", catalog key `shareholder_intelligence`** (the installer admits `[a-z0-9_]`; the repository `maludb-os-shareholder-intelligence` as the owner named it, the clone `/srv/apps/shareholder_intelligence`), **DNS label `shareholders`** (`shareholders.<domain>`), database `<tenant>_shareholder_intelligence`, roles `shareholder_intelligence_rw` / `_records_ro` / `_activity_ro`, prefix `si_`; business area Finance, category `investor_relations` (K34 widens), icon `feather-trending-down` | label `ir` (`ir.<domain>` — short, the trade's word) or `intel`; key `shareholders`; category `other` (widens nothing) |
| D2 | Roles | **Four**: Viewer, Analyst (`user`), IR Manager, SI admin; the advisory firm's client is an external Viewer restricted to their issuer | three (Analyst, Manager, admin) with the Viewer folded into a read grant — rejected: the board and the client must never see a retail holder's name, and that needs a role |
| D3 | One issuer or many; scope | **Many issuers in one installation, scope `none`, a restriction list per issuer** (`issuer_members`): the company tracking itself has one issuer and no list; the advisory firm has one per client, each restricted to its team and the client's externals | one issuer per installation (simpler walls; the advisory firm installs per client — a VM each, the OS's model, but a firm with twenty clients will not); department scope (an issuer per department — Extended) |
| D4 | The PII wall | **A natural person's name and address are the Analyst's and above; a Viewer sees aggregates and entities; exports of holders are logged with their row count; the K7 shares and the API's Viewer reads carry no person** | names open to every role (the board sees the list — rejected: the NOBO list is personal data obtained under a rule that limits its use to the issuer's communications) |
| D5 | What the command bar's expert does and never does | **Answers everything of §7, records events and notes, proposes matches and dispositions, drafts the pack; never sends, deletes, merges or changes a rule** | the expert acknowledging signals itself under a standing approval — rejected: a disposition is a judgement |
| D6 | Uploads | **The three files are uploaded, never fetched; a parse is one transaction with control totals and a tie; the file kept whole; a wrong file deleted to the tombstone and re-uploaded; connectors `dtc_spr_xlsx`, `dtc_spr_ccf`, `broadridge_nobo_xlsx/csv`, `stc_position_detail`, `computershare_holder_list` (from the owner's first real file), `generic_mapped` with saved templates** | an automated login to DTC's SPR web or Broadridge's portal — rejected (no API, the agent's credentials, terms of use) |
| D7 | The SPR's grain and the buckets | **One row per participant per date; the weekly grid unpivoted on load; a missing day is absent, not zero; classes (retail, clearing-retail, prime, market maker, lending, transfer agent, bank custodian, other) seeded from the directory's names and this plan's table, a setting a manager corrects; the migration signal is marked lore** | the reference implementation's weekly row with five columns; no classes (every bucketed view by hand) |
| D8 | Holder identity | **A normalised holder with many accounts; the custodian and account kind parsed from the registration lines; the matcher scores and proposes; ≥ 0.95 auto-accepted and logged; below it an Analyst decides; rejected pairs remembered; a merge is a manager's** | exact name on line 1 (the reference implementation) — rejected: one person's TOD and IRA became two holders and two different Robert Smiths one |
| D9 | EDGAR's reach in v1 | **The index and the cover facts for every issuer; the text of the watched forms; full-text search for the dilution terms and for others' filings about us; 13F from the quarterly data sets; 13D/G, Forms 3/4/5 and 144 from the XML; one declared user-agent, 2 requests a second by default; everything against fixtures before a live pull** | 13F-HR fresh XML by accession (Extended), N-PORT (Extended) |
| D10 | The short side in v1 | **FINRA short interest (the Query API) with revisions as new rows, FINRA's daily off-exchange short volume with the caveat printed, the SEC's fails, the three threshold lists; cost to borrow typed until a licensed key exists; Form SHO and SLATE tables created empty now** | a short-data vendor's key required at install — rejected: the free public record first; the vendor is Extended |
| D11 | Signals | **Nineteen seeded rules as SQL functions with an evidence class on every one (established / contested / lore / data defect), run after every pull and upload; one open signal per rule per security; a person's disposition with a reason and an explaining event; `signal_outcomes` for the Auditor; nothing sent** | a composite "short attack score" — rejected: it would launder lore into a number; a model deciding severity — rejected (no model in the application) |
| D12 | The reconciliation | **A dated row per run: outstanding (the XBRL cover fact or the agent's total) against the register's components, Cede against the SPR's total, a tolerance in settings; untied is a data-defect signal first, never an inference** | no reconciliation (the reference implementation had none) — rejected: it is the one fact that keeps the rest honest |
| D13 | Prices | **A connector with `stooq` keyless CSV as the default, Alpha Vantage and Polygon with a key in `source_credentials`, a manual CSV; one series per security** | a paid feed required — rejected |
| D14 | Agents | **Expert and Surveillance Analyst hired on install; the pause list of §5; the Analyst every market day 07:00 ET (the holidays from settings), one note per issuer, dispositions and matches as proposals, the Friday pack as a draft the manager approves** | one agent; the Analyst acknowledging signals itself — rejected |
| D15 | Default or on request; the repository | **On request, not a default; the repository private until the owner says** | — |
| D16 | Ports and the division | **8192 / 8845 / 8846**, scratch 8901–8907; **the Spaces division**: the planning model builds K34, Phase 0, Phase 1, Phase 2, slice 1 and the exemplars (slice 2 the SPR, slice 7 the signals, with 3–6 between as time allows — at least slice 3, the holders, because the matcher is judgement); Sonnet 5.5 builds the remaining slices and Phases 4–5 | — |

**Open questions for the owner beyond the table**: (a) **the fixtures** — the Phase 0 proof needs **one real SPR week** (the weekly
grid as DTC delivered it, with its header), one real NOBO job (the `NOBOLIST.xlsx` in the reference repository, if it may be
used anonymised — names and addresses replaced, shares and structure kept) and one real register export (`STCCommon.xlsx`,
the same way); and whether the owner has a **Computershare** or **Continental** export to seed those templates; (b) **the
EDGAR user-agent** — the business name and a contact email the SEC may write to; (c) **which exchange and which issuers first** —
the recommendation is the owner's own public company (the reference implementation tracked a Nasdaq/OTC small cap) so Phase 5's
proof is real; (d) **the holder PII default for exports** — logged and allowed for the Analyst (the recommendation), or admin-only.

## 14. Ports, names and files

- Repository `/srv/apps/shareholder_intelligence` (origin `github.com/maludb/maludb-os-shareholder-intelligence`, private); record
  database `<tenant>_shareholder_intelligence` (`subello_shareholder_intelligence`); roles `shareholder_intelligence_rw`,
  `shareholder_intelligence_records_ro`, `shareholder_intelligence_activity_ro`; the tenant's memory (`certstudy_memory`)
  receives the activity episodes tagged `shareholder_intelligence`.
- `shareholders.<domain>` (`vhost.label = "shareholders"`); `APP_INTERNAL_PORT=8192`, `MCP_RECORDS_PORT=8845`, `MCP_ACTIVITY_PORT=8846`,
  all three in `env.required` and written to `config/.env` before `apply`; `deploy/` files are templates (`{{DOMAIN}}`, `{{APP_DIR}}`,
  `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`); uploads 50 MB (an SPR year or a large NOBO
  job); the canonical store rules for `attachments`.
- Units: `shareholder_intelligence-records-mcp`, `shareholder_intelligence-activity-mcp`, `shareholder_intelligence-activity-ingest`
  (+ timer), `shareholder_intelligence-directory-sync` (+ timer), `shareholder_intelligence-worker` (+ timer, every five minutes:
  the fetch schedule by source, the upload queue, the matcher, the rules, the reconciliation, the full-text index catch-up,
  notifications, the pack's render, exports, the tombstone purge window, dispatch retries).
- `maludb-os.json`: `catalog_key shareholder_intelligence`, `name "Shareholder Intelligence"`, `business_area "Finance"`, `category
  investor_relations`, `icon feather-trending-down`, `criticality medium`, `vhost.label shareholders`, no `scopes`, `sso`, `directory
  {reads: true, writes: false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert, analyst]` each
  `hired_on_install: true` (the Analyst with `duty "0 7 * * 1-5"` — `TZ=America/New_York` in the unit), `approvals` (§5), endpoints
  (the UI, health, the API, the two MCPs), services, `shares` (five: `filings_index`, `filing_text` (60 s), `ownership_summary`,
  `signals_open`, `events_index`), `reads` (none in v1; `sales_crm.contacts_index` and `doccloud.document_link` listed as Extended with
  their `why`).
- Env: the contract's required keys plus `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME`, `SI_SECRETS_KEY` (the source credentials'
  box key, generated at install), `SI_STORAGE_DIR` (default `storage`), `SI_MAX_UPLOAD_BYTES` (optional), `SI_WKHTMLTOPDF` (optional).
- Vhost allow-list (public): `/api/v1/`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/shareholder_intelligence.json` (made by the installer), db/178 (K34).
- Proof scratch ports: 8901–8907; scratch databases `si_dev`, `si_proof`; fixtures `tests/fixtures/` (the three anonymised files,
  the EDGAR JSON/XML/HTML samples, the FINRA and SEC files, a DTCC directory extract, a stooq CSV; **nothing of a real person**).

## 15. The owner's answers

**2026-10-09 — every recommendation of §13 taken, and the four open questions answered**, walked through one by one.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Shareholder Intelligence", key `shareholder_intelligence`, DNS label `shareholders`**, database `<tenant>_shareholder_intelligence`, roles `shareholder_intelligence_rw` / `_records_ro` / `_activity_ro`, prefix `si_`; Finance; category `investor_relations` (K34 widens both checks); the repository keeps the name `maludb-os-shareholder-intelligence` | `maludb-os.json`; §14; kernel db/178 |
| D2 | **Four roles**: Viewer, Analyst (`user`), IR Manager, SI admin; the advisory firm's client an external Viewer restricted to their issuer | §3; `si_roles`; `app_roles` |
| D3 | **Many issuers, scope none, a restriction list per issuer** (`issuer_members`) | §3.1; §6.1 |
| D4 | **The PII wall**: a natural person's name and address for Analyst and above; a Viewer sees aggregates and entities; the shares and Viewer API reads carry no person | §3.2; `mcp_holders` |
| D5 | **The Expert answers, records, proposes and drafts; never sends, deletes, merges or changes a rule** | §5; `os/expert.md` |
| D6 | **The three files uploaded, never fetched**; one transaction with control totals tied; the vendor connectors and `generic_mapped` with templates | §6.2; slice 2 and 3 |
| D7 | **One row per participant per date**; the weekly grid unpivoted; absent ≠ zero; seeded classes a manager corrects; the migration signal marked lore | §6.1–6.2; `participant_classes` |
| D8 | **A normalised holder with many accounts; the scoring matcher; ≥ 0.95 auto and logged; below it an Analyst; merge a manager's** | §6.3; `docs/build-specs/holder-identity.md` |
| D9 | **EDGAR v1**: the index and cover facts, the watched forms' text, the full-text watch, 13F from the quarterly data sets, 13D/G, Forms 3/4/5 and 144 from XML, 2 requests a second; fresh 13F-HR and N-PORT Extended | §6.5; slices 4–5 |
| D10 | **The free public record**: FINRA short interest with revisions as rows, daily short volume with the caveat, the SEC's fails, the three threshold lists; borrow typed until a licensed key; Form SHO and SLATE tables empty now | §6.5; slice 6 |
| D11 | **Nineteen SQL rules with an evidence class on every signal**, one open per rule per security, a person's disposition with a reason; `signal_outcomes`; no composite score, no model | §6.6; `docs/build-specs/signals.md` |
| D12 | **The reconciliation** as a dated row with a tolerance; untied is a data-defect signal first | §6.4 |
| D13 | **Prices through a connector**: stooq keyless by default, Alpha Vantage and Polygon with a key, manual CSV | §6.5 |
| D14 | **Expert and Surveillance Analyst hired on install**; the Analyst 07:00 ET on market days, proposals only, the Friday pack as a draft | §5; `agents[]` |
| D15 | **On request, not a default; the repository private** until the owner says | §12; the org |
| D16 | **Ports 8192 / 8845 / 8846**, scratch 8901–8907; **the Spaces division** — the planning model builds K34, Phases 0–2, slice 1 and the exemplars (slice 2 the SPR, slice 3 the holders, slice 7 the signals); Sonnet 5.5 the remaining slices and Phases 4–5 | §14; `CLAUDE.md` |
| (a) | **The two files in KineticIntel2 (`NOBOLIST.xlsx`, `STCCommon.xlsx`) anonymised as fixtures** — names and addresses replaced, shares and structure kept; **the owner provides one real SPR week** as DTC delivered it (the parser is built from the Angular grid until it arrives and confirmed on it) | `tests/fixtures/`; Phase 0 |
| (b) | **The EDGAR user-agent is a setting the admin fills at install**; the proofs use `Kinetic Seas claude@kineticseas.com`; `ROOT_STEPS.sh` reminds; K35 makes the installer warn when empty | `si_settings`; §12 |
| (c) | **The first issuer is the company behind the reference files (CUSIP 07831X206)**; its ticker and CIK are looked up at setup | Phase 5 |
| (d) | **Holder PII exports: Analyst and above, every export logged with its row count**; a setting may tighten it to admin-only later | §3.3; `si_settings.pii_in_exports` |

## 16. State

**2026-10-09 — PHASE 0 COMPLETE** (the same day as the plan and its approval). Built and proven on the scratch database `si_dev0`:
- **The kit** (db/001–005, copied from Sales CRM's and refitted: the audit keys `issuer_id`/`security_id`/`import_id`/`signal_id`,
  the four roles and thirty-five rights with the PII wall `si_sees_holder_pii()`, the settings with the EDGAR user-agent
  `si_edgar_user_agent()` and the market-day rule) — `tests/phase0/run.sh`: **73 checks** without a kernel (the directory fixture,
  the hand-off and every refusal, CSRF, the funnel, keys and the rate rule, the sign-out notice, the revoked grant, the worker's
  thirteen stub passes, the ingest bridge, mail).
- **The schema** (db/006–016): issuers with the restriction list and `si_my_issuers()`; participants with seeded classes and the
  directory upsert; uploads (`imports` reused) with the SPR unpivoted into `participant_positions` and the series; the NOBO rows,
  the broker listing and the register rows; holders with accounts, positions and the scoring matcher (auto at 0.95, proposals,
  decisions, the forced new holder, the merge; an account's position is the SUM of its rows); Inventory's `sources` model with
  fifteen v1 sources seeded and the back-off ladder; filings with text and search, events from watched forms, dilution
  instruments and the watch; institutions, 13F with quarter-over-quarter changes, 13D/13G, Forms 3/4/5, 144; the short side with
  revisions as rows, FINRA's caveat as a column, consecutive list days, the overlay; the reconciliation; **nineteen rules** as SQL
  functions with an evidence class, the runner (one open per rule per security), dispositions, proposals, outcomes; the reused
  tables, reports with a frozen narrative, import templates seeded from the fixtures' layouts, the tombstone; the search index with
  both walls; the mcp_* views — `db/proof/phase0_proof.sql`: **142 checks**, every one of §10's list, against the fixtures.
- **The fixtures** (`tests/fixtures/`, built by `build_fixtures.py` from the reference files, the owner's answer (a)): the NOBO list
  (841 rows, 1,450,101 shares; the header's 855 is a difference the tie names) and the STC register (842 rows, 17,053,900 shares;
  Cede & Co 1,581,000) **anonymised** — every natural person's name, street and zip replaced deterministically so a person's three
  registrations stay one person, the custodian lines, legends, entity names of brokers and Cede kept; a synthetic SPR fortnight
  over seventeen seeded participants whose total ties to Cede, with a crafted retail→prime migration and a participant jump; the
  public-record samples in the fetchers' shapes (a FINRA short-interest page, a `CNMSshvol` day file, a `cnsfails` half-month, a
  Nasdaq threshold file, a stooq CSV, a 13F `INFOTABLE` slice, 13D/G rows, Form 4 and 144 rows, a filing index) and the XML/JSON/HTML
  samples the Phase 3 parsers are tested against. Nothing of a real person. **The owner's real SPR week is still awaited: the
  weekly-grid connector is built from the reference implementation's shape and is confirmed on the first real file.**
- `maludb-os.json` (the manifest: five shares, the approvals, the two agents with their grants and the Analyst's duty
  `0 7 * * 1-5` in `America/New_York`, `settings_required_at_install` for K35), `os/expert.md`, `os/analyst.md`, ten skills (five
  knowledge, five runbooks), `deploy/` (the vhost with the API allow-list, the units, the registry's resolve block, `ROOT_STEPS.sh`
  with the EDGAR user-agent step), `config/.env.example` (`SI_SECRETS_KEY` for the sources' credentials); the installer's `plan`
  reads clean (34 steps, 8 notes; the catalog row found; two agents proposed with 87 and 46 tools).
- **Found in Phase 0**: a trigram threshold of 0.85 never matches a real one-letter typo on a short name (0.6 with the address
  exact does); an account's position must be the sum of its rows (several certificates of one registration); a subquery beside a
  function call in one statement is planned before the call runs (every proof check calls, then reads); a rule key may not start
  with a digit (`13f_exit` → `top_holder_exit`); the dilution-overlap rule fires in the same run as the short-side rules (the rules
  run in order); the plugin's catalogue gains this application's canonical tables.
**Next:** Phase 1 — `docs/shareholder-intelligence-mcp-tool-surface.md`, `docs/shareholder-intelligence-action-manifest.md`,
`mcp/action_registry.json`, the four build specs and the eleven slice specs, for the owner's approval together; then Phase 2.

**2026-10-09 — APPROVED the same day (§15: D1–D16 and (a)–(d), every recommendation), walked through one by one with the owner.**
K34 built in the kernel (db/178, applied).

**2026-10-09 — planned; awaiting the owner's decisions (§13).** The research of §0 done: the field (Q4, Nasdaq IR Intelligence,
Broadridge, Georgeson, Alliance, Morrow Sodali, Okapi, Irwin, S&P, Sequire; Ortex, S3, Fintel, Breakout Point, Insightia,
Hazeltree; edgartools, sec-edgar-downloader, sec-api.io — and the finding that no open parser of an SPR or a NOBO list exists),
the data an issuer can obtain with its cost and cadence (DTC's SPR and its 2009 fee card, Broadridge's NOBO form and the
$0.065-a-name rule, the OBO remainder, the register and GameStop's 10-K as the worked example, DTCC's public participant
directory parsed with the numbers that matter, EDGAR's APIs, specs, data sets and the user-agent rule, FINRA's short interest
and daily files, the SEC's fails, the threshold lists, Rule 204, and the regulatory clock — Form SHO 2028, SLATE 2028/29), the
evidence behind each signal sorted into established / contested / lore, the owner's reference implementation read in full
and its three file formats taken as the fixtures, the estate surveyed in every sibling's `db/*.sql` (Inventory's `sources`
and pulls as the fetcher model, Knowledge's `source_text` and `sections` as the shape of a file cut into proven rows, the
GL's reconciliation, Spaces' `imports`/`exports`/`search_index`, the kit), the free ports and prefix found, the category gap
(K34) noted. Repository `maludb/maludb-os-shareholder-intelligence` created private and cloned to `/srv/apps/shareholder_intelligence`.
**Next:** the owner's answers and the three anonymised files → K34 (kernel db/178) → Phase 0 (schema and proofs against the
fixtures, the kit, `maludb-os.json`, agents, skills, `deploy/`, the plugin's catalogue) → Phase 1 for approval → Phase 2 → slice 1
→ the exemplars (slice 2 the SPR, slice 3 the holders, slice 7 the signals) → the handoff to Sonnet 5.5.
