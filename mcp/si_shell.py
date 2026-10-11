"""The shell, me and the vocabulary: the one search box, my notices, the settings a member may read, members, departments, tags and the notes on a record.
docs/shareholder-intelligence-mcp-tool-surface.md "The shell, me and the vocabulary". Both walls are the database's: si_search() holds them inside, the views hold the rest."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from si_common import RO, Ref, Refusal, Where, _Base, answers, describe_models, detail, exact_first, issuer_row, is_member, like, listing, lim, off, page, resolving

SEARCH_KINDS = Literal["signal", "filing", "event", "holder", "institution", "participant", "issuer"]
NOTE_KINDS = Literal["issuer", "security", "holder", "event", "signal", "report", "dilution_instrument", "filing", "import", "participant", "institution"]


class SearchIn(_Base):
    q: str = Field(..., min_length=1, max_length=200, description="Websearch words: holders, institutions, participants, filings, events, signals and issuers (a natural person reads as 'Individual holder' unless you are an Analyst)")
    kinds: list[SEARCH_KINDS] | None = Field(None, description="Only these kinds of record")
    issuer: Ref | None = Field(None, description="Only one issuer's records (id, ticker, CIK or name)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class NotificationsIn(_Base):
    unread_only: bool = Field(False, description="Only the ones not read yet")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class MembersIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of a name; alone it resolves one person or agent")
    role: str | None = Field(None, max_length=40, description="Only holders of this role key here (viewer, user, manager, admin)")
    kind: Literal["human", "agent"] | None = Field(None, description="Only people (human) or only agents")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class QIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of a name; alone it resolves one")
    limit: int = Field(100, ge=1, le=100, description="How many rows at most")


class NotesIn(_Base):
    kind: NOTE_KINDS = Field(..., description="What the note is on")
    record: int = Field(..., ge=1, description="The record's id")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="search", annotations={"title": "Search everything", **RO})
    @answers
    async def search(params: SearchIn) -> str:
        """The one box (Q: any words): holders (names for an Analyst), institutions, participants, filings, events, signals and issuers, ranked, with a highlight. BOTH WALLS ARE IN THE FUNCTION: a restricted issuer's
        rows never come back, and a natural person reads as 'Individual holder' with no headline unless you are an Analyst. Call this first when you only have a few words and no id."""
        p = params
        if not await is_member(q):
            raise Refusal("not_found", "Nothing to search: you are not a member of the business here.")
        issuer_id = (await issuer_row(q, p.issuer))["issuer_id"] if p.issuer not in (None, "") else None
        rows = await q("SELECT entity_kind, entity_id, title, headline, status, issuer_id, rank, total FROM si_search($1, $2::text[], $3, $4, $5)", p.q, list(p.kinds) if p.kinds else None, issuer_id, lim(p.limit), off(p.offset))
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        return listing(rows, lim(p.limit), total=total)

    @mcp.tool(name="my_notifications", annotations={"title": "My notices", **RO})
    @answers
    async def my_notifications(params: NotificationsIn | None = None) -> str:
        """My notices, unread first: the morning note, a signal raised, a pack waiting. Only yours — the view keeps them to the asking member."""
        p = params or NotificationsIn()
        w = Where()
        if p.unread_only:
            w.raw("n.read_at IS NULL")
        rows = await page(q, f"""SELECT n.notification_id, n.kind, n.title, n.body, n.record_type, n.record_id, n.signal_id, n.issuer_id, n.read_at, n.created_at FROM mcp_notifications n WHERE {w.sql()}""", w.args,
                          lim(p.limit), 0, "ORDER BY (n.read_at IS NOT NULL), n.created_at DESC, n.notification_id DESC")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_settings", annotations={"title": "The application's settings", **RO})
    @answers
    async def get_settings() -> str:
        """The settings a member may read: the exchange, the market time zone and holidays, the reconciliation tolerance, the matcher's auto threshold, whether holders may be exported and by whom, the morning note's hour, the
        pack's day, whether the EDGAR user-agent is set (never its words). Who gets the high-severity escalation is shown to the administrator only."""
        rows = await q("SELECT * FROM mcp_settings")
        if not rows:
            raise Refusal("not_found", "The settings are for the members of the business.")
        return detail(**rows[0])

    @mcp.tool(name="find_members", annotations={"title": "People and agents", **RO})
    @answers
    async def find_members(params: MembersIn | None = None) -> str:
        """People and agents admitted here, with the roles they hold: by name, role or kind. `q` alone resolves one (the registry's member resolver). No e-mail address is ever returned."""
        p = params or MembersIn()
        w = Where()
        if p.q:
            w.add("m.display_name ILIKE {}", like(p.q))
        if p.role:
            w.add("{} = ANY (m.roles_resolved)", p.role)
        if p.kind:
            w.add("m.member_kind = {}", p.kind)
        rows = await page(q, f"SELECT m.member_id, m.display_name, m.member_kind, m.roles_resolved, m.job_title, m.department_ids FROM mcp_members m WHERE {w.sql()}", w.args, lim(p.limit), 0, "ORDER BY m.display_name, m.member_id")
        if resolving(p):
            rows = exact_first(rows, p.q, "display_name")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="find_departments", annotations={"title": "Departments", **RO})
    @answers
    async def find_departments(params: QIn | None = None) -> str:
        """The business's departments by name, with the parent and the manager. `q` alone resolves one."""
        p = params or QIn()
        w = Where()
        if p.q:
            w.add("d.name ILIKE {}", like(p.q))
        rows = await page(q, f"SELECT d.department_id, d.name, d.parent_id, d.manager_member_id FROM mcp_departments d WHERE d.archived_at IS NULL AND {w.sql()}", w.args, lim(p.limit, 100), 0, "ORDER BY d.name")
        if resolving(p):
            rows = exact_first(rows, p.q, "name")
        return listing(rows, lim(p.limit, 100))

    @mcp.tool(name="find_tags", annotations={"title": "The tag vocabulary", **RO})
    @answers
    async def find_tags(params: QIn | None = None) -> str:
        """The tags in use, most used first, with their usage count. `q` alone resolves one."""
        p = params or QIn()
        w = Where()
        if p.q:
            w.add("t.label ILIKE {}", like(p.q))
        rows = await page(q, f"SELECT t.tag_id, t.slug, t.label, t.usage_count FROM mcp_tags t WHERE {w.sql()}", w.args, lim(p.limit, 100), 0, "ORDER BY t.usage_count DESC, t.label")
        if resolving(p):
            rows = exact_first(rows, p.q, "label", "slug")
        return listing(rows, lim(p.limit, 100))

    @mcp.tool(name="find_notes", annotations={"title": "The notes on a record", **RO})
    @answers
    async def find_notes(params: NotesIn) -> str:
        """The notes people wrote on a record (an issuer, a security, a holder, an event, a signal, a report, a filing, an upload, a participant, an institution), newest first. A note on a record behind the issuer wall,
        or on a natural-person holder when you are not an Analyst, does not exist for you — the view keeps both walls."""
        p = params
        rows = await page(q, "SELECT n.note_id, n.member_id, (SELECT display_name FROM mcp_members m WHERE m.member_id = n.member_id) AS author, n.body, n.created_at FROM mcp_notes n WHERE n.record_type = $1 AND n.record_id = $2",
                          [p.kind, p.record], lim(p.limit), 0, "ORDER BY n.created_at DESC, n.note_id DESC")
        return listing(rows, lim(p.limit))
