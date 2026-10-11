"""Events, reports, exports (Q35, Q36, Q39): docs/shareholder-intelligence-mcp-tool-surface.md "Events, reports, exports". Every read goes through mcp_events / mcp_reports / mcp_exports — the issuer wall, and a draft pack is
seen by reports.run alone. `events_upcoming` adds the days the calendar COMPUTES (FINRA's short-interest publication, the 13F deadline) and a dilution instrument's expiry: no row is stored for them (a port of
app/features/calendar/queries.php calendar_computed() and calendar_expiries())."""
from __future__ import annotations

import datetime
import zoneinfo
from typing import Literal

from pydantic import Field

from si_common import RO, Ref, Where, _Base, answers, day, describe_models, detail, exact_first, issuer_row, like, listing, lim, off, one_by_id, page, resolving

EVENT_KINDS = Literal["filing", "financing", "dilution_instrument", "record_date", "earnings", "press", "activist_short_report", "lawsuit", "exchange_notice", "insider_transaction", "share_count", "threshold", "note", "other"]
SEVERITIES = Literal["info", "watch", "high"]
REPORT_KINDS = Literal["weekly_pack", "monthly_pack", "board_pack", "record_date_pack", "custom"]
EVENT_COLS = "e.event_id, e.issuer_id, e.on_date, e.kind, e.title, e.severity, e.url, e.filing_id, e.source"


class EventsOnIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's events (id, ticker, CIK or name); omitted: every issuer you may see")
    date: str = Field(..., description="The day, YYYY-MM-DD")
    days: int = Field(0, ge=0, le=14, description="Also this many days either side of it (0 to 14)")


class FindEventsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's events")
    kind: list[EVENT_KINDS] | None = Field(None, description="Only these kinds")
    severity: list[SEVERITIES] | None = Field(None, description="Only these severities")
    from_: str | None = Field(None, alias="from", description="On or after this day, YYYY-MM-DD")
    to: str | None = Field(None, description="On or before this day, YYYY-MM-DD")
    q: str | None = Field(None, max_length=200, description="Part of the title or the body; alone it resolves one event")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class UpcomingIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's events and instruments' expiries")
    days: int = Field(30, ge=1, le=366, description="How many days ahead")


class FindReportsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's packs")
    kind: REPORT_KINDS | None = Field(None, description="The kind of pack")
    status: Literal["draft", "approved", "published"] | None = Field(None, description="Only packs in this state (a draft is seen by those who run packs)")
    q: str | None = Field(None, max_length=200, description="Part of the title; alone it resolves one pack")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ReportIn(_Base):
    report: int = Field(..., ge=1, description="The pack (id)")


class FindExportsIn(_Base):
    kind: Literal["positions_csv", "holders_csv", "institutions_csv", "short_csv", "signals_csv", "events_csv", "filings_csv"] | None = Field(None, description="The kind of export")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


describe_models(globals())


def market_days(holidays: set, start: datetime.date, end: datetime.date) -> list[datetime.date]:
    out, d = [], start
    while d <= end:
        if d.weekday() < 5 and d not in holidays:
            out.append(d)
        d += datetime.timedelta(days=1)
    return out


def computed_days(holidays: set, frm: datetime.date, to: datetime.date) -> list[dict]:
    """FINRA's expected short-interest publication days and the 13F deadlines in [frm, to]: rows {on_date, kind, title, source: computed, severity: info}."""
    rows: list[dict] = []
    start = (frm - datetime.timedelta(days=45)).replace(day=1)
    days = market_days(holidays, start, to + datetime.timedelta(days=20))
    settlements: set[datetime.date] = set()
    m = start
    while m <= to:
        s1 = s2 = None
        nxt = (m.replace(day=28) + datetime.timedelta(days=4)).replace(day=1)
        for d in days:
            if d < m or d >= nxt:
                continue
            if d <= m.replace(day=15):
                s1 = d
            s2 = d
        settlements.update(x for x in (s1, s2) if x)
        m = nxt
    for s in sorted(settlements):
        i = days.index(s) if s in days else None
        pub = days[i + 7] if i is not None and i + 7 < len(days) else None
        if pub is not None and frm <= pub <= to:
            rows.append({"on_date": pub, "kind": "short_interest_publication", "title": f"FINRA short interest for {s.strftime('%b')} {s.day} expected", "source": "computed", "severity": "info", "event_id": None, "url": None})
    for y in range(frm.year - 1, to.year + 1):
        for mm, dd in ((3, 31), (6, 30), (9, 30), (12, 31)):
            qe = datetime.date(y, mm, dd)
            due = qe + datetime.timedelta(days=45)
            nd = market_days(holidays, due, due + datetime.timedelta(days=7))
            deadline = nd[0] if nd else due
            if frm <= deadline <= to:
                rows.append({"on_date": deadline, "kind": "thirteen_f_deadline", "title": f"13F filings due for the quarter ending {qe.strftime('%b')} {qe.day}", "source": "computed", "severity": "info", "event_id": None, "url": None})
    return rows


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="events_on", annotations={"title": "What happened on a date", **RO})
    @answers
    async def events_on(params: EventsOnIn) -> str:
        """What happened on a day, or within up to 14 days either side of it (Q35): the events recorded for the issuers you may see — filings, financings, record dates, press, a short report, an exchange notice — oldest first."""
        p = params
        d = day(p.date, "date")
        w = Where().add("e.on_date BETWEEN {0}::date - {1}::int AND {0}::date + {1}::int", d, p.days)
        if p.issuer not in (None, ""):
            w.add("e.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        rows = await q(f"SELECT {EVENT_COLS} FROM mcp_events e WHERE {w.sql()} ORDER BY e.on_date, e.event_id LIMIT 201", *w.args)
        return listing(rows, 200)

    @mcp.tool(name="find_events", annotations={"title": "Events", **RO})
    @answers
    async def find_events(params: FindEventsIn | None = None) -> str:
        """Events by issuer, kind, severity, window or words (Q35), newest first. `q` alone resolves one event (the registry's `event` resolver) — how an explaining event is named when a signal is disposed of."""
        p = params or FindEventsIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("e.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.kind:
            w.add("e.kind = ANY ({}::text[])", list(p.kind))
        if p.severity:
            w.add("e.severity = ANY ({}::text[])", list(p.severity))
        if p.from_:
            w.add("e.on_date >= {}", day(p.from_, "from"))
        if p.to:
            w.add("e.on_date <= {}", day(p.to, "to"))
        if p.q:
            w.add("(e.title ILIKE {0} OR coalesce(e.body, '') ILIKE {0})", like(p.q))
        rows = await page(q, f"SELECT {EVENT_COLS}, e.tags FROM mcp_events e WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), "ORDER BY e.on_date DESC, e.event_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "title")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="events_upcoming", annotations={"title": "What is coming", **RO})
    @answers
    async def events_upcoming(params: UpcomingIn | None = None) -> str:
        """What is coming in the next days (Q36): record dates and earnings a person recorded, a dilution instrument's expiry, FINRA's next short-interest publication and the next 13F deadline. The last two are COMPUTED
        from the calendar, not stored — they carry source 'computed' and mean 'expected'."""
        p = params or UpcomingIn()
        st = (await q("SELECT market_timezone, market_holidays FROM mcp_settings"))
        tz = zoneinfo.ZoneInfo((st[0]["market_timezone"] if st else None) or "America/New_York")
        holidays = set(st[0]["market_holidays"] or []) if st else set()
        today = datetime.datetime.now(tz).date()
        to = today + datetime.timedelta(days=p.days)
        iss = (await issuer_row(q, p.issuer))["issuer_id"] if p.issuer not in (None, "") else None
        rows = await q("SELECT e.event_id, e.on_date, e.kind, e.title, e.severity, e.source, e.url FROM mcp_events e WHERE ($1::bigint IS NULL OR e.issuer_id = $1) AND e.on_date BETWEEN $2 AND $3 ORDER BY e.on_date, e.event_id", iss, today, to)
        for r in await q("""SELECT d.instrument_id, d.expires_on, d.kind, d.counterparty FROM mcp_dilution_instruments d WHERE ($1::bigint IS NULL OR d.issuer_id = $1) AND d.status IN ('proposed', 'active')
                             AND d.expires_on BETWEEN $2 AND $3 ORDER BY d.expires_on, d.instrument_id""", iss, today, to):
            rows.append({"event_id": None, "on_date": r["expires_on"], "kind": "dilution_expiry", "title": r["kind"].replace("_", " ").capitalize() + (f" with {r['counterparty']}" if r["counterparty"] else "") + " expires",
                         "severity": "watch", "source": "instrument", "url": None})
        rows += computed_days(holidays, today, to)
        rows.sort(key=lambda r: (str(r["on_date"]), r["title"]))
        return listing(rows, 300, window={"from": today, "to": to})

    @mcp.tool(name="find_reports", annotations={"title": "Packs", **RO})
    @answers
    async def find_reports(params: FindReportsIn | None = None) -> str:
        """The weekly, monthly and board packs by issuer, kind, state or title (Q39), newest period first. A draft is seen only by those who run packs. `q` alone resolves one (the registry's `report` resolver)."""
        p = params or FindReportsIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("r.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.kind:
            w.add("r.kind = {}", p.kind)
        if p.status:
            w.add("r.status = {}", p.status)
        if p.q:
            w.add("r.title ILIKE {}", like(p.q))
        rows = await page(q, f"SELECT r.report_id, r.issuer_id, r.kind, r.title, r.period_from, r.period_to, r.status, r.approved_by, r.approved_at, r.rendered, r.has_pdf FROM mcp_reports r WHERE {w.sql()}", w.args, lim(p.limit), 0,
                          "ORDER BY r.period_to DESC, r.report_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "title")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_report", annotations={"title": "One pack", **RO})
    @answers
    async def get_report(params: ReportIn) -> str:
        """One pack (Q39): the narrative the Analyst or an agent drafted, the sections it prints and the state — drafted by whom, approved by whom and when. A draft is for those who run packs."""
        r = await one_by_id(q, "report", "mcp_reports", "report_id", params.report)
        keep = ("report_id", "issuer_id", "kind", "title", "period_from", "period_to", "status", "narrative", "sections", "drafted_by", "approved_by", "approved_at", "rendered", "has_pdf", "created_at", "updated_at")
        return detail(**{k: r[k] for k in keep})

    @mcp.tool(name="find_exports", annotations={"title": "My exports", **RO})
    @answers
    async def find_exports(params: FindExportsIn | None = None) -> str:
        """My exports — the CSVs I asked for, built from what I see, kept seven days — and, for those who hold the right to see everyone's, the shared ones. Never the file's path; the row count, the size and whether it carries a
        natural person's details."""
        p = params or FindExportsIn()
        w = Where()
        if p.kind:
            w.add("x.kind = {}", p.kind)
        rows = await page(q, f"SELECT x.export_id, x.kind, x.format, x.status, x.item_count, x.byte_size, x.includes_pii, x.created_by, x.created_at, x.expires_at FROM mcp_exports x WHERE {w.sql()}", w.args, lim(p.limit), 0,
                          "ORDER BY x.created_at DESC, x.export_id DESC")
        return listing(rows, lim(p.limit))
