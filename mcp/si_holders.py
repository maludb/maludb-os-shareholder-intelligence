"""Holders (Q11–Q18): docs/shareholder-intelligence-mcp-tool-surface.md "Holders". THE PII WALL: a natural person's name, address and registration lines are an Analyst's (si_sees_holder_pii()); mcp_holders
shows a Viewer the row nulled and the name "Individual holder", and the raw rows, the matcher's proposals and the queue answer a Viewer nothing. The aggregate functions (si_holders_by_*, si_obo_remainder,
si_share_ranges …) are SECURITY DEFINER without a wall of their own, so each tool proves the security visible first (security_row), as app/features/holders/queries.php does."""
from __future__ import annotations

import datetime
from typing import Literal

from pydantic import Field

from si_common import (RO, Ref, Refusal, SecIn, Where, _Base, answers, day, describe_models, detail, exact_first, holder_row, issuer_row, like, listing, lim, member_ref, off, page, participant_row, pii, pii_withheld, resolving, security_row)

CHANNELS = Literal["nobo", "register"]


class FindHoldersIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's holders (id, ticker, CIK or name)")
    channel: CHANNELS | None = Field(None, description="nobo (the NOBO list) or register (the transfer agent's register)")
    record_date: str | None = Field(None, description="Only holders on that date's list, YYYY-MM-DD")
    kind: Literal["person", "entity", "custodian_account", "unknown"] | None = Field(None, description="The kind of holder")
    custodian: Ref | None = Field(None, description="Only holders with an account at this custodian (a participant: id, number or name)")
    account_kind: str | None = Field(None, max_length=40, description="Only holders with an account of this kind (individual, joint, trust, ira, entity, street_name …)")
    min_shares: int | None = Field(None, ge=0, description="Only holders with at least this many shares (on the record date, else the last list)")
    tag: str | None = Field(None, max_length=60, description="Only holders carrying this tag")
    q: str | None = Field(None, max_length=100, description="Part of a name or a city (a natural person is searched by an Analyst only)")
    sort: Literal["shares", "name", "last_seen"] = Field("shares", description="shares (largest first), name or last_seen")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class HolderIn(_Base):
    holder: int = Field(..., ge=1, description="The holder (id)")


class ChangesIn(SecIn):
    channel: CHANNELS = Field(..., description="Compare two NOBO lists or two registers")
    date_a: str = Field(..., description="The earlier record date, YYYY-MM-DD")
    date_b: str = Field(..., description="The later record date, YYYY-MM-DD")
    state: Literal["new", "lost", "up", "down", "same"] | None = Field(None, description="Only holders in this state")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class RangeIn(SecIn):
    channel: CHANNELS = Field(..., description="nobo or register")
    record_date: str | None = Field(None, description="A record date, YYYY-MM-DD; omitted: the latest of the channel")


class RecordDateIn(SecIn):
    record_date: str | None = Field(None, description="A record date, YYYY-MM-DD; omitted: the latest")


class UnmatchedIn(_Base):
    upload: int | None = Field(None, ge=1, description="Only the rows of this upload (id)")
    issuer: Ref | None = Field(None, description="Only this issuer's rows")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class ProposalsIn(_Base):
    upload: int | None = Field(None, ge=1, description="Only the proposals about this upload's rows (id)")
    status: Literal["proposed", "accepted", "rejected", "auto"] | None = Field(None, description="Only proposals in this state")
    proposed_by: Ref | None = Field(None, description="Only this proposer (a member: id, name or me), or `matcher` for the matcher's own")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class OboRemainderIn(SecIn):
    record_date: str = Field(..., description="The NOBO record date, YYYY-MM-DD")


class ParticipantNoboIn(SecIn):
    participant: Ref = Field(..., description="The participant (id, DTC number or name)")


class InsiderHoldingsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's insiders")


describe_models(globals())


async def record_dates(q, security_id: int, channel: str) -> list:
    if channel == "nobo":
        rows = await q("SELECT DISTINCT record_date AS d FROM mcp_nobo_jobs WHERE security_id = $1 ORDER BY 1 DESC", security_id)
    else:
        rows = await q("SELECT DISTINCT record_date AS d FROM mcp_imports WHERE security_id = $1 AND kind = 'register' AND status = 'done' AND NOT deleted ORDER BY 1 DESC", security_id)
    return [r["d"] for r in rows]


async def date_or_latest(q, security_id: int, channel: str, given: str | None, what: str = "record_date") -> datetime.date:
    d = day(given, what)
    if d is not None:
        return d
    dates = await record_dates(q, security_id, channel)
    if not dates:
        raise Refusal("no_data", f"No {'NOBO list' if channel == 'nobo' else 'register'} has been uploaded for this security yet.")
    return dates[0]


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_holders", annotations={"title": "Holders", **RO})
    @answers
    async def find_holders(params: FindHoldersIn | None = None) -> str:
        """An issuer's holders, from the NOBO list and the register as one identity each (Q11, Q12): by channel, record date, kind, custodian, account kind, minimum shares, tag or words. `q` alone resolves one holder. THE PII WALL:
        a natural person is 'Individual holder' with no address (the zip cut to three digits) unless you are an Analyst — and a Viewer's search finds entities only. Aggregates by type, range or custodian never show a person."""
        p = params or FindHoldersIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("h.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        shares = "h.last_shares"
        if p.record_date or p.channel:
            parts = []
            if p.channel:
                parts.append("hp.channel = " + w.p(p.channel))
            if p.record_date:
                parts.append("hp.record_date = " + w.p(day(p.record_date, "record_date")))
            cond = " AND ".join(parts)
            w.raw(f"EXISTS (SELECT 1 FROM mcp_holder_positions hp WHERE hp.holder_id = h.holder_id AND {cond})")
            if p.record_date:
                shares = f"(SELECT sum(hp.shares) FROM mcp_holder_positions hp WHERE hp.holder_id = h.holder_id AND {cond})"
        if p.kind:
            w.add("h.kind = {}", p.kind)
        if p.custodian not in (None, ""):
            w.add("EXISTS (SELECT 1 FROM mcp_holder_accounts a WHERE a.holder_id = h.holder_id AND a.custodian_participant_id = {})", (await participant_row(q, p.custodian))["participant_id"])
        if p.account_kind:
            w.add("EXISTS (SELECT 1 FROM mcp_holder_accounts a WHERE a.holder_id = h.holder_id AND a.account_kind = {})", p.account_kind)
        if p.min_shares is not None:
            w.raw(f"{shares} >= " + w.p(p.min_shares))
        if p.tag:
            w.add("{} = ANY (h.tags)", p.tag)
        if p.q:
            w.add("(h.display_name ILIKE {0} OR coalesce(h.city, '') ILIKE {0})", like(p.q))
        order = {"shares": f"{shares} DESC NULLS LAST, h.holder_id", "name": "h.display_name, h.holder_id", "last_seen": "h.last_seen_on DESC NULLS LAST, h.holder_id"}[p.sort]
        rows = await page(q, f"""SELECT h.holder_id, h.issuer_id, h.display_name, h.kind, h.entity_type, h.city, h.state, h.zip, h.is_insider, h.institution_id, h.last_seen_on, {shares} AS last_shares, h.accounts_count, h.tags, h.pii_withheld,
                                        count(*) OVER () AS total FROM mcp_holders h WHERE {w.sql()}""", w.args, lim(p.limit), off(p.offset), "ORDER BY " + order)
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        if resolving(p):
            rows = exact_first(rows, p.q, "display_name")
        return listing(rows, lim(p.limit), total=total)

    @mcp.tool(name="get_holder", annotations={"title": "One holder", **RO})
    @answers
    async def get_holder(params: HolderIn) -> str:
        """One holder (Q15): the identity, every account (the registration as read, the custodian, the account kind), the shares by record date and channel, the insider and institution links and how many notes it carries. A
        natural person is an Analyst's — everyone else is told `pii_withheld` (an entity is open to anyone who may see the issuer)."""
        h = await holder_row(q, params.holder, need_pii=True)
        accounts = await q("""SELECT a.holder_account_id, a.channel, a.custodian_participant_id, a.custodian_name, a.account_kind, a.registration_text, a.holder_ref, a.first_seen_on, a.last_seen_on,
                                     (SELECT hp.shares FROM mcp_holder_positions hp WHERE hp.holder_account_id = a.holder_account_id ORDER BY hp.record_date DESC LIMIT 1) AS last_shares,
                                     (SELECT hp.record_date FROM mcp_holder_positions hp WHERE hp.holder_account_id = a.holder_account_id ORDER BY hp.record_date DESC LIMIT 1) AS last_date
                                FROM mcp_holder_accounts a WHERE a.holder_id = $1 ORDER BY a.channel, a.last_seen_on DESC NULLS LAST, a.holder_account_id""", h["holder_id"])
        history = await q("SELECT record_date, channel, sum(shares) AS shares, count(*) AS accounts FROM mcp_holder_positions WHERE holder_id = $1 GROUP BY record_date, channel ORDER BY record_date, channel", h["holder_id"])
        insider = None
        if h["is_insider"] and h["insider_cik"]:
            rows = await q("""SELECT reporting_owner_cik, reporting_owner_name, relationship, officer_title, transaction_date, shares_owned_after FROM mcp_insider_transactions WHERE issuer_id = $1 AND reporting_owner_cik = $2 AND NOT is_derivative
                              ORDER BY transaction_date DESC NULLS LAST, transaction_id DESC LIMIT 1""", h["issuer_id"], h["insider_cik"])
            insider = rows[0] if rows else {"reporting_owner_cik": h["insider_cik"]}
        institution = None
        if h["institution_id"]:
            rows = await q("SELECT institution_id, cik, name, kind, is_activist FROM mcp_institutions WHERE institution_id = $1", h["institution_id"])
            institution = rows[0] if rows else None
        notes = (await q("SELECT count(*) AS n FROM mcp_notes WHERE record_type = 'holder' AND record_id = $1", h["holder_id"]))[0]["n"]
        holder = {k: h[k] for k in ("holder_id", "issuer_id", "display_name", "kind", "entity_type", "address_line", "city", "state", "country", "zip", "is_insider", "first_seen_on", "last_seen_on", "accounts_count", "last_shares", "notes", "tags", "pii_withheld")}
        return detail(holder=holder, accounts=accounts, history=history, insider=insider, institution=institution, notes_count=notes)

    @mcp.tool(name="holder_history", annotations={"title": "A holder's shares over time", **RO})
    @answers
    async def holder_history(params: HolderIn) -> str:
        """One holder's shares by record date, account and channel (Q15), oldest first. A natural person's history is an Analyst's (pii_withheld otherwise)."""
        h = await holder_row(q, params.holder, need_pii=True)
        rows = await q("SELECT record_date, channel, holder_account_id, account_kind, custodian_participant_id, security_id, shares FROM si_holder_history($1) ORDER BY record_date, channel, holder_account_id", h["holder_id"])
        return listing(rows, 1000, holder_id=h["holder_id"])

    @mcp.tool(name="holders_changes", annotations={"title": "Holders between two record dates", **RO})
    @answers
    async def holders_changes(params: ChangesIn) -> str:
        """Holders between two record dates of one channel: new, lost, up, down, same (Q13). An Analyst gets a row per holder with its name and shares at each date; a Viewer gets the counts and shares by state (never a
        holder) and, beside them, the entities by name — a natural person is never listed."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        a, b = day(p.date_a, "date_a"), day(p.date_b, "date_b")
        n, o = lim(p.limit), off(p.offset)
        if await pii(q):
            rows = await q("""SELECT c.holder_id, h.display_name, h.kind, c.state, c.shares_a, c.shares_b, c.net, count(*) OVER () AS total FROM si_holders_changes($1, $2, $3, $4) c JOIN mcp_holders h ON h.holder_id = c.holder_id
                               WHERE ($5::text IS NULL OR c.state = $5) ORDER BY abs(c.net) DESC, c.holder_id LIMIT $6 OFFSET $7""", sec["security_id"], p.channel, a, b, p.state, n + 1, o)
            total = int(rows[0]["total"]) if rows else 0
            for r in rows:
                r.pop("total", None)
            return listing(rows, n, total=total, security_id=sec["security_id"], summary=await q("SELECT * FROM si_holders_changes_summary($1, $2, $3, $4)", sec["security_id"], p.channel, a, b))
        summary = await q("SELECT state, holders, shares_a, shares_b, net FROM si_holders_changes_summary($1, $2, $3, $4)", sec["security_id"], p.channel, a, b)
        entities = await q("""SELECT c.holder_id, h.display_name, h.kind, c.state, c.shares_a, c.shares_b, c.net FROM si_holders_changes($1, $2, $3, $4) c JOIN mcp_holders h ON h.holder_id = c.holder_id
                               WHERE NOT h.pii_withheld AND h.kind <> 'person' AND ($5::text IS NULL OR c.state = $5) ORDER BY abs(c.net) DESC, c.holder_id LIMIT 50""", sec["security_id"], p.channel, a, b, p.state)
        if p.state:
            summary = [r for r in summary if r["state"] == p.state]
        return listing(summary, 20, security_id=sec["security_id"], entities=entities)

    @mcp.tool(name="holders_by_range", annotations={"title": "Holders by share range", **RO})
    @answers
    async def holders_by_range(params: RangeIn) -> str:
        """Holders by share range on a record date of a list (Q14): how many holders and how many shares sit in each range, and each range's share of the total. No holder is named."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        d = await date_or_latest(q, sec["security_id"], p.channel, p.record_date)
        rows = await q("SELECT range_label, range_from, holders, shares, share_pct FROM si_holders_by_range($1, $2, $3) ORDER BY range_from", sec["security_id"], p.channel, d)
        return listing(rows, 100, security_id=sec["security_id"], channel=p.channel, record_date=d)

    @mcp.tool(name="holders_by_geography", annotations={"title": "Holders by country and state", **RO})
    @answers
    async def holders_by_geography(params: RangeIn) -> str:
        """Holders by country and state on a record date of a list (Q14): how many holders and shares in each place. No holder is named."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        d = await date_or_latest(q, sec["security_id"], p.channel, p.record_date)
        rows = await q("SELECT country, state, holders, shares FROM si_holders_by_geography($1, $2, $3) ORDER BY shares DESC NULLS LAST, country, state LIMIT 201", sec["security_id"], p.channel, d)
        return listing(rows, 200, security_id=sec["security_id"], channel=p.channel, record_date=d)

    @mcp.tool(name="holders_by_custodian", annotations={"title": "NOBO holders by custodian", **RO})
    @answers
    async def holders_by_custodian(params: RecordDateIn | None = None) -> str:
        """NOBO holders and shares by custodian (the broker holding the account) and account kind on a record date (Q12). No holder is named."""
        p = params or RecordDateIn()
        sec = await security_row(q, p.security, p.issuer)
        d = await date_or_latest(q, sec["security_id"], "nobo", p.record_date)
        rows = await q("SELECT participant_id, number, name, class_key, account_kind, holders, shares FROM si_holders_by_custodian($1, $2) ORDER BY shares DESC NULLS LAST, number LIMIT 201", sec["security_id"], d)
        return listing(rows, 200, security_id=sec["security_id"], record_date=d)

    @mcp.tool(name="holders_by_type", annotations={"title": "Registered holders by position type", **RO})
    @answers
    async def holders_by_type(params: RecordDateIn | None = None) -> str:
        """Registered holders by position type on a register's record date — certificate, book, DRS, restricted, plan (Q11): how many holders and how many shares of each. No holder is named."""
        p = params or RecordDateIn()
        sec = await security_row(q, p.security, p.issuer)
        d = await date_or_latest(q, sec["security_id"], "register", p.record_date)
        rows = await q("SELECT account_kind, holders, shares FROM si_holders_by_type($1, $2) ORDER BY shares DESC NULLS LAST", sec["security_id"], d)
        return listing(rows, 100, security_id=sec["security_id"], record_date=d)

    @mcp.tool(name="holders_unmatched", annotations={"title": "Rows still waiting for a holder", **RO})
    @answers
    async def holders_unmatched(params: UnmatchedIn | None = None) -> str:
        """The queue (Q16): NOBO and register rows from tied (or overridden) uploads that no holder owns yet, with up to three candidate holders each — the largest first. The rows ARE registrations of natural persons, so this is
        an Analyst's: anyone else is told `pii_withheld`."""
        p = params or UnmatchedIn()
        if not await pii(q):
            raise pii_withheld("get_import", "The unmatched rows")
        w = Where().raw("NOT x.deleted").raw("(x.tied OR x.override_at IS NOT NULL)")
        if p.upload:
            w.add("x.import_id = {}", p.upload)
        if p.issuer not in (None, ""):
            w.add("x.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        cond = w.sql()
        rows = await q(f"""SELECT * FROM (
                             SELECT 'nobo' AS row_kind, n.nobo_row_id AS row_id, n.import_id, n.row_no, n.shares, concat_ws(E'\\n', n.line1, n.line2, n.line3, n.line4, n.line5, n.line6, n.line7) AS registration, n.account_kind, n.custodian_participant_id
                               FROM mcp_nobo_rows n JOIN mcp_imports x ON x.import_id = n.import_id WHERE n.holder_id IS NULL AND n.error IS NULL AND {cond}
                             UNION ALL
                             SELECT 'register', g.register_row_id, g.import_id, g.row_no, g.shares, concat_ws(', ', g.registration_name, g.address_line, concat_ws(' ', g.city, g.state, g.zip)), lower(coalesce(g.position_type, 'unknown')), NULL::bigint
                               FROM mcp_register_rows g JOIN mcp_imports x ON x.import_id = g.import_id WHERE g.holder_id IS NULL AND NOT g.is_cede AND g.error IS NULL AND {cond}) u
                           ORDER BY (SELECT max(m.score) FROM mcp_holder_match_proposals m WHERE m.row_kind = u.row_kind AND m.row_id = u.row_id AND m.status = 'proposed') DESC NULLS LAST, u.shares DESC NULLS LAST, u.row_id
                           LIMIT {lim(p.limit) + 1} OFFSET {off(p.offset)}""", *w.args)
        for r in rows:
            r["candidates"] = await q("""SELECT m.holder_id, h.display_name, m.score, m.evidence, m.proposed_by FROM mcp_holder_match_proposals m JOIN mcp_holders h ON h.holder_id = m.holder_id
                                         WHERE m.row_kind = $1 AND m.row_id = $2 AND m.status = 'proposed' ORDER BY m.score DESC, m.proposal_id LIMIT 3""", r["row_kind"], r["row_id"])
        return listing(rows, lim(p.limit))

    @mcp.tool(name="match_proposals", annotations={"title": "The matcher's and the agents' proposals", **RO})
    @answers
    async def match_proposals(params: ProposalsIn | None = None) -> str:
        """The proposals that tie a NOBO or register row to a holder (Q16), with the score and the evidence (name and address agreement) and who proposed it (the matcher, or an agent), and what a person decided. An
        Analyst's: the proposals name holders."""
        p = params or ProposalsIn()
        if not await pii(q):
            raise pii_withheld("get_import", "The match proposals")
        w = Where()
        if p.upload:
            w.add("""((m.row_kind = 'nobo' AND EXISTS (SELECT 1 FROM mcp_nobo_rows n WHERE n.nobo_row_id = m.row_id AND n.import_id = {0})) OR (m.row_kind = 'register' AND EXISTS (SELECT 1 FROM mcp_register_rows g WHERE g.register_row_id = m.row_id AND g.import_id = {0})))""", p.upload)
        if p.status:
            w.add("m.status = {}", p.status)
        if p.proposed_by not in (None, ""):
            if str(p.proposed_by).strip().lower() == "matcher":
                w.raw("m.proposed_by IS NULL")
            else:
                w.add("m.proposed_by = {}", await member_ref(q, p.proposed_by))
        rows = await page(q, f"""SELECT m.proposal_id, m.row_kind, m.row_id, m.issuer_id, m.holder_id, m.score, m.evidence, m.proposed_by, m.status, m.decided_by, m.decided_at, m.created_at FROM mcp_holder_match_proposals m WHERE {w.sql()}""",
                          w.args, lim(p.limit), 0, "ORDER BY m.created_at DESC, m.proposal_id DESC")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="obo_remainder", annotations={"title": "Each participant's SPR position against its NOBO shares", **RO})
    @answers
    async def obo_remainder(params: OboRemainderIn) -> str:
        """Each participant's position in the SPR against the shares of its clients on the NOBO list on a record date — what is left is the OBOs' (Q17): the SPR shares and date, the NOBO shares and count, and the remainder."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("SELECT participant_id, number, name, class_key, spr_shares, spr_date, nobo_shares, nobo_count, obo_remainder FROM si_obo_remainder($1, $2) ORDER BY spr_shares DESC NULLS LAST, number LIMIT 201", sec["security_id"], day(p.record_date, "record_date"))
        return listing(rows, 200, security_id=sec["security_id"])

    @mcp.tool(name="participant_nobo_summary", annotations={"title": "One participant's NOBO count and its OBO remainder", **RO})
    @answers
    async def participant_nobo_summary(params: ParticipantNoboIn) -> str:
        """One participant's NOBO clients and shares on every record date, beside its SPR position on the date and the OBO remainder (Q9, Q17), newest first."""
        p = params
        sec = await security_row(q, p.security, p.issuer)
        part = await participant_row(q, p.participant)
        rows = await q("""SELECT b.record_date, sum(b.nobo_count) AS nobo_count, sum(b.shares) AS nobo_shares FROM mcp_nobo_broker_rows b WHERE b.participant_id = $1 AND b.security_id = $2 GROUP BY b.record_date
                          ORDER BY b.record_date DESC LIMIT 12""", part["participant_id"], sec["security_id"])
        for r in rows:
            o = await q("SELECT spr_shares, spr_date, obo_remainder FROM si_obo_remainder($1, $2) WHERE participant_id = $3", sec["security_id"], r["record_date"], part["participant_id"])
            r.update(o[0] if o else {"spr_shares": None, "spr_date": None, "obo_remainder": None})
        return listing(rows, 12, security_id=sec["security_id"], participant={"participant_id": part["participant_id"], "number": part["number"], "name": part["name"]})

    @mcp.tool(name="share_ranges", annotations={"title": "Broadridge's Share Range Analysis", **RO})
    @answers
    async def share_ranges(params: RecordDateIn | None = None) -> str:
        """Broadridge's Share Range Analysis (Q14, Q17): for each range, every account and the OBOs' accounts and shares, with the NOBOs derived as the difference. Omit the date for the latest report."""
        p = params or RecordDateIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("SELECT record_date, range_label, range_from, all_accounts, all_shares, obo_accounts, obo_shares, nobo_accounts, nobo_shares FROM si_share_ranges($1, $2) ORDER BY record_date DESC, range_from", sec["security_id"], day(p.record_date, "record_date"))
        if not rows:
            raise Refusal("no_data", "No Share Range Analysis has been uploaded for this security yet.")
        return listing(rows, 300, security_id=sec["security_id"])

    @mcp.tool(name="obo_totals", annotations={"title": "The OBO population over time", **RO})
    @answers
    async def obo_totals(params: SecIn | None = None) -> str:
        """The OBO population over time (Q17): the accounts and shares of all accounts and of the OBOs on each Share Range Analysis, and the OBOs' share of the accounts."""
        p = params or SecIn()
        sec = await security_row(q, p.security, p.issuer)
        rows = await q("SELECT record_date, all_accounts, all_shares, obo_accounts, obo_shares, obo_pct_of_accounts FROM si_obo_totals($1) ORDER BY record_date", sec["security_id"])
        return listing(rows, 300, security_id=sec["security_id"])

    @mcp.tool(name="insider_holdings", annotations={"title": "The insiders' holdings", **RO})
    @answers
    async def insider_holdings(params: InsiderHoldingsIn | None = None) -> str:
        """The insiders (Forms 3, 4 and 5): what each held after the last transaction, with the relationship and the date (Q18). The register's own holder and shares for the same person are an Analyst's (`holder_id` and
        `register_shares` are null for anyone else); the Form 4 itself is public record."""
        p = params or InsiderHoldingsIn()
        w = Where().raw("NOT t.is_derivative")
        if p.issuer not in (None, ""):
            w.add("t.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        cp = w.p(await pii(q))
        rows = await q(f"""SELECT * FROM (SELECT DISTINCT ON (t.issuer_id, t.reporting_owner_cik) t.issuer_id, t.reporting_owner_cik, t.reporting_owner_name, t.relationship, t.officer_title, t.transaction_date AS last_transaction_date,
                                  t.shares_owned_after, CASE WHEN {cp} THEN t.holder_id END AS holder_id,
                                  CASE WHEN {cp} THEN (SELECT h.last_shares FROM mcp_holders h WHERE h.holder_id = t.holder_id) END AS register_shares
                             FROM mcp_insider_transactions t WHERE {w.sql()}
                            ORDER BY t.issuer_id, t.reporting_owner_cik, t.transaction_date DESC NULLS LAST, t.transaction_id DESC) x ORDER BY x.shares_owned_after DESC NULLS LAST, x.reporting_owner_name LIMIT 201""", *w.args)
        return listing(rows, 200)
