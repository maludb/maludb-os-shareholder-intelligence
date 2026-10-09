---
name: draft-the-weekly-pack
description: Runbook for the Friday pack — the standard sections (movement, flow by class, top participants, holders by range and geography, 13F changes, 13D/13G, insiders, the short side, fails and threshold, the reconciliation, the signals, the events) each in one plain paragraph with the evidence class beside every signal, no natural person's name, no recommendation; the IR Manager approves and sends. Run on the pack day or when a manager asks.
kind: runbook
---

# Draft the weekly pack

**When**: the settings' pack day (Friday by default) after the morning note, or when a manager asks. **Who**: the Surveillance
Analyst (the Expert on request). **Writes**: `report_draft` (kind `weekly_pack`, the week's window). The IR Manager approves
(`report_approve`); `report_send` is a person's and pauses for an agent. You never send.

## The sections, each one paragraph in plain words
1. **The reconciliation** — tied, or the named difference and what is being done about it.
2. **Movement** — `position_changes` over the week: the five largest moves with number, class and shares; the DTC total's change.
3. **Flow by class** — `participant_flow`: the net by side in shares and as a share of the total; the sentence "practitioner lore,
   no published validation" when you describe migration.
4. **Top participants** — `positions_on` on the last date: the top twenty with class and share of total; `concentration`.
5. **Holders** — `holders_by_range`, `holders_by_geography`, `holders_by_custodian` on the latest record date; changes since the
   previous record date (`holders_changes` counts: new, lost, up, down). **No name of a natural person.** Entities and institutions
   may be named.
6. **13F** — `13f_changes` for the latest quarter: the total held, new, out, halved; the top ten by name.
7. **13D / 13G** — `beneficial_owners`: each filer, percent, the purpose in one sentence.
8. **Insiders** — `insider_transactions` and `form144_notices` for the window: who, code, shares, price.
9. **The short side** — `short_vs_float` (date, publication, % float, days to cover), `daily_short_volume_series` (the 5-day mean,
   with FINRA's caveat), `borrow_series`.
10. **Fails and the threshold list** — `fails_series`, `threshold_status`.
11. **The signals** — `signals_open` and the week's dispositions (`signals_history`): "{rule} [{class}] — {summary} — {disposition}".
12. **The events** — `find_events` for the window and `events_upcoming` for the next two weeks.

## The rules
Every number with its date and source. The evidence class beside every signal. No recommendation to buy, sell, borrow, issue,
or say anything to the market; no forecast. Under two pages on a phone. The narrative is Markdown; the sections list in the
report's `sections`.
