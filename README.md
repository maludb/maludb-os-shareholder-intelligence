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

**State (2026-10-09):** planned and approved (`docs/shareholder-intelligence-design.md`, §15); K34 built in the kernel; **Phase 0 complete**
(§16): the kit, the schema db/001–016 proven against the anonymised fixtures (142 checks) and without a kernel (73), the manifest, the
agents, the skills, the deploy templates; the installer's plan clean; the owner's real files confirmed the connectors (design §0.2b, db/017; 154 checks). Next: Phase 1 for
the owner's approval. No feature PHP yet.
