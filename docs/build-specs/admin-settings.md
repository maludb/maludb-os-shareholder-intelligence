# Build spec: admin — sources, credentials, classes, templates, settings, tokens, purge (slice 10)

What exists at the end: the admin runs the application: the fetchers (schedule, pause, the last pulls, the policy each followed), their credentials (set and rotated,
never shown back), the participant classes, the import templates, the settings (the EDGAR user-agent first), everyone's keys, and the tombstones.

Schema (db/005, 006, 009, 014, 018, never modified): `si_settings`, `si_edgar_user_agent()` (db/005); `participant_classes`, `participants` (db/006); `sources`, `source_pulls`,
`source_credentials` (db/009); `import_templates`, `si_tombstones_purge()` (db/014); `si_source_health()`, `si_tombstones()` (db/018); `mcp_sources`, `mcp_source_pulls`,
`mcp_source_credentials`, `mcp_import_templates`, `mcp_settings`, `mcp_keys` (db/016).

## Divergences from the design
1. **A credential is libsodium secretbox ciphertext under `SI_SECRETS_KEY`**, decrypted in ONE file (`app/si/fetch/credentials.php`) by the fetcher that needs it; the form shows the label
and the last four only; `credential_set` is `people`. 2. **`settings_save` is `people`** (pauses for an agent too, by policy, should the column ever change). 3. **The admin settings page
shows a red banner while the EDGAR user-agent is empty** — the same check K35 asks the installer to make.

## Who may do what
source_save / source_schedule_set: `sources.manage` (pause, `other`) · source_pause / source_resume: `sources.manage` · credential_set: `credentials.manage` (`people`; pauses, `other`) ·
participant_class_set: `issuers.manage` · template_save: `settings.manage` · template_delete: `settings.manage` (pauses, `deletion`) · settings_save: `settings.manage` (`people`; pauses, `other`) ·
token_revoke_any: `tokens.manage` (`people`) · records_purge: `records.purge` (pauses, `deletion`).

## Screens
`admin-sources` `/admin/sources?connector=` (the table: name, connector, schedule, the calendar's next slot, the last pull's status and time, failures, backed off until, paused; Pull now,
Pause, Resume; the EDGAR user-agent banner) · `admin-source-view` `/admin/sources/{id}` (settings as a definition list, the credential's label and last four, the last 50 pulls: status,
counts, requests, bytes, error, **the policy followed** — user-agent, rate, robots, ETag hits) · `admin-source-edit` · `admin-source-credential` (kind, label, the secret in a password field;
never echoed) · `admin-participant-classes` `/admin/participant-classes?class=` (the eight classes with their participants; reclass by the participant picker) · `admin-templates` (the templates
by kind and connector) · `admin-template-edit` (the mapping as a JSON editor with the sample rendered beside it) · `admin-settings` (the form, grouped: the business and the EDGAR contact; the
exchange, time zone and holidays; the fetch rates; the tolerances and thresholds; PII in exports; the escalation member; keys; uploads; the price source) · `admin-tokens` (everyone's keys
and MCP tokens: person, label, scope, limits, today's calls, last used; Revoke) · `admin-purge` (`si_tombstones()`: kind, label, deleted when and by whom, purges after; Restore, Purge now).

## Files (exactly these)
`html/admin/{sources,participant-classes,templates,settings,tokens,purge}/index.php`, `html/admin/sources/{view,form,save,schedule,pause,resume,credential}.php`, `html/admin/participant-classes/set.php`,
`html/admin/templates/{form,save,delete}.php`, `html/admin/settings/save.php`, `html/admin/tokens/revoke.php`, `html/admin/purge/run.php`; `app/features/admin/{queries,present,write}.php`,
`app/si/fetch/credentials.php`; `app/views/admin/{sources,source,source-form,credential,classes,templates,template-form,settings,tokens,purge}.php` + `partials/ua-banner.php`; the worker's
`tombstones_purge` and `key_usage_prune` passes; `tests/phase3/slice10/{run.sh,lib.php,sources.php,credentials.php,settings.php,purge.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`sources_overview(PDO): array` · `find_source(PDO, int $id): ?array` · `source_pulls(PDO, int $id, int $limit): array` · `classes_overview(PDO): array` · `find_templates(PDO, ?string $kind): array` ·
`find_template(PDO, int $id): ?array` · `settings_form(PDO): array` · `all_tokens(PDO, ?int $member): array` · `tombstones(PDO, ?string $kind): array` · writes: `source_save(PDO, int $id, array $f)` ·
`source_schedule_set(PDO, int $id, int $minutes)` · `source_pause(PDO, int $id, ?string $reason, bool $resume)` · `credential_set(PDO, int $source, array $f, int $by)` · `participant_class_set(PDO, int $participant, string $class)` ·
`template_save(PDO, ?int $id, array $f, int $by): int` · `template_delete(PDO, int $id)` · `settings_save(PDO, array $f)` · `token_revoke_any(PDO, int $id)` · `records_purge(PDO, ?string $kind, ?int $record): int`

## MCP tools this slice makes real
`find_sources` (the resolver), `source_health`, `find_templates`, `tombstones`.

## Manifest rows claimed
Screens (10): `admin-sources`, `admin-source-view`, `admin-source-edit`, `admin-source-credential`, `admin-participant-classes`, `admin-templates`, `admin-template-edit`, `admin-settings`, `admin-tokens`, `admin-purge`
Actions (11): `source_save`, `source_schedule_set`, `source_pause`, `source_resume`, `credential_set`, `participant_class_set`, `template_save`, `template_delete`, `settings_save`, `token_revoke_any`, `records_purge`

## Activity log events
`source.save|schedule|pause|resume`, `credential.set|rotate` (`{source_id, kind, label, last4}` — never the secret), `participant.class_set`, `template.save|delete`, `settings.save`
(the changed keys, never a value of a secret), `token.revoke_any`, `records.purge` (`{kind, count}`).

## Status vocabulary
Pull status chips (ok `text-bg-success`, partial `text-bg-info`, failed `text-bg-danger`, blocked `text-bg-dark`, running `text-bg-secondary`); paused `text-bg-warning`; the UA banner `alert alert-danger`.
Ids: `sources-table`, `source-row-{id}`, `source-pulls`, `credential-form`, `classes`, `templates`, `settings-form`, `ua-banner`, `tokens-table`, `purge-table`.

## Out of scope for this slice
Nothing of the design remains after this slice but Phases 4 and 5.

## Proof (`tests/phase3/slice10/run.sh`: scratch `si_dev310`; the stub server; Playwright; the registry `--check`)
A source paused is skipped by the worker and resumed; a schedule change moves its next slot; a credential set is stored as ciphertext (the database never holds the secret's bytes — a scan),
shown as last four, decrypted by the fetcher against the stub (which checks the header), rotated; an agent's `source_save` pauses; the settings save with the EDGAR contact removes the banner and
an EDGAR pull runs; a template's mapping edit re-parses an upload; everyone's keys listed and one revoked; the purge removes what waited past the window; a Viewer and an Analyst are refused
every admin POST (403); 375 and 1280; JSON mode.

## Open questions
None.

## Built ahead (2026-10-11, the planning model) — the credential door
For Alpha Vantage's key (the owner: added later), `admin-source-credential` and `credential_set` are built: `html/admin/sources/credential.php` (GET the
screen, POST the action — one file, as the Files list has it), `app/features/admin/{queries,write,present}.php` with `find_source()`, `credential_set()`
and `present_source_credential()`, `app/views/admin/credential.php`, proven by `tests/phase3/slice6/alphavantage.php`. **Slice 10 extends these files**
(adds the other functions beside them; keeps `credential_set`'s behaviour: one credential a source, a rotation replaces the ciphertext and stamps
`rotated_at`, `credential.set|rotate` logged with `{source_id, kind, label, last4}`, and a source paused for want of a key — `settings.paused_for_key`,
db/025 — resumes when the key arrives, logged `source.resume`). Its `tests/phase3/slice10/credentials.php` may re-prove the door through the sources screens.

## Built (2026-10-11) — what the build found and decided within this spec
`tests/phase3/slice10/run.sh` — sources 56, credentials 22, settings 32, purge 43, json 10, browser 92, under php -S and under Apache (`SI_APP=apache`), all green; the registry `--check` passes: 84/84 screens and 83/83 actions built, no stub.
1. **db/027** (additive): `mcp_all_tokens` (everyone's MCP tokens and keys, tokens.manage, no hash), `si_token_revoke_any()`, `si_tombstones_purge_due(kind, record, limit)` (the one purge, shared by the worker and the admin: only past `tombstone_days`, attachments of the purged records with it, answers the stored files for PHP to unlink after the commit), and the settings column's default price source follows db/025 (Alpha Vantage).
2. **The next slot** (`source_next_slot()`) walks the connector's own calendar (`si_source_on_calendar`) in half-hour steps from max(now, last good pull + schedule, back-off); paused, inactive, manual or unbuilt connectors have none. `si_is_market_day_on()` memoises per process for it.
3. **source_save** refuses a base URL with user:password, a non-http(s) scheme, settings that are not an object or hold a key named like a secret (at any depth), an EDGAR source above the settings' EDGAR rate; it logs the changed field names and the settings by the names of the keys that differ. **settings_save** lowering the EDGAR rate lowers every EDGAR source above it and says how many.
4. **A source paused for want of its key** cannot be resumed by `source_resume` (422 naming the credential); `credential_set` lifts it, as built ahead.
5. **Templates**: a mapping is a saved JSON object; of it the parsers read today only the extract's `drop_columns` (in `upload_bytes_to_store`). Decision (safe, taken): the extract template cannot be deleted, keeps its connector, and its `drop_columns` may grow but never lose a never-stored column (`SI_NEVER_STORED_COLUMNS`, also enforced at storing time whatever the template says). OPEN QUESTION for the owner: should the other connectors' layouts become template-driven? Today they are code; the editor shows the mapping beside "what it reads" and says so.
6. **Purge**: Restore on the screen posts to each kind's own restore action (upload, holder, event, issuer), which now honour `return_to`; a report has no restore action in the manifest, so none is offered. A named record not past its window is a 422, "all" with nothing due says so.
7. **Tokens**: `token_revoke_any` (people only) revokes a key or an MCP token; the person's own screens are slice 9's and untouched.
8. **Worker**: `tombstones_purge` and `key_usage_prune` are real (logged `records.purge` by cron).
9. **No placeholder left**: `render_nav_stub()`, `NAV_SLICES` and `NAV_SCREENS` are removed; Phase 2's `gates.php`, `vhost.php` and `browser.mjs` now prove that (no controller calls a stub helper, no `stub` in the registry, `/admin/sources` is the real screen). Phase 2 stays green under both servers.
10. **Phone layout**: the row actions of the sources and the purge sit in the first cell (a Do column overflowed the card at 375 px).
Regression re-run under the lock: phase 1 specs 60, Phase 2 (82+86+46+30+14, both servers), slice 6 (`SI_LIVE=0`), 7, 8, 9 all green; the installer's `plan` reads: 49 steps, 7 notes.
11. **Templates read (2026-10-11, after Phase 3)**: a `generic_mapped` template's mapping is now read by `app/si/parse/generic_mapped.php` and checked as it will be read when saved (`template_mapping_problem(…, kind)`: kind spr, nobo or register, the kind's required fields, no never-stored column) — `docs/build-specs/position-reports.md` §7.
