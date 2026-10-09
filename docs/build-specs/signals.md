# Build spec: signals — the rules, the evidence, the disposition (design §0.1, §6.6; D11) — read before slice 7

A signal is what a rule raised: a value against a threshold in a window, with the rows it saw, a sentence a template wrote, and the
**evidence class** of the rule — established, contested, lore or data defect — printed beside it everywhere it appears. A person disposes of
it with a reason; an agent may only propose. Nothing is sent, scored, traded or published. **The database is the referee** (db/013, 018:
nineteen `si_rule_*` functions, `si_rules_run`, one open signal per rule per security, expiry, `si_signal_dispose`, `si_signal_propose`,
`si_signal_outcomes_update`); PHP renders the cards, the evidence and the forms; the worker runs the rules after every pull and upload.

## 1. A rule is three things, always together
1. **A function** `si_rule_<key>(security bigint, as_of date) RETURNS si_rule_result` — NULL when silent; else `(severity, value,
   threshold, baseline, window_from, window_to, evidence jsonb, summary text)`. Read-only (STABLE); its parameters from
   `si_rule_params(key, issuer)` (the rule's params with the issuer's override merged).
2. **A seed row** in `signal_rules` (key, name, description, evidence_class, **basis** — the sentence of design §0.1 it rests on,
   severity_default, params, window_days, sort_order). The runner calls the rules in `sort_order`: the short-side rules come before
   `dilution_overlap`, which reads their open signals.
3. **A proof case** in the schema proof: the rule fires on a crafted window and stays silent on a quiet one.
**Adding a rule** is a numbered migration with all three and a line in this table; the key matches `^[a-z][a-z0-9_]{0,39}$` (a key may not
begin with a digit — `13f_exit` became `top_holder_exit`). **Never a composite score; never a model.**

## 2. The nineteen (v1) and how each signal page renders its evidence
| Key | Class | The evidence table the page shows | The chart |
|---|---|---|---|
| `si_over_float` | established | settlement date, short, float, % float, days to cover, average volume | short % float and days to cover, 6 months |
| `si_change` | established | the two settlements, short from → to, the volume change | short interest bars, the two highlighted |
| `borrow_fee` | established | the observation: date, source ("indicative" for a quote), fee, utilisation | fee and utilisation points |
| `ftd_spike` | contested | the settlement date, quantity, % outstanding, the 60-day median | fails bars with the median line |
| `threshold_list` | contested | the list, since, consecutive list days | the threshold days shaded on the price |
| `short_volume_ratio` | contested | the 5-day mean now and 20 trading days ago, **FINRA's caveat in full** | the ratio and its means |
| `prime_migration` | lore | the window, the retail side's net, the other side's net, the DTC total | the flow by class (slice 2's chart) |
| `participant_jump` | lore | the participant, number, class, the move, the date, % of total | the participant's series |
| `concentration_shift` | lore | top-5 from → to, the index from → to | the concentration lines |
| `nobo_drain` | lore | the participant, NOBO shares from → to, SPR from → to, the two record dates | the participant's series with the record dates marked |
| `spr_untied` | data defect | the reconciliation's two sides and the difference | — (the equation as rows) |
| `register_untied` | data defect | outstanding and the register's components, the difference | — |
| `new_13d` | established | the filer, percent, shares, filed and event dates, the purpose (item 4) | — (the filing linked) |
| `holder_5pct` | established | the filer, schedule, percent, filed | — |
| `top_holder_exit` | established | the institution, shares from → to, the two quarters | the institution's holdings by quarter |
| `insider_sales` | established | sales and shares in 30 days; the Form 144 if one | the insider ledger's window |
| `dilution_overlap` | established | the instrument (kind, counterparty, terms, remaining) and the overlapping signal | the price with both windows |
| `activist_short_report` | established | the event: title, date, URL | the price around the date |
| `price_volume_anomaly` | established | the closes, the drop, the volume against the median, "no event recorded" | price and volume, 30 days |
The evidence partial is one file per rule (`app/views/signals/evidence/<key>.php`) reading only `signals.evidence` and the ids in it — the
page never recomputes the rule. **The class chip**: established `text-bg-primary` "Established", contested `text-bg-warning` "Contested",
lore `text-bg-secondary` "Lore — no published validation", data defect `text-bg-danger` "Data defect". **The basis** (the rule's sentence)
sits under the summary on every signal page and in every card's tooltip.

## 3. When the rules run
`si_rules_run(security, as_of)` (the worker's `rules` pass) after: an upload ties (SPR, NOBO, register, share range); a pull that changed a
security's short interest, volume, fails, threshold, prices, filings or ownership rows; a borrow observation; an event of kind
`activist_short_report`; a share count; a dilution instrument confirmed; and once each market day at 06:45 market time for every security (so
the morning note at 07:00 reads today's signals). `rules_run` (an action) runs it on demand. A run refreshes an open signal (the window, the
value, the evidence, `repeat_count`) rather than raising a second; **one open signal per rule per security** is the database's unique index.
A signal expires at `window_to + signal_grace_days`; the worker's run expires it.

## 4. The disposition (a person's) and the proposal (an agent's)
- **Acknowledge** (`signal_acknowledge`: `known` or `watching`, a note), **Dismiss** (`signal_dismiss`: `explained_by_event` — the event is
  required and chosen with the picker over the window's events; `data_defect`; `false_positive`; `other` — a note), **Escalate**
  (`signal_escalate`: a note; the IR Manager and the escalation member are notified). All three are `people` (the handlers call
  `require_human()`): an agent cannot dispose.
- **Propose** (`signal_propose`, an agent or a person): the reason, the explaining event, one sentence — stored on the signal; the card shows
  "The Analyst proposes: dismiss — explained by the 8-K of 2024-03-15" with **Take it** (posts the matching disposition with the proposal's
  words) and **Not this**. The proposals screen (slice 9) lists them across issuers.
- A disposed signal stays readable for ever (`signals_history`); a deleted one (`signal_delete`, a manager, pauses for an agent) is for a
  signal raised on data since corrected and is logged with the reason.

## 5. Notifications and the agent
A new `high` signal (or one raised to high): the escalation member (`si_settings.escalate_high_to`) is notified (the bell, email per their
preferences, a text when they asked — K6) and the Surveillance Analyst is dispatched once (`agent_dispatches.kind = 'signal'`, the worker's
`dispatches` pass sends one chat turn: "A high signal on {security}: {summary}. Propose a disposition."). Members who asked for signals of a
severity get a notification; **`si_notify` never tells a member about an issuer they may not see**.

## 6. Outcomes (the Auditor's test of the lore)
`si_signal_outcomes_update()` daily: the close at the signal and 5, 20 and 60 trading days after. `signal_outcomes` (the tool) aggregates by
rule: n, the mean 20-day return, the share negative — **the page states the n beside every figure and calls a rule with n < 30 "too few to
say"**. Nothing here changes a rule; the Auditor's report advises.

## 7. Files (slice 7)
`html/signals/{index,view,history,acknowledge,dismiss,escalate,propose,note,delete,run}.php`, `html/rules/{index,form,save,override}.php`;
`app/features/signals/{queries,present,write}.php`, `app/features/rules/{queries,write}.php`; `app/views/signals/{index,view,history}.php` +
`partials/{card,class-chip,basis,disposition-form,proposal,outcome}.php` + `evidence/<key>.php` × 19; `app/views/rules/{index,form}.php`; the worker's
`rules` pass and the 06:45 run; `app/pickers.php` gains `signal`, `rule`, `event` (scoped to a window).

## 8. Proof (slice 7's `tests/phase3/slice7/run.sh`)
On the Phase 0 fixtures: the first run raises the crafted signals and the cards show each class chip and the basis; every evidence partial
renders its table and chart; the second run raises nothing new; a dismissal without an event for `explained_by_event` is refused; an agent's
dispose call is refused (people only) and its proposal is shown and taken in one click; an escalation notifies the manager; a rule's
threshold override for one issuer changes the next run for that issuer only; a disabled rule raises nothing; expiry; outcomes with "too few to
say"; a Viewer sees every signal and disposes of none; 375 and 1280; JSON mode; the registry `--check`.
