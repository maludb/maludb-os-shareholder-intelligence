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
