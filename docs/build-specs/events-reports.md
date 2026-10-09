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
