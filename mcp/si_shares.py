"""The five K7 shares (Q45–Q49; design §8, D13): filings_index, filing_text, ownership_summary, signals_open, events_index. THEY ARE THE KERNEL'S ALONE (server_common.KERNEL_ONLY): a sibling application reads them through the
kernel's approved connection, the kernel's token admits them and `app_roles` and nothing else, and who is asking comes from the headers the kernel adds (K26: X-OS-Consumer — never an argument, which the kernel strips and
server_common drops again). Each calls a db/018 SECURITY DEFINER function that runs as the application: an issuer with a restriction list is NEVER shared (not_found, by id, ticker or CIK alike), NO natural person crosses
(holders appear only as counts, ranges and entities; institutions and insiders are the public record), and an empty consumer is `consumer_unknown`. Each call is logged `share.read` {application = the consumer, tool,
issuer_id, rows, ms} by db/028's si_log_mcp_read() — never the answer. A person or an agent reads the same facts through the tools of their own role (find_filings, filing_read, get_issuer, find_signals, find_events)."""
from __future__ import annotations

import time

from pydantic import Field

import db
from si_common import RO, Ref, _Base, answers, day, describe_models, dumps, log_read

SHARE_ONLY = "The kernel's own token reaches this share for a sibling application; a person or an agent reads the same facts through find_filings, filing_read, get_issuer, find_signals or find_events."


class FilingsIndexIn(_Base):
    issuer: Ref = Field(..., description="The issuer: an id, a ticker or a CIK")
    since: str | None = Field(None, description="Only filings filed on or after this day, YYYY-MM-DD")
    forms: list[str] | None = Field(None, description="Only these forms (8-K, 10-K, SC 13D …)")
    limit: int | None = Field(None, ge=1, le=500, description="How many filings at most (500 at most)")
    offset: int | None = Field(None, ge=0, le=100000, description="Skip this many filings (paging)")


class FilingTextIn(_Base):
    filing: int = Field(..., ge=1, description="The filing (id) from filings_index")


class OwnershipIn(_Base):
    issuer: Ref = Field(..., description="The issuer: an id, a ticker or a CIK")
    as_of: str | None = Field(None, description="The picture as of this day, YYYY-MM-DD; default today")


class SignalsOpenIn(_Base):
    issuer: Ref = Field(..., description="The issuer: an id, a ticker or a CIK")


class EventsIndexIn(_Base):
    issuer: Ref = Field(..., description="The issuer: an id, a ticker or a CIK")
    from_: str | None = Field(None, alias="from", description="Events on or after this day, YYYY-MM-DD")
    to: str | None = Field(None, description="Events on or before this day, YYYY-MM-DD")
    limit: int | None = Field(None, ge=1, le=500, description="How many events at most (500 at most)")


describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    async def shared(tool: str, sql: str, args: list, rows_of) -> str:
        t0 = time.perf_counter()
        doc = (await q(sql, *args))[0]["d"]
        issuer_id = doc.get("issuer_id") or (doc.get("issuer") or {}).get("issuer_id") if isinstance(doc, dict) else None
        n = 0 if not isinstance(doc, dict) or doc.get("error") else rows_of(doc)
        await log_read(q, "share.read", {"application": db.request_consumer.get(), "tool": tool, "issuer_id": issuer_id, "rows": n, "error": doc.get("error") if isinstance(doc, dict) else None,
                                         "ms": int(round((time.perf_counter() - t0) * 1000))}, issuer_id=issuer_id, request_id=db.request_kernel_request_id.get())
        return dumps(doc)

    @mcp.tool(name="filings_index", annotations={"title": "An issuer's filings — for a sibling", **RO})
    @answers
    async def filings_index(params: FilingsIndexIn) -> str:
        """An issuer's filings: id, accession number, form, filed date, title, items, URL, whether it is about the issuer rather than by it, whether its text is kept (os.si-filings/1) — Knowledge's feed. Newest first, paged
        (500 at most). An issuer behind a restriction list is not shared. """ + SHARE_ONLY
        p = params
        return await shared("filings_index", "SELECT si_share_filings_index($1, $2, $3, $4::text[], COALESCE($5::int, 100), COALESCE($6::int, 0)) AS d",
                            [db.request_consumer.get() or "", str(p.issuer), day(p.since, "since"), list(p.forms) if p.forms else None, p.limit, p.offset], lambda d: len(d.get("filings") or []))

    @mcp.tool(name="filing_text", annotations={"title": "A filing's text — for a sibling", **RO})
    @answers
    async def filing_text(params: FilingTextIn) -> str:
        """The extracted text of one filing, whole, up to 5 MB (os.si-filing-text/1) — Knowledge's sectioner. The answer says if it was truncated. A filing of an issuer behind a restriction list is `not_found`; a filing
        whose text was not kept is `no_text`. """ + SHARE_ONLY
        return await shared("filing_text", "SELECT si_share_filing_text($1, $2) AS d", [db.request_consumer.get() or "", params.filing], lambda d: 1)

    @mcp.tool(name="ownership_summary", annotations={"title": "The ownership picture — for a sibling", **RO})
    @answers
    async def ownership_summary(params: OwnershipIn) -> str:
        """The ownership picture in aggregate (os.si-ownership/1): outstanding and float, the latest short interest, the DTC total by side, the concentration, the reconciliation's state, the 13F totals, the 13D/13G
        holders, the insiders' shares, the NOBO and OBO counts. NEVER a natural person's name. An issuer behind a restriction list is not shared. """ + SHARE_ONLY
        p = params
        return await shared("ownership_summary", "SELECT si_share_ownership_summary($1, $2, $3) AS d", [db.request_consumer.get() or "", str(p.issuer), day(p.as_of, "as_of")], lambda d: 1)

    @mcp.tool(name="signals_open", annotations={"title": "The open signals — for a sibling", **RO})
    @answers
    async def signals_open(params: SignalsOpenIn) -> str:
        """The open signals of an issuer (os.si-signals/1): the rule, its name, its evidence class, the severity, the dates and the summary — a card for a Spaces page. An issuer behind a restriction list is not shared. """ + SHARE_ONLY
        return await shared("signals_open", "SELECT si_share_signals_open($1, $2) AS d", [db.request_consumer.get() or "", str(params.issuer)], lambda d: len(d.get("signals") or []))

    @mcp.tool(name="events_index", annotations={"title": "An issuer's events — for a sibling", **RO})
    @answers
    async def events_index(params: EventsIndexIn) -> str:
        """The events of an issuer in a window (os.si-events/1): date, kind, title, severity, URL, the filing — newest first. An issuer behind a restriction list is not shared. """ + SHARE_ONLY
        p = params
        return await shared("events_index", "SELECT si_share_events_index($1, $2, $3, $4, COALESCE($5::int, 100)) AS d", [db.request_consumer.get() or "", str(p.issuer), day(p.from_, "from"), day(p.to, "to"), p.limit],
                            lambda d: len(d.get("events") or []))
