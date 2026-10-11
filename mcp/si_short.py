"""The short side (Q29–Q33): docs/shareholder-intelligence-mcp-tool-surface.md "The short side". FINRA's short interest, FINRA's daily off-exchange short volume, the SEC's fails and the Reg SHO threshold lists, borrow
observations and prices. The db/011 and db/023 functions are SECURITY DEFINER without a wall of their own: every tool proves its security visible first. Every number keeps its date and its source; a missing day is
absent; nothing interpolates."""
from __future__ import annotations

import datetime
from typing import Literal

from pydantic import Field

from si_common import RO, Refusal, SecIn, answers, day, describe_models, detail, listing, security_row, span

FINRA_CAVEAT = "FINRA's daily short volume is off-exchange only and is not comparable with short interest; a high ratio is not a count of open short positions."
MAX_DAYS = 400


class WindowIn(SecIn):
    from_: str = Field(..., alias="from", description="First day of the window, YYYY-MM-DD")
    to: str = Field(..., description="Last day of the window, YYYY-MM-DD")


class OptWindowIn(SecIn):
    from_: str | None = Field(None, alias="from", description="Only on or after this day, YYYY-MM-DD")
    to: str | None = Field(None, description="Only on or before this day, YYYY-MM-DD")


class ShortVsFloatIn(SecIn):
    as_of: str | None = Field(None, description="The latest short interest on or before this day, YYYY-MM-DD; default today")


class ShortVolumeIn(WindowIn):
    market: Literal["CNMS", "FNQC", "FNRA", "FNSQ", "FNYX", "FORF"] = Field("CNMS", description="FINRA's reporting facility: CNMS (consolidated, default), FNQC, FNRA, FNSQ, FNYX or FORF")


describe_models(globals())


def window(p, default_days: int = 180) -> tuple[datetime.date, datetime.date]:
    a, b = span(p.from_, p.to)
    if (b - a).days > MAX_DAYS:
        raise Refusal("too_large", f"That window is {(b - a).days} days; ask for at most {MAX_DAYS}.", fields=["from", "to"])
    return a, b


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="short_interest_series", annotations={"title": "FINRA short interest", **RO})
    @answers
    async def short_interest_series(params: WindowIn) -> str:
        """FINRA's short interest by settlement date (Q29): the latest observation of each date, with the change, the average daily volume, days to cover, the share of the float and of the shares outstanding, whether
        FINRA revised it, and when we observed it. It is published twice a month, about a week after each settlement date; a date not published yet is absent."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = window(p)
        rows = await q("""SELECT settlement_date, short_shares, change_percent, avg_daily_volume, days_to_cover, revision_flag, pct_of_float, pct_of_outstanding, observed_at FROM si_short_interest_series($1, $2, $3)
                           ORDER BY settlement_date DESC""", sec["security_id"], a, b)
        return listing(rows, 400, security_id=sec["security_id"], source="FINRA consolidated short interest")

    @mcp.tool(name="short_vs_float", annotations={"title": "Short interest against the float", **RO})
    @answers
    async def short_vs_float(params: ShortVsFloatIn | None = None) -> str:
        """The latest short interest on a day as a share of the float and of the shares outstanding, with days to cover (Q29), the settlement date it is for and the float and outstanding counts it was divided by."""
        p = params or ShortVsFloatIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("SELECT settlement_date, short_shares, float_shares, outstanding, pct_of_float, pct_of_outstanding, days_to_cover, avg_daily_volume FROM si_short_vs_float($1, $2)", sec["security_id"], day(p.as_of, "as_of") or datetime.date.today())
        if not rows or rows[0]["settlement_date"] is None:
            raise Refusal("no_data", "FINRA has published no short interest for this security yet (or the float is not recorded).")
        return detail(security_id=sec["security_id"], **rows[0])

    @mcp.tool(name="daily_short_volume_series", annotations={"title": "FINRA's daily short volume", **RO})
    @answers
    async def daily_short_volume_series(params: ShortVolumeIn) -> str:
        """FINRA's daily off-exchange short volume of one reporting facility (Q30), with the short-exempt volume, the total volume, the ratio and its 5- and 20-day means. THE CAVEAT IS IN EVERY ANSWER: it is off-exchange
        only and is not short interest."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = window(p)
        rows = await q("SELECT trade_date, short_volume, short_exempt_volume, total_volume, ratio, mean_5, mean_20 FROM si_daily_short_volume_series($1, $2, $3, $4) WHERE trade_date >= $3 ORDER BY trade_date DESC", sec["security_id"], p.market, a, b)
        return listing(rows, 400, security_id=sec["security_id"], market=p.market, caveat=FINRA_CAVEAT)

    @mcp.tool(name="fails_series", annotations={"title": "Fails to deliver", **RO})
    @answers
    async def fails_series(params: WindowIn) -> str:
        """The SEC's fails-to-deliver by settlement date (Q31): the quantity, the price, the share of the shares outstanding and the 60-day median. Published twice a month in half-month files, about two weeks after each."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = window(p)
        rows = await q("SELECT settlement_date, quantity, price, pct_of_outstanding, median_60 FROM si_fails_series($1, $2, $3) ORDER BY settlement_date DESC", sec["security_id"], a, b)
        return listing(rows, 400, security_id=sec["security_id"], source="SEC fails-to-deliver")

    @mcp.tool(name="threshold_status", annotations={"title": "On a Reg SHO threshold list now", **RO})
    @answers
    async def threshold_status(params: SecIn | None = None) -> str:
        """Whether the security is on a Reg SHO threshold list now (Q31), which list, since when, and for how many consecutive list days — and every run on a list it has had (list, first day, last day, list days)."""
        p = params or SecIn()
        sec = await security_row(q, p.security, p.issuer)
        st = await q("SELECT on_list, list, since, consecutive_days, latest_list_date FROM si_threshold_status($1)", sec["security_id"])
        runs = await q("SELECT list, from_date, to_date, list_days, current FROM si_threshold_runs($1) ORDER BY from_date DESC LIMIT 20", sec["security_id"])
        row = st[0] if st else {"on_list": False, "list": None, "since": None, "consecutive_days": 0, "latest_list_date": None}
        return detail(security_id=sec["security_id"], **row, runs=runs)

    @mcp.tool(name="threshold_history", annotations={"title": "Every threshold-list day", **RO})
    @answers
    async def threshold_history(params: OptWindowIn | None = None) -> str:
        """Every day the security was on a threshold list, newest first (Q31): the date, the list (Nasdaq, NYSE, OTC) and the flags, including whether Rule 203(b)(3) close-out applies."""
        p = params or OptWindowIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("SELECT list_date, list, flag, rule_3210 FROM mcp_threshold_listings WHERE security_id = $1 AND ($2::date IS NULL OR list_date >= $2) AND ($3::date IS NULL OR list_date <= $3) ORDER BY list_date DESC LIMIT 401",
                       sec["security_id"], day(p.from_, "from"), day(p.to, "to"))
        return listing(rows, 400, security_id=sec["security_id"])

    @mcp.tool(name="borrow_series", annotations={"title": "Borrow observations", **RO})
    @answers
    async def borrow_series(params: OptWindowIn | None = None) -> str:
        """The borrow observations (Q32): the date, the source, the fee rate, the utilisation, the shares on loan and available — each typed by a person or a vendor feed, and marked `indicative` when it is a broker's quote
        rather than a vendor's data."""
        p = params or OptWindowIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("""SELECT observation_id, observed_on, source, source = 'broker_quote' AS indicative, fee_rate_percent, utilization_percent, shares_on_loan, shares_available, note FROM mcp_borrow_observations
                           WHERE security_id = $1 AND ($2::date IS NULL OR observed_on >= $2) AND ($3::date IS NULL OR observed_on <= $3) ORDER BY observed_on DESC, recorded_at DESC LIMIT 401""",
                       sec["security_id"], day(p.from_, "from"), day(p.to, "to"))
        return listing(rows, 400, security_id=sec["security_id"])

    @mcp.tool(name="short_side_overlay", annotations={"title": "Price, volume and the short side on one chart", **RO})
    @answers
    async def short_side_overlay(params: WindowIn) -> str:
        """Price, volume and the short-side series aligned by trading day for one chart (Q33): the close and volume, short interest as a share of the float on its settlement dates, days to cover, the fails, the 5-day
        off-exchange ratio, whether the day was on a threshold list, and the borrow fee and utilisation — a column is null on a day the series has no row."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = window(p)
        rows = await q("SELECT d, close, volume, short_pct_float, days_to_cover, fails, short_volume_ratio_5, on_threshold, borrow_fee, utilization FROM si_short_side_overlay($1, $2, $3) ORDER BY d", sec["security_id"], a, b)
        return listing(rows, 400, security_id=sec["security_id"], caveat=FINRA_CAVEAT)
