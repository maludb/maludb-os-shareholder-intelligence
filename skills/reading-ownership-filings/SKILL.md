---
name: reading-ownership-filings
description: How to read the ownership forms as the application keeps them — 13F (quarterly, the $100M-plus managers, 45 days late; the data set's fields; puts and calls apart), Schedule 13D (an active 5% holder, five business days, item 4 the purpose) versus 13G (passive; QII), Forms 3/4/5 and the transaction codes, Form 144, and what an ATM, an equity line, a floating-price convertible and a warrant look like in a filing.
---

# Reading the ownership filings

## 13F — the institutions, quarterly, late
- Managers with ≥$100M of 13(f) securities file within 45 days of each quarter's end. The application loads the SEC's quarterly
  **data set** (`INFOTABLE`: `shares` = SSHPRNAMT, `value_dollars` since 2023, `put_call`, `discretion`, `voting_sole/shared/none`).
  `institutional_holders(security, period)` and `13f_changes(security, period)` (new, out, up, down against the prior quarter);
  `13f_put_call` lists reported puts and calls apart — a PUT row is not a holding.
- A top-20 holder closing or halving (`top_holder_exit`) is information from the public record, 45–90 days old by the time you
  read it. Say the period end and the filing date.

## 13D and 13G — the 5% holders
- **13D**: an owner above 5% with a **purpose** (item 4): engage the board, seek seats, a transaction. Filed within **five
  business days** (since 2024), XML, amendments on a material change. **13G**: passive or a qualified institution; amendments after
  a ±5% move or at the quarter/year.
- `beneficial_owners(security)` gives the current filers with percent, shares, the event date and the purpose text; a **13G
  converting to 13D** is the activist's first move (`new_13d` is high). A new filer above 5% (`holder_5pct`) is information.

## Forms 3, 4, 5 — the insiders
- Form 4 within two business days of a transaction; the **code** says what: **P** open-market purchase, **S** sale, **A** grant,
  **M** option exercise, **F** shares withheld for tax, **G** gift, **D** disposition to the issuer, **C** conversion.
  `insider_transactions` keeps the XML's fields (shares, price, acquired/disposed, owned after, direct/indirect, footnotes,
  10b5-1). An insider matched to the register shows on `insider_holdings`.
- **Form 144**: notice of an intended sale of restricted or control stock (seller, shares, approximate date, broker). The rule
  `insider_sales` counts sales and 144s over 30 days.

## The dilution terms the watch looks for
- **ATM** (at-the-market): an S-3 shelf and a 424B5 prospectus supplement; the company sells into the market at its discretion.
- **Equity line / ELOC** (equity purchase agreement, 8-K item 1.01 and 3.02): the investor buys at a **discount to recent VWAP**
  — often floating; the `dilution_instruments.floating_price` flag; the literature's "death spiral" is the floating-price
  convertible (−34% over a year, Hillion & Vermaelen).
- **Convertible notes / preferred** with a variable conversion price; **warrants** with a reset; **registered directs** and
  **PIPEs** (8-K 3.02). The watch proposes an instrument from the term it matched; **a person confirms** the kind, the
  counterparty, the facility and the price terms. `dilution_overlap` reads an active floating-price instrument beside an open
  short-side signal.

## The honest words
"{filer} reported {pct}% on Schedule {13D|13G} filed {date} (event {event_date}); purpose: {item 4 in one sentence}." A 13F
figure is "as of {period end}, filed {date}". Never infer an owner from the SPR; the filings name them.
