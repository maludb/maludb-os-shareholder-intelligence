# Shareholder Intelligence — CLAUDE.md

This repository is the **Shareholder Intelligence** application of the MaluDB Business OS
(`github.com/maludb/maludb-os-shareholder-intelligence`, private until the owner says otherwise; local clone and install path
`/srv/apps/shareholder_intelligence`; catalog key `shareholder_intelligence`; DNS label `shareholders`). The kernel is `/var/www`
(`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory, the agents, approvals and the token
ledger; this application owns a publicly traded company's **picture of its shareholder base and of the short side of its
stock**: the DTC Security Position Report, the NOBO list and the transfer agent's register (uploaded), EDGAR, FINRA, the SEC's
fails and the threshold lists (fetched), holders matched across lists, the reconciliation against the share count, prices and
events, and the **signals** rules raise for a person to judge (the owner's brief, 2026-10-09).

## Read first
1. `docs/shareholder-intelligence-design.md` — the plan (Phase 0): the research (§0: the field and the gap, the owner's reference
   implementation and its three file formats, the data an issuer can obtain with cost and cadence, what is evidence and what is
   lore, the regulatory clock, the estate's canonical tables), what it is and is not (§1), the actors (§2), **roles and the two
   walls** (§3 — the issuer's restriction list, the PII wall on natural persons), the doors (§4), the agents and what pauses (§5),
   the memory model (§6 — issuers, participants, uploads and their raw rows, positions, holders and the matcher, the
   reconciliation, the public record's tables with the SEC's and FINRA's field names, the nineteen rules and signals, the
   shared-schema decisions), the question inventory with every tool named (§7), the kernel's part, the API and K7 (§8), the
   screens (§9), the build order (§10), Extended (§11), what the kernel, MaluDB and the siblings owe (§12), the decisions D1–D16
   and the open questions (§13), ports and files (§14), the owner's answers (§15), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references, `shared-schema.md`
   included) and `htmx-php-builder` 0.9.0 (`~/htmx-php-builder`: `os-application`, `php-patterns`, `design-system` with the record
   picker, `mcp-servers`, `chat-actions`).
3. `/srv/apps/inventory` (sources, pulls, credentials, the crawl policy, connectors against fixtures), `/srv/apps/knowledge`
   (a file cut into proven rows), `/srv/apps/gl` (the reconciliation's shape) — the nearest built exemplars; `/srv/apps/sales_crm`
   and `/srv/apps/doccloud` for the kit.
4. The reference implementation `github.com/KineticGuides/KineticIntel2` — read only for the three file formats (`NOBOLIST.xlsx`,
   `STCCommon.xlsx`, the weekly DTC grid in `ksa_dtc_upload`); nothing of its code or UI is reused.

## Rules that bind here
- `htmx-php-builder` skills govern the PHP; `os-integration` governs the fit. Where the two conflict, the integration contract
  wins: **no password, no login form, no account, no model key, no Twilio key, no shared tables, no actions server of our own, no
  writes to the directory, no writes to another application** (K7 is reads only).
- **Nothing leaves the business.** No email to a holder or an institution, no post, no filing, no public page. A pack goes to
  members; an agent's send pauses. Reg FD is a wall, not a feature.
- **The three files are uploaded, never fetched.** No automation against DTC's SPR web, Broadridge's portal or a transfer
  agent's site. The public hosts (EDGAR, FINRA, the SEC's data pages, the exchanges' threshold pages, DTCC's directory, a price
  host) are fetched through `sources` under the policy: the declared user-agent from settings, the rate the host asks for (EDGAR
  2 a second by default, 10 at most), `If-Modified-Since`, a 403/429 backs off and tells the admin, robots honoured; nothing
  scraped that was not published for machines; no vendor page ever.
- **The PII wall is SQL's**: a natural person's name, address and registration lines are the Analyst's and above
  (`si_sees_holder_pii()`); a Viewer sees aggregates and entities; exports of holders are logged with their row count; the K7
  shares and the API's Viewer reads carry no person. A holder's lines, a filing's text and a credential are never in a log payload.
- **Every number has a date and a source**; a missing day is absent, not zero; a revision is a new row; nothing interpolates.
- **Signals advise.** A rule is a SQL function with an evidence class (established / contested / lore / data defect) printed on
  every signal; one open signal per rule per security; a person disposes with a reason; no composite score, no model deciding.
- **A parse is one transaction** with the file's control totals tied; a wrong upload is tombstoned and re-uploaded, never edited.
- **Vocabulary: "agent" means an OS AI agent**, always. An *issuer* is a company tracked; a *security* one class (a CUSIP); a
  *participant* a DTC member with a number; a *position* a participant's shares on a date; a *holder* one registration on a
  list; an *institution* a 13F/13D/13G filer by CIK; an *insider* a Form 3/4/5 reporting person; a *signal* what a rule raised;
  an *event* something that happened on a day.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + the four build
  specs + slice specs together (Phase 1). Schema changes after that are numbered additive migrations. **Never modify a migration.**
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`, SQL
  `si_has_right()`) + the issuer wall (`si_can_see_issuer()`) + `log_activity()` + `emit_action_status()`; a create's `location`
  ends in the record id.
- The kernel's rules copied here: activity `source` is `web` for the UI, `api` for a key, `agent` under a run token, `cron` for
  the worker; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see; the shared-schema rule — survey the
  siblings before any `CREATE TABLE`, reuse verbatim, extend by appending, record every table as read / reuse / new (design §6.8).
- Commit after every finished step on `main` in the repository's message style; never push unless the owner asks.

## State
2026-10-09 — planned and **APPROVED** (design §15), K34 built in the kernel (db/178), **PHASE 0 COMPLETE** the same day (design §16): the kit
(73 checks without a kernel), the schema db/001–016 and its proof against the anonymised fixtures (142 checks), `maludb-os.json`, the two
agents, ten skills, `deploy/`; the installer's `plan` clean. Proofs: `bash tests/phase0/run.sh` (both). The fixtures:
`mcp/venv/bin/python -I tests/fixtures/build_fixtures.py <KineticIntel2 clone>`. The owner's real SPR week is awaited (the weekly-grid
connector is confirmed on it). **Next: Phase 1** (the tool surface, the action manifest, the four build specs, the slice specs) for the
owner's approval together; no feature PHP before that.
