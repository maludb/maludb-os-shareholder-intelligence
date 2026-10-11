# Build spec: SIGNALS and the rules (slice 7 — THE SECOND EXEMPLAR)

Builds `signals.md`. What exists at the end: the nineteen rules run by the worker after every pull and upload and at 06:45 on market days; signals as cards with
their evidence class and basis; each signal's page with its own evidence table and chart; dispositions by a person, proposals by an agent taken in one click;
escalation; the history; the rules' catalogue and their thresholds, globally and per issuer; the outcomes for the Auditor.

Schema (db/013, 018, never modified): `signal_rules`, `signal_rule_overrides`, `signals`, `signal_outcomes`, `si_rule_result`, `si_rule_params()`, `si_rule_enabled()`,
the nineteen `si_rule_*()`, `si_rules_run()`, `si_signal_dispose()`, `si_signal_propose()`, `si_signal_outcomes_update()` (db/013); the `signals_after` trigger and
`si_notify()` (db/014); `si_analyst_proposals()` (db/018); `mcp_signal_rules`, `mcp_signals`, `mcp_signal_outcomes` (db/016).

## Divergences from the design
1. **A disposition is a person's** (`signal_acknowledge`, `signal_dismiss`, `signal_escalate` are `people`); an agent's door is `signal_propose`. 2. **`signal_delete`**
(a manager's, pauses for an agent) exists for a signal raised on data since corrected — the design's "nothing is deleted" is kept by logging the reason. 3. **The 06:45
run** precedes the morning note (07:00).

## Who may do what
signal_acknowledge / signal_dismiss / signal_escalate: `signals.work` (`people`) · signal_propose / signal_note / rules_run: `signals.work` (`signal_note` `notes.write`) ·
signal_delete: `rules.manage` (pauses, `deletion`) · rule_save / rule_override_save: `rules.manage` (pause, `other`) · every screen: `see`.

## Screens
`signal-list`, `signal-view`, `signal-history`, `rule-list`, `rule-edit` — `signals.md` §2, §4, §6.

## Files (exactly these)
`signals.md` §7, plus `tests/phase3/slice7/{run.sh,lib.php,rules.php,dispositions.php,proposals.php,outcomes.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_signals(PDO, int $issuer, array $filters, int $page): array` · `find_signal(PDO, int $id): ?array` (the signal, the rule, the evidence, the events of the window, the outcome) ·
`signals_history(PDO, int $issuer, array $filters, int $page): array` · `rules_catalogue(PDO, ?int $issuer): array` · `find_rule(PDO, int $id, ?int $issuer): ?array` · `outcomes_by_rule(PDO, ?int $issuer): array` ·
writes: `signal_dispose(PDO, int $id, string $status, string $reason, ?string $note, ?int $event)` · `signal_propose(PDO, int $id, string $reason, ?int $event, ?string $note)` · `signal_note(PDO, int $id, string $body, int $by)` ·
`signal_delete(PDO, int $id, string $reason)` · `rules_run(PDO, int $security, ?string $asOf): array` · `rule_save(PDO, int $rule, array $f, int $by)` · `rule_override_save(PDO, int $rule, int $issuer, array $f, int $by)`

## MCP tools this slice makes real
`find_signals` (the resolver), `get_signal`, `signals_history`, `rules_catalogue`, `find_rules` (the resolver), `signal_outcomes`, `record_episodes`.

## Manifest rows claimed
Screens (5): `signal-list`, `signal-view`, `signal-history`, `rule-list`, `rule-edit`
Actions (9): `signal_acknowledge`, `signal_dismiss`, `signal_escalate`, `signal_propose`, `signal_note`, `signal_delete`, `rules_run`, `rule_save`, `rule_override_save`

## Activity log events
`signal.raise|refresh|expire` (the worker: `{rule, severity, value, security_id}`), `signal.acknowledge|dismiss|escalate|propose|note|delete` (`{reason, event_id}`), `rules.run`
(`{raised, refreshed, expired}`), `rule.save|override` (`{params changed}`), `outcomes.update`.

## Status vocabulary
`signals.md` §2 (the class chips) and the severity colours of the shell; status chips (open, acknowledged `text-bg-info`, dismissed `text-bg-secondary`, escalated `text-bg-danger`,
expired `text-bg-light`). Ids: `signal-card-{id}`, `signal-class`, `signal-basis`, `signal-evidence`, `signal-chart`, `signal-proposal`, `signal-dispose`, `rule-row-{key}`.

## Out of scope for this slice
The proposals screen across kinds and the Analyst's dispatch turn (9); the pack (8).

## Proof (`tests/phase3/slice7/run.sh`: scratch `si_dev37`; Playwright; the registry `--check`)
`signals.md` §8, plus: the worker's rules pass after a fixture upload raises the SPR rules; the 06:45 run on a market day and not on a holiday; 5 screens built, 9 actions, 4 approvals in step.

## Open questions
None.

## Built (2026-10-11) — what the build found and decided within this spec
1. **The worker's rules pass needs a memory of its runs**: db/024 adds `rule_runs` (one row a security: the last run, its day and counts, `daily_on` —
   the market day whose 06:45 run covered it) and `si_rules_due(limit)` — the securities whose inputs changed since their last run (an upload finished,
   tombstoned or overridden, short interest, volume, fails, a threshold listing or a list file read, prices, filings, 13D/G, 13F, Forms 4 and 144, borrow,
   events, share counts, a dilution instrument, a reconciliation). The pass runs those as of today in market time, and from 06:45 on a market day every
   security not yet run that day (a changed security's run after 06:45 IS its run of the day — no double refresh), then `si_signal_outcomes_update()`
   (`outcomes.update`). A holiday, a weekend and the hours before 06:45 have no daily run. `rules_run()` (`app/features/signals/write.php`) is the one
   runner for the worker and the action: `si_rules_run()`, then a `signal.raise|refresh|expire` row a signal (`{rule, severity, value, security_id}`),
   the `rule_runs` row, and the members who asked told.
2. **Who is told**: a high signal — the escalation member and the Analyst's dispatch (db/014's trigger, unchanged); a new signal — every member whose
   preferences carry the kind `signal` at or below its severity (`signals_min_severity`, `issuer_ids`), through `si_notify()`, so never a member who may
   not see the issuer (proven both ways); an escalation — every IR Manager (role `manager`) and the escalation member, kind `signal_high`, the note as its
   body, never the one escalating.
3. **The manifest's `signal_delete` lacked its reason** (the write's signature and signals.md §4 log one): the row gained `**reason**`, the registry rebuilt.
   The approvals in step are **three**, not four: `signal_delete` (deletion), `rule_save` and `rule_override_save` (other).
4. **"Not this"** is no action of its own (the manifest has none): it opens the disposition form (`/signals/{id}#signal-dispose`); the proposal stays on the
   signal and the history calls it **not taken** when the person disposed otherwise (`proposal_fate()`: taken, not taken, waiting, lapsed with the signal).
   The disposition's log row records the fate. An escalated signal is still a person's to acknowledge or dismiss; only an open one is escalated.
5. **The explaining event** is one of the issuer's events from two weeks before the window to a week after it (or after the day raised) — the picker's
   `event` source with `signal=` offers exactly these and the handlers refuse any other. The pickers gained `signal`, `rule` and `event`.
6. **The status filter's default is `live`** — open and escalated, what still needs a person; the history defaults to every status of the last 90 days.
   A rule's thresholds are its seeded keys only, each a number ≥ 0 (`params[key]` from the form, or a JSON object from an agent); an override emptied of
   thresholds and switch is removed.
7. **The proofs' fixtures** (`tests/phase3/slice7/seed.sql`, the Phase 0 fixtures and the anonymised register) raise all nineteen rules as of 2024-04-05:
   seventeen on the SMOKE security (nine February one-day SPR files with the top five halved give `concentration_shift` its twenty position dates;
   Schwab's NOBO broker rows halved between record dates give `nobo_drain`) and `spr_untied` and `price_volume_anomaly` on a second issuer. `spr_weekly.xlsx`
   is not a DTC layout the detector reads; the fixture fortnight is uploaded as DTC's two weekly CSVs (`fortnight_csvs()`). The dilution instrument's page
   now lists the signals that name it.
8. **Tests**: `tests/phase3/slice7/run.sh` — rules 46, dispositions 32, proposals 19, outcomes 12, json 14, browser 79 — under php -S and Apache. Phase 2's
   placeholder checks move to `/events` (slice 8's).
