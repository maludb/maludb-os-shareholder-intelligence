# Phase 1 — the consistency record (2026-10-09)

What was checked before the owner's checkpoint, and how to check it again: `bash tests/phase1/check_specs.sh` (read-only; no database, no server).

## The documents, together
| Document | What it fixes |
|---|---|
| `docs/shareholder-intelligence-mcp-tool-surface.md` | the read tools: records 95 (88 for the questions, `app_roles`, `records_search`, the five kernel-only shares), activity 7; 17 resolvers; the records API's routes |
| `docs/shareholder-intelligence-action-manifest.md` | 84 screens and 83 actions in eleven sections; 28 paused for an agent (14 other, 13 deletion, 1 external_send); 23 for people only |
| `mcp/action_registry.json` | built from the manifest by `bin/build_action_registry.php` (0 parse warnings); `--check` proves it current |
| `maludb-os.json` `approvals[]` | written from the manifest by `bin/sync_approvals.php` (the installer's list form `[{action, category}]`) |
| `docs/build-specs/{position-reports,holder-identity,edgar-and-finra-fetchers,signals}.md` | the four build specs (the design's hard parts) |
| `docs/build-specs/{sso-shell,issuers-participants,spr-positions,holders,edgar-filings,ownership-forms,short-side,signals-rules,events-reports,agents-k7-api,admin-settings}.md` | the eleven slice specs (Phase 2 and slices 1–10) |
| `db/018_phase1_reads.sql` | what the surface needed that the schema lacked (below); the schema proof's db/018 section |

## The checks (`tests/phase1/check_specs.sh`: 60, all green)
1. The registry is the manifest's (`build_action_registry.php --check`).
2. `maludb-os.json`'s 28 approvals are the manifest's, with the same categories (`sync_approvals.php --check`).
3. Every slice spec has `## Manifest rows claimed` with `Screens (N)` and `Actions (N)` lines whose counts match their names, no name twice.
4. **Every one of the 84 screens and 83 actions is claimed by exactly one spec.**
5. **Every one of the 102 surface tools is named by a slice, and every tool a slice names is a row of the surface.**
6. Every approval is a registry action with the same category.

## Checked by hand and by the proofs
- **The agents' grants are the surface's names** (`maludb-os.json`, rewritten in Phase 1): the Expert 83 records tools (all but the keys, dispatches,
  templates, tombstones, the long tail, `app_roles` and the shares) and four activity tools; the Surveillance Analyst 50 records tools (the morning
  note's and the pack's reads) and three activity tools; every action either names is in the registry. The installer's `plan` reads them: 100 and 60
  tools in all (the kernel's own tools added).
- **Every resolver of `deploy/kernel-registry-shareholder_intelligence.json` names a surface tool** (17 entities).
- **db/018** proven by the schema proof (now **172 checks**): the ownership summary in one read with no person in it; the five shares by ticker or CIK,
  a restricted issuer never shared, no consumer → nothing; an agent's kind proposal landing in the Analyst's queue and decided; a Viewer seeing no
  holder proposal and the changes as counts; the tombstones to a manager only; the sources' health; a dispatch cancelled. The kit's 73 checks are
  unchanged.
- **The installer's `plan`** reads the repository clean: 51 steps, 7 notes, no warning.

## What db/018 added, and why
| Addition | The tool or action that needed it |
|---|---|
| `si_ownership_summary()` | the home's tiles, `ownership_summary` (the share), the pack's first section |
| the five `si_share_*` functions + `si_share_issuer()` | the K7 shares as the application, with the issuer wall and the PII wall in SQL |
| `holder_kind_proposals`, `si_holder_kind_propose()`, `si_holder_kind_decide()`, `mcp_holder_kind_proposals` | `holder_kind_propose` (an agent's), the proposals screen |
| `si_analyst_proposals()` | `analyst_proposals`, the proposals screen |
| `si_holders_changes_summary()` | `holders_changes` for a Viewer (counts by state, never a holder) |
| `si_source_health()` | `source_health`, the admin's sources screen |
| `si_tombstones()` | `tombstones`, the purge screen |
| `agent_dispatches.status` admits `cancelled` | `dispatch_cancel` |

## Found while writing Phase 1
- The checker's tool-name pattern did not admit a digit (`form144_notices`): widened in `tests/phase1/check_specs.php`.
- A PL/pgSQL record that is never assigned cannot be read even inside a `CASE`: `si_ownership_summary()` assigns its records unconditionally.
- `maludb-os.json` held `approvals` as a map of category → actions; the installer reads a list of `{action, category}` — the sync rewrote it.
