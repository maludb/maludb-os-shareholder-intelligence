# Shareholder Intelligence — an application of the MaluDB Business OS

Shareholder surveillance for a publicly traded company (or an advisory firm serving several) running the
[MaluDB Business OS](https://github.com/maludb/maludb-os-core): the weekly DTC Security Position Report, Broadridge's NOBO list
and the transfer agent's register uploaded, parsed against their own totals and reconciled with the share count; SEC EDGAR
(the filing index, the cover facts, full-text watch for dilution terms, 13F, 13D/13G, Forms 3/4/5, 144), FINRA short interest and
daily short-sale volume, the SEC's fails-to-deliver and the Reg SHO threshold lists fetched under a declared policy; holders
matched across lists by proposal; every series charted against the price with the issuer's events; nineteen rules that raise
signals with their evidence and its class (established, contested, lore, data defect) for a person to judge. Two agents ship
with it: the Shareholder Intelligence Expert (the command bar) and the Surveillance Analyst (the market-day morning note,
proposed dispositions and matches, the Friday pack as a draft). Nothing leaves the business: no email to holders, no post, no
filing — the application takes no position and contacts nobody.

Built with the `htmx-php-builder` plugin (`maludb-os-htmx-php-guidelines`) and fitted to the kernel by `maludb-os-integration`.
Installed by the kernel's installer at `/srv/apps/shareholder_intelligence`, served as `shareholders.<domain>`; catalog key
`shareholder_intelligence`. The reference implementation for the three file formats is the owner's
[KineticIntel2](https://github.com/KineticGuides/KineticIntel2) (Angular 18, 2025).

**State (2026-10-09):** planned and approved (`docs/shareholder-intelligence-design.md`, §15); K34 built in the kernel; Phase 0 complete; **Phase 1
approved** (the tool surface, the action manifest and its registry, four build specs, eleven slice specs); **Phase 2 the shell built** — the issuer
switcher, the home's ownership tiles with their sources, the search box, the person's screens, the record picker, seventeen placeholders
(`tests/phase2/run.sh`, under php -S and a real Apache); **slice 1 built** — issuers from a ticker read from EDGAR, securities, share counts, the restriction list, tags and notes, the DTC participant directory (`tests/phase3/slice1/run.sh`, with one live pull each); **slice 2 built** — the SPR upload and its tie, the positions, the movement matrix, the flow by class, the concentration and the reconciliation, every one of the owner's real SPR files tied (`tests/phase3/slice2/run.sh`); **slice 3 built** — NOBO lists, the register, Broadridge's Share Range Analysis, holders, the matcher and its queue, the merge, the aggregates and the PII wall, every one of the owner's real files of these kinds tied and the real reconciliation tied on both halves (`tests/phase3/slice3/run.sh`); **slice 4 built** — EDGAR's filing index, the filings' text and search, the cover share counts, the full-text watch for dilution terms and for filings by others about the company, the dilution instruments (`tests/phase3/slice4/run.sh`, with live pulls); **slice 5 built** — the SEC's 13F data sets, Schedules 13D and 13G, Forms 3/4/5 in the insider ledger linked to the register, Forms 144, institutions and the ownership screens (`tests/phase3/slice5/run.sh`, with live pulls); **slice 6 built** — FINRA short interest with its revisions, FINRA's daily off-exchange short volume with its caveat, the SEC's fails-to-deliver, the three Reg SHO threshold lists, prices (a keyed provider or an upload — stooq refuses machines), borrow observations and the short-side page with its one chart (`tests/phase3/slice6/run.sh`, with live pulls). Next: slice 7, the signals.
