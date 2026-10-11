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

## Built (2026-10-11) — what the build found and decided within this spec
1. **db/026 (additive) — the one thing the schema lacked.** The agents screen's "the Analyst's issuers (empty = all)" had no column: `si_settings.analyst_issuer_ids` (appended last to `mcp_settings`), `si_analyst_covers(issuer)`
   (the one rule: empty covers every issuer) and `si_agents_settings_save()` — the screen's one write, the database checking the right (`agents.settings`, a person) and the shape (hour 0–23, day 1–7, issuers that exist,
   an escalation member who is an admitted person). The dispatches pass cancels, with its reason, a dispatch for an issuer the Analyst does not cover; the Friday draft is made for the covered issuers alone.
2. **The records API is the screens' own JSON, and a write is the form's own handler.** `GET /api/v1/<route>[/{id}]` includes the screen it mirrors (`issuers/index.php`, `holders/view.php`, `short/interest.php` …) after the key
   has been resolved, so the answer is exactly `{data: …}` of that screen under that person's rights and both walls — there is no second query layer to drift; `POST /api/v1/{events|uploads|borrow-observations}` `require`s
   `events/save.php`, `uploads/save.php`, `short/borrow/save.php` (a JSON body is read as the form fields; multipart works as it is). Three small kit changes make that possible and nothing else: `respond_screen()` remembers its
   payload (the `api.call` row counts rows from it), `verify_csrf()` lets a Bearer key stand in (a key's call has no cookie to forge; a session POSTing to the API still needs its CSRF token), and `app/api/bootstrap.php`
   forces `Accept: application/json` (a refusal is never an HTML page). `?issuer=` (id, ticker or CIK) names the issuer **without switching anything** (no `issuer.switch`, nothing in a session); a key that reaches several
   issuers and names none is a 422 in words, a restricted issuer named by a key not on its list is a 404 — the same nothing as a missing one.
3. **A Bearer key is what the call is.** The kit's `api_authenticate()` read a session first; a browser that held a session and sent a stale key would have been answered as the person. Now a presented Bearer wins (401 if it
   is bad) and a call without one uses the session; a key's call leaves no session behind, and a browser session the call happened to carry is put back as it was (never destroyed). One `api.call` row a call, written at
   shutdown: `{route (ids as {id}), method, status, rows, holders_rows?}` under the key's `token_id` and person, source `api` — **never the query string** (a name searched is a person's) and never the key. The 429 is logged too.
4. **Keys** (`sik_` + 48 hex): minted on the Tokens screen, shown once (the session carries it across the redirect; a JSON caller on a session gets `key` in the answer), only the sha256 stored, the label in the log.
   **Through the kernel's relay (an action token) `key_mint` and `key_rotate` are refused** ("shown once, to you, on the Tokens screen") — the registry makes them `people` actions, but a person's command-bar turn runs through the
   actions server and the answer would have put a key in a model's context. `member` (tokens.manage) mints for another person — a Viewer's key is minted by an administrator, since `tokens.own` is the Analyst's and above.
   At most ten live keys a person. Rotation is the owner's alone (an administrator would hold the new key: 403; another person 404), refused for a key already stopped, the old key working for
   `key_rotation_overlap_hours` (24). **Revoking is the key's own person's whatever their role** (the spec's "own or `tokens.manage`" read as *their own key*, not the right `tokens.own`: a leaked Viewer key must be killable by the
   Viewer), and `tokens.manage` revokes anyone's. A person no longer active here makes their key 401 (the database's `si_resolve_key`).
5. **Dispatches**: the database makes the `signal` and `filing` dispatches (db/014); `upload_ingest` now makes `upload_done` after a tied SPR, NOBO list or register (`upload_dispatch_done`, a failure logged and never undoing the
   upload) and the worker's `packs` pass makes `pack` on `pack_day` in market time, once an issuer a day, for the issuers the Analyst covers **and can see** (a restricted issuer's list must name it) with no weekly pack for the
   week. One chat turn each on `agents/chat.php?agent=<id>`, `X-Acting-Member` = the dispatch's person, else **the agent itself** (the GL's pattern; the fake kernel and the real one both take it), `wait` 20, the conversation
   `si-dispatch-<id>`. The words are built from the record as it is NOW (a signal disposed meanwhile cancels its dispatch, "The signal is dismissed already."). `answered` keeps 200 characters of the reply; a kernel `running`
   keeps `sent` with the run id and is asked again (`GET ?run=`) on the next pass, failing after 15 minutes; `awaiting_approval` is left to the kernel; **409 (a busy agent) is a failed turn, not a refusal**; any other 4xx is `refused`
   and never retried; three turns in all, the waits 5 and 15 minutes (`next_attempt_at`); at most 12 turns a pass. A cancel made while a turn ran stands. `agent.dispatch` (every turn), `agent.reply` (the reply's LENGTH),
   `agent.fail`; neither the reply nor the prompt is in any log row.
6. **The outbox**: the waits 1, 5 and 30 minutes after the first, second and third failure and the fourth failure final ("failed after three" read as three retries). MaluMail: a refused or suppressed address (400/422, or 200 with
   the address not accepted) and a missing key are `skipped` with the reason — retrying them is pointless — a transport failure or 5xx is retried; the kernel (K6): its 422/503/404/403 (`opted_out`, `no_verified_phone`,
   `no_sender`) are `skipped` with its code, a 5xx or no answer is retried. A member no longer active is skipped. The morning note has its own template (`emails/morning-note.php`), every other kind `emails/notification.php`;
   the link is the record's screen. `outbox.sent|failed` carry the id, channel, kind and attempts — no address, phone or word.
7. **Holder kinds**: the manifest has no action to accept or reject a kind proposal, so the proposals screen posts to `holder_kind_set` (`/holders/kind.php`): setting the kind an agent proposed now **accepts** that proposal
   (before, every open proposal was closed `rejected`, even the one taken), any other open proposal is rejected, and `decision=reject` with the `proposal` refuses it (`holder_kind_reject`, log `holder.kind_reject`, the holder
   unchanged, remembered: the worker never proposes that holder again). `proposal` must be open and the holder's. The worker's standing proposals (`standing_proposals_run`): an unknown-kind holder whose name ends in
   LLC, Inc or Trust, entity type as db/008's own reading of a name (`trust`, else `corporation`), `proposed_by` NULL, only while the settings have the Analyst proposing and for the issuers it covers; a signal explained by an
   event is **not** proposed by the worker (the agent proposes, the worker never disposes). Finding 9 of the holders slice (a trust is a person for the wall) is why the evidence says "a person decides".
8. **The proposals screen is open to every role that can see an issuer** (the spec's "`see`"; holder proposals `pii` — the view gives a Viewer the signals alone, and no button). The menu still shows Proposals only to
   `signals.work|matches.decide` (Phase 2's choice, kept: a Viewer reaches it by a link or the URL). Take it posts the matching disposition (`acknowledge`, `dismiss` or `escalate`) with the proposal's reason, event and note;
   Not this opens the signal's own form. The ids: `proposal-{signal|match|kind}-{id}`, `proposal-take-{id}`, `proposal-accept-{match|kind}-{id}`, `proposal-reject-{match|kind}-{id}`.
9. **K7**: `share_call()` is the proof's (and Phase 4's) door to db/018's five functions; it logs `share.read` {application = the consumer, tool, issuer_id, rows, error, ms} on the application's own connection (source
   `application`, no actor) — the records role cannot write the trail — never the answer. Beta (restricted) is `not_found` by id, ticker and CIK from every share, and its filings' text too; an empty consumer is
   `consumer_unknown`; every answer was searched for the fixture's people: none. The records role has no table (`permission denied` on `holders`), so the shares are its only door.
10. **Smaller**: the Tokens screen lists API keys beside the MCP tokens (calls today, limits, last used, when it stops; the rotate and revoke buttons; phones show the calls under the label); `/admin/agents` shows the dispatches
    as a table whose About cell wraps (a phone's Cancel button stays inside the card); `present_api_key()` is the key's whitelist (`app/api/present.php`); the registry marks 75 screens and 73 actions built, the installer's
    plan clean; the placeholder `render_nav_stub` of `/proposals` and `/admin/agents` is gone (`NAV_SLICES` keeps the words for the registry).
11. **Defects and gaps found, for the planning model**: (a) the manifest's `proposals` row also names "watch hits" among the proposals; `si_analyst_proposals()` (db/018) does not return them and the spec lists three kinds,
    so the screen has three; (b) `si_analyst_proposals()` lists a signal's proposal only while the signal is `open` (an escalated signal's disappears from the screen, though the signal card still shows it); (c) the fake
    MaluDB+MaluMail on :8903 (`tests/fake_maludb.php`) answers `/v1/send` without a `message_id`, so the proof cannot show MaluMail's reference kept; (d) an administrator cannot mint a key from the screen for another person
    (only the handler's `member` field) — a "Keys" admin list is slice 10's `tokens.manage` surface if wanted.
12. **Tests** (`tests/phase3/slice9/run.sh`, a fresh scratch database each): proposals 37, dispatch 38, outbox 29, shares 22, keys 35, api 63, json 21, browser 84 — under php -S and under Apache (the same counts).
    Phase 2's placeholder checks move to `/admin/sources` (slice 10's), `/proposals` is open to every role in its role × screen matrix, and the unauthenticated records API is 401 (it was 404 until this slice).
