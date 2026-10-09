---
name: set-up-an-issuer
description: Runbook for setting up an issuer — the ticker to CIK lookup, the EDGAR entity, the securities with their CUSIPs, the transfer agent and its connector, the restriction list for an advisory firm's client, the share counts, the first SPR, NOBO list and register, and the first reconciliation. Run when a person asks to add a company, or guides them through it.
kind: runbook
---

# Set up an issuer

**Who**: the IR Manager creates (`issuer_save`, `security_save`, `share_count_save` pause for an agent — you guide, the person
presses); an Analyst uploads. **The order**: issuer → securities → share counts → the files → the reconciliation.

## 1. The company
- The **ticker** → the **CIK**: the kernel's fetcher reads `company_tickers.json`; the EDGAR entity (`edgar_submissions`) fills the
  name, the state, the fiscal year end, the former names. An OTC company with no ticker in the SEC's file is entered by CIK.
- The **exchange** (Nasdaq, NYSE, NYSE American, OTC, CSE, TSX) — it decides which threshold list and which holidays.
- The **transfer agent** and its **connector** (STC's position detail, Computershare's holder list, the generic mapped upload
  with a saved template).
- For an advisory firm: the **restriction list** (`restriction_save`): the firm's team on the account and the client's own
  external members as Viewers. Without a list the issuer is open to every role holder.

## 2. The securities
One row per class with its **CUSIP** (the 13(f) list confirms CUSIP → name); the primary one carries the ticker. Preferred,
warrants and units are their own rows: the SPR and the NOBO list come per CUSIP.

## 3. The share counts
`edgar_companyfacts` writes **outstanding** from every 10-Q/10-K cover (dei:EntityCommonStockSharesOutstanding) and the public
float value; the **float** in shares is a person's (outstanding − restricted and insider holdings, or the data provider's figure);
**Cede** comes from the register or DTC's confirmation letter.

## 4. The files
This week's **SPR** (`upload_create`: kind spr, the security, the file — the parser confirms the layout on the first real file),
the latest **NOBO list** (kind nobo, the record date) and the **register** (kind register, the record date). Each parse ties to
the file's own totals or names the difference; a manager overrides with a note when the difference is explained.

## 5. The first reconciliation
`reconciliation_run` → `reconciliation_latest`: outstanding against the register's components, Cede against the SPR. Tied, or a
data question to answer before any signal means anything. Then `rules_run`, and the morning note takes over.
