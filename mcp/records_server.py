"""shareholder_intelligence_records_mcp — Shareholder Intelligence's record-memory server (PostgreSQL, the mcp_* views and the si_* read functions, read-only).

Tool surface: docs/shareholder-intelligence-mcp-tool-surface.md. Every tool reads as the asking member, so a person or an agent sees what the screens show them: the views and the functions decide the rows, THE ISSUER WALL
(si_my_issuers(), once per statement) and THE PII WALL (si_sees_holder_pii() — a Viewer never sees a natural person's name, address or registration lines); the server only resolves names through the same views, trims,
and chooses the error. The SECURITY DEFINER read functions have no wall of their own, so a tool proves the security visible through mcp_securities before it hands one over. An agent sees only the tools the kernel granted
it (server_common). The kernel's own token reaches `app_roles` and the five K7 shares (filings_index, filing_text, ownership_summary, signals_open, events_index — the kernel's alone). Writes are never here: they are the
kernel's Actions MCP, built from mcp/action_registry.json. The one write this server makes is the trail of a share read and of `records_search` (db/028 si_log_mcp_read).
"""
from __future__ import annotations

import time

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
import server_common
from si_common import RO, Refusal, _Base, answers, dumps, listing, log_read

mcp = FastMCP("shareholder_intelligence_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="app_roles", annotations={"title": "Shareholder Intelligence's roles and rights", **RO})
async def app_roles() -> str:
    """The roles Shareholder Intelligence offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1): Viewer, Analyst (user), IR Manager and SI admin, with
    their rights. The catalogue is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM si_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "application": "shareholder_intelligence",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


# The views records_search may read: every mcp_* view the records role holds SELECT on, but the ones that are another server's, an administrator's credential or token list, or a whole text.
SEARCH_EXCLUDE = {"mcp_activity_log", "mcp_all_tokens", "mcp_source_credentials", "mcp_filing_text"}
SEARCH_CELL = 2000


class SearchViewIn(_Base):
    view: str = Field(..., min_length=5, max_length=60, description="The mcp_* view to read (mcp_holders, mcp_signals, mcp_filings, mcp_participant_positions … — the walled views the tools read)")
    filters: dict[str, str | int | float | bool | None] | None = Field(None, description="Up to five equality filters: {column: value}; every column must be one of the view's")
    columns: list[str] | None = Field(None, max_length=30, description="Which columns to return; omitted: all of the view's")
    order_by: str | None = Field(None, max_length=60, description="One column to order by; prefix with - for descending")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most (100 is the cap)")


@mcp.tool(name="records_search", annotations={"title": "Guarded read of the views", **RO})
@answers
async def records_search(params: SearchViewIn) -> str:
    """The long tail: read one of the walled mcp_* views the named tools read, with up to five equality filters, as the asking member — no joins, no functions, no SQL; 5 seconds, at most 100 rows, a cell cut at 2,000
    characters. Both walls are the view's: an issuer you may not see and a natural person's details (unless you are an Analyst) do not come back. The readable views are the mcp_* ones: mcp_issuers, mcp_securities,
    mcp_share_counts, mcp_participants, mcp_participant_positions, mcp_imports, mcp_nobo_jobs, mcp_holders, mcp_holder_accounts, mcp_holder_positions, mcp_share_reconciliations, mcp_filings, mcp_events,
    mcp_dilution_instruments, mcp_watch_hits, mcp_institutions, mcp_institutional_holdings, mcp_beneficial_ownership, mcp_insider_transactions, mcp_form144_notices, mcp_short_interest, mcp_daily_short_volume,
    mcp_fails_to_deliver, mcp_threshold_listings, mcp_borrow_observations, mcp_prices, mcp_signal_rules, mcp_signals, mcp_signal_outcomes, mcp_reports, mcp_notes, mcp_tags, mcp_notifications, mcp_members,
    mcp_departments, mcp_settings, mcp_sources, mcp_source_pulls … Use a named tool when one answers the question."""
    p = params
    t0 = time.perf_counter()
    pool = await _pool()
    view = p.view.strip().lower()
    allowed = await db.fetch_scoped(pool, """SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'v' AND c.relname LIKE 'mcp\\_%'
                                              AND has_table_privilege(current_user, c.oid, 'SELECT')""")
    names = {r["relname"] for r in allowed} - SEARCH_EXCLUDE
    if view not in names:
        raise Refusal("invalid_argument", "That is not a view this tool reads (mcp_activity_log is the activity server's; credentials and whole texts have their own tools).", fields=["view"])
    cols = await db.fetch_scoped(pool, "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1 ORDER BY ordinal_position", view)
    have = [r["column_name"] for r in cols]
    flt = p.filters or {}
    if len(flt) > 5:
        raise Refusal("invalid_argument", "At most five filters.", fields=["filters"])
    bad = [c for c in list(flt) + list(p.columns or []) if c not in have]
    order = (p.order_by or "").strip()
    desc = order.startswith("-")
    if order.lstrip("-") and order.lstrip("-") not in have:
        bad.append(order.lstrip("-"))
    if bad:
        raise Refusal("invalid_argument", f"{view} has no column {bad[0]!r}; its columns are {', '.join(have)}.", fields=["filters", "columns", "order_by"])
    select = ", ".join(f'"{c}"' for c in (p.columns or have))
    where, args = [], []
    for c, v in flt.items():
        if v is None:
            where.append(f'"{c}" IS NULL')
        else:
            args.append(str(v).lower() if isinstance(v, bool) else str(v))
            where.append(f'"{c}"::text = ${len(args)}')
    sql = f'SELECT {select} FROM {view}' + (" WHERE " + " AND ".join(where) if where else "") + (f' ORDER BY "{order.lstrip("-")}" {"DESC" if desc else "ASC"}' if order.lstrip("-") else "") + f" LIMIT {p.limit + 1}"
    rows = await db.fetch_scoped(pool, sql, *args, timeout_ms=5000)
    cut = False
    for r in rows:
        for k, v in r.items():
            if isinstance(v, str) and len(v) > SEARCH_CELL:
                r[k] = v[:SEARCH_CELL] + "…"
                cut = True
    try:
        run = server_common.db.verify_run_token(db.request_token.get())
        await log_read(q, "records.search", {"view": view, "filters": sorted(flt), "rows": min(len(rows), p.limit), "ms": int(round((time.perf_counter() - t0) * 1000))}, None, None, run[1] if run else None)
    except Exception:  # noqa: BLE001 — the trail never costs the caller their answer
        pass
    return listing(rows, p.limit, view=view, cells_cut=cut)


import si_admin  # noqa: E402
import si_events  # noqa: E402
import si_filings  # noqa: E402
import si_holders  # noqa: E402
import si_issuers  # noqa: E402
import si_ownership  # noqa: E402
import si_positions  # noqa: E402
import si_shares  # noqa: E402
import si_shell  # noqa: E402
import si_short  # noqa: E402
import si_signals  # noqa: E402

for module in (si_shell, si_issuers, si_positions, si_holders, si_ownership, si_filings, si_short, si_signals, si_events, si_admin, si_shares):
    module.register(mcp, q)


if __name__ == "__main__":
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
