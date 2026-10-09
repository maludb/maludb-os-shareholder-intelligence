---
name: work-the-signals
description: Runbook for working the open signals — reading a signal's evidence and class, finding the event that explains it, proposing (an agent) or taking (a person) a disposition with a reason, when to escalate, and what a disposition never is (a trade, a statement, a send). Run when a signal is raised or when the morning note lists one.
kind: runbook
---

# Work the signals

**Who**: the Expert and the Surveillance Analyst **propose**; an Analyst or manager **disposes**. **Writes**: `signal_propose`
(an agent), `signal_note`; a person's `signal_acknowledge` / `signal_dismiss` / `signal_escalate`.

## 1. Read it whole
`get_signal`: the rule, the **evidence class**, the window, the value against the threshold and the baseline, the evidence rows.
`rules_catalogue` for the rule's basis paragraph. Say the class in your first sentence.

## 2. Look for the explanation
`events_on(window)`: a filing (an 8-K 3.02 placement settling late explains fails; a record date explains a NOBO job; a 13D
explains a participant jump at the filer's prime broker); `find_filings` and `filing_read` for the words; `position_changes` for
the participant behind a flow; `short_vs_float` and `fails_series` for the short side's shape. One explaining event is worth more
than three correlated signals.

## 3. Propose (an agent) — one of
- **acknowledge** — "seen, consistent with {what}; watching" (reason `watching` or `known`).
- **dismiss, explained by event** — name the event (`explaining_event`), one sentence.
- **dismiss, data defect** — the file, the date, what is missing (an SPR day, a stale share count).
- **escalate** — to the IR Manager when the class is established, the severity high, and no event explains it; or when counsel
  should read a 13D's item 4.
Never propose on a signal you cannot read whole; never propose a disposition that implies an action outside the application.

## 4. What a disposition is not
Not a trade, not a buyback, not a statement, not a letter to a broker, not a complaint to a regulator — those are a person's
decisions outside this application, and nothing here sends anything. A dismissed lore signal is not "refuted"; it is set aside with
its reason, and `signal_outcomes` keeps the price after for the Auditor.
