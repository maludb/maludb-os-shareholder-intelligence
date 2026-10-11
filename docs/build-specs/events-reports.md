# Build spec: events, the calendar, reports and the pack (slice 8)

What exists at the end: the issuer's timeline of events (made by filings, threshold days and people), the month calendar of what happened and what is coming, the
packs (the Friday draft by the Analyst, edited and approved by the IR Manager, rendered to HTML and PDF, sent to members), and exports (every kind, the holders export
behind the PII gate and logged with its count).

Schema (db/009, 014, 016, never modified): `events` (db/009), `reports`, `si_report_approve()`, the `reports_guard` trigger, `exports`, `attachments` (db/014);
`mcp_events`, `mcp_reports`, `mcp_exports` (db/016); `si_ownership_summary()` (db/018) for the pack's first section.

## Divergences from the design
1. **The pack is rendered from the sections' own queries at render time** (the same functions the screens use); the narrative is the only text stored. 2. **`report_send`
sends to members only** (the recipients by picker; an external member is admitted when the issuer's list names them — the advisory firm's client) — through MaluMail with
the PDF attached when rendered; it pauses for an agent (`external_send`). 3. **The calendar's coming events** include the computed publication days of FINRA's short interest
and the 13F deadlines (no row stored).

## Who may do what
event_create / event_update / event_delete / event_restore: `events.write` (`event_delete` pauses, `deletion`) · report_draft / report_update / report_render: `reports.run` ·
report_approve: `reports.approve` (pauses, `other`) · report_send: `reports.approve` (pauses, `external_send`) · report_delete: `reports.approve` (pauses, `deletion`) · export_create:
`export.own` (a holders export also `pii_export_allowed()`); the screens: `see`; a draft pack `reports.run`.

## Screens
`event-list` `/events?kind=&severity=&from=&to=&q=` (the timeline grouped by month: date, kind chip, severity dot, title, the filing linked) · `event-view` (the event, its filing,
the signals it explains, notes, tags) · `event-add` / `event-edit` (date, kind, title, body (Markdown), URL, severity, the filing by picker, tags) · `calendar` `/calendar?at=YYYY-MM`
(the month grid, the cidery's server-rendered grid: past events as chips by kind; coming record dates, earnings and expiries; FINRA's publication days and the 13F deadlines in muted
chips; a day opens its events) · `report-list` (cards: kind, period, status chip, approved by) · `report-view` (the narrative rendered, the sections rendered below it (`signals.md`'s and the
other slices' partials re-used), Approve, Render, Download PDF, Send to…) · `report-add` / `report-edit` (period, kind, the sections as checkboxes, the narrative editor; frozen once approved) ·
`export-list` (mine and, with `export.shared`, everyone's: kind, rows, PII chip, size, expiry; Download).

## Files (exactly these)
`html/events/{index,view,form,save,delete,restore}.php`, `html/calendar/index.php`, `html/reports/{index,view,form,save,render,approve,send,delete,file}.php`, `html/exports/{index,create,download}.php`;
`app/features/events/{queries,present,write}.php`, `app/features/calendar/queries.php`, `app/features/reports/{queries,present,write,render}.php` (`render_pack()`: HTML from the sections'
partials; the PDF through `SI_WKHTMLTOPDF` when set), `app/features/exports/{queries,write,build}.php` (the worker's `exports` pass builds the CSV); `app/views/events/{index,view,form}.php`,
`app/views/calendar/month.php`, `app/views/reports/{index,view,form,pack}.php` + `partials/section-*.php`, `app/views/exports/index.php`, `app/views/emails/pack.php`; the worker's `packs` and
`exports` passes; `app/pickers.php` gains `event` (already in 7), `report`; `tests/phase3/slice8/{run.sh,lib.php,events.php,calendar.php,reports.php,exports.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_events(PDO, int $issuer, array $filters, int $page): array` · `find_event(PDO, int $id): ?array` · `events_on(PDO, int $issuer, string $date, int $days): array` · `events_upcoming(PDO, int $issuer, int $days): array` ·
`calendar_month(PDO, int $issuer, string $month): array` · `find_reports(PDO, int $issuer, array $filters): array` · `find_report(PDO, int $id): ?array` · `render_pack(PDO, int $report): array{html, pdf_path?}` ·
`find_exports(PDO, int $memberId, bool $all, ?string $kind): array` · writes: `event_save(PDO, ?int $id, array $f, int $by): int` · `event_delete(PDO, int $id, bool $restore)` · `report_save(PDO, ?int $id, array $f, int $by): int` ·
`report_approve(PDO, int $id)` · `report_send(PDO, int $id, array $recipients, ?string $message, int $by): int` · `report_delete(PDO, int $id)` · `export_create(PDO, array $f, int $by): int`

## MCP tools this slice makes real
`events_on`, `find_events` (the resolver), `events_upcoming`, `find_reports` (the resolver), `get_report`, `find_exports`, `activity_timeline`.

## Manifest rows claimed
Screens (10): `event-list`, `event-view`, `event-add`, `event-edit`, `calendar`, `report-list`, `report-view`, `report-add`, `report-edit`, `export-list`
Actions (11): `event_create`, `event_update`, `event_delete`, `event_restore`, `report_draft`, `report_update`, `report_render`, `report_approve`, `report_send`, `report_delete`, `export_create`

## Activity log events
`event.create|update|delete|restore`, `report.draft|update|render|approve|send|delete` (`report.send {recipients: n, pdf: bool}` — ids, never addresses), `export.create|build|download`
(`{kind, rows, includes_pii}`).

## Status vocabulary
Event kind chips; severity dots; pack status (draft `text-bg-warning`, approved `text-bg-success`, published `text-bg-primary`); the PII chip `text-bg-danger` "contains names" on an export.
Ids: `event-row-{id}`, `calendar-grid`, `calendar-day-{date}`, `report-card-{id}`, `report-narrative`, `report-sections`, `export-row-{id}`.

## Out of scope for this slice
The Analyst's Friday draft as a duty (9 — this slice gives it `report_draft`); the morning note (9).

## Proof (`tests/phase3/slice8/run.sh`: scratch `si_dev38`; the fake MaluMail; Playwright; the registry `--check`)
Events from the fixture filings and a person's short report; the calendar shows them and the computed publication days; a pack drafted by the Analyst's token, edited, rendered (HTML, and the
PDF when `wkhtmltopdf` is present), approved by the manager (an Analyst refused, an agent paused), the narrative frozen after; sent to two members through the fake MaluMail (an agent's send
paused; an address outside the members refused); a holders export by an Analyst logged with its count, refused to a Viewer; 375 and 1280; JSON mode.

## Open questions
None.

## Built (2026-10-11) — what the build found and decided within this spec
1. **MaluMail's send API takes no attachment** (the plugin's `malumail-send`: "No attachments/inline images, no cc/bcc"), so "sent … with the PDF attached" cannot be. `report_send` mails one message a
   recipient — the sender's words, the narrative as written and a link to `/reports/{id}` inside the application — and the PDF/HTML is downloaded after sign-in (`/reports/{id}/file?format=`). The log's `pdf`
   says whether a PDF existed. A recipient is an active human member admitted here who may see the issuer; an EXTERNAL member only when the issuer's list names them; one bad recipient refuses the whole
   send before any mail; an address (not a member id) is refused in words; a suppressed address is counted `failed`, and a send nobody accepted is a 422 that changes nothing. The pack is `published` once a
   message is accepted. "Send to…" is three member pickers (the picker is one record at a time); the action takes any number (≤ 25).
2. **Approval keeps the numbers it was approved on.** `report_approve` approves (db/014's function, the narrative trigger) and renders the pack at once, replacing the draft's file; an approved pack's
   `report_render` is refused ("kept as approved") unless it has no rendering, and the page shows the stored sections of an approved pack ("as rendered when approved") but a draft's drawn from today's data.
   The stored file is a stand-alone document (inline CSS) served with `Content-Security-Policy: default-src 'none'; … sandbox`. Edits to an approved pack — any field — are a 422 in PHP (the database only freezes
   the narrative); the way to correct one is a new draft.
3. **The pack is aggregates and the public record**: the holders sections are counts and ranges, never a name, so a pack rendered by an Analyst reads the same to a Viewer. Thirteen section partials
   (`app/views/reports/partials/section-<key>.php`) draw `pack_section_data()` — each calls the screens' own query function, for the issuer's primary security. The Markdown renderer
   (`markdown_html()`, `app/features/events/present.php`) escapes first, then headings, lists, bold, italic, code and http(s) links only.
4. **Exports are built in the request**, as the person who asked, and the row is `queued` only for an instant: `export_create()` inserts and calls `export_build()` (which claims the row `queued → running`
   atomically); the worker's `exports` pass builds whatever was left queued with `as_member()` (the database's acting member set to the creator, so the walls read as them), fails one with no active creator,
   tells the creator (`export_ready`) and removes the file of an export past its seven days (the row stays; the download answers 410). **`has_right()` and `si_sees_pii()` cache per request — the build asks the
   database each time** (`export_pii_allowed()`), because the worker builds for several people in one pass. A holders export needs `holders.read` as well as `export.own`, carries a natural person's name only
   when `pii_in_exports` admits the creator (analyst / admin / nobody; otherwise "Individual holder", no street, city or full zip) and sets `includes_pii` only when a name is actually in it; a cell that could be a
   formula gets an apostrophe. An export's download is its creator's or `export.shared`'s (mcp_exports) and refused with names in it to anyone who may not see names. Cut at 100,000 rows, said in its log.
5. **The worker's `packs` pass** renders an APPROVED pack that has no rendering (as its approver); a draft is a person's to render. The Friday draft on pack_day stays slice 9's. A pack an agent drafts tells the IR
   Managers (`pack_drafted`); an approval tells the drafter (`pack_approved`) — both through `si_notify()`, which keeps the issuer wall.
6. **The calendar's computed days**: FINRA's publication is the 7th market day after each settlement date (the 15th and the month's last, each moved back to a market day — `si_market_days()`), the 13F
   deadline 45 days after each quarter end moved on to a market day; a dilution instrument's `expires_on` is a coming chip. None is stored; `events_upcoming()` returns the same rows (source `computed` /
   `instrument`). The grid is Monday-first, server-rendered; at 575 px and under a day shows dots and a day number that is a 44 px target, the agenda beneath carrying the words.
7. **Events**: a title is shown in the trail (it is a label, not a body); the body, a link and the tags' words are not. A filing-kind event is unique per filing (db/009's index) — the form says so before the
   database does; a restore that would collide is refused in words. After a delete the list offers "Undo the delete". `event.update` logs only what changed (kind, severity, date, filing, security).
8. **Smaller**: the pickers gain `report`; `html/exports/download.php` is `/exports/{id}` (the vhost's named rewrite, both vhosts); `render_nav_stub`'s placeholders for events, reports and calendar are gone, the
   registry marks 72 screens and 66 actions built; activity words added for the new log events; `app/si/storage.php` gains `si_store_named()` / `si_stored_named()` / `si_unlink_named()` (reports/ and exports/).
   `si_report_approve()` raises `insufficient_privilege` for a caller without the right — unreachable (the handler checks the right first), noted in case a future caller does not.
9. **Tests**: `tests/phase3/slice8/run.sh` — events 40, calendar 22, reports 67, exports 31, json 14, browser 134 — under php -S and under Apache (the same counts). The PDF is proved with a stand-in script
   named by `SI_WKHTMLTOPDF`; no renderer is installed on this machine and none was installed. Phase 2's placeholder checks move to `/proposals` (slice 9's).
