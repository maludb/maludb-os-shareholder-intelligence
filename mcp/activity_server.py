"""shareholder_intelligence_activity_mcp — Shareholder Intelligence's activity-memory server (activity_log through mcp_activity_log, read-only; the tenant's MaluDB for a record's episodes).

Tool surface: docs/shareholder-intelligence-mcp-tool-surface.md "Activity server". Every query runs as the asking member; the view decides which rows they see: the events about the issuers the issuer wall lets them see
(and the events that concern no issuer), never a payload with a natural person (the writers' rule: a holder's name, address or registration lines, a filing's text, a credential, a token or a key are never in a payload —
ids, counts, dates, shares, rule keys and severities are). The activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_issuers and mcp_securities for names beside a row, and nothing else.
"""
from __future__ import annotations

import datetime
import json

import httpx
from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from si_common import RO, Ref, Refusal, Where, _Base, ago, answers, day, describe_models, detail, is_manager, issuer_row, like, listing, lim, managers_only, me, member_ref, page, when

mcp = FastMCP("shareholder_intelligence_activity_mcp")

COLS = """l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.source, l.action, l.entity_type, l.entity_id, l.before, l.after, l.issuer_id, l.security_id, l.import_id, l.signal_id, l.agent_run_id"""
WORDS = {
    "member.sign_on": "signed on", "directory.sync": "refreshed the directory", "token.mint": "made an access token", "token.revoke": "revoked an access token", "issuer.save": "saved an issuer",
    "issuer.edgar_sync": "read an issuer from EDGAR", "security.save": "saved a security", "share_count.save": "recorded a share count", "restriction.save": "changed who may see an issuer",
    "upload.create": "uploaded a file", "upload.parse": "parsed an upload", "upload.tie": "tied an upload", "upload.untie": "found an upload untied", "upload.override": "overrode an untied upload",
    "upload.delete": "deleted an upload", "upload.restore": "restored an upload", "reconciliation.run": "ran the reconciliation", "match.auto": "matched a holder", "match.accept": "accepted a holder match",
    "match.reject": "rejected a holder match", "match.new_holder": "made a holder", "holder.merge": "merged holders", "signal.raise": "raised a signal", "signal.acknowledge": "acknowledged a signal",
    "signal.dismiss": "dismissed a signal", "signal.escalate": "escalated a signal", "signal.propose": "proposed a disposition", "event.create": "recorded an event", "report.draft": "drafted a pack",
    "report.approve": "approved a pack", "report.send": "sent a pack", "export.create": "exported", "source.pull_done": "pulled a source", "source.pull_fail": "failed to pull a source",
    "key.mint": "minted a key", "key.revoke": "revoked a key", "share.read": "read a share", "settings.save": "changed the settings", "agent.dispatch": "dispatched an agent", "agent.reply": "heard back from an agent",
}
DECISIONS = ("signal.acknowledge", "signal.dismiss", "signal.escalate", "match.accept", "match.reject", "holder.kind_set", "holder.kind_reject")


class ActivitySearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="Words to find in an action, a record type, a name, a rule key or a title the payloads carry, and the actor's name — each word must match")
    issuer: Ref | None = Field(None, description="Only the trail of this issuer (id, ticker, CIK or name)")
    from_: str | None = Field(None, alias="from", description="Only on or after this day, YYYY-MM-DD")
    to: str | None = Field(None, description="Only on or before this day, YYYY-MM-DD")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class RecordHistoryIn(_Base):
    kind: str = Field(..., min_length=3, max_length=40, pattern=r"^[a-z_]+$", description="What kind of record: issuer, security, upload, signal (the audit keys), or holder, filing, event, report, institution, participant, dilution_instrument … (the entity)")
    record: int = Field(..., ge=1, description="The record's id")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class ActorHistoryIn(_Base):
    member: Ref = Field(..., description="A person or an agent (id, name or me)")
    from_: str | None = Field(None, alias="from", description="Only on or after this day or time; default the last 7 days")
    to: str | None = Field(None, description="Only before the end of this day or time")
    action: str | None = Field(None, max_length=60, description="An action prefix: signal., upload., match. …")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class RecordEpisodesIn(RecordHistoryIn):
    pass


class RecentIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's trail")
    action: str | None = Field(None, max_length=60, description="An action prefix")
    since: str | None = Field(None, description="A day or ISO time; default the last 7 days")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class AgentActivityIn(_Base):
    from_: str | None = Field(None, alias="from", description="Since this day; default the last 7 days")
    to: str | None = Field(None, description="Until the end of this day")
    agent: Ref | None = Field(None, description="Only this agent (id or name)")


class TimelineIn(_Base):
    issuer: Ref = Field(..., description="The issuer (id, ticker, CIK or name)")
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")


describe_models(globals())


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


def sentence(r: dict) -> str:
    who = r.get("actor_name") or "Shareholder Intelligence"
    after = r.get("after") if isinstance(r.get("after"), dict) else {}
    tail = ""
    for k in ("title", "name", "rule", "rule_key"):
        if isinstance(after.get(k), str):
            tail = ": " + after[k][:80]
            break
    return f"{who} {WORDS.get(r['action'], str(r['action']).replace('.', ' ').replace('_', ' '))}{tail}"


def shape(rows: list[dict]) -> list[dict]:
    for r in rows:
        r["summary"] = sentence(r)
    return rows


def audit_filter(kind: str, record: int, w: Where) -> None:
    keys = {"issuer": "issuer_id", "security": "security_id", "upload": "import_id", "import": "import_id", "signal": "signal_id"}
    if kind in keys:
        w.add(f"l.{keys[kind]} = {{}}", record)
    else:
        w.add("l.entity_type = {}", kind).add("l.entity_id = {}", record)


@mcp.tool(name="activity_search", annotations={"title": "Search the trail", **RO})
@answers
async def activity_search(params: ActivitySearchIn) -> str:
    """The trail by a phrase (Q50) — 'when did we first flag the build-up at Goldman': each word is looked for in the action, the record type, the actor's name and the names, rule keys and titles the payloads carry; newest
    first, only rows you may see (the issuer wall is the view's). The same rows the tenant's memory holds as episodes: nothing is ranked by a model."""
    p = params
    w = Where().raw("l.action <> 'screen.view'")
    for word in [x for x in p.q.split() if x][:6]:
        n = w.p(like(word))
        w.raw(f"(l.action ILIKE {n} OR coalesce(l.entity_type, '') ILIKE {n} OR coalesce(l.actor_name, '') ILIKE {n} OR coalesce(l.after::text, '') ILIKE {n})")
    if p.issuer not in (None, ""):
        w.add("l.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
    if p.from_:
        w.add("l.occurred_at >= {}", when(p.from_, "from"))
    if p.to:
        w.add("l.occurred_at < {}", when(p.to, "to", end_of_day=True))
    rows = await page(q, f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()}", w.args, lim(p.limit), 0, "ORDER BY l.occurred_at DESC, l.activity_id DESC")
    return listing(shape(rows), lim(p.limit))


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
@answers
async def record_history(params: RecordHistoryIn) -> str:
    """Who did what to a record, in order (Q51): an upload (its parse, its tie, an override), a holder, a signal (raised, proposed, disposed), a filing, an event, a report — by the record's kind and id, or by an audit key
    (issuer, security, upload, signal: everything that touched it). Oldest first. You see the rows about records you may see; for a record behind the wall there are none, in the words of one that has none."""
    p = params
    w = Where().raw("l.action <> 'screen.view'")
    audit_filter(p.kind, p.record, w)
    rows = await page(q, f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()}", w.args, lim(p.limit, 50, 200), 0, "ORDER BY l.occurred_at ASC, l.activity_id ASC")
    return listing(shape(rows), lim(p.limit, 50, 200), kind=p.kind, record=p.record)


@mcp.tool(name="actor_history", annotations={"title": "What a person or an agent did", **RO})
@answers
async def actor_history(params: ActorHistoryIn) -> str:
    """What one person or agent did here since a time — the last 7 days by default — newest first (Q51): an Analyst's uploads and dispositions this week, an agent's proposals. Your own trail is yours; another member's
    is a manager's to read."""
    p = params
    target = await member_ref(q, p.member)
    if target != await me(q) and not await is_manager(q):
        raise managers_only("Another member's trail")
    w = Where().raw("l.action <> 'screen.view'").add("l.actor_member_id = {}", target).add("l.occurred_at >= {}", when(p.from_, "from") if p.from_ else ago(7))
    if p.to:
        w.add("l.occurred_at < {}", when(p.to, "to", end_of_day=True))
    if p.action:
        w.add("l.action LIKE {}", p.action.replace("\\", "").replace("%", "").replace("_", "\\_") + "%")
    rows = await page(q, f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()}", w.args, lim(p.limit, 50, 200), 0, "ORDER BY l.occurred_at DESC, l.activity_id DESC")
    return listing(shape(rows), lim(p.limit, 50, 200), member_id=target)


@mcp.tool(name="record_episodes", annotations={"title": "The memory's episodes about a record", **RO})
@answers
async def record_episodes(params: RecordEpisodesIn) -> str:
    """The tenant's memory (MaluDB) holds every logged event as an episode; this returns the episodes about a record (Q51) — the MaluDB side of its trail — for the events you may see (the wall is the view's: an episode is
    returned only if its activity-log row is one you can read). Without a memory configured the answer is the log's own rows, said so. Oldest first."""
    p = params
    w = Where().raw("l.action <> 'screen.view'")
    audit_filter(p.kind, p.record, w)
    visible = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at ASC, l.activity_id ASC LIMIT {lim(p.limit, 50, 200)}", *w.args)
    ids = {int(r["activity_id"]) for r in visible}
    url, token = db.ENV.get("MALUDB_API_URL", "").rstrip("/"), db.ENV.get("MALUDB_API_TOKEN", "")
    if not ids:
        return listing([], lim(p.limit, 50, 200), source="memory", kind=p.kind, record=p.record)
    if not url or not token:
        return listing(shape(visible), lim(p.limit, 50, 200), source="activity_log", note="The tenant's memory is not configured here; these are the log's own rows.")
    lo = min(r["occurred_at"] for r in visible) - datetime.timedelta(seconds=1)
    hi = max(r["occurred_at"] for r in visible) + datetime.timedelta(seconds=1)
    found: list[dict] = []
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            for offset in range(0, 1000, 200):
                resp = await client.get(f"{url}/v1/episodes", params=[("kind", "activity"), ("occurred_at", f"gte.{lo.isoformat()}"), ("occurred_at", f"lte.{hi.isoformat()}"), ("order", "occurred_at.asc"), ("limit", "200"), ("offset", str(offset))],
                                        headers={"Authorization": f"Bearer {token}"})
                if resp.status_code != 200:
                    raise RuntimeError(f"memory answered {resp.status_code}")
                eps = resp.json().get("episodes") or []
                for e in eps:
                    pl = e.get("payload") or {}
                    if isinstance(pl, str):
                        pl = json.loads(pl)
                    if pl.get("application") in (None, "shareholder_intelligence") and pl.get("activity_log_id") in ids:
                        found.append({"episode_id": e.get("id"), "occurred_at": e.get("occurred_at"), "title": e.get("title"), "payload": pl})
                if len(eps) < 200:
                    break
    except Exception:  # noqa: BLE001
        return listing(shape(visible), lim(p.limit, 50, 200), source="activity_log", note="The tenant's memory could not be reached; these are the log's own rows.")
    return listing(found, lim(p.limit, 50, 200), source="memory", kind=p.kind, record=p.record)


@mcp.tool(name="recent_activity", annotations={"title": "What happened here lately", **RO})
@answers
async def recent_activity(params: RecentIn | None = None) -> str:
    """What happened here lately — the last 7 days by default — newest first, by issuer or action prefix, across everything you may see. For catching up: what was uploaded, tied, raised, disposed. Leaves out screen views."""
    p = params or RecentIn()
    w = Where().raw("l.action <> 'screen.view'").add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(7))
    if p.issuer not in (None, ""):
        w.add("l.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
    if p.action:
        w.add("l.action LIKE {}", p.action.replace("\\", "").replace("%", "").replace("_", "\\_") + "%")
    rows = await page(q, f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()}", w.args, lim(p.limit, 50, 200), 0, "ORDER BY l.occurred_at DESC, l.activity_id DESC")
    return listing(shape(rows), lim(p.limit, 50, 200))


@mcp.tool(name="agent_activity", annotations={"title": "What the agents did and proposed", **RO})
@answers
async def agent_activity(params: AgentActivityIn | None = None) -> str:
    """What the agents did this week and what they proposed that nobody took (Q52): the agents' actions in the window, the proposals still open (a signal's disposition, a holder match, a holder's kind) and the decisions people
    made in the window — so 'what did the Surveillance Analyst propose that no one acted on' is one call."""
    p = params or AgentActivityIn()
    a = when(p.from_, "from") if p.from_ else ago(7)
    b = when(p.to, "to", end_of_day=True) if p.to else None
    w = Where().raw("l.action <> 'screen.view'").add("l.occurred_at >= {}", a)
    if b:
        w.add("l.occurred_at < {}", b)
    agent = await member_ref(q, p.agent) if p.agent not in (None, "") else None
    w1 = Where()
    w1.parts, w1.args = list(w.parts), list(w.args)
    if agent is not None:
        w1.add("l.actor_member_id = {}", agent)
    else:
        w1.raw("(l.source = 'agent' OR l.actor_member_id IN (SELECT member_id FROM mcp_members WHERE member_kind = 'agent'))")
    actions = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w1.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT 101", *w1.args)
    w2 = Where()
    w2.parts, w2.args = list(w.parts), list(w.args)
    w2.add("l.action = ANY ({}::text[])", list(DECISIONS))
    decided = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w2.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT 101", *w2.args)
    opened = await q("SELECT kind, proposal_id, issuer_id, record_type, record_id, title, reason, note, proposed_by, proposed_at FROM si_analyst_proposals(NULL, NULL) WHERE ($1::bigint IS NULL OR proposed_by = $1) LIMIT 101", agent)
    return detail(actions=shape(actions[:100]), actions_truncated=len(actions) > 100, proposals_open=opened[:100], proposals_decided=shape(decided[:100]))


@mcp.tool(name="activity_timeline", annotations={"title": "An issuer's trail by day", **RO})
@answers
async def activity_timeline(params: TimelineIn) -> str:
    """An issuer's trail by day (Q53): for each day the count of each kind of event — uploads, ties, signals raised and disposed, filings indexed, packs — newest day first. The shape of a week at a glance."""
    p = params
    iss = await issuer_row(q, p.issuer)
    a, b = day(p.from_, "from"), day(p.to, "to")
    a, b = min(a, b), max(a, b)
    if (b - a).days > 400:
        raise Refusal("too_large", "At most 400 days.", fields=["from", "to"])
    rows = await q("""SELECT (l.occurred_at AT TIME ZONE 'UTC')::date AS day, l.action, count(*) AS count FROM mcp_activity_log l WHERE l.issuer_id = $1 AND l.action <> 'screen.view'
                         AND l.occurred_at >= $2::date AND l.occurred_at < $3::date + 1 GROUP BY 1, 2 ORDER BY 1 DESC, 2 LIMIT 801""", iss["issuer_id"], a, b)
    return listing(rows, 800, issuer_id=iss["issuer_id"])


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
