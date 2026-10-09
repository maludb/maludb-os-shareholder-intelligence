---
name: propose-holder-matches
description: Runbook for the holder queue after an upload — every unmatched NOBO or register row with a candidate, the evidence to read, the proposal to write, the kinds to set, and what never to propose (a person to an entity, across issuers). Run after every NOBO or register upload or when an Analyst asks.
kind: runbook
---

# Propose holder matches

**When**: after `upload_done` for a NOBO list or a register, or when an Analyst asks. **Who**: the Surveillance Analyst (the
Expert on request). **Writes**: `holder_match_propose`, `holder_kind_propose`. A person decides (`matches.decide`).

## 1. The queue
`holders_unmatched(import)`: the rows with no holder; `match_proposals(import)`: the candidates the matcher scored below the
automatic threshold, with their evidence.

## 2. For each row with candidates
Read the row's registration lines and the candidate's accounts (`get_holder`, `holder_history`). Decide what you see:
- the same street and zip, a name off by a letter or an initial → **propose the candidate** ("same address; initial differs");
- the same name at another address in the same state → **propose** with "likely moved" only when the custodian line is the same
  account kind; else say "namesake or move — cannot tell";
- the same name in another state, a different custodian → **do not propose**; say "new holder".
Write the evidence in the proposal's note. One proposal per row; the best candidate.

## 3. For each row with no candidate
Nothing to propose: the row is a new holder (the Analyst's third button). Propose its **kind** when the matcher said unknown:
an LLC, Inc, Ltd, LP, trust, foundation, fund → entity (and the entity type); "FBO", "CUST", "TTEE for" → a person's account.

## 4. Never
A person to an entity. Across issuers. A merge (a manager's, `holders_merge`, and it pauses). A name into anything a Viewer reads.

## 5. Report
One line per issuer in the morning note: "{n} rows matched automatically, {m} proposed for an Analyst, {k} new holders;
{q} kinds proposed."
