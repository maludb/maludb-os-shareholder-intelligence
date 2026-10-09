# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Shareholder Intelligence on the launcher — or types `shareholders.<domain>` on a phone — and lands,
signed in, on a home that is finished though nothing is uploaded yet: **one button for a new installation ("Add your first issuer")**, the
issuer switcher in the header (the issuers the wall lets them see; one issuer = no switcher), the bell, the command bar, the one search box,
the application switcher, the bottom tab bar; their notification preferences exist before they look; the mirror refreshes every minute;
every request is logged; the command bar answers through the kernel; the record picker is in the shell for every later form. **The kit is in
the repo already (Phase 0) and proven without a kernel (`tests/phase0/run.sh`, 73 checks)**; this phase wires the shell around it and proves
it under Apache at 375 and 1280. Copied from Sales CRM's and DocCloud's shells (2026-10-09); every file keeps its sibling's contract.

Schema it runs on (db/001–018, never modified): `members` (+ `is_agent`, `notes`), `departments`, `department_members`, `directory_sync_state`,
`sso_nonces`, `member_sessions` (db/001); `activity_log` (+ `issuer_id`, `security_id`, `import_id`, `signal_id`, `token_id`) (db/002);
`mcp_access_tokens` + `key_usage` + `mcp_resolve_token()` (db/003); `si_rights`, `si_roles`, `si_role_rights`, `si_has_right()`, `si_is_admin()`,
`si_is_manager()`, `si_is_viewer()`, `si_sees_holder_pii()`, `si_is_member_here()`, `mcp_app_roles` (db/004); `si_settings`, `si_edgar_user_agent()`,
`si_is_market_day()`, the key functions (db/005); `si_my_issuers()`, `si_can_see_issuer()` (db/006); `notifications`, `notification_prefs`,
`notification_outbox`, `si_notify()`, `worker_passes` (db/014); `search_index`, `si_search()` (db/015); `si_ownership_summary()` (db/018); the `mcp_*` views.

## Files
**In the repo (proven by `tests/phase0/run.sh`):** `app/{bootstrap,db,http,directory,auth,handler,activity,mail,partial_update}.php`, `app/api/{bootstrap,kernel}.php`,
`bin/{directory_sync,dev_handoff,mint_mcp_token,make_brand_images,worker,build_action_registry,sync_approvals}.php`, `bin/dev_directory.json`,
`mcp/{activity_ingest.py,db.py,server_common.py,requirements.txt}`, `tests/{dev_router,fake_kernel,fake_maludb,fake_malumail}.php`, `tests/setup_dev.sh`, `tests/phase0/`.
**Phase 2 adds:**
- `app/features/shell/{nav,queries,issuer}.php` (`NAV_ITEMS`: Home, Positions, Holders, Ownership, Filings, Short side, Signals, Events, Reports, Issuers, Admin —
  each with the right that shows it and `NAV_SLICES` naming who builds its placeholder; `bell_count()`; `business_name()`; **`current_issuer()`** — the session's
  issuer, set from `?issuer=` (an id, ticker or CIK) when the wall admits it, else the only issuer visible, else none; `current_security()` the issuer's primary),
  `app/features/home/queries.php` (`home_summary()` over `si_ownership_summary()`, the open signals, the next events, the latest uploads), `app/features/settings/{queries,present,write}.php`,
  `app/features/search/{queries,present}.php`, `app/features/activity/queries.php`, `app/features/assistant/queries.php`, `app/switcher.php` (the kernel's `GET /api/v1/apps/mine.php`, cached 5 min).
- `app/picker.php` (`picker_field()`, `picker_source()`, `picker_label()`), **`app/pickers.php` with `member` and `issuer` only** (each slice adds its sources), `html/pick/index.php`,
  `shared/picker-rows.php`, `assets/js/record-picker.js`, the one `#record-picker` modal in `layout.php` after `</main>`.
- Views: `layout.php` (the nxl shell; the header with the search box, **the issuer switcher** (a dropdown of `find_issuers`; the current one's ticker in the header),
  the application switcher, the bell, the command bar; the bottom tab bar at 375: Home, Signals, Positions, Holders, More), `shared/{header,flash,notice,message,tab-bar,
  issuer-switcher,assistant-bar,assistant-reply,picker-rows,app-switcher,page-header,source-line}.php` (`source-line`: "Source: FINRA, settlement 2024-03-28, published 04-10" —
  the line under every number and chart), `home/{index,first-run}.php` + `partials/{tiles,signals,overlay,events,uploads,note}.php`, `search/index.php` + `partials/result.php`,
  `settings/{index,tokens}.php` + `partials/prefs.php`, `notifications/{page.php,partials/bell.php}`, `activity/{page.php,partials/rows.php}`, `sso/refused.php`,
  `nav-stub.php` (the placeholder every slice's screen renders until built: the screen's name, the slice that builds it, 501 for JSON and POST).
- Handlers: `html/{index,search,logout,notifications,activity}.php`, `html/notifications/{read,read-all}.php`, `html/settings/{index,prefs,tokens}.php`,
  `html/settings/tokens/{mint,revoke}.php` (the key handlers `key-mint`, `key-rotate`, `key-revoke` are slice 9's), `html/assistant/ask.php`, `html/pick/index.php`,
  `html/sso.php`, `html/sso/logout.php`, `html/api/v1/health.php`, the placeholders.
- `deploy/apache-shareholder_intelligence.conf` and `tests/dev_router.php` carry every rewrite of the manifest's URL forms.

## Shareholder Intelligence is not Sales CRM
**The current issuer is the shell's first fact**: every screen below reads it; the switcher writes it; a member who may see no issuer sees the first-run
page (a manager: "Add your first issuer"; anyone else: "No issuer is shared with you yet — ask the IR Manager"). The home is **the ownership summary**
(`si_ownership_summary(primary security, today)`) as tiles — outstanding, float, short as a share of float with its settlement date, days to cover, the DTC total and
the retail/institutional split with the position date, the reconciliation's state (tied green, untied red with the difference), the 13F holders' total with the quarter,
the 5% filers' count — each tile with its source line; the open signals by severity as cards (the class chip); the price with the events overlay (90 days, SVG); the
next 30 days' events; the latest upload of each kind with its tie badge; the morning note's last text when the Analyst wrote one. **The search box's holder results
follow the PII wall** (`si_search` decides). `notification_prefs` is created on sign-on and by the directory sync for every new member.

## The receiver (`/sso`) — in order
As the siblings': verify the two signatures, the TTL, the audience (`APP_KEY = shareholder_intelligence`), the single-use nonce; mirror the member and departments from
the claims; refuse an unknown or ungranted member (403, logged `member.sign_on.refused` with the reason, never told); open the hardened session; ensure
`notification_prefs`; log `member.sign_on` with the roles; 302 to `/` (or the `next` the launcher carried, same-origin only).

## Every request
`app/bootstrap.php`: the session, the member re-checked against the mirror (revoked → the launcher within a minute), `app.member_id` on the PDO connection,
the action-token / relay path for the kernel's actions server, `json_mode_begin()`, `log_screen_view()` on GET screens (with `issuer_id` when one is current).

## MCP tools this slice names (Phase 4 serves them; the shell's screens read the same SQL)
`app_roles`, `records_search`, `search`, `my_notifications`, `get_settings`, `find_members`, `find_departments`, `find_tags`, `find_notes`, `recent_activity`, `activity_search`.

## Query functions (signatures fixed)
- `current_issuer(PDO): ?array` · `set_current_issuer(PDO, string $ref): ?array` · `current_security(PDO): ?array`
- `home_summary(PDO, int $memberId, ?int $issuer): array` → `['first_run' => bool, 'summary' => si_ownership_summary json, 'signals' => rows, 'overlay' => rows, 'events' => rows, 'uploads' => rows, 'note' => ?text]`
- `bell_count(PDO, int $memberId): int` · `business_name(PDO): string`
- `search(PDO, string $q, array $kinds, ?int $issuer, int $page): array{rows, total}` (over `si_search`)
- `my_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $fields): array`
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $limit, int $page): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): array` · `revoke_token(PDO, int $memberId, int $id): bool`
- `find_my_activity(PDO, int $memberId, int $page, array $filters): array` · `activity_sentence(array $row): string`
- `ask_assistant(PDO, int $memberId, string $utterance, array $context): array` (the context carries the current issuer and the screen)

## Handlers (built)
`settings/prefs.php` (`prefs_save`), `notifications/read.php` (`notification_read`), `notifications/read-all.php` (`notifications_read_all`), `settings/tokens/mint.php`
(`token_mint`: scope `mcp` here; `api` is slice 9's `key_mint`), `settings/tokens/revoke.php` (`token_revoke`), `logout.php` (POST + CSRF; `member.sign_out`),
`assistant/ask.php` (`assistant.ask`: the length and the screen, never the words).

## Manifest rows claimed
Screens (6): `home`, `search`, `notifications`, `activity`, `settings`, `settings-tokens`
Actions (5): `prefs_save`, `notification_read`, `notifications_read_all`, `token_mint`, `token_revoke`

Routes, not actions: `/sso`, `/sso/logout`, `/api/v1/health`, `/pick/`, the issuer switcher's GET, the command bar's post to the kernel's chat endpoint, the
application switcher's feed. The placeholders (Positions, Holders, Ownership, Filings, Short side, Signals, Events, Reports, Issuers, Admin) are the slices' screens
whose files call `render_nav_stub()`; the registry marks them `stub`; they are claimed by the slices that build them, never here.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.session_ended`, `screen.view` (every GET screen, the screen id, the issuer), `issuer.switch`,
`settings.prefs`, `notification.read`, `notification.read_all`, `token.mint`, `token.revoke`, `assistant.ask`, `search.keyword` (the query cut at 120, the count).

## Status vocabulary
The bell `badge text-bg-danger` with the unread count; the severity colours (high `text-bg-danger`, watch `text-bg-warning`, info `text-bg-info`); the class chips
of `signals.md` §2; tied/untied of `position-reports.md` §5. Ids: `header-search`, `header-user-name`, `issuer-switcher`, `bell`, `bell-count`, `tab-bar`,
`home-tiles`, `home-signals`, `home-overlay`, `home-events`, `home-uploads`, `home-first-run-btn`, `record-picker`, `assistant-bar`.

## Out of scope for this phase
Every record screen (the slices); the worker's real passes (stubs until their slices); the API's routes but health; the keys (slice 9).

## Proof (`tests/phase2/run.sh`: scratch database `si_dev2`, php -S on 8901, the fake kernel on 8902, the fake MaluDB + MaluMail on 8903; then the same under a real Apache vhost)
Sign-on for every fixture member with the right roles and the refusals of Phase 0 re-run; the home for a new installation (a manager's one button; a Viewer's
sentence), for an Analyst with the Phase 0 fixtures loaded (the tiles with their source lines, the signals, the overlay); the issuer switcher offers the open
issuers and, to a listed member, the restricted one — never to anyone else; the search box finds a fixture filing and honours both walls; the bell counts and
reads; prefs save and read back; a token minted once and revoked; the application switcher renders the fake kernel's applications; every placeholder answers 200 to
a person, 501 to JSON; the command bar's post reaches the fake kernel's chat endpoint and renders the reply; 375 (the tab bar, no horizontal scroll) and 1280; no
console errors; the installer's `plan` clean.

## Built (2026-10-09) — what the build decided within this spec
1. **The phone's fifth tab is Me** (My settings, as in every sibling), not "More": the sidebar's offcanvas is already the header's menu button.
2. **The issuers a person follows** (`prefs_save` `issuer_ids[]`) are checkboxes of the issuers they may see, not the record picker: the picker
   chooses one record and an installation follows a handful of issuers; the handler refuses an issuer outside the wall.
3. **Seventeen placeholders**: the fourteen menu items, the calendar the home links to, and the two buttons the home offers (`/issuers/new`,
   `/uploads/new`) — so no link of the shell is a 404. The registry marks eighteen screens `stub` (issuer-edit shares the form placeholder).
   Each logs the manifest's screen id (`NAV_SCREENS`).
4. **`X-Nav`**: an HTMX screen sends the menu item it belongs to, so the highlight follows the server's choice, not a guess from the screen id.
5. **db/019** — `mcp_activity_log` gains `actor_is_agent` (the trail says "(agent)"), appended last.
6. **`app/si/svg.php` starts here** with the home's price-and-events line (gaps of more than five days unjoined, a table twin); slice 2 adds the
   bars, the stacked flow and the matrix to the same file.
7. **Health** reports `edgar_user_agent` (set/missing) and `storage` (unset/missing/unwritable/ok; only missing or unwritable fail it).
8. **The kit's mirror fix** (Inventory, 2026-10-09) is in: a hand-off's claims carry no title, phone or time zone, and the mirror keeps the
   directory's (proven).
9. **The command bar's context** carries `issuer_id` and the issuer's ticker to the kernel's chat endpoint.
10. DocCloud's leftovers removed from the kit: the `/d/`, `/f/`, `/upload/` rewrites in the internal vhost and the dev router, the `INVSID`
    session name (now `SISID`), the web manifest's name; `is_dc_admin()` is `is_si_admin()`.

Proof: `tests/phase2/run.sh` — browser 82, gates 87, vhost 46, picker 30, switcher 14 under php -S; the same with 49 vhost checks under a real
Apache (`SI_APP=apache`); the schema proof 172 and the kit 73 re-run green; Phase 1's 60 consistency checks green; the installer's plan clean.

## Open questions
None.
