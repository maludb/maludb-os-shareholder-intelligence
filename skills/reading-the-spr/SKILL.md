---
name: reading-the-spr
description: How to read the DTC Security Position Report as the application keeps it — one row per participant per position date, the daily change and the Friday-to-Monday bridge, the retail / clearing-retail / prime / market-maker / lending / transfer-agent / bank-custodian classes and why the migration between them is practitioner lore, the tie to Cede & Co., and what a movement can and cannot mean.
---

# Reading the SPR

The **Security Position Report** is DTC's list of every participant's position in a CUSIP on a date. The issuer (or its agent)
subscribes and uploads the weekly file; the application unpivots the five daily columns into **one row per participant per date**.

## The grain and the gaps
- `positions_on(security, date)` ranks the participants on a date. `position_changes(security, from, to)` gives open, close, net,
  the largest one-day move and the days present. `participant_series` is one participant's line.
- A participant **absent** on a date had no row in the file that day — the chart breaks the line; it is not zero. A participant at
  **zero** in the file is zero. A date with no file is absent for everyone.
- The **Friday-to-Monday change** bridges weeks; the first day of a file compares against the last day of the previous one.
- `spr_total(security, date)` is what the file implies Cede & Co. holds. It must equal Cede's line on the register
  (`reconciliation_latest`); a difference is a data defect before it is a movement.

## The classes (a setting, not a fact)
Every participant carries a class from the DTC directory's words: **retail** (Schwab 0164, National Financial 0226, Robinhood
6769, Apex 0158, Vanguard 0062, IBKR retail 0534), **clearing_retail** (Pershing 0443, Wedbush 0103, StoneX 0750, Wells Clearing
0141), **prime** (Goldman 0005, Morgan Stanley 0050, BofA Securities 0161 and 0551, J.P. Morgan 0187, UBS 0221, Barclays 0229,
Clear Street 9132), **market_maker** (Citadel 0395 and 2963, Virtu 0063 and 0295, Jane Street), **lending** (the "/LENDING",
"/STOCK LOAN", "/SAFEKEEPING" accounts — Robinhood 3691, BofA 5198), **transfer_agent** (Computershare 2415, 2586, 2330),
**bank_custodian** (BNY, State Street, Northern Trust). A manager may correct any of them.

## What a movement can mean — and the honest words
- `participant_flow(security, from, to)` nets the classes. Shares leaving the retail boxes and arriving in prime, lending and
  market-maker boxes is what the surveillance firms call **migration toward lending supply**. It is **practitioner lore**: no
  published study validates it as a predictor. Say "lore" when you say it; the signal `prime_migration` does.
- A participant's jump of a percent of the total in a day (`participant_jump`) is an observation: a block trade, a transfer between
  brokers, a custodian change. Ask what happened that day (`events_on`) before reading anything into it.
- A new participant with a large position (`participants_new_lost`) may be an institution's custodian, a fund's prime broker, or
  a retail broker that changed clearing firm. The class says which side; the 13F and 13D/G say who, with a lag.
- `concentration(security, date)`: the top-5, top-10 and top-20 shares of the DTC total and a Herfindahl index. A shift of five
  points over twenty position days (`concentration_shift`) is lore too.
- A retail box shrinking while its NOBO count holds can be shares lent out (they leave the box to the borrower's prime) — the
  `nobo_drain` rule's reading, lore again.

## The one sentence
"On {date} {participant} ({number}, {class}) held {shares} ({pct}% of the DTC total), {net} since {from}; the largest day was
{move} on {move_date}. The file ties to Cede: {tied}."
