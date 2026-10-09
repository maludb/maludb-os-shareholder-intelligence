---
name: morning-note
description: Runbook for the Surveillance Analyst's market-day duty — one note per issuer at 07:00 (the exchange's clock) with the reconciliation's state, the SPR's movements since the last note, the overnight public record, the short side with FINRA's caveat, the open signals with their evidence class and your proposed dispositions, and the week ahead; delivered to each person's assistant, else in-app and by email. Run every market day or when a person asks.
kind: runbook
---

# The morning note

**When**: every market day at 07:00 in the exchange's time zone (the holidays from the settings), or when an Analyst or manager
asks. **Who runs it**: the Surveillance Analyst. **Writes**: notifications and kernel messages to members; proposals on signals and
matches. Nothing to anyone outside the business.

## 1. The issuers
`find_issuers` — one note per issuer you may see. A restricted issuer's note goes only to its listed members and managers.

## 2. The reconciliation first
`reconciliation_latest(security)` for the primary security: "Tied as of {as_of}" or the named difference ("Cede 1,581,000; the
SPR of {date} 1,557,278: −23,722 — a data defect until explained"). An untied state is the first line, always.

## 3. The SPR since the last note
`position_changes(security, since, latest)`: the five largest movers with number, class, net and the largest day;
`participant_flow`: the net by class ("retail boxes −47,000; prime +31,000; lending +16,000 — practitioner lore, no published
validation"); `participants_new_lost`; `concentration` (the top-5 share and its change). Say the position dates.

## 4. The overnight public record
`find_filings(since yesterday)` by form; `beneficial_owners` for a new 13D/13G (percent, purpose in one sentence);
`13f_changes` when a quarter landed (new, out, halved); `insider_transactions` by code; `form144_notices`; `watch_hits` with the
term and the filing — "waits for confirmation". Each with the filing date and the URL.

## 5. The short side
`short_vs_float` when published ("at {settlement}, published {date}: {shares}, {pct}% of float, {dtc} days to cover");
`daily_short_volume_series` ("yesterday's off-exchange ratio {x}%, 5-day mean {y}% — off-exchange only, not comparable with short
interest"); `fails_series` (new fails); `threshold_status` ("on the Nasdaq list since {date}, {n} days"); `borrow_series`
("indicative {fee}%, utilisation {u}%").

## 6. The open signals and your proposals
`signals_open` by severity: "{severity} — {rule name} [{evidence class}]: {summary}". For each new one, `signal_propose` a
disposition with one sentence of why (acknowledge; dismiss as explained by an event you name from `events_on`; escalate), and say
in the note what you proposed.

## 7. The week ahead
`events_upcoming(30)`: record dates, earnings, expiries; the next short-interest publication day; the next 13F deadline.

## 8. Deliver
`message_send` to the person's assistant when they have one; else `notification` (kind `morning_note`, in-app and email per their
preferences). A Viewer's note carries no natural person's name. Keep the whole note under a screen on a phone: lead with what
changed; leave out what did not.
