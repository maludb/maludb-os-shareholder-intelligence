---
name: holder-identity
description: How a holder is one person across the NOBO list, the register and the insider filings — the name key, the custodian and legends parsed from the registration lines, the matcher's scores (name and address exact 1.0, trigram with the address 0.95, trigram with the zip 0.8, the name elsewhere 0.6), automatic acceptance at 0.95 and the Analyst's queue below it, what to propose, what never to merge, and the PII wall.
---

# Holder identity

A holder is **one person or entity**; an account is **one registration** of theirs on a list; a position is an account's shares
at a record date. The same person's TOD account and Schwab IRA are two accounts of one holder. Two people of one name at two
addresses are two holders. A person is **never** matched or merged with an entity.

## How a registration is read
- The NOBO list's seven lines: line 1 the name; the custodian and the legends after it ("CHARLES SCHWAB & CO INC CUST", "IRA
  CONTRIBUTORY", "DESIGNATED BENE PLAN/TOD", "TTEE", "JT TEN"); the first line with a digit is the street; the city/state/zip line
  follows. The custodian becomes the account's participant (Schwab → 0164, FMT Co → National Financial 0226); the legends the
  **account kind** (ira, tod, trust, joint, custodial, corporate, individual).
- The register's columns: the registration name, the address, the position type (certificate, book, DRS, plan), the agent's holder
  id, restrictions. Cede & Co.'s line is flagged and never a holder.
- The **name key**: upper, punctuation and legends stripped. The **address key**: the street squeezed plus the five-digit zip.

## The scores (what `match_proposals` shows)
| Evidence | Score | What happens |
|---|---|---|
| name exact, address exact | 1.00 | accepted automatically, logged `auto` |
| name exact, one side has no address | 0.90 | proposed |
| name similar (trigram ≥ 0.6) and the address exact | 0.95 | accepted automatically — a one-letter typo on the same street |
| name similar (≥ 0.7) and the same zip | 0.80 | proposed |
| name exact at another address | 0.60 | proposed — a move, or a namesake |
| nothing close | — | a new holder |

A rejected pair is remembered and never proposed again. "New holder" (the third button) makes one whatever the candidates.

## What you propose (`holder_match_propose`), and how
- Read the row's registration and the candidate's (`holders_unmatched`, `match_proposals`, `get_holder`, `holder_history`). Say the
  evidence in the proposal: "same street and zip, the name differs by one letter"; "same name, a different city — likely a
  namesake"; "the custodian line names the same IRA".
- Propose the **kind** (`holder_kind_propose`) for an unknown: an LLC, a trust, a fund is an entity; "FBO <name>" is a person's
  custodial account.
- Never propose a match between a person and an entity; never propose across issuers.

## The wall
Only an Analyst and above read a natural person's name, address and lines. In anything a Viewer may read, or a pack, a person is a
range, a geography, a custodian — never a name.
