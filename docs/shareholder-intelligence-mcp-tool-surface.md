# Shareholder Intelligence — MCP tool surface

2026-10-11 · **PHASE 4 BUILT** (`mcp/records_server.py` with eleven `si_*` modules — 95 tools — and `mcp/activity_server.py` — 7 tools; proofs `tests/phase4/run.sh`; one migration, db/028: `mcp_key_usage`, `mcp_spr_rows`, `si_log_mcp_read`). Where this document and the schema or the built screens differ, the schema and the screens won: see "Built" at the foot.

2026-10-09 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/shareholder-intelligence-design.md` §7
(Q1–Q53), the five K7 shares, and the resolvers the kernel's actions server names. Writes are never here: they are the action tools the
kernel's Actions MCP builds from `docs/shareholder-intelligence-action-manifest.md`. **No tool reaches anyone outside the business, no tool
calls a model, and no tool returns a natural person's name, address or registration lines to a caller who is not an Analyst** (D4).

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd unit each
(`shareholder_intelligence-records-mcp`, `shareholder_intelligence-activity-mcp`); `mcp/db.py` and `mcp/server_common.py` (the kit's). Every
query runs through `db.fetch_scoped()` with `app.member_id` and `app.role` set **transaction-locally** from the verified bearer, so the
`mcp_*` views (db/016, 017, 018) and the read functions (db/006–018) decide the rows and answer as the caller. Tool names are snake_case.
Every tool takes one `params` object (Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described `Field` on every field). Every
list tool takes `limit` (1–100, default 25) and, where it pages, `offset` (0–10,000). Every tool carries `readOnlyHint: true` and
`openWorldHint: false`; every result is a JSON string (`db.to_json`). Ids are integers, dates ISO 8601, shares as integers, money as a
string with two decimals. **Every number carries its date and its source** in the answer (a position's `position_date`, a short-interest
row's `settlement_date` and `observed_at`, a share count's `as_of` and `source`). **The issuer wall is per statement** (`si_my_issuers()`
once); **the PII wall is per statement** (`si_sees_holder_pii()` once).

**The current issuer.** Every tool that reads an issuer's data takes `issuer` (an id, a ticker or a CIK) or `security` (an id, a CUSIP or a
ticker); when both are omitted and the caller may see exactly one issuer, that one is used; when they may see several the tool answers
`ambiguous` with the candidates. A security defaults to the issuer's primary one.

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`.
It never raises, except for the run-facts gate's own refusal, which is the contract's ToolError. Codes:

| Code | Means |
|---|---|
| `not_found` | no such record, **or one on an issuer the caller may not see**: the same words, so a tool never confirms that a restricted issuer exists |
| `pii_withheld` | the tool's answer is a natural person's details and the caller is not an Analyst; the aggregate tool is named in `message` |
| `managers_only` | the record is readable, but this tool is a manager's (the tombstones, another member's dispatches) |
| `invalid_argument` | a parameter failed validation; `fields` names it |
| `ambiguous` | a name matched several records (or no issuer was named and several are visible); `candidates[]` (≤ 6, id + label) |
| `no_data` | the series has no row in the window (no SPR uploaded yet, no short interest published): an answer, with what is needed to have one |
| `too_large` | the answer would pass the tool's byte cap (a filing's text over 1 MB through `filing_read`, a matrix over 500 cells): ask for less |

Gates are what the view or function already enforces. The tool only trims and chooses the error. Words used below (db/004, db/006, db/016,
design §3):

| Word | Means |
|---|---|
| `see` | `si_can_see_issuer()` for the record's issuer: the issuer carries no restriction list, or the caller is on it, or a manager |
| `pii` | `si_sees_holder_pii()`: Analyst and above (a holder of `holders.read`); a Viewer's answer is the aggregate form or `pii_withheld` |
| `own` | the caller's own: notifications, tokens, exports, trail |
| `insider` | `si_is_member_here()`: admitted (any role, agents included) |
| `manager` | `si_is_manager()`: `issuers.manage` or the admin |
| `admin` | `si_is_admin()`: the super-admin, or a human holding `settings.manage` |
| `kernel` | the kernel's own token (`kernel.{exp}.{app}.{nonce}.{hmac}`): `app_roles` and the five shares, nothing else |

**An agent is a member**: the same functions, no special case; the shipped agents hold Analyst and therefore read holders.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `shareholder_intelligence_records_mcp` | `shareholder_intelligence_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8845) | `MCP_ACTIVITY_PORT` (pinned 8846) |
| Role / env | `shareholder_intelligence_records_ro` | `shareholder_intelligence_activity_ro` |
| Sees | every `mcp_*` view but `mcp_activity_log`; the db/006–018 read functions granted to it; the `si_share_*` functions for the kernel's token; `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind` | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_issuers`, `mcp_securities` (names beside a row) |
| Public path | `shareholders.<domain>/mcp/records` | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`, the kit's).

1. **A person's own token** (`mcp_` + 48 hex, scope `mcp`, shown once, sha256 kept): `mcp_resolve_token(hash, 'mcp')`, active and
   admitted. **A key (scope `api`) is refused here** (401): keys are for the records API. Never tool-filtered; its rows are the views'.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): the person's `{mid}.{exp}.{hmac}` or the agent run token `{mid}.{exp}.{run}.{hmac}`.
   The member id must be in the mirror and admitted; **an unknown id is refused, never created**. For a run token the server asks the
   kernel's run-facts (`POST {OS_INTERNAL_URL}/api/v1/runs/facts.php`, bearer `OS_APPLICATION_TOKEN`), caches 300 s, and offers exactly
   the tools named for `Records MCP` / `Activity MCP`; a vouched agent is admitted on first contact (`mcp_admit_agent`). **Fail closed**
   when the kernel is unreachable or `valid: false`. **`is_eval`:** these servers never write.
3. **The kernel's own token** (`verify_kernel_token`, 60 s, bound to `APP_KEY = shareholder_intelligence`): `KERNEL_TOOLS = {app_roles,
   filings_index, filing_text, ownership_summary, signals_open, events_index}`, all `KERNEL_ONLY` but `app_roles`. No member identity is set;
   the shares answer through the db/018 `si_share_*` functions as the application, with the consumer's key from `X-OS-Consumer` (K26).

**Identity on every query.** `app.member_id` = the resolved member, `app.role` = `members.business_role`, transaction-local. The server
never filters rows in Python by who is asking: the views and functions do. Names resolve to ids through the same views.

**No bridge.** Every read lives in SQL; the application consumes no K7 read in version 1 (`reads[]` is empty; the two Extended reads are
named in `maludb-os.json` `reads_extended`). The fetchers and the parsers are PHP's (the worker); the tools read what they wrote.

## Records server: `shareholder_intelligence_records_mcp`

### Roles (kernel db/145)
`app_roles` (prose row, the contract's): `mcp_app_roles` as `os.app-roles/1` — the four roles and their rights. The kernel's token only.
`records_search` (prose row, the contract's guarded long tail): a named view and up to five equality filters, `limit` ≤ 100, over the
`mcp_*` views the caller's token may read — for a question no tool below answers; logged with the view's name.

### The shell, me and the vocabulary
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `search` | The one box: holders (names for an Analyst), institutions, participants, filings, events, signals, issuers by any words | all | `si_search(q, kinds, issuer)` | `q`, `kinds?[]`, `issuer?`, `limit`, `offset` | rows `{entity_kind, entity_id, title, headline, status, issuer_id, rank}`, `total` — a person is "Individual holder" with no headline for a Viewer | insider; both walls in the function |
| `my_notifications` | My notices, unread first | — | `mcp_notifications` | `unread_only?`, `limit` | rows `{notification_id, kind, title, body, record_type, record_id, signal_id, issuer_id, read_at, created_at}` | own |
| `get_settings` | The application's settings a member may read: exchange, time zone, holidays, tolerances, thresholds, whether the EDGAR user-agent is set | Q41 | `mcp_settings` | — | `{business_name, edgar_user_agent_set, default_exchange, market_timezone, market_holidays, edgar_rate_per_second, reconciliation_tolerance_shares, auto_match_threshold, pii_in_exports, morning_note_hour, pack_day, …}` | insider |
| `find_members` | Members by name, role, kind; `q` alone resolves one | Q42 | `mcp_members` | `q?`, `role?`, `kind?` (human, agent), `limit` | rows `{member_id, display_name, member_kind, roles_resolved, job_title, department_ids}` | insider |
| `find_departments` | Departments by name; `q` alone resolves one | Q42 | `mcp_departments` | `q?` | rows `{department_id, name, parent_id, manager_member_id}` | insider |
| `find_tags` | The tag vocabulary; `q` alone resolves one | — | `mcp_tags` | `q?` | rows `{tag_id, slug, label, usage_count}` | insider |
| `find_notes` | The notes on a record | — | `mcp_notes` | `kind`, `record`, `limit` | rows `{note_id, member_id, body, created_at}` — a note on a person-holder only for an Analyst | see; pii for a person-holder |

### Issuers, securities, share counts, participants (Q1–Q3, Q9)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_issuers` | The issuers I may see; `q` alone resolves one by name, ticker or CIK (the registry's `issuer` resolver) | Q1 | `mcp_issuers` | `q?`, `exchange?`, `limit` | rows `{issuer_id, name, cik, ticker, exchange, transfer_agent, restricted, securities_count, primary_security_id, edgar_synced_at}` | see |
| `get_issuer` | One issuer in full: the EDGAR entity, securities, the latest share counts, the transfer agent, the restriction state, the latest upload per kind, the sources' state | Q1 | `mcp_issuers`, `mcp_securities`, `mcp_share_counts`, `mcp_imports` | `issuer` | `{issuer, securities[], share_counts{kind: {value, as_of, source}}, uploads{kind: {import_id, record_date or period_to, tied}}, restricted}` | see |
| `find_securities` | An issuer's securities; `q` alone resolves one by CUSIP, ticker or name (the `security` resolver) | Q1 | `mcp_securities` | `issuer?`, `q?` | rows `{security_id, issuer_id, issuer_name, class, name, cusip, ticker, is_primary, outstanding, float_shares, cede_shares, last_position_date}` | see |
| `share_counts` | The dated share counts by kind and source; the latest at a date | Q2 | `mcp_share_counts`, `si_share_count()` | `security`, `kind?`, `as_of?`, `history?` (all rows when true) | `{latest: {kind: {value, as_of, source}}, rows?: [{share_count_id, as_of, kind, value, source, filing_id, import_id, xbrl_frame, note}]}` | see |
| `issuer_restrictions` | Which issuers are restricted to whom | Q42 | `mcp_issuer_members` | `issuer?` | rows `{issuer_id, member_id, display_name, added_by, added_at}` | manager |
| `find_participants` | DTC participants by number, name, alias or class; `q` alone resolves one (the `participant` resolver) | Q9 | `mcp_participants` | `q?`, `class?`, `active?`, `limit` | rows `{participant_id, number, name, aliases, class_key, side, class_source, directory_kind, last_seen_in_directory, tags}` | insider |
| `get_participant` | One participant: class and its source, aliases, its latest position in a security, the dates it was present | Q9 | `mcp_participants`, `mcp_participant_positions` | `participant`, `security?` | `{participant, latest?: {position_date, shares, pct_of_total}, first_date?, dates_present}` | insider; positions: see |

### Uploads, positions and the reconciliation (Q3–Q10, Q40)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_imports` | The uploads by kind, security, status, tie; `q` alone resolves one by file name (the `upload` resolver) | Q40 | `mcp_imports` | `issuer?`, `security?`, `kind?`, `tied?`, `q?`, `limit`, `offset` | rows `{import_id, kind, connector, file_name, security_id, record_date, period_from, period_to, status, rows_made, rows_failed, tied, tie_note, override_at, created_by, created_at}` | see |
| `get_import` | One upload: control against derived totals, the tie or the difference named, the override, the matcher's counts | Q40 | `mcp_imports`, `mcp_nobo_jobs` | `upload` | `{import, control_totals, derived_totals, tied, tie_note, override?, nobo_job?, matcher?: {auto, proposed, new, unmatched}}` | see |
| `import_rows` | An upload's rows as read, the ones that failed to type first | Q40 | `mcp_nobo_rows`, `mcp_register_rows`, `spr_rows` through `mcp_participant_positions` | `upload`, `failed_only?`, `limit`, `offset` | rows by kind: an SPR's `{row_no, participant_number, participant_name, position_date, shares}`; a NOBO's or a register's rows **for an Analyst only** | see; pii for NOBO and register rows (else `pii_withheld`) |
| `positions_on` | Who held at DTC on a date, ranked, with the class and the change against the previous date | Q4 | `mcp_participant_positions`, `si_position_changes()` | `security`, `date?` (the latest when omitted), `class?`, `top?` (25) | rows `{participant_id, number, name, class_key, shares, pct_of_total, change_prev}`, `position_date`, `total` | see |
| `position_changes` | Each participant's open, close, net and largest day over a window | Q5 | `si_position_changes(security, from, to)` | `security`, `from`, `to`, `class?`, `limit` | rows `{participant_id, number, name, class_key, open_shares, close_shares, net, largest_move, largest_move_date, days_present, open_date, close_date}` | see |
| `participants_new_lost` | Participants new to the file or gone in a window | Q6 | `si_participants_new_lost(security, from, to)` | `security`, `from`, `to` | rows `{participant_id, number, name, class_key, state, shares, on_date}` | see |
| `participant_flow` | Net shares by class over a window — **the reply carries "practitioner lore, no published validation" when it describes migration** | Q7 | `si_participant_flow(security, from, to)` | `security`, `from`, `to` | rows `{class_key, side, open_shares, close_shares, net, share_of_total_close}`, `caveat` | see |
| `concentration` | Top-5, top-10, top-20 shares of the DTC total and the index, on a date or as a series | Q8 | `si_concentration(security, date)` | `security`, `date?`, `from?`, `to?` (a series when both) | rows `{position_date, total, participants, top5_pct, top10_pct, top20_pct, hhi}` | see |
| `participant_series` | One participant's daily series in a window | Q9 | `si_participant_series(security, participant, from, to)` | `security`, `participant`, `from`, `to` | rows `{position_date, shares, change}` | see |
| `spr_total` | What the SPR implies Cede holds on a date (absent days answer `no_data`) | Q10 | `si_spr_total(security, date)` | `security`, `date` | `{position_date, total}` | see |
| `reconciliation_latest` | Does it tie: outstanding against the register's components, Cede against the SPR, the differences named | Q3, Q10 | `si_reconciliation_latest(security)` | `security` | `{as_of, outstanding, cede_registered, cede_source, register_date, spr_total, spr_date, drs_book, certificated, plan, registered_other, difference_registered, difference_spr, tolerance, tied_registered, tied_spr, tied, nobo_shares, obo_remainder, ran_at}` | see |
| `reconciliation_history` | Every reconciliation run of a security | Q3 | `mcp_share_reconciliations` | `security`, `from?`, `to?`, `limit` | rows as `reconciliation_latest` | see |

### Holders (Q11–Q18)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_holders` | Holders by channel, record date, kind, custodian, tag, words; `q` alone resolves one (the `holder` resolver — an Analyst's only) | Q11, Q12 | `mcp_holders`, `mcp_holder_positions`, `mcp_holder_accounts` | `issuer?`, `channel?` (nobo, register), `record_date?`, `kind?`, `custodian?` (a participant), `account_kind?`, `min_shares?`, `tag?`, `q?`, `sort?` (shares, name, last_seen), `limit`, `offset` | rows `{holder_id, display_name, kind, entity_type, city, state, zip, is_insider, institution_id, last_seen_on, last_shares, accounts_count, tags, pii_withheld}`, `total` | see; a person is "Individual holder" with no address for a Viewer |
| `get_holder` | One holder: accounts (the registration as read), positions by record date and channel, the insider and institution links, notes | Q15 | `mcp_holders`, `mcp_holder_accounts`, `si_holder_history()`, `mcp_notes` | `holder` | `{holder, accounts[], history[], insider?, institution?, notes_count}` | see; a person-holder: pii (else `pii_withheld`) |
| `holder_history` | One holder's shares by record date, by account and channel | Q15 | `si_holder_history(holder)` | `holder` | rows `{record_date, channel, holder_account_id, account_kind, custodian_participant_id, security_id, shares}` | see; pii for a person-holder |
| `holders_changes` | Holders between two record dates: new, lost, up, down, same | Q13 | `si_holders_changes()` + `mcp_holders`; for a Viewer `si_holders_changes_summary()` | `security`, `channel`, `date_a`, `date_b`, `state?`, `limit`, `offset` | an Analyst: rows `{holder_id, display_name, kind, state, shares_a, shares_b, net}`; a Viewer: rows `{state, holders, shares_a, shares_b, net}` | see |
| `holders_by_range` | Holders by share range on a record date from a list | Q14 | `si_holders_by_range(security, channel, date)` | `security`, `channel`, `record_date?` | rows `{range_label, range_from, holders, shares, share_pct}` | see |
| `holders_by_geography` | Holders by country and state on a record date | Q14 | `si_holders_by_geography()` | `security`, `channel`, `record_date?` | rows `{country, state, holders, shares}` | see |
| `holders_by_custodian` | NOBO holders and shares by custodian and account kind | Q12 | `si_holders_by_custodian()` | `security`, `record_date?` | rows `{participant_id, number, name, class_key, account_kind, holders, shares}` | see |
| `holders_by_type` | Registered holders by position type (certificate, book, DRS, restricted, plan) | Q11 | `si_holders_by_type()` | `security`, `record_date?` | rows `{account_kind, holders, shares}` | see |
| `holders_unmatched` | Raw NOBO and register rows still without a holder, with their best candidate | Q16 | `mcp_nobo_rows`, `mcp_register_rows`, `mcp_holder_match_proposals` | `upload?`, `issuer?`, `limit`, `offset` | rows `{row_kind, row_id, import_id, registration, shares, candidates: [{holder_id, display_name, score, evidence}]}` | pii |
| `match_proposals` | The matcher's and the agents' proposals with score and evidence | Q16 | `mcp_holder_match_proposals` | `upload?`, `status?`, `proposed_by?`, `limit` | rows `{proposal_id, row_kind, row_id, holder_id, score, evidence, proposed_by, status, decided_by, decided_at}` | pii |
| `obo_remainder` | Each participant's SPR position against its NOBO shares on a record date | Q17 | `si_obo_remainder(security, record_date)` | `security`, `record_date` | rows `{participant_id, number, name, class_key, spr_shares, spr_date, nobo_shares, nobo_count, obo_remainder}` | see |
| `participant_nobo_summary` | One participant's NOBO count and shares and its OBO remainder on every record date | Q9, Q17 | `mcp_nobo_broker_rows`, `si_obo_remainder()` | `participant`, `security` | rows `{record_date, nobo_count, nobo_shares, spr_shares, obo_remainder}` | see |
| `share_ranges` | Broadridge's Share Range Analysis: every account and the OBOs by range, the NOBOs derived | Q14, Q17 | `si_share_ranges(security, record_date)` | `security`, `record_date?` | rows `{record_date, range_label, range_from, all_accounts, all_shares, obo_accounts, obo_shares, nobo_accounts, nobo_shares}` | see |
| `obo_totals` | The OBO population over time: accounts, shares, the share of accounts | Q17 | `si_obo_totals(security)` | `security` | rows `{record_date, all_accounts, all_shares, obo_accounts, obo_shares, obo_pct_of_accounts}` | see |
| `insider_holdings` | The insiders: their Form 4 holdings after their last transaction beside their register positions | Q18 | `mcp_insider_transactions`, `mcp_holders` (is_insider) | `issuer?` | rows `{reporting_owner_cik, reporting_owner_name, relationship, last_transaction_date, shares_owned_after, holder_id, register_shares}` | see; `register_shares` and `holder_id` need pii |

### Institutions, beneficial owners, insiders (Q19–Q23)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_institutions` | 13F and 13D/13G filers by name, CIK, kind; `q` alone resolves one (the `institution` resolver) | Q22 | `mcp_institutions` | `q?`, `kind?`, `activist?`, `limit` | rows `{institution_id, cik, name, kind, is_activist, aliases, tags}` | insider |
| `get_institution` | One institution: kind, its holdings in a security by quarter, its 13D/13G history, its filings about us | Q22 | `mcp_institutions`, `mcp_institutional_holdings`, `mcp_beneficial_ownership`, `mcp_filings` | `institution`, `security?` | `{institution, holdings[], beneficial[], filings[]}` | insider; holdings: see |
| `institution_history` | One institution's shares in a security by quarter | Q22 | `mcp_institutional_holdings` | `institution`, `security` | rows `{period_end, shares, value_dollars, put_call, filing_id}` | see |
| `institutional_holders` | The 13F holders of a quarter ranked, with value, voting authority and the share of outstanding | Q19 | `si_13f_changes(security, period)` | `security`, `period?` (the latest), `limit` | rows `{institution_id, name, kind, shares, value_dollars, voting_sole, pct_of_outstanding}`, `period_end` | see |
| `holdings_changes` | The quarter against the prior: new, out, up, down | Q19 | `si_13f_changes(security, period)` | `security`, `period?`, `state?` | rows `{institution_id, name, kind, shares, prior_shares, net, state}` | see |
| `holdings_summary` | The quarter's totals: holders, shares, value, the share of outstanding, the top-10 share, puts and calls | Q19 | `si_13f_summary(security, period)` | `security`, `period?` | `{period_end, holders, shares, value_dollars, pct_of_outstanding, top10_pct, puts_shares, calls_shares}` | see |
| `holdings_put_call` | Puts and calls reported on the security in 13Fs | Q23 | `mcp_institutional_holdings WHERE put_call IS NOT NULL` | `security`, `period?` | rows `{institution_id, institution_name, period_end, put_call, shares, value_dollars}` | see |
| `beneficial_owners` | The 5% holders: the latest 13D or 13G per filer with percent, shares, event date, purpose | Q20 | `si_beneficial_owners(security)` | `security` | rows `{institution_id, name, kind, schedule, percent_of_class, shares, filed_on, event_date, purpose_text, filing_id}` | see |
| `get_beneficial_filing` | One 13D or 13G in full: the cover's powers, the group, the purpose | Q20 | `mcp_beneficial_ownership` | `filing` or `beneficial_id` | `{beneficial_id, institution, schedule, is_amendment, amendment_no, filed_on, event_date, percent_of_class, shares_beneficially_owned, sole_voting, shared_voting, sole_dispositive, shared_dispositive, purpose_text, group_members, filer_type}` | see |
| `insider_transactions` | Forms 3/4/5 transactions by person, code and window; a summary per person | Q21 | `mcp_insider_transactions`, `si_insider_summary()` | `issuer?`, `from`, `to`, `owner?` (a CIK or name), `code?`, `summary?` | rows `{transaction_id, form, reporting_owner_cik, reporting_owner_name, relationship, officer_title, transaction_date, transaction_code, shares, price, acquired_disposed, shares_owned_after, ownership, is_derivative, is_10b5_1}` or the summary rows | see |
| `form144_notices` | Form 144 notices in a window | Q21 | `mcp_form144_notices` | `issuer?`, `from`, `to` | rows `{notice_id, filed_on, seller_name, seller_cik, relationship, shares_to_sell, aggregate_market_value, approx_sale_date, broker}` | see |

### Filings, the watch, dilution (Q24–Q28)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_filings` | Filings by form, window, about-us; `q` alone resolves one by accession or form and date (the `filing` resolver) | Q24, Q26 | `mcp_filings` | `issuer?`, `form?[]`, `from?`, `to?`, `about_us?`, `q?`, `limit`, `offset` | rows `{filing_id, label, form, filed_on, report_date, items, primary_doc_description, url, about_us, has_text, watched}` | see |
| `get_filing` | One filing: its index row, the event it made, the structured rows it produced (13D/G, Forms 4, 144) | Q24 | `mcp_filings`, `mcp_events`, `mcp_beneficial_ownership`, `mcp_insider_transactions`, `mcp_form144_notices` | `filing` | `{filing, event?, structured?: {beneficial?, insider[]?, form144?}}` | see |
| `filing_read` | The text of a filing — a window of up to 6,000 characters, or the passages around a term | Q25 | `mcp_filing_text` | `filing`, `offset?`, `length?` (≤ 6,000), `around?` (a term) | `{filing_id, form, filed_on, char_count, offset, text, passages?[]}` | see |
| `filings_search` | Words across every filing's text, with highlights | Q25 | `si_filings_search(issuer, q, limit)` | `issuer?`, `q`, `limit` | rows `{filing_id, form, filed_on, headline, rank}` | see |
| `watch_hits` | The full-text watch's hits since a date, by status | Q28 | `mcp_watch_hits` | `issuer?`, `status?`, `since?`, `limit` | rows `{watch_hit_id, filing_id, term, snippet, found_at, status}` | see |
| `dilution_instruments` | The instruments: kind, counterparty, facility, remaining, terms, floating, status; `q` alone resolves one (the `instrument` resolver) | Q27 | `mcp_dilution_instruments` | `issuer?`, `status?[]`, `kind?`, `q?` | rows `{instrument_id, label, kind, counterparty, filed_on, filing_id, facility_amount, remaining_amount, price_terms, floating_price, shares_issuable_max, expires_on, status}` | see |
| `dilution_proposals` | Instruments the watch proposed and nobody confirmed | Q28 | `mcp_dilution_instruments WHERE status = 'proposed'` | `issuer?` | rows as `dilution_instruments` + `watch_term` | see |

### The short side (Q29–Q33)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `short_interest_series` | FINRA short interest by settlement date (the latest observation of each), with the share of float and outstanding | Q29 | `si_short_interest_series(security, from, to)` | `security`, `from`, `to` | rows `{settlement_date, short_shares, change_percent, avg_daily_volume, days_to_cover, revision_flag, pct_of_float, pct_of_outstanding, observed_at}` | see |
| `short_vs_float` | The latest short interest at a date as a share of float and outstanding, with days to cover | Q29 | `si_short_vs_float(security, as_of)` | `security`, `as_of?` | `{settlement_date, short_shares, float_shares, outstanding, pct_of_float, pct_of_outstanding, days_to_cover, avg_daily_volume}` | see |
| `daily_short_volume_series` | FINRA's daily off-exchange short volume with the 5- and 20-day means — **FINRA's caveat is in every answer** | Q30 | `si_daily_short_volume_series(security, market, from, to)` | `security`, `market?` (CNMS), `from`, `to` | rows `{trade_date, short_volume, short_exempt_volume, total_volume, ratio, mean_5, mean_20}`, `caveat` | see |
| `fails_series` | Fails-to-deliver by settlement date with the share of outstanding and the 60-day median | Q31 | `si_fails_series(security, from, to)` | `security`, `from`, `to` | rows `{settlement_date, quantity, price, pct_of_outstanding, median_60}` | see |
| `threshold_status` | On a Reg SHO threshold list now, which, since when, how many consecutive list days | Q31 | `si_threshold_status(security)` | `security` | `{on_list, list, since, consecutive_days, latest_list_date}` | see |
| `threshold_history` | Every threshold-list day of a security | Q31 | `mcp_threshold_listings` | `security`, `from?`, `to?` | rows `{list_date, list, flag, rule_3210}` | see |
| `borrow_series` | The borrow observations: date, source, fee, utilisation — "indicative" when a quote | Q32 | `mcp_borrow_observations` | `security`, `from?`, `to?` | rows `{observation_id, observed_on, source, fee_rate_percent, utilization_percent, shares_on_loan, shares_available, note}` | see |
| `short_side_overlay` | Price, volume and the short-side series aligned by date for one chart | Q33 | `si_short_side_overlay(security, from, to)` | `security`, `from`, `to` | rows `{d, close, volume, short_pct_float, days_to_cover, fails, short_volume_ratio_5, on_threshold, borrow_fee, utilization}` | see |

### Signals and rules (Q34, Q37, Q38, Q53)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_signals` | Signals by status, severity, rule, class, security; the open ones by default; `q` alone resolves one (the `signal` resolver) | Q34 | `mcp_signals` | `issuer?`, `security?`, `status?[]` (open), `severity?[]`, `rule?`, `class?`, `q?`, `limit`, `offset` | rows `{signal_id, rule_key, rule_name, evidence_class, security_id, raised_on, window_from, window_to, severity, value, threshold, summary, status, proposal_reason, proposal_at, repeat_count}` | see |
| `get_signal` | One signal whole: the rule's basis, the evidence rows, the window, the disposition, the proposal, the explaining event, the outcome | Q34 | `mcp_signals`, `mcp_signal_rules`, `mcp_events`, `mcp_signal_outcomes` | `signal` | `{signal, rule: {key, name, evidence_class, basis, params}, evidence, event?, proposal?, outcome?}` | see |
| `signals_history` | Signals of a period with their dispositions, who and why | Q37 | `mcp_signals` | `issuer?`, `from`, `to`, `status?[]`, `rule?`, `limit`, `offset` | rows as `find_signals` + `disposition_reason, disposition_note, disposed_by, disposed_at, event_id` | see |
| `rules_catalogue` | The rules: key, name, class, basis, severity, thresholds, enabled, last fired, the per-issuer overrides | Q38 | `mcp_signal_rules`, `si_rule_params()` | `issuer?`, `class?` | rows `{rule_id, key, name, evidence_class, basis, severity_default, params, effective_params?, window_days, enabled, last_fired_on}` | insider |
| `find_rules` | A rule by key or name (the `rule` resolver) | Q38 | `mcp_signal_rules` | `q` | rows `{rule_id, key, name, evidence_class}` | insider |
| `signal_outcomes` | The price at each signal and 5, 20, 60 trading days after — the Auditor's test of the lore rules | Q53 | `mcp_signal_outcomes`, `mcp_signals` | `issuer?`, `rule?`, `class?`, `from?`, `to?` | rows `{signal_id, rule_key, evidence_class, raised_on, price_at, return_5d, return_20d, return_60d}`, per rule `{n, mean_return_20d, share_negative_20d}` | see |

### Events, reports, exports (Q35, Q36, Q39)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `events_on` | What happened on a date or in a short window | Q35 | `mcp_events` | `issuer?`, `date`, `days?` (0–14 around it) | rows `{event_id, on_date, kind, title, severity, url, filing_id, source}` | see |
| `find_events` | Events by kind, severity, window, words; `q` alone resolves one (the `event` resolver) | Q35 | `mcp_events` | `issuer?`, `kind?[]`, `severity?[]`, `from?`, `to?`, `q?`, `limit`, `offset` | rows as `events_on` + `tags` | see |
| `events_upcoming` | What is coming in the next days: record dates, earnings, expiries, the next short-interest publication, the next 13F deadline | Q36 | `mcp_events` (`on_date ≥ today`) + the calendars of the sources (`mcp_sources.settings`) | `issuer?`, `days?` (30) | rows `{on_date, kind, title, source}` | see |
| `find_reports` | The packs by kind, status, period; `q` alone resolves one (the `report` resolver) | Q39 | `mcp_reports` | `issuer?`, `kind?`, `status?`, `q?`, `limit` | rows `{report_id, kind, title, period_from, period_to, status, approved_by, approved_at, rendered, has_pdf}` | see; drafts to `reports.run` |
| `get_report` | One pack: the narrative and the sections | Q39 | `mcp_reports` | `report` | `{report_id, kind, title, period_from, period_to, status, narrative, sections, approved_by, approved_at}` | see; drafts to `reports.run` |
| `find_exports` | My exports and the shared ones | — | `mcp_exports` | `kind?`, `limit` | rows `{export_id, kind, format, status, item_count, byte_size, includes_pii, created_at, expires_at}` | own; `export.shared` for all |

### Agents, keys, sources, the admin (Q41–Q44)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `analyst_proposals` | Every open proposal an agent made: signal dispositions, holder matches, holder kinds | Q44 | `si_analyst_proposals(issuer, kind)` | `issuer?`, `kind?` (signal, holder_match, holder_kind) | rows `{kind, proposal_id, issuer_id, record_type, record_id, title, reason, note, proposed_by, proposed_at}` | see; holder rows: pii |
| `agent_dispatches` | The dispatches to agents: kind, status, the reply's excerpt | Q44 | `mcp_agent_dispatches` | `status?`, `kind?`, `limit` | rows `{dispatch_id, kind, agent_member_id, status, reply_excerpt, detail, attempts, created_at, answered_at, signal_id, filing_id, import_id}` | `agents.settings`, or the agent's own |
| `members_with_roles` | Who holds which role here, people and agents | Q42 | `mcp_members` | `role?`, `agents_only?` | rows `{member_id, display_name, member_kind, roles_resolved}` | insider |
| `find_keys` | The API keys: mine, or everyone's for `tokens.manage`; `q` alone resolves one (the `api_key` resolver) | Q43 | `mcp_keys` | `q?`, `member?` | rows `{key_id, member_id, label, rate_per_minute, rate_per_day, last_used_at, expires_at, revoked_at, calls_today}` | own; `tokens.manage` for all |
| `key_usage_summary` | A key's calls and refusals by day | Q43 | `key_usage` through `mcp_keys` | `api_key`, `days?` (30) | rows `{day, calls, refused}` | own; `tokens.manage` |
| `find_sources` | The fetchers by connector; `q` alone resolves one (the `source` resolver) | Q41 | `mcp_sources` | `q?`, `connector?`, `active?` | rows `{source_id, name, connector, role, schedule_minutes, rate_per_second, last_ok_at, consecutive_failures, backoff_until, paused_at, active, has_credential}` | insider |
| `source_health` | Are the sources healthy: due, paused, backed off, the last status and error, the EDGAR user-agent set | Q41 | `si_source_health()`, `mcp_source_pulls` | `connector?`, `pulls?` (the last N of each) | rows `{source_id, name, connector, active, paused, due, last_ok_at, last_status, last_error, consecutive_failures, backoff_until, has_credential, needs_user_agent, user_agent_set}` | insider |
| `find_templates` | The import templates by kind | — | `mcp_import_templates` | `kind?` | rows `{template_id, name, kind, connector, mapping}` | insider |
| `tombstones` | What waits in the tombstone and when it purges | — | `si_tombstones()` | `kind?` | rows `{kind, record_id, label, issuer_id, deleted_at, deleted_by, purge_after}` | manager |

## The shares, for the kernel's token (K7, design §8, D13)

**Who is asking comes from the kernel (K26):** `X-OS-Consumer` (the consumer's catalog key) and `X-OS-Consumer-Agent`; anything the
consumer put in the arguments as `consumer` or `as_agent` is stripped by the kernel. The gate reads the headers into request-scoped
variables and passes `consumer` to the db/018 `si_share_*` functions; no header → `consumer_unknown`, nothing shared. **An issuer with a
restriction list is never shared; no natural person crosses** (holders appear only as counts; institutions and insiders are the public
record). `filing_text` declares `timeout_seconds: 60`; the others keep the kernel's 8 s.

| Tool | Document | Arguments (flat from the kernel) | Answers |
|---|---|---|---|
| `filings_index` | `os.si-filings/1` | `issuer` (id, ticker or CIK), `since?` (a date), `forms?[]`, `limit?` (≤ 500), `offset?` | `si_share_filings_index(consumer, …)`: `{schema, application, issuer: {issuer_id, name, ticker, cik}, filings: [{filing_id, accession_number, form, filed_on, report_date, title, items, url, about_us, has_text, char_count}], total}` — Knowledge's feed (KN2) |
| `filing_text` | `os.si-filing-text/1` | `filing` (id) | `si_share_filing_text(consumer, filing)`: `{schema, application, filing_id, issuer_id, form, filed_on, url, extractor, truncated, text}` (≤ 5 MB) — Knowledge's sectioner |
| `ownership_summary` | `os.si-ownership/1` | `issuer`, `as_of?` | `si_share_ownership_summary(consumer, issuer, as_of)`: outstanding, float, short, the DTC total by side, the reconciliation's state, the 13F totals, the 5% filers, the insiders' shares, the NOBO and OBO counts |
| `signals_open` | `os.si-signals/1` | `issuer` | `si_share_signals_open(consumer, issuer)`: `{signals: [{signal_id, rule, rule_name, evidence_class, severity, raised_on, window_from, window_to, summary}]}` — a Spaces card (SP5) |
| `events_index` | `os.si-events/1` | `issuer`, `from?`, `to?`, `limit?` | `si_share_events_index(consumer, issuer, from, to, limit)`: `{events: [{event_id, on_date, kind, title, severity, url, filing_id}]}` |

- **Logged:** `share.read` (source `application`, `after = {application, tool, issuer_id, rows, ms}`) on every call.
- The kernel refuses an answer over 256 kB but for a share that declares more (`filing_text` pages by `filing`); the indexes page.

## The records API (bearer = a key; `/api/v1/` on the vhost's allow-list; design §8)
A key acts as its person: `api_authenticate()` resolves it (`si_resolve_key`), sets `app.member_id`, counts it (`si_rate_ok` → 429 with
`Retry-After`), and every route reads the same views and functions as the tools above, **so a Viewer's key never carries a person**:
`GET /api/v1/issuers|securities|participants|positions|holders|institutions|holdings|beneficial|insiders|filings|short-interest|short-volume|fails|threshold|signals|events|reports`
= the `find_*` and series tools with the same filters; `GET /api/v1/{kind}/{id}` = `get_*`; `POST /api/v1/uploads` = `upload_create` (a
multipart file, `kind`, `security`, `record_date`) — the warehouse's or the transfer agent's nightly drop; `POST /api/v1/events` =
`event_create`; `POST /api/v1/borrow-observations` = `borrow_observation_create`; `GET /api/v1/health`. Logged with source `api` and
`token_id`; a holders read logs its row count.

## Activity server: `shareholder_intelligence_activity_mcp`

Over the tenant's one memory, filtered to `application = shareholder_intelligence`, and `mcp_activity_log` here (the issuer wall in the view).
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `activity_search` | Words across what happened here: "when did we first flag the build-up at Goldman" | Q50 | MaluDB `POST /v1/memory/search` (the tenant's memory, `application` filter) then `mcp_activity_log` for the rows the caller may see | `q`, `issuer?`, `from?`, `to?`, `limit` | rows `{activity_id, occurred_at, actor_name, action, entity_type, entity_id, issuer_id, summary}` | insider; the wall on every row |
| `record_history` | One record's trail: an upload, a holder, a signal, an issuer | Q51 | `mcp_activity_log` (`entity_type`, `entity_id`, or the audit keys) | `kind`, `record`, `limit` | rows `{occurred_at, actor_name, source, action, before, after}` | see |
| `actor_history` | What one member or agent did here in a window | Q51 | `mcp_activity_log` (`actor_member_id`) | `member`, `from?`, `to?`, `action?`, `limit` | rows as `record_history` + `entity_type, entity_id` | insider; another member's to a manager |
| `record_episodes` | The memory's episodes about a record (the MaluDB side of a trail) | Q51 | MaluDB `GET /v1/episodes` filtered by `entity_type`/`entity_id` | `kind`, `record`, `limit` | rows `{episode_id, occurred_at, title, payload}` | see |
| `recent_activity` | What happened here lately, by action or issuer | — | `mcp_activity_log` | `issuer?`, `action?`, `since?`, `limit` | rows as `actor_history` | insider |
| `agent_activity` | What the agents did this week and what they proposed that nobody took | Q52 | `mcp_activity_log` (`source = 'agent'`) + `si_analyst_proposals()` | `from?`, `to?`, `agent?` | `{actions: rows, proposals_open: rows, proposals_decided: rows}` | insider |
| `activity_timeline` | An issuer's trail by day: uploads, signals raised and disposed, filings, events | Q53 | `mcp_activity_log` (`issuer_id`) | `issuer`, `from`, `to` | rows `{day, action, count}` | see |

### The activity vocabulary (design §6.8): what the log must carry for these tools
Every event about an issuer's records carries `issuer_id`; a security's carries `security_id`; an upload's and the rows it made carry
`import_id`; a signal's carries `signal_id`. The events are the design's §6.8 list; the payload holds ids, counts, dates, shares, rule keys
and severities — **never a holder's name, address or registration lines, a filing's text, a credential, a token or a key.**

## Entity resolution for the action tools (the registry's `resolve` block, `deploy/kernel-registry-shareholder_intelligence.json`)
| Entity | Tool | Param names |
|---|---|---|
| issuer | `find_issuers` | issuer, company |
| security | `find_securities` | security, cusip, class |
| participant | `find_participants` | participant, broker, custodian |
| holder | `find_holders` (an Analyst's only) | holder, survivor, loser |
| institution | `find_institutions` | institution, filer, fund |
| filing | `find_filings` | filing |
| event | `find_events` | event, explaining_event |
| signal | `find_signals` | signal |
| rule | `find_rules` | rule |
| upload | `find_imports` | upload, import |
| report | `find_reports` | report, pack |
| source | `find_sources` | source, fetcher |
| instrument | `dilution_instruments` | instrument |
| tag | `find_tags` | tag |
| api_key | `find_keys` | api_key |
| member | `find_members` | member, person_member, agent, recipient, who |
| department | `find_departments` | department |

Each answers a plain list of rows carrying the id field and the label field when given `q` alone. Proposal, row, share count,
observation, watch hit, template, credential, note, attachment, dispatch, notification, token, export and override ids are ids only.

## Coverage

**Design §7 → tools**
| # | Tools |
|---|---|
| Q1 `find_issuers`, `get_issuer`, `find_securities` · Q2 `share_counts` · Q3 `reconciliation_latest`, `reconciliation_history` |
| Q4 `positions_on` · Q5 `position_changes` · Q6 `participants_new_lost` · Q7 `participant_flow` · Q8 `concentration` · Q9 `get_participant`, `participant_series`, `participant_nobo_summary`, `find_participants` · Q10 `spr_total`, `reconciliation_latest` |
| Q11 `find_holders`, `holders_by_type` · Q12 `find_holders`, `holders_by_custodian` · Q13 `holders_changes` · Q14 `holders_by_range`, `holders_by_geography`, `share_ranges` · Q15 `get_holder`, `holder_history` · Q16 `holders_unmatched`, `match_proposals` · Q17 `obo_remainder`, `participant_nobo_summary`, `share_ranges`, `obo_totals` · Q18 `insider_holdings` |
| Q19 `institutional_holders`, `holdings_changes`, `holdings_summary` · Q20 `beneficial_owners`, `get_beneficial_filing` · Q21 `insider_transactions`, `form144_notices` · Q22 `get_institution`, `institution_history`, `find_institutions` · Q23 `holdings_put_call` |
| Q24 `find_filings`, `get_filing` · Q25 `filing_read`, `filings_search` · Q26 `find_filings` (about_us) · Q27 `dilution_instruments` · Q28 `watch_hits`, `dilution_proposals` |
| Q29 `short_interest_series`, `short_vs_float` · Q30 `daily_short_volume_series` · Q31 `fails_series`, `threshold_status`, `threshold_history` · Q32 `borrow_series` · Q33 `short_side_overlay` |
| Q34 `find_signals`, `get_signal` · Q35 `events_on`, `find_events` · Q36 `events_upcoming` · Q37 `signals_history`, `analyst_proposals` · Q38 `rules_catalogue`, `find_rules` |
| Q39 `find_reports`, `get_report` · Q40 `find_imports`, `get_import`, `import_rows` · Q41 `source_health`, `find_sources`, `get_settings` · Q42 `app_roles`, `members_with_roles`, `issuer_restrictions` · Q43 `find_keys`, `key_usage_summary` · Q44 `analyst_proposals`, `agent_dispatches` |
| Q45 `filings_index` · Q46 `filing_text` · Q47 `ownership_summary` · Q48 `signals_open` · Q49 `events_index` |
| Q50 `activity_search` · Q51 `actor_history`, `record_history`, `record_episodes` · Q52 `agent_activity` · Q53 `signal_outcomes`, `activity_timeline` |

**Agents' grants** (`maludb-os.json`, reconciled to these names in Phase 1): the **Expert** holds every records tool above but
`agent_dispatches`, `find_keys`, `key_usage_summary`, `tombstones`, `find_templates`, `records_search`, `app_roles` and the shares, and the
activity server's `record_history`, `actor_history`, `recent_activity`, `activity_search`; the **Surveillance Analyst** holds the morning
note's and the pack's reads (the reconciliation, the positions, the holders' queue, the ownership forms, the filings and the watch, the
short side, the signals and rules, the events, the reports) and `record_history`, `recent_activity`, `activity_search`.

## What the schema lacked that a tool needs — built in db/018 (Phase 1)
`si_ownership_summary()` (the picture in one read, no person), `holder_kind_proposals` + `si_holder_kind_propose()` / `si_holder_kind_decide()`
(where an agent's kind proposal lands), `si_analyst_proposals()` (the open proposals in one read, holder rows to the PII roles),
`si_holders_changes_summary()` (the Viewer's counts by state), `si_source_health()`, `si_tombstones()`, `agent_dispatches.status` admits
`cancelled`, `mcp_holder_kind_proposals`, and **the five shares as SECURITY DEFINER functions** `si_share_filings_index`,
`si_share_filing_text`, `si_share_ownership_summary`, `si_share_signals_open`, `si_share_events_index` (as the application; a restricted
issuer never shared; no person). Proven by the schema proof's db/018 section (172 checks in all).

## Size
Records: **95 tools** (88 for the questions and the people/mine group, `app_roles`, `records_search`, the five kernel-only shares), none
calling a model. Activity: **7 tools**. The resolve block names 17 entities. Five share documents and the records API's routes. Phase 4
builds them as `mcp/records_server.py` with `si_shell`, `si_issuers`, `si_positions`, `si_holders`, `si_filings`, `si_ownership`, `si_short`,
`si_signals`, `si_events`, `si_admin`, `si_shares` (each `register(mcp, q)`) plus `mcp/activity_server.py`.

## Built (Phase 4, 2026-10-11)
`mcp/records_server.py` (+ `si_shell`, `si_issuers`, `si_positions`, `si_holders`, `si_ownership`, `si_filings`, `si_short`, `si_signals`, `si_events`, `si_admin`, `si_shares`, `si_common`) — **95 tools**; `mcp/activity_server.py` — **7 tools**; the kit
(`db.py`, `server_common.py`) is Inventory's, with K26's headers and the five shares KERNEL_ONLY. Proofs: `tests/phase4/run.sh` (444 checks under php -S, 447 under Apache — the vhost's own `/mcp/records` and `/mcp/activity`
lines included). **One migration, db/028**: `mcp_key_usage`, `mcp_spr_rows`, `mcp_signal_rule_overrides` (views the tools needed), `si_log_mcp_read()` (the one write: `share.read` and `records.search`), `si_analyst_proposals` granted
to the activity role, and the records role's direct SELECT on `signals`, `signal_outcomes`, `signal_rule_overrides` and `search_index` REVOKED (base tables with no wall of their own; db/013 and db/015 had granted them).
Where this document and the schema or the screens differ, the schema and the screens won:
- `activity_search` reads `mcp_activity_log` (each word ILIKE over the action, record type, actor and payload), not MaluDB `POST /v1/memory/search` — that route is a vector search over documents and wants a subject or verb; the episodes are the same rows the ingest ships.
  `record_episodes` does read MaluDB (`GET /v1/episodes`, kind activity, the visible rows' time window), keeping only episodes whose `activity_log_id` the caller's view shows; without a memory it answers the log's rows and says so.
- `import_rows` reads an SPR's rows from the new `mcp_spr_rows` (the doc named `spr_rows` "through `mcp_participant_positions`", which has no row number or error); `key_usage_summary` from `mcp_key_usage`.
- `reconciliation_latest` reads `mcp_share_reconciliations` (the walled view; the same row `si_reconciliation_latest()` gives), `rules_catalogue` the overrides through `mcp_signal_rule_overrides`.
- **List and find tools take `issuer` as an optional FILTER** (none: every issuer the caller may see) so a resolver called with `q` alone never answers `ambiguous`; the series and aggregate tools default to the one visible issuer's primary security and answer `ambiguous` when several are visible.
- `find_signals` defaults to what still needs a person (open **and escalated**, as the screen does), not open alone; `get_issuer` names the latest upload of a kind by its period, then creation.
- `holders_changes` for a Viewer answers the counts by state and, beside them, `entities` (non-person holders by name), as the screen does. A Viewer's `find_holders` with `q` finds entities only. `get_import.matcher` carries `{rows, matched, auto, proposed, unmatched, failed}` (no `new`: it is not recoverable from the rows) and is null for a Viewer.
- Money is a string with two decimals for `value_dollars`, `aggregate_market_value`, prices, facility and remaining amounts; shares are integers (a jsonb document's `17100000.0` is normalised); percentages and ratios are numbers.
- The five shares answer the SQL functions' documents as they are (`{error: not_found|consumer_unknown|no_text}`), not `{ok:false}`; they are logged by `si_log_mcp_read`, the consumer from `X-OS-Consumer`.
- `records_search` takes a view and up to five equality filters (the surface's prose), never SQL; `mcp_activity_log`, `mcp_all_tokens`, `mcp_source_credentials` and `mcp_filing_text` are refused; it logs `records.search` with the filters' NAMES.

## Open questions
1. ~~**The registry wrapper's resolve params collide with enumerated values**~~ — **fixed at merge (2026-10-11, the planning model):** the kernel resolves every parameter named in a resolve entity across the whole registry, so the manifest's two enum `source` params (`share_count_save`, `borrow_observation_create`) are named `origin` (the handlers read `origin`, then the forms' `source`) and `class` left the security entity's aliases; `tests/phase4/registry.php` drives all three through the kernel's tool.
2. **K55:** 20 of our 83 action names are also other registries' (listed by `tests/phase4/registry.php`); the kernel's installer now gives a colliding action a `tool_name`, so nothing was renamed here.
