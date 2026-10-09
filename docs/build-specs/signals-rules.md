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
