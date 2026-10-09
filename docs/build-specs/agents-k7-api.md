# Build spec: agents, proposals, notifications, K7, the API and keys (slice 9)

What exists at the end: the proposals screen with one-click take for every kind; the agents' write doors (`notification`, the proposals of slices 3 and 7 shown
together); the dispatch pass's chat turns (a high signal, a 13D, an upload done, the Friday pack) and the morning note's delivery; the outbox pass sending email
(MaluMail) and texts (K6); the five K7 shares; the records API with keys; the admin's agents screen. Phase 4 builds the MCP servers themselves; this slice builds every
PHP they call and the API.

Schema (db/014, 018, 003, 005, never modified): `agent_dispatches`, `notifications`, `notification_outbox`, `si_notify()`, `si_analyst_agent()` (db/014); the `si_share_*`
functions, `si_analyst_proposals()`, `holder_kind_proposals` (db/018); `mcp_access_tokens` + `key_usage`, `si_resolve_key()`, `si_rate_ok()`, `si_key_rotate()` (db/003, 005);
`mcp_agent_dispatches`, `mcp_keys` (db/016).

## Divergences from the design
1. **The morning note is composed by the Surveillance Analyst in its duty run**, not by PHP: this slice gives it `notification` and the kernel's `message_send`; PHP's part is
the `morning_note` kind, its mail body (`emails/morning-note.php`) and the home's strip. 2. **The Friday pack's draft is a dispatch** (`kind = 'pack'`) the worker's `packs` pass
makes on `pack_day` for each issuer — the Analyst answers with `report_draft`. 3. **The shares run as the application** through the records server's kernel gate (Phase 4); this slice
proves the SQL functions behind them through `tests/phase3/slice9/shares.php` as the records role.

## Who may do what
notification: `signals.work` (an agent to a member; never about an issuer the recipient may not see) · dispatch_cancel / agents_settings_save: `agents.settings` (`people`; the
settings pause, `other`) · key_mint / key_rotate: `tokens.own` (`people`) · key_revoke: own or `tokens.manage` (`people`) · the proposals screen: `see`; holder proposals `pii`.

## Screens
`proposals` `/proposals?kind=&issuer=` (cards by kind: a signal disposition — the rule, the reason, the explaining event, **Take it** (the matching disposition with the proposal's
words) and Not this; a holder match — the two registrations side by side (PII roles), Accept, Reject; a holder kind — Accept, Reject; each with the agent's name and when) ·
`admin-agents` `/admin/agents` (the agents from `members_with_roles` with their last dispatch; the dispatches table with status and Cancel; the settings form: the morning note on/off
and hour, proposals on/off, the pack's day, the Analyst's issuers (empty = all), the escalation member).

## The API (`html/api/v1/*.php`, `app/api/bootstrap.php` — the kit's gate)
The routes of the surface's "records API": every read is the same query function as the screen (the PII wall in the view: a Viewer's key gets "Individual holder");
`POST /api/v1/uploads` (multipart: `file`, `kind`, `security`, `record_date`) = `upload_create`; `POST /api/v1/events`; `POST /api/v1/borrow-observations`; 429 with `Retry-After` from
`si_rate_ok`; 401 for a revoked or an `mcp` token; every call logged with source `api`, `token_id` and, for a holders read, its row count.

## The worker's passes (filled here)
`outbox` (email through `malumail_send`, texts through the kernel's `POST /api/v1/notify/sms.php` — K6; retries 1, 5, 30 minutes; `failed` after three); `dispatches` (each `sent`
dispatch → one chat turn on the kernel's chat endpoint as the agent with the dispatch's prompt; the reply's excerpt kept; three attempts; `cancelled` skipped); `proposals` (the
standing proposals the worker can make without a model: a holder of kind unknown whose name ends in LLC/Inc/Trust → a `holder_kind_proposals` row by the worker, `proposed_by = NULL`;
a signal whose window holds exactly one high event → nothing: the agent proposes, the worker never disposes); `packs` (the Friday dispatch).

## Files (exactly these)
`html/proposals/index.php`, `html/agents/{notify,settings}.php`, `html/agents/dispatches/cancel.php`, `html/admin/agents/index.php`, `html/settings/tokens/{key-mint,key-rotate,key-revoke}.php`,
`html/api/v1/{issuers,securities,participants,positions,holders,institutions,holdings,beneficial,insiders,filings,short-interest,short-volume,fails,threshold,signals,events,reports,uploads,borrow-observations}.php`;
`app/features/proposals/{queries,present}.php`, `app/features/agents/{queries,present,write}.php`, `app/features/keys/write.php`, `app/api/{routes,present}.php`; `app/views/proposals/index.php` +
`partials/{signal,match,kind}.php`, `app/views/admin/agents.php`, `app/views/emails/{morning-note,notification}.php`; the worker's four passes;
`tests/phase3/slice9/{run.sh,lib.php,proposals.php,dispatch.php,outbox.php,shares.php,api.php,keys.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`proposals(PDO, ?int $issuer, ?string $kind): array` · `find_dispatches(PDO, array $filters, int $page): array` · `agents_overview(PDO): array` · `share_call(PDO, string $tool, string $consumer, array $args): array` (the proof's door
to the db/018 functions) · writes: `notify_member(PDO, int $to, string $kind, string $title, ?string $body, ?int $issuer, int $by): ?int` · `dispatch_cancel(PDO, int $id)` · `agents_settings_save(PDO, array $f)` ·
`key_mint(PDO, int $member, string $label): array{raw, id}` · `key_rotate(PDO, int $id): array{raw, id}` · `key_revoke(PDO, int $id)`

## MCP tools this slice makes real
`analyst_proposals`, `agent_dispatches`, `members_with_roles`, `find_keys` (the resolver), `key_usage_summary`, `filings_index`, `filing_text`, `ownership_summary`, `signals_open`, `events_index`,
`actor_history`, `agent_activity`.

## Manifest rows claimed
Screens (2): `proposals`, `admin-agents`
Actions (6): `notification`, `dispatch_cancel`, `agents_settings_save`, `key_mint`, `key_rotate`, `key_revoke`

## Activity log events
`notification.send` (`{to, kind, issuer_id}`), `agent.dispatch|reply|fail`, `dispatch.cancel`, `agents_settings.save`, `key.mint|rotate|revoke` (the label, never the key), `api.call`
(`{route, rows, holders_rows?}`), `share.read` (`{application, tool, issuer_id, rows, ms}`), `outbox.sent|failed`.

## Status vocabulary
Proposal kind icons (signal `bi-flag`, match `bi-people`, kind `bi-tag`); dispatch status chips (sent, answered `text-bg-success`, refused, failed `text-bg-danger`, awaiting approval
`text-bg-warning`, cancelled `text-bg-light`). Ids: `proposal-{kind}-{id}`, `proposal-take-{id}`, `dispatch-row-{id}`, `agents-settings`.

## Out of scope for this slice
The MCP servers (Phase 4); the sources' admin (10).

## Proof (`tests/phase3/slice9/run.sh`: scratch `si_dev39`; the fake kernel (chat endpoint, SMS) and the fake MaluMail; Playwright; the registry `--check`)
An agent's signal proposal, holder match and kind proposal appear on the proposals screen and are taken in one click; a Viewer sees only the signal proposals; a high signal's dispatch makes
one chat turn on the fake kernel and keeps the excerpt; a cancelled dispatch is skipped; the outbox sends an email and a text (the fake kernel's SMS endpoint) and retries a failure; the five
share functions answer as the application (a restricted issuer `not_found`, no person in any answer — grep for the fixture names); the API with an Analyst's key answers names, with a
Viewer's key "Individual holder", a revoked key 401, the 61st call in a minute 429; an upload through the API ties; keys minted (shown once), rotated (the old one 24 hours), revoked; 375 and
1280; JSON mode.

## Open questions
None.
