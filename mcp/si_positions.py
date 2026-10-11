"""The DTC positions and the reconciliation (Q3–Q10): docs/shareholder-intelligence-mcp-tool-surface.md "Uploads, positions and the reconciliation". The db/007 functions are SECURITY DEFINER without a wall of
their own, so every tool proves its security visible first (security_row → mcp_securities) and then hands it over — exactly what the screens do (app/features/positions/queries.php)."""
from __future__ import annotations

from pydantic import Field

from si_common import RO, Ref, Refusal, SecIn, answers, day, describe_models, detail, listing, lim, participant_row, security_row, span

LORE = "Participant flow by class is practitioner lore, with no published validation: it describes who held the shares at DTC, not why they moved."


class PositionsOnIn(SecIn):
    date: str | None = Field(None, description="A position date, YYYY-MM-DD; omitted: the latest the SPR has")
    class_: str | None = Field(None, alias="class", max_length=40, description="Only participants of this class (retail, prime, market_maker, lending …)")
    top: int = Field(25, ge=1, le=100, description="How many participants, largest first")


class WindowIn(SecIn):
    from_: str = Field(..., alias="from", description="First day of the window, YYYY-MM-DD")
    to: str = Field(..., description="Last day of the window, YYYY-MM-DD")


class ChangesIn(WindowIn):
    class_: str | None = Field(None, alias="class", max_length=40, description="Only participants of this class")
    limit: int = Field(25, ge=1, le=100, description="How many participants, by size of net change")


class ConcentrationIn(SecIn):
    date: str | None = Field(None, description="One position date, YYYY-MM-DD; omitted: the latest")
    from_: str | None = Field(None, alias="from", description="With `to`: a series over the window, a row per position date")
    to: str | None = Field(None, description="With `from`: the end of the series")


class SeriesIn(WindowIn):
    participant: Ref = Field(..., description="The participant (id, DTC number or name)")


class SprTotalIn(SecIn):
    date: str = Field(..., description="The position date, YYYY-MM-DD")


class HistoryIn(SecIn):
    from_: str | None = Field(None, alias="from", description="Only runs as of this day or later")
    to: str | None = Field(None, description="Only runs as of this day or earlier")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


describe_models(globals())

RECON = """r.reconciliation_id, r.security_id, r.as_of, r.outstanding, r.cede_registered, r.cede_source, r.register_date, r.spr_total, r.spr_date, r.drs_book, r.certificated, r.plan, r.registered_other,
           r.difference_registered, r.difference_spr, r.tolerance, r.tied_registered, r.tied_spr, r.tied, r.nobo_shares, r.nobo_date, r.obo_remainder, r.note, r.ran_at"""


async def latest_position_date(q, security_id: int):
    rows = await q("SELECT max(position_date) AS d FROM mcp_participant_positions WHERE security_id = $1", security_id)
    return rows[0]["d"] if rows else None


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="positions_on", annotations={"title": "Who held at DTC on a date", **RO})
    @answers
    async def positions_on(params: PositionsOnIn | None = None) -> str:
        """Who held at DTC on a date (Q4), ranked, with the class of each participant, its share of the DTC total and the change against the previous position date in the SPR. A day the SPR does not have is absent
        (`no_data`), never zero. Omit `date` for the latest."""
        p = params or PositionsOnIn()
        sec = await security_row(q, p.security, p.issuer)
        d = day(p.date, "date") or await latest_position_date(q, sec["security_id"])
        if d is None:
            raise Refusal("no_data", "No Security Position Report has been uploaded for this security yet; upload the DTC SPR first.")
        prev = (await q("SELECT max(position_date) AS d FROM mcp_participant_positions WHERE security_id = $1 AND position_date < $2", sec["security_id"], d))[0]["d"]
        total = (await q("SELECT si_spr_total($1, $2) AS t", sec["security_id"], d))[0]["t"]
        if total is None:
            raise Refusal("no_data", f"The SPR has no positions on {d.isoformat()}; a missing day is absent, not zero.")
        rows = await q("""SELECT pp.participant_id, pp.number, pp.name, pp.class_key, pp.shares, round(100.0 * pp.shares / NULLIF($3::numeric, 0), 2) AS pct_of_total,
                                 pp.shares - (SELECT x.shares FROM mcp_participant_positions x WHERE x.security_id = $1 AND x.participant_id = pp.participant_id AND x.position_date = $4) AS change_prev,
                                 ($4::date IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_participant_positions x WHERE x.security_id = $1 AND x.participant_id = pp.participant_id AND x.position_date = $4)) AS is_new
                            FROM mcp_participant_positions pp WHERE pp.security_id = $1 AND pp.position_date = $2 AND ($5::text IS NULL OR pp.class_key = $5) ORDER BY pp.shares DESC, pp.number LIMIT $6""",
                          sec["security_id"], d, total, prev, p.class_, p.top)
        participants = (await q("SELECT count(*) AS n FROM mcp_participant_positions WHERE security_id = $1 AND position_date = $2", sec["security_id"], d))[0]["n"]
        return detail(security_id=sec["security_id"], issuer_id=sec["issuer_id"], cusip=sec["cusip"], position_date=d, previous_date=prev, total=total, participants=participants, count=len(rows), rows=rows)

    @mcp.tool(name="position_changes", annotations={"title": "Each participant's movement over a window", **RO})
    @answers
    async def position_changes(params: ChangesIn) -> str:
        """Each participant's opening and closing position over a window, its net change, its largest one-day move and the day, and how many dates it was present (Q5) — largest net movers first. The positions are
        read from the SPR dates inside the window; nothing is interpolated between them."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = span(p.from_, p.to)
        rows = await q("""SELECT participant_id, number, name, class_key, open_shares, close_shares, net, largest_move, largest_move_date, days_present, open_date, close_date FROM si_position_changes($1, $2, $3)
                           WHERE ($4::text IS NULL OR class_key = $4) ORDER BY abs(net) DESC NULLS LAST, close_shares DESC NULLS LAST, number LIMIT $5""", sec["security_id"], a, b, p.class_, lim(p.limit) + 1)
        return listing(rows, lim(p.limit), security_id=sec["security_id"], window={"from": a, "to": b})

    @mcp.tool(name="participants_new_lost", annotations={"title": "Participants new to the file or gone", **RO})
    @answers
    async def participants_new_lost(params: WindowIn) -> str:
        """Participants that appear in the SPR inside the window but not before it (new) and those that were there and are gone by its end (lost), with their shares and the date (Q6)."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = span(p.from_, p.to)
        rows = await q("SELECT participant_id, number, name, class_key, state, shares, on_date FROM si_participants_new_lost($1, $2, $3) ORDER BY state, shares DESC NULLS LAST, number LIMIT 101", sec["security_id"], a, b)
        return listing(rows, 100, security_id=sec["security_id"], window={"from": a, "to": b})

    @mcp.tool(name="participant_flow", annotations={"title": "Net shares by participant class", **RO})
    @answers
    async def participant_flow(params: WindowIn) -> str:
        """Net shares by participant class (retail, prime, market maker, lending …) over a window, with the side each class is on and its share of the DTC total at the close (Q7). THE REPLY CARRIES THE CAVEAT: it is
        practitioner lore, with no published validation, when it is read as migration between classes."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = span(p.from_, p.to)
        rows = await q("SELECT class_key, side, open_shares, close_shares, net, share_of_total_close FROM si_participant_flow($1, $2, $3) ORDER BY class_key", sec["security_id"], a, b)
        return listing(rows, 100, security_id=sec["security_id"], window={"from": a, "to": b}, caveat=LORE)

    @mcp.tool(name="concentration", annotations={"title": "How concentrated the DTC positions are", **RO})
    @answers
    async def concentration(params: ConcentrationIn | None = None) -> str:
        """How concentrated the DTC positions are (Q8): the top 5, top 10 and top 20 participants' share of the total and the Herfindahl index — on one date (the latest by default) or, with `from` and `to`, as a series
        with a row per position date."""
        p = params or ConcentrationIn()
        sec = await security_row(q, p.security, p.issuer)
        if p.from_ and p.to:
            a, b = span(p.from_, p.to)
            dates = [r["d"] for r in await q("SELECT d FROM si_position_dates($1, $2, $3) d", sec["security_id"], a, b)]
            if len(dates) > 250:
                dates = dates[-250:]
            rows = []
            for d in dates:
                rows += await q("SELECT * FROM si_concentration($1, $2)", sec["security_id"], d)
            if not rows:
                raise Refusal("no_data", "The SPR has no positions in that window.")
            return listing(rows, 250, security_id=sec["security_id"])
        d = day(p.date, "date") or await latest_position_date(q, sec["security_id"])
        if d is None:
            raise Refusal("no_data", "No Security Position Report has been uploaded for this security yet.")
        rows = await q("SELECT * FROM si_concentration($1, $2)", sec["security_id"], d)
        if not rows:
            raise Refusal("no_data", f"The SPR has no positions on {d.isoformat()}.")
        return detail(security_id=sec["security_id"], **rows[0])

    @mcp.tool(name="participant_series", annotations={"title": "One participant's daily series", **RO})
    @answers
    async def participant_series(params: SeriesIn) -> str:
        """One participant's position on every SPR date in a window, with the change from the date before (Q9). A date the participant is not in the file is absent from the series."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        part = await participant_row(q, p.participant)
        a, b = span(p.from_, p.to)
        rows = await q("SELECT position_date, shares, change FROM si_participant_series($1, $2, $3, $4)", sec["security_id"], part["participant_id"], a, b)
        return listing(rows, 1000, security_id=sec["security_id"], participant={"participant_id": part["participant_id"], "number": part["number"], "name": part["name"], "class_key": part["class_key"]})

    @mcp.tool(name="spr_total", annotations={"title": "What the SPR implies Cede holds on a date", **RO})
    @answers
    async def spr_total(params: SprTotalIn) -> str:
        """The sum of every participant's position on a date — what the SPR implies DTC (Cede & Co) holds (Q10). A day the SPR does not have answers `no_data`, never zero."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        d = day(p.date, "date")
        total = (await q("SELECT si_spr_total($1, $2) AS t", sec["security_id"], d))[0]["t"]
        if total is None:
            raise Refusal("no_data", f"The SPR has no positions on {d.isoformat()}; a missing day is absent, not zero.")
        return detail(security_id=sec["security_id"], position_date=d, total=total)

    @mcp.tool(name="reconciliation_latest", annotations={"title": "Does it tie", **RO})
    @answers
    async def reconciliation_latest(params: SecIn | None = None) -> str:
        """Does it tie (Q3, Q10): the shares outstanding against the register's components (Cede, DRS and book, certificated, plan, others) and Cede against what the SPR implies, with each difference, the tolerance and which
        of the two tied. A failure to tie is a data defect before it is anything else. `no_data` until a reconciliation has been run."""
        p = params or SecIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q(f"SELECT {RECON} FROM mcp_share_reconciliations r WHERE r.security_id = $1 ORDER BY r.as_of DESC, r.ran_at DESC LIMIT 1", sec["security_id"])
        if not rows:
            raise Refusal("no_data", "No reconciliation has been run for this security; it needs the register and the SPR uploaded and tied.")
        return detail(**rows[0])

    @mcp.tool(name="reconciliation_history", annotations={"title": "Every reconciliation run", **RO})
    @answers
    async def reconciliation_history(params: HistoryIn | None = None) -> str:
        """Every reconciliation run of a security, newest first (Q3), each with its components, differences and whether it tied."""
        p = params or HistoryIn()
        sec = await security_row(q, p.security, p.issuer)
        a, b = day(p.from_, "from"), day(p.to, "to")
        rows = await q(f"""SELECT {RECON} FROM mcp_share_reconciliations r WHERE r.security_id = $1 AND ($2::date IS NULL OR r.as_of >= $2) AND ($3::date IS NULL OR r.as_of <= $3)
                           ORDER BY r.as_of DESC, r.ran_at DESC LIMIT {lim(p.limit) + 1}""", sec["security_id"], a, b)
        return listing(rows, lim(p.limit), security_id=sec["security_id"])
