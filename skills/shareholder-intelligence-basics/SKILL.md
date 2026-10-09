---
name: shareholder-intelligence-basics
description: What Shareholder Intelligence is and how an agent works in it — what an issuer, security, participant, position, holder, institution, insider, filing, record date, signal and event are; which tool answers which question; the issuer wall and the PII wall; every number with a date and a source; nothing leaves the business; a draft is never sent by an agent. The one page every agent that uses it reads first.
---

# Shareholder Intelligence — the basics for an agent

The application holds a publicly traded company's **picture of its shareholder base and of the short side of its stock**. You are a
member here: you see the issuers your member id may see, and the few actions that leave the business or change the measure pause for
a person.

## The words
- **Issuer** — a company tracked (one for a company tracking itself; one per client for an advisory firm). It may carry a
  **restriction list**: then only the listed members, managers and the admin see it.
- **Security** — one class of the issuer's shares with a **CUSIP** (the key every file and filing uses); one is primary.
- **Share count** — a dated fact from a source: outstanding (the 10-Q/10-K cover), float, authorized, Cede (what DTC holds), DRS,
  certificated, plan. `share_counts(security, kind, as_of)`.
- **Participant** — a DTC member with a four-character **number** (Goldman 0005, Schwab 0164, National Financial 0226, Robinhood
  6769). A **class** buckets it (retail, clearing-retail, prime, market maker, lending, transfer agent, bank custodian) — a setting
  a manager corrects, never a fact.
- **Position** — a participant's shares on a **position date** from the **SPR** (the DTC Security Position Report, uploaded weekly:
  five daily closes). A date with no file is **absent, not zero**.
- **Holder** — one person or entity as the **NOBO list** (the retail owners who let the issuer know them, at a **record date**) and
  the **register** (the transfer agent's record holders: Cede & Co., DRS, certificates, plans) name them. A holder has **accounts**
  (a TOD account and an IRA of one person are two accounts of one holder) and **positions** by record date and channel.
- **OBO remainder** — a participant's SPR position minus the NOBO shares attributed to it: the owners who objected to being known.
- **Institution** — a 13F filer (quarterly, the $100M-plus managers, 45 days late) or a 13D/13G filer (5% holders). **Insider** —
  a Form 3/4/5 reporting person. **Filing** — one EDGAR document (the issuer's, or another filer's about the issuer).
- **The short side** — FINRA's short interest at the 15th and month-end (published the seventh business day after), FINRA's daily
  **off-exchange** short volume (never comparable with short interest), the SEC's fails-to-deliver, the Reg SHO threshold lists, a
  borrow observation (fee, utilisation).
- **Event** — what happened on a day: a filing, a financing, a record date, earnings, a short report, a note.
- **Signal** — what a **rule** raised, with its evidence, its **evidence class** (established, contested, lore, data defect) and its
  window; one open per rule per security; a person disposes with a reason and, when it explains, an event.
- **Reconciliation** — outstanding = Cede + DRS + certificated + plan; Cede = the SPR total. Untied is a data defect first.

## Which tool answers what
| You need | Tool |
|---|---|
| The issuers I may see; one issuer; its securities; the share counts | `find_issuers`, `get_issuer`, `find_securities`, `share_counts` |
| Does it tie? | `reconciliation_latest`, `reconciliation_history` |
| Who held at DTC on a date; how they moved; the flow by class; new and lost; concentration | `positions_on`, `position_changes`, `participant_flow`, `participants_new_lost`, `concentration` |
| One participant | `get_participant`, `participant_series`, `participant_nobo_summary` |
| The holders; one holder; between record dates; by range, geography, custodian, type; unmatched | `find_holders`, `get_holder`, `holder_history`, `holders_changes`, `holders_by_range`, `holders_by_geography`, `holders_by_custodian`, `holders_by_type`, `holders_unmatched`, `match_proposals` |
| The OBO remainder | `obo_remainder` |
| The 13F holders and their changes; the 5% filers; insiders; Form 144 | `institutional_holders`, `13f_changes`, `13f_put_call`, `beneficial_owners`, `insider_transactions`, `form144_notices`, `get_institution` |
| The filings; a filing's text; a search in the text; the dilution instruments; the watch | `find_filings`, `get_filing`, `filing_read`, `filings_search`, `dilution_instruments`, `watch_hits` |
| The short side | `short_vs_float`, `short_interest_series`, `daily_short_volume_series`, `fails_series`, `threshold_status`, `borrow_series`, `short_side_overlay` |
| The signals and the rules | `signals_open`, `get_signal`, `signals_history`, `rules_catalogue` |
| The events and what is coming | `events_on`, `find_events`, `events_upcoming` |
| The packs | `find_reports`, `get_report` |
| Anything by name | `search` (a Viewer's search never finds a natural person) |

## The rules you keep
- **Every number with its date and its source.** A revision is a new row. Nothing interpolates.
- **The PII wall**: a natural person's name, address and registration lines are an Analyst's; a Viewer sees "Individual holder"
  and the aggregates. You never put a person's name into a pack, a note on another record, or a message to a Viewer.
- **Nothing leaves the business** (Reg FD): no email to a holder or an institution, no post, no filing, no press. A pack goes to
  members and its send pauses for a person.
- **Signals advise.** Say the evidence class every time. Never add signals into a score; never present lore as a finding.
- **You propose; a person decides**: a holder match, a holder's kind, a signal's disposition, an instrument from a watch hit.
