"""Filings, the watch and dilution (Q24–Q28): docs/shareholder-intelligence-mcp-tool-surface.md "Filings, the watch, dilution". The filings are the public record, behind the issuer wall (mcp_filings, mcp_filing_text);
si_filings_search() holds the wall inside. A filing's text is read by WINDOW (at most 6,000 characters) — the whole text of a filing is the K7 share's (`filing_text`), the kernel's alone."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from si_common import RO, Ref, Refusal, Where, _Base, answers, day, describe_models, detail, exact_first, is_int, issuer_row, like, listing, lim, not_found, off, page, resolving, when

DILUTION_KINDS = Literal["atm", "eloc", "convertible_note", "convertible_preferred", "warrant", "rights_offering", "registered_direct", "pipe", "other"]
DILUTION_STATUSES = Literal["proposed", "active", "exhausted", "terminated", "dismissed"]
WINDOW_MAX = 6000
SCAN_MAX = 1_000_000


class FindFilingsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's filings (id, ticker, CIK or name)")
    form: list[str] | None = Field(None, description="Only these forms (8-K, 10-K, 10-Q, SC 13D, 4, 144, S-3, 424B5 …)")
    from_: str | None = Field(None, alias="from", description="Filed on or after this day, YYYY-MM-DD")
    to: str | None = Field(None, description="Filed on or before this day, YYYY-MM-DD")
    about_us: bool | None = Field(None, description="true: only what OTHERS filed about the issuer (a 13D, a Form 4 of its insiders)")
    q: str | None = Field(None, max_length=200, description="Part of the form, its description or the accession number; alone it resolves one filing")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class FilingIn(_Base):
    filing: Ref = Field(..., description="The filing (id or accession number)")


class FilingReadIn(_Base):
    filing: Ref = Field(..., description="The filing (id or accession number)")
    offset: int = Field(0, ge=0, description="Start at this character")
    length: int = Field(WINDOW_MAX, ge=1, le=WINDOW_MAX, description="How many characters, at most 6,000")
    around: str | None = Field(None, max_length=100, description="Instead of a window: the passages (up to 20) around each occurrence of this term")


class FilingsSearchIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's filings; omitted: every issuer you may see")
    q: str = Field(..., min_length=2, max_length=200, description="Websearch words across the filings' text")
    limit: int = Field(20, ge=1, le=100, description="How many rows at most")


class WatchHitsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's hits")
    status: Literal["new", "confirmed", "dismissed"] | None = Field(None, description="Only hits in this state")
    since: str | None = Field(None, description="Found on or after this day, YYYY-MM-DD")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class DilutionIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's instruments")
    status: list[DILUTION_STATUSES] | None = Field(None, description="Only these states (default: all but dismissed)")
    kind: DILUTION_KINDS | None = Field(None, description="The kind of instrument")
    q: str | None = Field(None, max_length=100, description="Part of the counterparty or the kind; alone it resolves one instrument")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ProposedIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's proposals")


describe_models(globals())

FILING_COLS = """f.filing_id, f.issuer_id, f.form || ' ' || to_char(f.filed_on, 'YYYY-MM-DD') || coalesce(' — ' || f.primary_doc_description, '') AS label, f.form, f.filed_on, f.report_date, f.items, f.primary_doc_description,
                 f.url, f.about_us, f.has_text, f.watched, f.accession_number"""
INSTRUMENT_COLS = """d.instrument_id, d.issuer_id, d.security_id, d.kind || coalesce(' with ' || d.counterparty, '') || coalesce(' (' || to_char(d.filed_on, 'YYYY-MM-DD') || ')', '') AS label, d.kind, d.counterparty, d.filed_on, d.filing_id,
                     d.facility_amount, d.currency, d.remaining_amount, d.price_terms, d.floating_price, d.shares_issuable_max, d.expires_on, d.status"""


async def filing_ref(q, v) -> dict:
    if v in (None, ""):
        raise Refusal("invalid_argument", "Name the filing (its id or its accession number).", fields=["filing"])
    s = str(v).strip()
    if is_int(v):
        rows = await q("SELECT * FROM mcp_filings WHERE filing_id = $1", int(s))
        if rows:
            return rows[0]
    rows = await q("SELECT * FROM mcp_filings WHERE accession_number = $1 ORDER BY filing_id LIMIT 2", s)
    if rows:
        return rows[0]
    raise not_found("filing")


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_filings", annotations={"title": "Filings", **RO})
    @answers
    async def find_filings(params: FindFilingsIn | None = None) -> str:
        """The filings of the issuers you may see (Q24, Q26), newest first — what the company filed and what others filed about it (`about_us`): by issuer, form, window or words. `q` alone resolves one filing by accession
        number, or by form and date (the registry's `filing` resolver). Each row says whether its text is kept and whether the full-text watch reads that form."""
        p = params or FindFilingsIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("f.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.form:
            w.add("f.form = ANY ({}::text[])", list(p.form))
        if p.from_:
            w.add("f.filed_on >= {}", day(p.from_, "from"))
        if p.to:
            w.add("f.filed_on <= {}", day(p.to, "to"))
        if p.about_us is not None:
            w.add("f.about_us = {}", p.about_us)
        if p.q:
            w.add("(f.form ILIKE {0} OR coalesce(f.primary_doc_description, '') ILIKE {0} OR f.accession_number ILIKE {0} OR (f.form || ' ' || to_char(f.filed_on, 'YYYY-MM-DD')) ILIKE {0})", like(p.q))
        rows = await page(q, f"SELECT {FILING_COLS} FROM mcp_filings f WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), "ORDER BY f.filed_on DESC, f.filing_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "accession_number", "label")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_filing", annotations={"title": "One filing", **RO})
    @answers
    async def get_filing(params: FilingIn) -> str:
        """One filing (Q24): its index row (form, dates, items, size, URL), the event it made on the calendar, the full-text watch's hits on it, and the structured rows it produced — a 13D/13G, the Forms 3/4/5 transactions
        or a Form 144. Read its text with `filing_read`."""
        f = await filing_ref(q, params.filing)
        fid = f["filing_id"]
        event = (await q("SELECT event_id, on_date, kind, title, severity FROM mcp_events WHERE filing_id = $1 ORDER BY event_id LIMIT 1", fid)) or [None]
        beneficial = await q("SELECT beneficial_id, institution_id, institution_name, schedule, filed_on, percent_of_class, shares_beneficially_owned FROM mcp_beneficial_ownership WHERE filing_id = $1 LIMIT 20", fid)
        insider = await q("""SELECT transaction_id, reporting_owner_cik, reporting_owner_name, relationship, transaction_date, transaction_code, shares, price, acquired_disposed, shares_owned_after
                               FROM mcp_insider_transactions WHERE filing_id = $1 ORDER BY transaction_id LIMIT 50""", fid)
        f144 = await q("SELECT notice_id, seller_name, relationship, shares_to_sell, aggregate_market_value, approx_sale_date, broker FROM mcp_form144_notices WHERE filing_id = $1 LIMIT 20", fid)
        hits = await q("SELECT watch_hit_id, term, snippet, status, found_at FROM mcp_watch_hits WHERE filing_id = $1 ORDER BY watch_hit_id LIMIT 20", fid)
        structured = {k: v for k, v in (("beneficial", beneficial), ("insider", insider), ("form144", f144)) if v}
        filing = {k: f[k] for k in ("filing_id", "issuer_id", "filer_cik", "accession_number", "form", "filed_on", "report_date", "accepted_at", "items", "size", "is_xbrl", "primary_document", "primary_doc_description", "url", "about_us", "has_text", "char_count", "watched")}
        return detail(filing=filing, event=event[0], structured=structured, watch_hits=hits)

    @mcp.tool(name="filing_read", annotations={"title": "Read a filing's text", **RO})
    @answers
    async def filing_read(params: FilingReadIn) -> str:
        """The text of a filing, a window of up to 6,000 characters from an offset — or, with `around`, the passages (up to 20) around each occurrence of a term (Q25). The text is the filing's own, kept by the worker; a
        filing whose text was not kept answers `no_data`. For the whole text the kernel's share is the door; for words across all filings use `filings_search`."""
        p = params
        f = await filing_ref(q, p.filing)
        meta = await q("SELECT char_length(t.text) AS n FROM mcp_filing_text t WHERE t.filing_id = $1", f["filing_id"])
        if not meta:
            raise Refusal("no_data", "The text of this filing was not kept (the worker reads the forms the watch names, and the issuer's own).")
        n = int(meta[0]["n"])
        base = {"filing_id": f["filing_id"], "form": f["form"], "filed_on": f["filed_on"], "char_count": n}
        if p.around:
            if n > SCAN_MAX:
                raise Refusal("too_large", f"This filing's text is {n:,} characters; scan it by window (offset, length) or use filings_search to find the passage.")
            text = (await q("SELECT t.text FROM mcp_filing_text t WHERE t.filing_id = $1", f["filing_id"]))[0]["text"]
            low, needle = text.lower(), p.around.strip().lower()
            passages, hits, pos = [], 0, 0
            while needle and (pos := low.find(needle, pos)) != -1:
                hits += 1
                if len(passages) < 20:
                    start = max(0, pos - 300)
                    passages.append({"offset": start, "text": text[start: start + 600 + len(needle)]})
                pos += max(1, len(needle))
            return detail(**base, offset=0, text=None, passages=passages, hits=hits)
        off0 = min(p.offset, max(0, n - 1))
        text = (await q("SELECT substr(t.text, $2::int, $3::int) AS w FROM mcp_filing_text t WHERE t.filing_id = $1", f["filing_id"], off0 + 1, p.length))[0]["w"]
        return detail(**base, offset=off0, text=text, next_offset=(off0 + p.length) if off0 + p.length < n else None)

    @mcp.tool(name="filings_search", annotations={"title": "Words across the filings", **RO})
    @answers
    async def filings_search(params: FilingsSearchIn) -> str:
        """Words across the text of every filing you may see, ranked, with the highlighted passage (Q25) — 'at-the-market', 'equity line', 'going concern'. The issuer wall is inside the function."""
        p = params
        if p.issuer not in (None, ""):
            ids = [(await issuer_row(q, p.issuer))["issuer_id"]]
        else:
            ids = [r["issuer_id"] for r in await q("SELECT issuer_id FROM mcp_issuers ORDER BY issuer_id LIMIT 20")]
        rows: list[dict] = []
        for i in ids:
            rows += await q("SELECT s.filing_id, s.form, s.filed_on, s.headline, s.rank, $1::bigint AS issuer_id FROM si_filings_search($1, $2, $3) s", i, p.q, lim(p.limit, 20))
        rows.sort(key=lambda r: (-(r["rank"] or 0), str(r["filed_on"])))
        return listing(rows[: lim(p.limit, 20) + 1], lim(p.limit, 20))

    @mcp.tool(name="watch_hits", annotations={"title": "The full-text watch's hits", **RO})
    @answers
    async def watch_hits(params: WatchHitsIn | None = None) -> str:
        """The hits of the full-text watch on dilution terms (at-the-market, equity line of credit, convertible …) in filings, newest first (Q28), each with the term, the snippet, the filing and whether a person confirmed it."""
        p = params or WatchHitsIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("h.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.status:
            w.add("h.status = {}", p.status)
        if p.since:
            w.add("h.found_at >= {}", when(p.since, "since"))
        rows = await page(q, f"SELECT h.watch_hit_id, h.issuer_id, h.filing_id, h.term, h.snippet, h.found_at, h.status FROM mcp_watch_hits h WHERE {w.sql()}", w.args, lim(p.limit), 0, "ORDER BY h.found_at DESC, h.watch_hit_id DESC")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="dilution_instruments", annotations={"title": "The dilution instruments", **RO})
    @answers
    async def dilution_instruments(params: DilutionIn | None = None) -> str:
        """The instruments that can dilute the shares (Q27) — an ATM, an equity line, a convertible, warrants, a registered direct — with the counterparty, the facility and what remains, the price terms, whether the price
        floats, the most shares it could issue, when it expires and its state. `q` alone resolves one (the registry's `instrument` resolver). Dismissed ones are left out unless asked."""
        p = params or DilutionIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("d.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.status:
            w.add("d.status = ANY ({}::text[])", list(p.status))
        elif not resolving(p):
            w.raw("d.status <> 'dismissed'")
        if p.kind:
            w.add("d.kind = {}", p.kind)
        if p.q:
            w.add("(d.kind ILIKE {0} OR coalesce(d.counterparty, '') ILIKE {0})", like(p.q))
        rows = await page(q, f"SELECT {INSTRUMENT_COLS} FROM mcp_dilution_instruments d WHERE {w.sql()}", w.args, lim(p.limit), 0,
                          "ORDER BY CASE d.status WHEN 'proposed' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, d.filed_on DESC NULLS LAST, d.instrument_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "label", "counterparty")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="dilution_proposals", annotations={"title": "Instruments nobody confirmed", **RO})
    @answers
    async def dilution_proposals(params: ProposedIn | None = None) -> str:
        """The instruments the full-text watch proposed and nobody has confirmed (Q28), with the term that raised each — a person confirms or dismisses, the watch never decides."""
        p = params or ProposedIn()
        w = Where().raw("d.status = 'proposed'")
        if p.issuer not in (None, ""):
            w.add("d.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        rows = await q(f"SELECT {INSTRUMENT_COLS}, d.watch_term FROM mcp_dilution_instruments d WHERE {w.sql()} ORDER BY d.filed_on DESC NULLS LAST, d.instrument_id DESC LIMIT 101", *w.args)
        return listing(rows, 100)
