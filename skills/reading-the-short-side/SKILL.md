---
name: reading-the-short-side
description: How to read the short side as the application keeps it — FINRA's short interest at the 15th and month-end (published the seventh business day after; revisions as rows) as a share of float and of outstanding with days to cover; FINRA's daily OFF-EXCHANGE short-volume ratio and its caveat; the SEC's fails-to-deliver and the Reg SHO threshold list and Rule 204; borrow fee and utilisation; what Form SHO and SLATE will add in 2028; which of these is evidence and which is contested.
---

# Reading the short side

## Short interest (established)
- FINRA Rule 4560: every broker reports its short positions at the **15th and the last day** of the month (the prior settlement
  day when those are not one); FINRA **publishes the seventh business day after**. `short_interest_series` gives each settlement
  date's **latest observation** (a revision is a new row with `revision_flag`); `short_vs_float` the latest as a share of the
  **float** and of **outstanding**, with **days to cover** (short ÷ average daily volume).
- Short interest over float and days to cover **predict negative returns** in the literature (Hong et al.; the shorting premium,
  Drechsler). The rules: `si_over_float` (10% / 20% of float; 5 / 10 days to cover), `si_change` (+25% settlement over settlement
  on flat volume).
- Say the settlement date and the publication date. Never compare it with daily short volume.

## Daily short volume (contested — say the caveat every time)
- FINRA's daily file (`CNMS` for the consolidated NMS, `FORF` for OTC) is **off-exchange** volume only; it counts market makers'
  short sales (which they hedge within minutes), omits the offsetting buys, and is **not comparable with short interest**. FINRA
  and OTC Markets both say so. `daily_short_volume_series` carries the caveat as a column; the `short_volume_ratio` rule is never
  high alone and puts the caveat in its sentence.
- A ratio of 60% is ordinary for many small caps. A **rising** 20-day trend is what the rule watches, as a question.

## Fails-to-deliver and the threshold list (contested)
- The SEC publishes every non-zero fail by settlement date twice a month (`fails_series`); the **threshold list** names securities
  with ≥10,000 shares failed and ≥0.5% of outstanding for five settlement days (`threshold_status`: on it, since when, how many
  consecutive list days).
- Fails are a **Rule 204** compliance fact (short-sale fails must close by the open of the next settlement day, long sales in three
  more, deemed-to-own in 35); a spike or a listing is a flag worth a question, **not proof of manipulation** — Fotak, Raman & Yadav
  (JFE 2014) found FTD spikes followed, not preceded, the 2008 failures. The rules `ftd_spike` and `threshold_list` are marked
  contested.

## Borrow fee and utilisation (established, when you have it)
- A vendor's feed is Extended; until then an Analyst types the desk's **indicative** quote (`borrow_observations`, `borrow_series`).
  A fee ≥20% or utilisation ≥80% is the `borrow_fee` rule's watch. Say "indicative" when the source is a quote.

## What is coming
- **Form SHO** (Rule 13f-2): monthly institutional gross short positions, published by the SEC per security in aggregate — exempt
  until 2028-01-02; the table `form_sho_aggregates` waits. **SLATE** (Rule 10c-1a): securities-lending transactions reported from
  2028-09-28, public from 2029 — `securities_lending_events` waits.

## The mosaic, honestly
Short interest, days to cover, the borrow, fails, the threshold list and a floating-price instrument **moving together** is the
practitioner's standard picture (`dilution_overlap` reads the overlap). It is a picture, not a verdict: say which parts are
established and which are contested, and ask what the events of the window say.
