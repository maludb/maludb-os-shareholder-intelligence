"""Institutions, beneficial owners, insiders (Q19–Q23): docs/shareholder-intelligence-mcp-tool-surface.md "Institutions, beneficial owners, insiders". These are the PUBLIC record — 13F, 13D/13G, Forms 3/4/5 and 144 —
so no tool here holds a natural person of the register; the holdings and filings sit behind the issuer wall (the views), and the db/010 functions (SECURITY DEFINER, no wall of their own) are handed a security only
after mcp_securities has shown it."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from si_common import (RO, Ref, Refusal, SecIn, Where, _Base, answers, day, describe_models, detail, exact_first, is_int, issuer_row, like, listing, lim, not_found, off, page, resolving, security_row, span)

INSTITUTION_KINDS = Literal["investment_adviser", "hedge_fund", "bank", "insurance", "pension", "mutual_fund", "etf_sponsor", "activist", "individual", "corporation", "family_office", "other"]


class FindInstitutionsIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of a name, an old name or a CIK; alone it resolves one institution")
    kind: INSTITUTION_KINDS | None = Field(None, description="The kind of institution")
    activist: bool | None = Field(None, description="true: only the ones known as activists")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class InstitutionIn(_Base):
    institution: Ref = Field(..., description="The institution (id, CIK or name)")
    issuer: Ref | None = Field(None, description="Narrows a ticker")
    security: Ref | None = Field(None, description="Only its holdings in this security (id, CUSIP or ticker)")


class InstitutionHistoryIn(SecIn):
    institution: Ref = Field(..., description="The institution (id, CIK or name)")


class PeriodIn(SecIn):
    period: str | None = Field(None, description="A quarter end, YYYY-MM-DD; omitted: the latest 13F quarter")


class QuarterIn(PeriodIn):
    state: Literal["new", "out", "up", "down", "same"] | None = Field(None, description="Only institutions in this state against the prior quarter")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class HoldersIn(PeriodIn):
    limit: int = Field(25, ge=1, le=100, description="How many holders, largest first")


class BeneficialFilingIn(_Base):
    filing: int | None = Field(None, ge=1, description="The 13D/13G filing (id)")
    beneficial_id: int | None = Field(None, ge=1, description="Or the beneficial-ownership row (id)")


class InsiderTransactionsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer (id, ticker, CIK or name)")
    from_: str = Field(..., alias="from", description="First transaction day, YYYY-MM-DD")
    to: str = Field(..., description="Last transaction day, YYYY-MM-DD")
    owner: str | None = Field(None, max_length=100, description="One reporting owner: a CIK or part of a name")
    code: str | None = Field(None, max_length=2, description="A transaction code (P purchase, S sale, A award, M exercise, F tax withholding, G gift …)")
    summary: bool = Field(False, description="true: a row per person with the sales, buys and awards of the window instead of every transaction")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class Form144In(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer (id, ticker, CIK or name)")
    from_: str = Field(..., alias="from", description="First filing day, YYYY-MM-DD")
    to: str = Field(..., description="Last filing day, YYYY-MM-DD")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


describe_models(globals())


async def institution_ref(q, v) -> dict:
    if v in (None, ""):
        raise Refusal("invalid_argument", "Name the institution (its id, its CIK or its name).", fields=["institution"])
    s = str(v).strip()
    if is_int(v) and len(s) < 7:
        rows = await q("SELECT * FROM mcp_institutions WHERE institution_id = $1", int(s))
        if rows:
            return rows[0]
    digits = "".join(c for c in s if c.isdigit())
    rows = await q("SELECT * FROM mcp_institutions WHERE ($1 <> '' AND cik = lpad($1, 10, '0')) OR lower(name) = lower($2) OR EXISTS (SELECT 1 FROM unnest(aliases) a WHERE lower(a) = lower($2)) ORDER BY institution_id LIMIT 7", digits if s.isdigit() else "", s)
    if not rows:
        rows = await q("SELECT * FROM mcp_institutions WHERE name ILIKE $1 OR EXISTS (SELECT 1 FROM unnest(aliases) a WHERE a ILIKE $1) ORDER BY name LIMIT 7", like(s))
    if len(rows) > 1:
        from si_common import ambiguous
        raise ambiguous("institution", s, rows, "institution_id", "name")
    if rows:
        return rows[0]
    raise not_found("institution")


async def quarter(q, security_id: int, given: str | None):
    d = day(given, "period")
    if d is not None:
        return d
    rows = await q("SELECT max(period_end) AS d FROM mcp_institutional_holdings WHERE security_id = $1", security_id)
    if not rows or rows[0]["d"] is None:
        raise Refusal("no_data", "No 13F quarter has been loaded for this security yet; the SEC's 13F data sets are read each quarter.")
    return rows[0]["d"]


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_institutions", annotations={"title": "13F and 13D/13G filers", **RO})
    @answers
    async def find_institutions(params: FindInstitutionsIn | None = None) -> str:
        """Institutions — 13F managers and 13D/13G filers — by name, an old name, CIK or kind (Q22). `q` alone resolves one (the registry's `institution` resolver). An institution belongs to the whole estate; what it
        holds in an issuer's security is behind that issuer's wall (`get_institution`)."""
        p = params or FindInstitutionsIn()
        w = Where()
        if p.q:
            digits = "".join(c for c in p.q if c.isdigit())
            w.add("(i.name ILIKE {0} OR EXISTS (SELECT 1 FROM unnest(i.aliases) a WHERE a ILIKE {0}) OR ({1} <> '' AND i.cik = lpad({1}, 10, '0')))", like(p.q), digits if p.q.strip().isdigit() else "")
        if p.kind:
            w.add("i.kind = {}", p.kind)
        if p.activist is not None:
            w.add("i.is_activist = {}", p.activist)
        rows = await page(q, f"SELECT i.institution_id, i.cik, i.name, i.kind, i.is_activist, i.aliases, i.tags FROM mcp_institutions i WHERE {w.sql()}", w.args, lim(p.limit), 0, "ORDER BY i.name, i.institution_id")
        if resolving(p):
            rows = exact_first(rows, p.q, "name", "cik")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_institution", annotations={"title": "One institution", **RO})
    @answers
    async def get_institution(params: InstitutionIn) -> str:
        """One institution (Q22): its kind, its holdings in the securities you may see by quarter (puts and calls apart), its 13D/13G history and the filings it made about the issuers you may see. Give a security to narrow
        the holdings to it."""
        p = params
        inst = await institution_ref(q, p.institution)
        sec = (await security_row(q, p.security, p.issuer))["security_id"] if p.security not in (None, "") else None
        holdings = await q("""SELECT h.security_id, h.period_end, h.put_call, sum(h.shares) AS shares, sum(h.value_dollars) AS value_dollars, max(h.accession_number) AS accession_number, bool_or(h.is_amendment) AS amended
                                FROM mcp_institutional_holdings h WHERE h.institution_id = $1 AND ($2::bigint IS NULL OR h.security_id = $2) GROUP BY h.security_id, h.period_end, h.put_call
                               ORDER BY h.period_end DESC, h.security_id, h.put_call NULLS FIRST LIMIT 60""", inst["institution_id"], sec)
        beneficial = await q("""SELECT beneficial_id, security_id, filing_id, schedule, is_amendment, amendment_no, filed_on, event_date, percent_of_class, shares_beneficially_owned FROM mcp_beneficial_ownership
                                 WHERE institution_id = $1 AND ($2::bigint IS NULL OR security_id = $2) ORDER BY filed_on DESC LIMIT 30""", inst["institution_id"], sec)
        filings = await q("""SELECT filing_id, issuer_id, form, filed_on, accession_number, url FROM mcp_filings WHERE filer_cik = $1 AND ($2::bigint IS NULL OR issuer_id = (SELECT issuer_id FROM mcp_securities WHERE security_id = $2))
                              ORDER BY filed_on DESC LIMIT 30""", inst["cik"], sec)
        return detail(institution={k: inst[k] for k in ("institution_id", "cik", "name", "kind", "kind_source", "is_activist", "aliases", "website", "tags")}, holdings=holdings, beneficial=beneficial, filings=filings)

    @mcp.tool(name="institution_history", annotations={"title": "An institution's shares by quarter", **RO})
    @answers
    async def institution_history(params: InstitutionHistoryIn) -> str:
        """One institution's shares in a security by quarter (Q22), oldest first, puts and calls kept apart, with the value reported and the filing."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        inst = await institution_ref(q, p.institution)
        rows = await q("""SELECT period_end, put_call, sum(shares) AS shares, sum(value_dollars) AS value_dollars, max(filing_id) AS filing_id FROM mcp_institutional_holdings WHERE institution_id = $1 AND security_id = $2
                           GROUP BY period_end, put_call ORDER BY period_end, put_call NULLS FIRST""", inst["institution_id"], sec["security_id"])
        return listing(rows, 200, security_id=sec["security_id"], institution={"institution_id": inst["institution_id"], "name": inst["name"]})

    @mcp.tool(name="institutional_holders", annotations={"title": "The 13F holders of a quarter", **RO})
    @answers
    async def institutional_holders(params: HoldersIn | None = None) -> str:
        """The 13F holders of a quarter, ranked by shares (Q19), with the value reported, voting authority and the share of the shares outstanding. Omit the quarter for the latest. A 13F is filed up to 45 days after
        the quarter, so the latest quarter is always some weeks old."""
        p = params or HoldersIn()
        sec = await security_row(q, p.security, p.issuer)
        d = await quarter(q, sec["security_id"], p.period)
        rows = await q("SELECT institution_id, name, kind, shares, value_dollars, voting_sole, pct_of_outstanding FROM si_13f_changes($1, $2) WHERE state <> 'out' ORDER BY shares DESC NULLS LAST, name LIMIT $3", sec["security_id"], d, lim(p.limit) + 1)
        return listing(rows, lim(p.limit), security_id=sec["security_id"], period_end=d)

    @mcp.tool(name="holdings_changes", annotations={"title": "13F quarter against the prior", **RO})
    @answers
    async def holdings_changes(params: QuarterIn | None = None) -> str:
        """The quarter's 13F holders against the prior quarter's (Q19): new, out, up, down, same, with the shares from and to and the net."""
        p = params or QuarterIn()
        sec = await security_row(q, p.security, p.issuer)
        d = await quarter(q, sec["security_id"], p.period)
        rows = await q("SELECT institution_id, name, kind, shares, prior_shares, net, state FROM si_13f_changes($1, $2) WHERE ($3::text IS NULL OR state = $3) ORDER BY abs(net) DESC NULLS LAST, name LIMIT $4", sec["security_id"], d, p.state, lim(p.limit) + 1)
        return listing(rows, lim(p.limit), security_id=sec["security_id"], period_end=d)

    @mcp.tool(name="holdings_summary", annotations={"title": "The quarter's 13F totals", **RO})
    @answers
    async def holdings_summary(params: PeriodIn | None = None) -> str:
        """The quarter's 13F totals (Q19): how many institutions hold it, the shares and value, the share of the shares outstanding, the top ten's share, and the puts and calls reported."""
        p = params or PeriodIn()
        sec = await security_row(q, p.security, p.issuer)
        d = await quarter(q, sec["security_id"], p.period)
        rows = await q("SELECT period_end, holders, shares, value_dollars, pct_of_outstanding, top10_pct, puts_shares, calls_shares FROM si_13f_summary($1, $2)", sec["security_id"], d)
        if not rows or rows[0]["period_end"] is None:
            raise Refusal("no_data", f"No 13F rows for the quarter ending {d.isoformat()}.")
        return detail(security_id=sec["security_id"], **rows[0])

    @mcp.tool(name="holdings_put_call", annotations={"title": "Puts and calls in the 13Fs", **RO})
    @answers
    async def holdings_put_call(params: PeriodIn | None = None) -> str:
        """The puts and calls reported on the security in the quarter's 13Fs (Q23): which institution, the option side, the shares it covers and the value."""
        p = params or PeriodIn()
        sec = await security_row(q, p.security, p.issuer)
        d = await quarter(q, sec["security_id"], p.period)
        rows = await q("""SELECT h.institution_id, h.institution_name, h.period_end, h.put_call, sum(h.shares) AS shares, sum(h.value_dollars) AS value_dollars FROM mcp_institutional_holdings h
                           WHERE h.security_id = $1 AND h.period_end = $2 AND h.put_call IS NOT NULL GROUP BY h.institution_id, h.institution_name, h.period_end, h.put_call ORDER BY sum(h.shares) DESC LIMIT 101""", sec["security_id"], d)
        return listing(rows, 100, security_id=sec["security_id"], period_end=d)

    @mcp.tool(name="beneficial_owners", annotations={"title": "The 5% holders", **RO})
    @answers
    async def beneficial_owners(params: SecIn | None = None) -> str:
        """The 5% holders (Q20): the latest 13D or 13G of each filer, with the percent of the class, the shares, the filing date, the event date and the purpose (13D). The public record, largest first."""
        p = params or SecIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("""SELECT institution_id, name, kind, schedule, percent_of_class, shares, filed_on, event_date, purpose_text, filing_id FROM si_beneficial_owners($1)
                           ORDER BY percent_of_class DESC NULLS LAST, filed_on DESC LIMIT 101""", sec["security_id"])
        return listing(rows, 100, security_id=sec["security_id"])

    @mcp.tool(name="get_beneficial_filing", annotations={"title": "One 13D or 13G in full", **RO})
    @answers
    async def get_beneficial_filing(params: BeneficialFilingIn) -> str:
        """One Schedule 13D or 13G in full (Q20): the filer, the schedule and amendment number, the dates, the percent of the class, the voting and dispositive powers on the cover, the group members and the purpose."""
        p = params
        if (p.filing is None) == (p.beneficial_id is None):
            raise Refusal("invalid_argument", "Give exactly one of filing or beneficial_id.", fields=["filing", "beneficial_id"])
        col, val = ("filing_id", p.filing) if p.filing else ("beneficial_id", p.beneficial_id)
        rows = await q(f"""SELECT beneficial_id, institution_id, institution_name, security_id, filing_id, schedule, is_amendment, amendment_no, filed_on, event_date, percent_of_class, shares_beneficially_owned, sole_voting,
                                  shared_voting, sole_dispositive, shared_dispositive, purpose_text, group_members, filer_type FROM mcp_beneficial_ownership WHERE {col} = $1 ORDER BY filed_on DESC LIMIT 1""", val)
        if not rows:
            raise not_found("13D or 13G filing")
        return detail(**rows[0])

    @mcp.tool(name="insider_transactions", annotations={"title": "Forms 3, 4 and 5", **RO})
    @answers
    async def insider_transactions(params: InsiderTransactionsIn) -> str:
        """Insiders' transactions (Forms 3, 4 and 5) in a window (Q21), by person, code or issuer, newest first — or, with `summary`, a row per person with the sales, buys and awards and what each owned after the last. The
        public record; 10b5-1 plans and derivative lines are flagged."""
        p = params
        a, b = span(p.from_, p.to)
        n, o = lim(p.limit, 50), off(p.offset)
        if p.summary:
            iss = await issuer_row(q, p.issuer)
            rows = await q("SELECT reporting_owner_cik, reporting_owner_name, sales, sale_shares, buys, buy_shares, awards, last_owned_after FROM si_insider_summary($1, $2, $3) ORDER BY sale_shares DESC NULLS LAST, reporting_owner_name LIMIT $4",
                           iss["issuer_id"], a, b, n + 1)
            return listing(rows, n, issuer_id=iss["issuer_id"])
        w = Where().add("t.transaction_date BETWEEN {} AND {}", a, b)
        if p.issuer not in (None, ""):
            w.add("t.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.owner:
            w.add("(t.reporting_owner_cik = {0} OR t.reporting_owner_name ILIKE {1})", p.owner, like(p.owner))
        if p.code:
            w.add("t.transaction_code = {}", p.code.upper())
        rows = await page(q, f"""SELECT t.transaction_id, t.issuer_id, t.form, t.reporting_owner_cik, t.reporting_owner_name, t.relationship, t.officer_title, t.transaction_date, t.transaction_code, t.shares, t.price, t.acquired_disposed,
                                        t.shares_owned_after, t.ownership, t.is_derivative, t.is_10b5_1, count(*) OVER () AS total FROM mcp_insider_transactions t WHERE {w.sql()}""", w.args, n, o, "ORDER BY t.transaction_date DESC NULLS LAST, t.transaction_id DESC")
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        return listing(rows, n, total=total)

    @mcp.tool(name="form144_notices", annotations={"title": "Form 144 notices", **RO})
    @answers
    async def form144_notices(params: Form144In) -> str:
        """Form 144 notices of proposed sales by insiders and affiliates in a window (Q21), newest first: the seller, the relationship, the shares to sell, the aggregate market value, the approximate sale date and the broker."""
        p = params
        a, b = span(p.from_, p.to)
        w = Where().add("n.filed_on BETWEEN {} AND {}", a, b)
        if p.issuer not in (None, ""):
            w.add("n.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        rows = await page(q, f"""SELECT n.notice_id, n.issuer_id, n.filed_on, n.seller_name, n.seller_cik, n.relationship, n.shares_to_sell, n.aggregate_market_value, n.approx_sale_date, n.broker FROM mcp_form144_notices n WHERE {w.sql()}""",
                          w.args, lim(p.limit, 50), 0, "ORDER BY n.filed_on DESC, n.notice_id DESC")
        return listing(rows, lim(p.limit, 50))
