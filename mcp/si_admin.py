"""Agents, keys, sources, the admin (Q41–Q44): docs/shareholder-intelligence-mcp-tool-surface.md "Agents, keys, sources, the admin". The walls are the views' and the functions' own: mcp_agent_dispatches (a holder of
agents.settings sees all, an agent its own), mcp_keys (mine, or everyone's with tokens.manage — never a hash), si_source_health(), si_tombstones() (a manager's). A credential is a label and the last four, nowhere
more; an API key's value is in no view."""
from __future__ import annotations

import datetime
from typing import Literal

from pydantic import Field

from si_common import RO, Ref, Refusal, Where, _Base, answers, describe_models, exact_first, is_int, is_manager, issuer_row, like, listing, lim, managers_only, member_ref, not_found, page, resolving

DISPATCH_KINDS = Literal["signal", "filing", "upload_done", "match", "pack", "ask", "duty_proposal"]


class ProposalsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's proposals")
    kind: Literal["signal", "holder_match", "holder_kind"] | None = Field(None, description="signal (a disposition), holder_match or holder_kind (the latter two are an Analyst's)")


class DispatchesIn(_Base):
    status: Literal["sent", "answered", "refused", "failed", "awaiting_approval", "cancelled"] | None = Field(None, description="Only dispatches in this state")
    kind: DISPATCH_KINDS | None = Field(None, description="Only this kind of dispatch")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class MembersRolesIn(_Base):
    role: str | None = Field(None, max_length=40, description="Only holders of this role key (viewer, user, manager, admin)")
    agents_only: bool = Field(False, description="true: only agents")


class FindKeysIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the key's label; alone it resolves one key")
    member: Ref | None = Field(None, description="Only this person's keys (id, name or me); everyone's need the right to manage tokens")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class KeyUsageIn(_Base):
    api_key: Ref = Field(..., description="The key (id or label)")
    days: int = Field(30, ge=1, le=365, description="How many days back")


class FindSourcesIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the name; alone it resolves one source")
    connector: str | None = Field(None, max_length=60, description="Only this connector (edgar_submissions, finra_short_interest, sec_ftd, …)")
    active: bool | None = Field(None, description="true: only active; false: only the inactive")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class SourceHealthIn(_Base):
    connector: str | None = Field(None, max_length=60, description="Only this connector")
    pulls: int = Field(0, ge=0, le=20, description="Also each source's last N pulls")


class TemplatesIn(_Base):
    kind: str | None = Field(None, max_length=40, description="Only this kind of file (spr, nobo, register, prices_csv, holders_csv, share_range)")


class TombstonesIn(_Base):
    kind: Literal["upload", "holder", "event", "report", "issuer"] | None = Field(None, description="Only this kind of record")


describe_models(globals())


async def key_ref(q, v) -> dict:
    if v in (None, ""):
        raise Refusal("invalid_argument", "Name the key (its id or its label).", fields=["api_key"])
    s = str(v).strip()
    if is_int(v):
        rows = await q("SELECT * FROM mcp_keys WHERE key_id = $1", int(s))
        if rows:
            return rows[0]
    rows = await q("SELECT * FROM mcp_keys WHERE lower(label) = lower($1) ORDER BY key_id LIMIT 2", s)
    if len(rows) > 1:
        from si_common import ambiguous
        raise ambiguous("key", s, rows, "key_id", "label")
    if rows:
        return rows[0]
    raise not_found("key")


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="analyst_proposals", annotations={"title": "What the agents proposed", **RO})
    @answers
    async def analyst_proposals(params: ProposalsIn | None = None) -> str:
        """Every open proposal an agent made, in one list (Q44): a signal's disposition, a holder match, a holder's kind — each with the title, the reason, who proposed it and when. The holder proposals name holders and are an
        Analyst's (the function leaves them out for anyone else). A person takes or declines each on the proposals screen; nothing here decides."""
        p = params or ProposalsIn()
        iss = (await issuer_row(q, p.issuer))["issuer_id"] if p.issuer not in (None, "") else None
        rows = await q("SELECT kind, proposal_id, issuer_id, record_type, record_id, title, reason, note, proposed_by, proposed_at FROM si_analyst_proposals($1, $2)", iss, p.kind)
        return listing(rows, 500)

    @mcp.tool(name="agent_dispatches", annotations={"title": "Dispatches to agents", **RO})
    @answers
    async def agent_dispatches(params: DispatchesIn | None = None) -> str:
        """The dispatches to agents — a high signal, a filing, an upload read, a holder match, the weekly pack, a question — with the state, the reply's excerpt, the attempts and the record it was about (Q44). Everyone's for
        those who set the agents; an agent sees its own."""
        p = params or DispatchesIn()
        w = Where()
        if p.status:
            w.add("d.status = {}", p.status)
        if p.kind:
            w.add("d.kind = {}", p.kind)
        rows = await page(q, f"""SELECT d.dispatch_id, d.kind, d.agent_member_id, d.status, left(d.reply_excerpt, 200) AS reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.signal_id, d.filing_id, d.import_id
                                   FROM mcp_agent_dispatches d WHERE {w.sql()}""", w.args, lim(p.limit), 0, "ORDER BY d.dispatch_id DESC")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="members_with_roles", annotations={"title": "Who holds which role here", **RO})
    @answers
    async def members_with_roles(params: MembersRolesIn | None = None) -> str:
        """Who holds which role here — people and agents (Q42): the roles resolved from what the directory sent and what the kernel granted. No e-mail address."""
        p = params or MembersRolesIn()
        w = Where()
        if p.role:
            w.add("{} = ANY (m.roles_resolved)", p.role)
        if p.agents_only:
            w.raw("m.member_kind = 'agent'")
        rows = await q(f"SELECT m.member_id, m.display_name, m.member_kind, m.roles_resolved FROM mcp_members m WHERE {w.sql()} ORDER BY m.member_kind, m.display_name LIMIT 201", *w.args)
        return listing(rows, 200)

    @mcp.tool(name="find_keys", annotations={"title": "API keys", **RO})
    @answers
    async def find_keys(params: FindKeysIn | None = None) -> str:
        """The records-API keys (Q43): mine, or everyone's for a holder of the right to manage tokens — the label, the limits, when last used, when it expires or was revoked, today's calls. NEVER a key's value or its hash.
        `q` alone resolves one key by its label."""
        p = params or FindKeysIn()
        w = Where()
        if p.q:
            w.add("k.label ILIKE {}", like(p.q))
        if p.member not in (None, ""):
            w.add("k.member_id = {}", await member_ref(q, p.member))
        rows = await page(q, f"SELECT k.key_id, k.member_id, k.label, k.rate_per_minute, k.rate_per_day, k.last_used_at, k.expires_at, k.revoked_at, k.created_at, k.calls_today FROM mcp_keys k WHERE {w.sql()}", w.args, lim(p.limit), 0,
                          "ORDER BY (k.revoked_at IS NOT NULL), k.created_at DESC, k.key_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "label")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="key_usage_summary", annotations={"title": "A key's calls by day", **RO})
    @answers
    async def key_usage_summary(params: KeyUsageIn) -> str:
        """A key's calls and refusals by day over the last days (Q43), newest first. Yours, or any with the right to manage tokens."""
        p = params
        k = await key_ref(q, p.api_key)
        since = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=p.days)
        rows = await q("SELECT (u.bucket_start AT TIME ZONE 'UTC')::date AS day, u.calls, u.refused FROM mcp_key_usage u WHERE u.key_id = $1 AND u.bucket_kind = 'day' AND u.bucket_start >= $2 ORDER BY u.bucket_start DESC",
                       k["key_id"], since)
        return listing(rows, 400, key_id=k["key_id"], label=k["label"])

    @mcp.tool(name="find_sources", annotations={"title": "The fetchers", **RO})
    @answers
    async def find_sources(params: FindSourcesIn | None = None) -> str:
        """The fetchers that read the public record — EDGAR, FINRA, the SEC's fails, the threshold lists, DTCC's directory, prices — by connector: the schedule, the rate, the last good pull, the failures in a row, the back-off,
        whether it is paused and whether a credential is set (never the credential). `q` alone resolves one (the registry's `source` resolver)."""
        p = params or FindSourcesIn()
        w = Where()
        if p.q:
            w.add("s.name ILIKE {}", like(p.q))
        if p.connector:
            w.add("s.connector = {}", p.connector)
        if p.active is not None:
            w.add("s.active = {}", p.active)
        rows = await page(q, f"""SELECT s.source_id, s.name, s.connector, s.role, s.schedule_minutes, s.rate_per_second, s.last_ok_at, s.consecutive_failures, s.backoff_until, s.paused_at, s.active, s.has_credential
                                   FROM mcp_sources s WHERE {w.sql()}""", w.args, lim(p.limit, 50), 0, "ORDER BY s.source_id")
        if resolving(p):
            rows = exact_first(rows, p.q, "name")
        return listing(rows, lim(p.limit, 50))

    @mcp.tool(name="source_health", annotations={"title": "Are the sources healthy", **RO})
    @answers
    async def source_health(params: SourceHealthIn | None = None) -> str:
        """Are the sources healthy (Q41): for each, whether it is due, paused or backed off, the last pull's state and error, the failures in a row, whether a credential is set and — for EDGAR — whether the declared
        user-agent is set. With `pulls`, the last pulls of each (the counts, the requests, the bytes and the error)."""
        p = params or SourceHealthIn()
        rows = await q("""SELECT source_id, name, connector, active, paused, due, last_ok_at, last_status, last_error, consecutive_failures, backoff_until, has_credential, needs_user_agent, user_agent_set FROM si_source_health()
                           WHERE ($1::text IS NULL OR connector = $1) ORDER BY source_id""", p.connector)
        if p.pulls:
            for r in rows:
                r["pulls"] = await q("""SELECT pull_id, kind, started_at, finished_at, status, items_seen, items_new, items_changed, http_requests, bytes, error FROM mcp_source_pulls WHERE source_id = $1
                                         ORDER BY pull_id DESC LIMIT $2""", r["source_id"], p.pulls)
        return listing(rows, 200)

    @mcp.tool(name="find_templates", annotations={"title": "The import templates", **RO})
    @answers
    async def find_templates(params: TemplatesIn | None = None) -> str:
        """The import templates by kind of file — the column mappings the parsers use for the DTC SPR, a NOBO list, a register, prices and holders."""
        p = params or TemplatesIn()
        rows = await q("SELECT template_id, name, kind, connector, mapping FROM mcp_import_templates WHERE ($1::text IS NULL OR kind = $1) ORDER BY kind, name LIMIT 101", p.kind)
        return listing(rows, 100)

    @mcp.tool(name="tombstones", annotations={"title": "What waits in the tombstone", **RO})
    @answers
    async def tombstones(params: TombstonesIn | None = None) -> str:
        """What waits in the tombstone — deleted uploads, holders, events, packs and issuers — with who deleted it, when, and when it will be purged for good. A manager's: a holder's name shows only to an Analyst."""
        p = params or TombstonesIn()
        if not await is_manager(q):
            raise managers_only("The tombstone")
        rows = await q("SELECT kind, record_id, label, issuer_id, deleted_at, deleted_by, purge_after FROM si_tombstones() WHERE ($1::text IS NULL OR kind = $1) ORDER BY deleted_at DESC LIMIT 201", p.kind)
        return listing(rows, 200)
