# Shareholder Intelligence Action Manifest

2026-10-09 · **Phase 1, for the checkpoint**

> The registry that the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent. That is unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/shareholder-intelligence-mcp-tool-surface.md` (read tools), the four build specs and the eleven slice
> specs in `docs/build-specs/`. `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file. An action whose file
> does not exist yet is registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**:
> `php bin/build_action_registry.php --approvals` (or `bin/sync_approvals.php`) rewrites that block from this file, and `--check` (in
> every proof) fails when the registry or the approvals are stale. The installer reads the resolve block from
> `deploy/kernel-registry-shareholder_intelligence.json`.

## Conventions

### Screens
- **Ids** are kebab-case: `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-shareholder_intelligence.conf` (and `tests/dev_router.php`): `/{x}/new` → `form.php`,
  `/{x}/{id}/edit` → `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`. Every id is an integer. **Every
  screen under an issuer carries the issuer** from the header's issuer switcher (`?issuer=` on the first visit, then the session) — the
  wall of db/006 decides which issuers the switcher offers. The home, the signals, a chart, a holder and the morning note are read at
  375 px first; the position table, the movement matrix, the overlay chart and the pack are designed at 1280 px and work at 375, one
  column at a time. No screen is a modal; the record picker is the one sanctioned modal.
- **Prefill params** become query-string values that the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity` and `data-record-id` on `#page-content`, so "dismiss this one", "tag that
  holder" and "run the rules on this security" resolve against the page the user is on.
- **Doors that are not screens** have no row here: the records API (`/api/v1/*`), the picker (`/pick/`), the polling partials
  (`/uploads/{id}/status`, `/positions/columns`), an export's download (`/exports/{id}`), a pack's file (`/reports/{id}/file`), `/sso`
  and `/sso/logout`.

### Actions
- **Name** `{entity}_{verb}`. The **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `issuer_id`,
  `security_id`, `import_id`, `signal_id` on every row that concerns one). It is also what the kernel's approval policies match
  (`approval_policies.action_pattern` = the registry's `log_event`). So **an action that pauses for an agent has a log event no free
  action shares**: `report.send` beside `report.draft`; `upload.delete` beside `upload.restore`; `holder.merge` beside `holder.update`;
  `issuer.save`, `security.save`, `share_count.save`, `restriction.save`, `rule.save`, `rule.override`, `source.save`, `source.schedule`,
  `credential.set`, `settings.save`, `agents_settings.save`, `report.approve`, `upload.override`, `key.mint` beside the free
  `participant.update`, `event.create`, `note.add`.
- **Endpoints** are POST to `/{base}/{file}` with `si_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the relayed
  action token), the right (`require_right()`), the issuer's wall (`require_visible()`: `none` → 404 — the same nothing for a record that
  does not exist), `si_guard()` around the database's verb, `log_activity()`, `emit_action_status()` and `si_done()`. PHP never re-implements
  a rule the database keeps (the tie, the matcher's scores, the merge's re-pointing, the reconciliation, the rules' thresholds, one open
  signal per rule, the pack's frozen narrative, the tombstone). A create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record accepts an id or a name, resolved through the records tool named in the resolve block of
  `deploy/kernel-registry-shareholder_intelligence.json`: `issuer`, `company`, `security`, `cusip`, `class`, `participant`, `broker`,
  `custodian`, `holder`, `survivor`, `loser`, `institution`, `filer`, `fund`, `filing`, `event`, `explaining_event`, `signal`, `rule`,
  `upload`, `import`, `report`, `pack`, `source`, `fetcher`, `instrument`, `tag`, `api_key`, `member`, `person_member`, `agent`,
  `recipient`, `who`, `department`. `proposal`, `row` (a NOBO or register row), `share_count`, `observation`, `watch_hit`, `template`,
  `credential`, `note`, `attachment`, `dispatch`, `notification`, `token`, `export`, `override` are ids only. `any field of X` means X's
  fields, all optional: a partial update. **A holder is resolved only for an Analyst** (the PII wall): the resolver answers a Viewer nothing.
- **Who** is enforced in the endpoint: `own` = the caller's own thing; a right (`uploads.write`, `matches.decide`, `events.write`,
  `borrow.write`, `signals.work`, `notes.write`, `tags.apply`, `reports.run`, `export.own`, `tokens.own`, `issuers.manage`, `rules.manage`,
  `restrictions.manage`, `holders.merge`, `uploads.delete`, `reports.approve`, `agents.settings`, `export.shared`, `settings.manage`,
  `sources.manage`, `credentials.manage`, `tokens.manage`, `api.manage`, `records.purge`) is held through `si_has_right()`; **`see`** means
  the issuer wall (`si_can_see_issuer()`); **`pii`** means `si_sees_holder_pii()` (Analyst and above). A super-admin holds every right. An
  agent is granted like a person.
- **Undo** is the inverse `undo_last` applies. `—` means it cannot be undone, and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, it reaches people outside, or it changes many records).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5): `external_send`
  (words leave the application: a pack sent to members' mail), `deletion` (a tombstone, a merge, a purge), `other` (the measure, the
  doors and the rules: issuers, securities, share counts, restrictions, rules and overrides, sources and schedules, credentials, settings,
  the agents' settings, a pack's approval, an upload's override, a key). People are never paused. **Looking, computing, proposing and
  annotating inside the business are free.** The categories are exactly `maludb-os.json` `approvals[]`.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`). An
  action that pauses is still `yes`: the pause comes first, then the call.

### Result contract
```json
{"status": "success", "did": "Loaded the SPR for the week ending 2024-06-28: 45 participants, 5 dates, tied", "record_id": 12, "undo_id": "act_31", "refresh": "uploadChanged"}
{"status": "pending_approval", "did": "The pack for the week to 2024-06-28 waits for a person's approval before it is sent", "approval_request_id": 17}
{"status": "refused", "did": "A signal explained by an event names the event", "errors": {"explaining_event": "required for this reason"}}
```

## Home, me and the shell (Phase 2; the search box)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | the start for the current issuer: the morning note's last text, the reconciliation tile, the short-side tiles with their dates, the open signals by severity, the price with the events overlay, the next thirty days' events, the latest upload per kind with its tie state; a new installation's one button, Add your first issuer (params: `issuer`) |
| `search` | `/search` | the one box's results grouped by kind with highlights — a Viewer's results never name a person (params: `q`, `kinds`, `issuer`) |
| `notifications` | `/notifications` | my notices, unread first |
| `activity` | `/activity` | my own trail here |
| `settings` | `/settings/` | my preferences: the morning note and its hour, the minimum signal severity, email and text, which issuers |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens and my API keys: mint (shown once), rotate, revoke |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | morning_note (yes or no), morning_hour (0 to 23 or blank for the setting), signals_min_severity (info or watch or high), email_enabled (yes or no), text_enabled (yes or no), kinds[], issuer_ids[] | restore prior | | | `settings.prefs` | own | yes | the member |
| `notification_read` | `/notifications/read.php` | **notification** | — | | | `notification.read` | own | yes | the notification |
| `notifications_read_all` | `/notifications/read-all.php` | | — | | | `notification.read_all` | own | yes | the member |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp or api) | token_revoke | | | `token.mint` | own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own or tokens.manage | people | the token |

## Issuers, securities, share counts and the DTC participants (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `issuer-list` | `/issuers` | the issuers they may see, as cards: name, ticker, exchange, the primary security's CUSIP, the last position date, the restriction mark (params: `q`) |
| `issuer-view` | `/issuers/{id}` | one issuer: the EDGAR entity, the securities, the share counts, the transfer agent, the restriction list, the last uploads, the sources' state for it (params: `tab`) |
| `issuer-add` | `/issuers/new` | to set up an issuer: the ticker with the CIK lookup, the name, the exchange, the transfer agent and its connector, the time zone (params: `ticker`, `cik`) |
| `issuer-edit` | `/issuers/{id}/edit` | to change an issuer's fields, holidays, notes |
| `issuer-securities` | `/issuers/{id}/securities` | the issuer's securities with CUSIP, class, ticker, primary; add and edit forms on the page |
| `issuer-share-counts` | `/issuers/{id}/share-counts` | the dated share counts by kind and source; record one (params: `security`, `kind`) |
| `issuer-access` | `/issuers/{id}/access` | the restriction list: the members who may see this issuer; add and remove (managers) |
| `participant-list` | `/participants` | the DTC participants as cards by class with number, name, aliases, the latest position for the current security (params: `class`, `q`) |
| `participant-view` | `/participants/{id}` | one participant: the series chart for the current security, the NOBO summary on each record date, the OBO remainder, aliases, class with its source, notes, tags (params: `security`, `from`, `to`) |
| `participant-edit` | `/participants/{id}/edit` | to set a participant's class (manual) and notes |

Actions (base `/issuers/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `issuer_save` | `save.php` | **name**, cik (ten digits), ticker, exchange (nasdaq or nyse or nyse_american or otc or cse or tsx or tsxv or other), state_of_incorporation, fiscal_year_end (MMDD), sic, transfer_agent, transfer_agent_connector (stc or computershare or continental or vstock or pacific or equiniti or generic), timezone, market_holidays[], notes, issuer (to change an existing one) | restore prior | | other | `issuer.save` | issuers.manage | yes | the issuer |
| `issuer_delete` | `delete.php` | **issuer** (to the tombstone with everything under it) | issuer_restore | ✔ | deletion | `issuer.delete` | issuers.manage | yes | the issuer |
| `issuer_restore` | `restore.php` | **issuer** | — | | | `issuer.restore` | issuers.manage | yes | the issuer |
| `issuer_edgar_sync` | `edgar-sync.php` | **issuer** (the submissions JSON now: the entity and the index) | — | | | `issuer.edgar_sync` | issuers.manage | yes | the issuer |
| `security_save` | `securities/save.php` | **issuer**, **name**, class (common or preferred or warrant or unit or note or other), cusip (nine characters), isin, ticker, figi, par_value, is_primary (yes or no), listed_on, delisted_on, security (to change an existing one) | restore prior | | other | `security.save` | issuers.manage | yes | the security |
| `security_delete` | `securities/delete.php` | **security** (with its positions and series) | — | ✔ | deletion | `security.delete` | issuers.manage | yes | the security |
| `share_count_save` | `share-counts/save.php` | **security**, **as_of** (a date), **kind** (outstanding or float or authorized or restricted or unrestricted or treasury or cede or drs or certificated or plan or public_float_value), **value**, source (manual or transfer_agent or dtc_letter), note | share_count_delete | | other | `share_count.save` | issuers.manage | yes | the share count |
| `share_count_delete` | `share-counts/delete.php` | **share_count** | — | ✔ | deletion | `share_count.delete` | issuers.manage | yes | the share count |
| `restriction_save` | `access/save.php` | **issuer**, members[] (the whole list — empty lifts the restriction) | restore prior | ✔ | other | `restriction.save` | restrictions.manage | yes | the issuer |
| `participant_update` | `/participants/save.php` | **participant**, class (retail or clearing_retail or prime or market_maker or lending or transfer_agent or bank_custodian or other), notes, tags[] | restore prior | | | `participant.update` | issuers.manage | yes | the participant |
| `tag_apply` | `/tags/apply.php` | **kind** (holder or institution or participant or event or issuer or signal), **record**, **tag** (a tag or a new label) | tag_remove | | | `tag.apply` | tags.apply | yes | the record |
| `tag_remove` | `/tags/remove.php` | **kind**, **record**, **tag** | tag_apply | | | `tag.remove` | tags.apply | yes | the record |
| `note_add` | `/notes/add.php` | **kind** (issuer or holder or institution or participant or event or signal or report or dilution_instrument or filing), **record**, **body** | — | | | `note.add` | notes.write | yes | the note |

## Uploads and THE SPR — THE FIRST EXEMPLAR (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `upload-list` | `/uploads` | the uploads of the current issuer by kind with their tie state, counts, who and when; the queue (params: `kind`, `security`, `status`) |
| `upload-add` | `/uploads/new` | to upload a file: the kind, the security, the connector (detected from the file's first cells where it can be), the record date or period (params: `kind`, `security`) |
| `upload-view` | `/uploads/{id}` | one upload: control totals against derived, tied or the difference named, the rows with the ones that failed to type first, the override, the matcher's counts, delete and restore (params: `rows`) |
| `positions` | `/positions` | the position table: the participants ranked on a date for the current security with the class chip, the change against the previous date and the window's open and net (params: `security`, `date`, `from`, `to`, `class`, `q`) |
| `positions-matrix` | `/positions/matrix` | the movement matrix: participants by position dates, the cells coloured by change, sortable by net; one column a date at 375 (params: `security`, `from`, `to`, `class`) |
| `positions-flow` | `/positions/flow` | the flow by class: net shares per class per date as a stacked bar and a cumulative line, with the lore caveat printed under it (params: `security`, `from`, `to`) |
| `positions-concentration` | `/positions/concentration` | the concentration over time: top-5, top-10, top-20 shares of the DTC total and the index (params: `security`, `from`, `to`) |
| `reconciliation` | `/reconciliation` | the reconciliation's runs for the current security: outstanding against the register's components, Cede against the SPR total, tied or the differences named; run one now (params: `security`) |

Actions (base `/uploads/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `upload_create` | `save.php` | **kind** (spr or nobo or register or share_range or prices_csv), **security**, **file** (the file itself — a person's form or the API), connector (detected when omitted), record_date (a NOBO job or a register), template (a saved mapping for a generic file) | upload_delete | | | `upload.create` | uploads.write | people | the upload |
| `upload_reparse` | `reparse.php` | **upload** (the file parsed again — after a template changed) | — | | | `upload.reparse` | uploads.write | yes | the upload |
| `upload_override` | `override.php` | **upload**, **note** (why the difference is accepted) | — | ✔ | other | `upload.override` | issuers.manage | yes | the upload |
| `upload_delete` | `delete.php` | **upload** (to the tombstone with the rows it made) | upload_restore | ✔ | deletion | `upload.delete` | uploads.delete | yes | the upload |
| `upload_restore` | `restore.php` | **upload** | upload_delete | | | `upload.restore` | uploads.delete | yes | the upload |
| `reconciliation_run` | `/reconciliation/run.php` | **security**, as_of (a date — today when omitted), note | — | | | `reconciliation.run` | uploads.write | yes | the reconciliation |

## NOBO, the register and HOLDERS (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `holder-list` | `/holders` | the holders of the current issuer as cards (names for the PII roles; "Individual holder" for a Viewer) with the latest position, the accounts, the kind; filters (params: `channel`, `record_date`, `kind`, `custodian`, `q`, `tag`) |
| `holder-view` | `/holders/{id}` | one holder: the accounts (the registration lines as read — PII roles), the positions by record date and channel as a chart, the insider and institution links, notes, tags, Merge into… (managers) (params: `tab`) |
| `holder-edit` | `/holders/{id}/edit` | to set a holder's kind, entity type, address, notes (PII roles) |
| `holder-merge` | `/holders/{id}/merge` | the two-column compare page for a merge: this holder and the one chosen by the picker; Merge (managers) (params: `into`) |
| `holders-unmatched` | `/holders/unmatched` | the queue: a raw registration on the left, the candidates with score and evidence on the right; Accept, Reject, New holder, Set kind (params: `upload`, `kind`) |
| `holders-changes` | `/holders/changes` | holders between two record dates: new, lost, up, down with the shares (params: `security`, `channel`, `date_a`, `date_b`) |
| `holders-ranges` | `/holders/ranges` | holders by share range on a record date — from the list, and from Broadridge's Share Range Analysis with the OBO population when one is loaded (params: `security`, `channel`, `record_date`) |
| `holders-geography` | `/holders/geography` | holders by country and state on a record date (params: `security`, `channel`, `record_date`) |
| `holders-custodians` | `/holders/custodians` | NOBO holders and shares by custodian (participant) and account kind on a record date, beside the OBO remainder (params: `security`, `record_date`) |
| `holders-register` | `/holders/register` | the register's rows on a record date by position type with Cede flagged, and the components the reconciliation reads (params: `security`, `record_date`) |

Actions (base `/holders/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `match_accept` | `matches/accept.php` | **proposal** | — | | | `match.accept` | matches.decide | people | the holder |
| `match_reject` | `matches/reject.php` | **proposal** (remembered — never proposed again) | — | | | `match.reject` | matches.decide | people | the proposal |
| `match_new_holder` | `matches/new-holder.php` | **row_kind** (nobo or register), **row** (the raw row — a new holder whatever the candidates) | — | | | `match.new_holder` | matches.decide | people | the holder |
| `holder_match_propose` | `matches/propose.php` | **row_kind** (nobo or register), **row**, **holder** (the candidate), evidence (one sentence) | — | | | `match.propose` | matches.decide or pii | yes | the proposal |
| `holder_kind_propose` | `kind-propose.php` | **holder**, **kind** (person or entity or custodian_account), entity_type, evidence | — | | | `holder.kind_propose` | pii | yes | the holder |
| `holder_kind_set` | `kind.php` | **holder**, **kind** (person or entity or custodian_account or unknown), entity_type (trust or corporation or fund or partnership or estate or bank or broker or government or nonprofit or other) | restore prior | | | `holder.kind_set` | matches.decide | people | the holder |
| `holder_update` | `save.php` | **holder**, any field of holder_kind_set, display_name, address_line, city, state, country, zip, notes, is_insider (yes or no), institution | restore prior | | | `holder.update` | matches.decide | people | the holder |
| `holders_merge` | `merge.php` | **survivor** (a holder), **loser** (a holder of the same issuer and kind) | — | ✔ | deletion | `holder.merge` | holders.merge | yes | the holder |
| `holder_delete` | `delete.php` | **holder** (to the tombstone) | holder_restore | ✔ | deletion | `holder.delete` | holders.merge | yes | the holder |
| `holder_restore` | `restore.php` | **holder** | — | | | `holder.restore` | holders.merge | yes | the holder |
| `matcher_run` | `matches/run.php` | **upload** (every unmatched row of it scored again) | — | | | `matcher.run` | uploads.write | yes | the upload |

## EDGAR — the index, the text, the watch and the dilution instruments (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `filing-list` | `/filings` | the filings of the current issuer: form, date, items, title, the text and structured chips, about-us filings by others (params: `form`, `from`, `to`, `about_us`, `q`) |
| `filing-view` | `/filings/{id}` | one filing: the text with search hits highlighted, the structured block when it is a 13D, a Form 4 or a 144, the event it made; Fetch text, Make event (params: `q`) |
| `filings-search` | `/filings/search` | a search in the words of every filing's text with highlights (params: `q`, `form`) |
| `watch-hits` | `/filings/watch` | the full-text watch's hits with the term, the filing and the snippet; Confirm instrument, Dismiss (params: `status`) |
| `dilution-list` | `/dilution` | the dilution instruments as cards: kind, counterparty, facility and remaining, the price terms, the floating-price flag, the status (params: `status`, `kind`) |
| `dilution-view` | `/dilution/{id}` | one instrument with its filing, terms, remaining, notes and the signals it is part of |
| `dilution-add` | `/dilution/new` | to record an instrument by hand (params: `filing`, `kind`) |
| `dilution-edit` | `/dilution/{id}/edit` | to confirm or change an instrument's kind, counterparty, amounts, terms, status |

Actions (base `/filings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `filing_fetch_text` | `fetch-text.php` | **filing** (the primary document fetched and its text kept — under the source's policy) | — | | | `filing.fetch_text` | events.write | yes | the filing |
| `filing_event_make` | `event-make.php` | **filing**, title (the form's by default), severity (info or watch or high), body | event_delete | | | `filing.event_make` | events.write | yes | the event |
| `watch_hit_confirm` | `watch/confirm.php` | **watch_hit**, kind (atm or eloc or convertible_note or convertible_preferred or warrant or rights_offering or registered_direct or pipe or other), counterparty, facility_amount, price_terms, floating_price (yes or no) | — | | | `watch.confirm` | events.write | people | the instrument |
| `watch_hit_dismiss` | `watch/dismiss.php` | **watch_hit** | — | | | `watch.dismiss` | events.write | people | the watch hit |
| `dilution_save` | `/dilution/save.php` | **issuer**, **kind** (atm or eloc or convertible_note or convertible_preferred or warrant or rights_offering or registered_direct or pipe or other), security, counterparty, filed_on, filing, facility_amount, currency, remaining_amount, price_terms, floating_price (yes or no), shares_issuable_max, expires_on, status (proposed or active or exhausted or terminated), notes, instrument (to change an existing one) | restore prior | | | `dilution.save` | events.write | people | the instrument |
| `dilution_delete` | `/dilution/delete.php` | **instrument** | — | ✔ | deletion | `dilution.delete` | events.write | yes | the instrument |
| `fetch_now` | `/admin/sources/pull.php` | **source** (a pull now within its policy — a blocked source stays blocked), issuer (one issuer only) | — | | | `source.pull_now` | uploads.write | yes | the pull |

## EDGAR — the ownership forms (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `institution-list` | `/institutions` | the 13F and 13D/13G filers as cards with kind, the latest holding in the current security, the activist mark (params: `kind`, `q`, `activist`) |
| `institution-view` | `/institutions/{id}` | one institution: its holdings in the current security by quarter as a chart, its 13D/13G history, its filings about us, aliases, notes, tags (params: `security`) |
| `institution-edit` | `/institutions/{id}/edit` | to set an institution's kind, activist flag, website, notes |
| `ownership-13f` | `/ownership/13f` | the 13F table for a quarter: holders ranked with shares, value, voting authority and the share of outstanding; the quarter's summary and the concentration (params: `security`, `period`) |
| `ownership-13f-changes` | `/ownership/13f/changes` | the quarter against the prior: new, out, up, down, with the shares (params: `security`, `period`, `state`) |
| `ownership-beneficial` | `/ownership/beneficial` | the 5% holders: the latest 13D or 13G per filer with percent, shares, the event date and the purpose (params: `security`) |
| `ownership-insiders` | `/ownership/insiders` | the insider ledger: Form 3/4/5 transactions by person with codes, shares, price, owned after, and the register link (params: `from`, `to`, `owner`, `code`) |
| `ownership-144` | `/ownership/144` | the Form 144 notices: seller, shares, approximate date, broker (params: `from`, `to`) |

Actions (base `/institutions/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `institution_update` | `save.php` | **institution**, kind (investment_adviser or hedge_fund or bank or insurance or pension or mutual_fund or etf_sponsor or activist or individual or corporation or family_office or other), is_activist (yes or no), website, notes, tags[] | restore prior | | | `institution.update` | events.write | yes | the institution |
| `insider_holder_link` | `/ownership/insiders/link.php` | **reporting_owner_cik** (the insider), **holder** (the register's holder) | restore prior | | | `insider.holder_link` | matches.decide | people | the holder |

## The short side (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `short` | `/short` | the short-side page: one chart of price and volume above and short interest as a share of float, fails, the 5-day short-volume ratio, threshold days shaded and borrow points below, with the events overlay (params: `security`, `from`, `to`) |
| `short-interest` | `/short/interest` | the short-interest series as a table: settlement date, publication, short, change, days to cover, the share of float and of outstanding, revisions (params: `security`, `from`, `to`) |
| `short-volume` | `/short/volume` | FINRA's daily off-exchange short volume with the 5- and 20-day means and the caveat on the page (params: `security`, `market`, `from`, `to`) |
| `short-fails` | `/short/fails` | the fails-to-deliver series with the share of outstanding and the 60-day median (params: `security`, `from`, `to`) |
| `short-threshold` | `/short/threshold` | the threshold-list history: on which list, since when, the runs (params: `security`) |
| `short-borrow` | `/short/borrow` | the borrow observations: date, source, fee, utilisation, note (params: `security`, `from`, `to`) |
| `borrow-add` | `/short/borrow/new` | to record a borrow observation: the date, the source (broker quote or manual), the fee, the utilisation, shares on loan and available, a note (params: `security`) |

Actions (base `/short/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `borrow_observation_create` | `borrow/save.php` | **security**, **observed_on** (a date), source (broker_quote or manual), fee_rate_percent, utilization_percent, shares_on_loan, shares_available, note | borrow_observation_delete | | | `borrow.record` | borrow.write | yes | the observation |
| `borrow_observation_delete` | `borrow/delete.php` | **observation** | — | ✔ | deletion | `borrow.delete` | borrow.write | yes | the observation |

## SIGNALS — THE SECOND EXEMPLAR (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `signal-list` | `/signals` | the signals of the current issuer as cards by severity with the evidence-class chip, the rule, the window, the summary, the agent's proposal when one waits (params: `status`, `severity`, `rule`, `security`, `class`) |
| `signal-view` | `/signals/{id}` | one signal: the summary, the basis paragraph, the evidence rendered as rows and a chart of the window, the related events, the outcome prices; Acknowledge, Dismiss with a reason and the explaining event, Escalate; the Analyst's proposal with Take it |
| `signal-history` | `/signals/history` | every signal of a period with its disposition, who and why; the proposals accepted and rejected (params: `from`, `to`, `rule`, `status`) |
| `rule-list` | `/rules` | the rules catalogue: key, name, class, basis, severity, thresholds, last fired, enabled; the per-issuer overrides (params: `class`) |
| `rule-edit` | `/rules/{id}/edit` | to set a rule's thresholds and enable it, globally or for the current issuer (managers) (params: `issuer`) |

Actions (base `/signals/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `signal_acknowledge` | `acknowledge.php` | **signal**, reason (known or watching), note | — | | | `signal.acknowledge` | signals.work | people | the signal |
| `signal_dismiss` | `dismiss.php` | **signal**, **reason** (explained_by_event or data_defect or false_positive or other), explaining_event (required for explained_by_event), note | — | | | `signal.dismiss` | signals.work | people | the signal |
| `signal_escalate` | `escalate.php` | **signal**, note (to the IR Manager) | — | | | `signal.escalate` | signals.work | people | the signal |
| `signal_propose` | `propose.php` | **signal**, **reason** (known or watching or explained_by_event or data_defect or false_positive or escalate), explaining_event, note (one sentence of why) | — | | | `signal.propose` | signals.work | yes | the signal |
| `signal_note` | `note.php` | **signal**, **body** | — | | | `signal.note` | notes.write | yes | the signal |
| `signal_delete` | `delete.php` | **signal** (a signal raised on data since corrected) | — | ✔ | deletion | `signal.delete` | rules.manage | yes | the signal |
| `rules_run` | `run.php` | **security** (every enabled rule now), as_of (a date — today when omitted) | — | | | `rules.run` | signals.work | yes | the security |
| `rule_save` | `/rules/save.php` | **rule**, params (the thresholds as an object), enabled (yes or no), severity_default (info or watch or high), window_days | restore prior | | other | `rule.save` | rules.manage | yes | the rule |
| `rule_override_save` | `/rules/override.php` | **rule**, **issuer**, params (the issuer's thresholds), enabled (yes or no or blank for the rule's) | restore prior | | other | `rule.override` | rules.manage | yes | the rule |

## Events, the calendar, reports and the pack (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `event-list` | `/events` | the timeline of the current issuer: filings, financings, record dates, earnings, short reports, notes — by kind and severity (params: `kind`, `severity`, `from`, `to`, `q`) |
| `event-view` | `/events/{id}` | one event with its filing, the signals it explains, notes, tags |
| `event-add` | `/events/new` | to record an event: the date, the kind, the title, the body, the URL, the severity (params: `on_date`, `kind`, `filing`) |
| `event-edit` | `/events/{id}/edit` | to change an event |
| `calendar` | `/calendar` | the month grid of what happened and what is coming: record dates, earnings, expiries, publication days (params: `at`, `kinds`) |
| `report-list` | `/reports` | the packs as cards: kind, period, status, approved by; the Friday draft (params: `kind`, `status`) |
| `report-view` | `/reports/{id}` | one pack: the narrative (the agent's draft or the person's edit), the sections toggled, the rendered preview, Approve, Download PDF, Send to… (members) |
| `report-add` | `/reports/new` | to draft a pack by hand: the kind, the period, the sections (params: `kind`, `from`, `to`) |
| `report-edit` | `/reports/{id}/edit` | to edit a draft's narrative and sections (frozen once approved) |
| `export-list` | `/exports` | my exports and the shared ones: kind, rows, size, expiry; download (params: `kind`) |

Actions (base `/events/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `event_create` | `save.php` | **issuer**, **on_date** (a date), **kind** (filing or financing or dilution_instrument or record_date or earnings or press or activist_short_report or lawsuit or exchange_notice or insider_transaction or share_count or threshold or note or other), **title**, security, body, url, filing, severity (info or watch or high), tags[] | event_delete | | | `event.create` | events.write | yes | the event |
| `event_update` | `save.php` | **event**, any field of event_create | restore prior | | | `event.update` | events.write | yes | the event |
| `event_delete` | `delete.php` | **event** (to the tombstone) | event_restore | ✔ | deletion | `event.delete` | events.write | yes | the event |
| `event_restore` | `restore.php` | **event** | — | | | `event.restore` | events.write | yes | the event |
| `report_draft` | `/reports/save.php` | **issuer**, kind (weekly_pack or monthly_pack or board_pack or record_date_pack or custom), **period_from** (a date), **period_to**, title, narrative (Markdown), sections[] | report_delete | | | `report.draft` | reports.run | yes | the report |
| `report_update` | `/reports/save.php` | **report**, any field of report_draft | restore prior | | | `report.update` | reports.run | yes | the report |
| `report_render` | `/reports/render.php` | **report** (the HTML and the PDF when the renderer is present) | — | | | `report.render` | reports.run | yes | the report |
| `report_approve` | `/reports/approve.php` | **report** (the narrative is frozen) | — | ✔ | other | `report.approve` | reports.approve | yes | the report |
| `report_send` | `/reports/send.php` | **report**, **recipients[]** (members only), message | — | ✔ | external_send | `report.send` | reports.approve | yes | the report |
| `report_delete` | `/reports/delete.php` | **report** (a draft or an approved pack to the tombstone) | — | ✔ | deletion | `report.delete` | reports.approve | yes | the report |
| `export_create` | `/exports/create.php` | **kind** (positions_csv or holders_csv or institutions_csv or short_csv or signals_csv or events_csv or filings_csv), **security**, from, to, record_date, channel (a holders export is logged with its row count — PII roles) | — | | | `export.create` | export.own | yes | the export |

## Agents, proposals, notifications, K7 and the API (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `proposals` | `/proposals` | everything the agents proposed and nobody has taken: signal dispositions, holder matches, holder kinds, watch hits — each with its one-click button (params: `kind`, `issuer`) |
| `admin-agents` | `/admin/agents` | the agents with their last dispatch, the dispatches table with status and Cancel, the Analyst's settings (issuers, the hour, the pack's day, proposals on or off) |

Actions (base `/agents/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `notification` | `notify.php` | **recipient** (a member), **title**, body, kind (morning_note or signal or mention or digest), issuer (never about an issuer the recipient may not see) | — | | | `notification.send` | signals.work | yes | the notification |
| `dispatch_cancel` | `dispatches/cancel.php` | **dispatch** | — | | | `dispatch.cancel` | agents.settings | people | the dispatch |
| `agents_settings_save` | `settings.php` | morning_note_enabled (yes or no), morning_note_hour (0 to 23), analyst_proposals (yes or no), pack_day (1 to 7), analyst_issuers[] (empty for every issuer), escalate_high_to (a member) | restore prior | | other | `agents_settings.save` | agents.settings | people | the settings |
| `key_mint` | `/settings/tokens/key-mint.php` | **label** | key_revoke | | other | `key.mint` | tokens.own | people | the key |
| `key_rotate` | `/settings/tokens/key-rotate.php` | **api_key** (the old one keeps 24 hours) | — | | | `key.rotate` | tokens.own | people | the key |
| `key_revoke` | `/settings/tokens/key-revoke.php` | **api_key** | — | ✔ | | `key.revoke` | tokens.own or tokens.manage | people | the key |

## Admin: sources, credentials, classes, templates, settings, tokens, purge (slice 10)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin-sources` | `/admin/sources` | the fetchers with their schedule, the last pull, the back-off, paused; Pull now, Pause, Resume (params: `connector`) |
| `admin-source-view` | `/admin/sources/{id}` | one source: its settings, its credential (the label and last4 only), its pulls as a table with status, counts, bytes and the policy followed |
| `admin-source-edit` | `/admin/sources/{id}/edit` | to change a source's name, base URL, settings, schedule, rate, user-agent override, active |
| `admin-source-credential` | `/admin/sources/{id}/credential` | to set or rotate the source's credential (never shown back) |
| `admin-participant-classes` | `/admin/participant-classes` | the class vocabulary and which participants sit in each; reclass (params: `class`) |
| `admin-templates` | `/admin/templates` | the import templates (the saved column mappings) by kind and connector |
| `admin-template-edit` | `/admin/templates/{id}/edit` | to change a template's mapping (params: `kind`) |
| `admin-settings` | `/admin/settings` | the application settings: the business name and the EDGAR contact (the user-agent), the exchange, the market time zone and holidays, the EDGAR rate, the reconciliation tolerance, the auto-match threshold, PII in exports, the escalation member, the key rates, the upload ceiling, the price source |
| `admin-tokens` | `/admin/tokens` | everyone's keys and tokens: label, person, scope, limits, usage; revoke (params: `member`) |
| `admin-purge` | `/admin/purge` | the tombstones waiting: uploads, holders, events, reports, issuers; restore or purge now |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `source_save` | `sources/save.php` | **source**, name, base_url, settings (an object), rate_per_second (0.1 to 10), user_agent, active (yes or no) | restore prior | | other | `source.save` | sources.manage | yes | the source |
| `source_schedule_set` | `sources/schedule.php` | **source**, **schedule_minutes** (0 for manual) | restore prior | | other | `source.schedule` | sources.manage | yes | the source |
| `source_pause` | `sources/pause.php` | **source**, reason | source_resume | | | `source.pause` | sources.manage | yes | the source |
| `source_resume` | `sources/resume.php` | **source** | source_pause | | | `source.resume` | sources.manage | yes | the source |
| `credential_set` | `sources/credential.php` | **source**, **kind** (api_key or bearer or basic or oauth_client), **label**, **secret** (never logged — a person's form) | — | ✔ | other | `credential.set` | credentials.manage | people | the source |
| `participant_class_set` | `participant-classes/set.php` | **participant**, **class** (retail or clearing_retail or prime or market_maker or lending or transfer_agent or bank_custodian or other) | restore prior | | | `participant.class_set` | issuers.manage | yes | the participant |
| `template_save` | `templates/save.php` | **name**, **kind** (spr or nobo or register or prices_csv or holders_csv or share_range), connector, **mapping** (an object), template (to change an existing one) | restore prior | | | `template.save` | settings.manage | yes | the template |
| `template_delete` | `templates/delete.php` | **template** | — | ✔ | deletion | `template.delete` | settings.manage | yes | the template |
| `settings_save` | `settings/save.php` | business_name, edgar_contact_email, default_exchange, market_timezone, market_holidays[], edgar_rate_per_second, reconciliation_tolerance_shares, auto_match_threshold (0.5 to 1), pii_in_exports (analyst or admin or nobody), escalate_high_to (a member), signal_grace_days, tombstone_days, retain_raw_rows_days, key_rate_per_minute, key_rate_per_day, max_upload_bytes, price_source_default, timezone | restore prior | | other | `settings.save` | settings.manage | people | the settings |
| `token_revoke_any` | `tokens/revoke.php` | **token** (anyone's) | — | ✔ | | `token.revoke_any` | tokens.manage | people | the token |
| `records_purge` | `purge/run.php` | kind (upload or holder or event or report or issuer or all), record (one record now) | — | ✔ | deletion | `records.purge` | records.purge | yes | the count |
