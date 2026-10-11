"""Issuers, securities, share counts, participants and uploads (Q1–Q3, Q9, Q40, Q42): docs/shareholder-intelligence-mcp-tool-surface.md "Issuers, securities, share counts, participants" and
"Uploads". An issuer a member may not see does not exist for them — every read goes through mcp_issuers / mcp_securities / mcp_imports (the issuer wall), and a function that has no wall of
its own is handed a security only after mcp_securities has shown it."""
from __future__ import annotations

import datetime
from typing import Literal

from pydantic import Field

from si_common import (RO, Ref, Refusal, Where, _Base, answers, day, describe_models, detail, exact_first, is_manager, issuer_row, like, listing, lim, managers_only, off, one_by_id, page, participant_row, pii, pii_withheld, resolving, security_row)

IMPORT_KINDS = Literal["spr", "nobo", "register", "share_range", "prices_csv", "holders_csv"]
SHARE_KINDS = Literal["outstanding", "float", "authorized", "restricted", "unrestricted", "treasury", "cede", "drs", "certificated", "plan", "public_float_value"]


class FindIssuersIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of a name, a ticker or a CIK; alone it resolves one issuer")
    exchange: Literal["nasdaq", "nyse", "nyse_american", "otc", "cse", "tsx", "tsxv", "other"] | None = Field(None, description="Only issuers listed on this exchange")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class IssuerIn(_Base):
    issuer: Ref | None = Field(None, description="The issuer (id, ticker, CIK or name); omitted: the one issuer you may see")


class FindSecuritiesIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's securities (id, ticker, CIK or name)")
    q: str | None = Field(None, max_length=100, description="A CUSIP, a ticker or part of a name; alone it resolves one security")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ShareCountsIn(_Base):
    issuer: Ref | None = Field(None, description="Narrows a ticker (id, ticker, CIK or name)")
    security: Ref | None = Field(None, description="The security (id, CUSIP or ticker); omitted: the issuer's primary one")
    kind: SHARE_KINDS | None = Field(None, description="Only this kind of count")
    as_of: str | None = Field(None, description="The latest count on or before this day, YYYY-MM-DD; default today")
    history: bool = Field(False, description="true: every dated row as well, newest first")


class RestrictionsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's list (id, ticker, CIK or name)")


class FindParticipantsIn(_Base):
    q: str | None = Field(None, max_length=100, description="A DTC number, part of a name or an alias; alone it resolves one participant")
    class_: str | None = Field(None, alias="class", max_length=40, description="The participant class key (retail, prime, market_maker, lending …)")
    active: bool | None = Field(None, description="true: only participants still in DTC's directory")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class GetParticipantIn(_Base):
    participant: Ref = Field(..., description="A participant (id, DTC number or name)")
    issuer: Ref | None = Field(None, description="Narrows a ticker")
    security: Ref | None = Field(None, description="The security its positions are read in (id, CUSIP or ticker); omitted: the one issuer's primary security, if you may see just one issuer")


class FindImportsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's uploads (id, ticker, CIK or name)")
    security: Ref | None = Field(None, description="Only this security's uploads (id, CUSIP or ticker)")
    kind: IMPORT_KINDS | None = Field(None, description="spr, nobo, register, share_range, prices_csv or holders_csv")
    tied: bool | None = Field(None, description="true: only the ones that tie; false: only the ones that do not")
    q: str | None = Field(None, max_length=200, description="Part of the file's name; alone it resolves one upload")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class UploadIn(_Base):
    upload: int = Field(..., ge=1, description="The upload (id)")


class ImportRowsIn(_Base):
    upload: int = Field(..., ge=1, description="The upload (id)")
    failed_only: bool = Field(False, description="true: only the rows whose cells failed to type")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


describe_models(globals())

IMPORT_COLUMNS = """u.import_id, u.issuer_id, u.kind, u.connector, u.file_name, u.security_id, u.record_date, u.period_from, u.period_to, u.status, u.rows_made, u.rows_failed, u.tied, u.tie_note, u.override_at,
                    u.created_by, u.created_at"""
LATEST_COUNTS = """SELECT DISTINCT ON (c.kind) c.share_count_id, c.kind, c.value, c.as_of, c.source, c.filing_id, c.xbrl_frame FROM mcp_share_counts c WHERE c.security_id = $1 AND c.as_of <= $2
                    ORDER BY c.kind, c.as_of DESC, CASE c.source WHEN 'filing' THEN 1 WHEN 'transfer_agent' THEN 2 WHEN 'dtc_letter' THEN 3 WHEN 'register' THEN 4 WHEN 'manual' THEN 5 ELSE 6 END, c.recorded_at DESC"""


async def maybe_security(q, security, issuer) -> dict | None:
    """The security a participant's position is read in: the one named; else, with no ambiguity, the sole issuer's primary; else None (the participant is estate-wide, its positions are an issuer's)."""
    if security not in (None, "") or issuer not in (None, ""):
        return await security_row(q, security, issuer)
    try:
        return await security_row(q)
    except Refusal:
        return None


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_issuers", annotations={"title": "The issuers I may see", **RO})
    @answers
    async def find_issuers(params: FindIssuersIn | None = None) -> str:
        """The issuers (companies) you may see — an issuer behind a restriction list that does not name you does not exist for you (Q1). `q` alone (a name, ticker or CIK) resolves one issuer, which is how the kernel's
        actions server turns 'Bitmine' into an id. Each row: the EDGAR entity, the exchange, the transfer agent, whether it carries a restriction list, how many securities it has and which is primary."""
        p = params or FindIssuersIn()
        w = Where()
        if p.q:
            w.add("(i.name ILIKE {0} OR coalesce(i.ticker, '') ILIKE {0} OR coalesce(i.cik, '') = lpad(regexp_replace({1}, '\\D', '', 'g'), 10, '0') AND regexp_replace({1}, '\\D', '', 'g') <> '')", like(p.q), p.q)
        if p.exchange:
            w.add("i.exchange = {}", p.exchange)
        rows = await page(q, f"""SELECT i.issuer_id, i.name, i.cik, i.ticker, i.exchange, i.transfer_agent, i.restricted, i.securities_count, i.primary_security_id, i.edgar_synced_at FROM mcp_issuers i WHERE {w.sql()}""", w.args,
                          lim(p.limit), 0, "ORDER BY i.name, i.issuer_id")
        if resolving(p):
            rows = exact_first(rows, p.q, "name", "ticker", "cik")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_issuer", annotations={"title": "One issuer in full", **RO})
    @answers
    async def get_issuer(params: IssuerIn | None = None) -> str:
        """One issuer in full (Q1): the EDGAR entity, its securities, the latest share count of each kind with its date and source, the latest upload of each kind and whether it tied, the restriction state,
        and the fetchers' state. Omit `issuer` when you may see just one."""
        p = params or IssuerIn()
        iss = await issuer_row(q, p.issuer)
        secs = await q("""SELECT security_id, class, name, cusip, isin, ticker, is_primary, active, outstanding, float_shares, cede_shares, last_position_date FROM mcp_securities WHERE issuer_id = $1
                          ORDER BY is_primary DESC, class, name""", iss["issuer_id"])
        counts: dict = {}
        if iss["primary_security_id"] is not None:
            for r in await q(LATEST_COUNTS, iss["primary_security_id"], datetime.date.today()):
                counts[r["kind"]] = {"value": r["value"], "as_of": r["as_of"], "source": r["source"]}
        uploads: dict = {}
        for r in await q("""SELECT DISTINCT ON (u.kind) u.import_id, u.kind, u.record_date, u.period_to, u.status, u.tied, u.override_at, u.created_at FROM mcp_imports u WHERE u.issuer_id = $1 AND NOT u.deleted
                            ORDER BY u.kind, coalesce(u.period_to, u.record_date) DESC NULLS LAST, u.created_at DESC""", iss["issuer_id"]):
            uploads[r["kind"]] = {"import_id": r["import_id"], "record_date": r["record_date"], "period_to": r["period_to"], "status": r["status"], "tied": r["tied"], "overridden": r["override_at"] is not None}
        sources = await q("SELECT source_id, name, connector, active, paused_at IS NOT NULL AS paused, last_ok_at, consecutive_failures FROM mcp_sources ORDER BY source_id LIMIT 30")
        return detail(issuer=iss, securities=secs, share_counts=counts, uploads=uploads, restricted=iss["restricted"], sources=sources)

    @mcp.tool(name="find_securities", annotations={"title": "Securities", **RO})
    @answers
    async def find_securities(params: FindSecuritiesIn | None = None) -> str:
        """An issuer's securities (classes) (Q1), or every one you may see: by issuer, or by a CUSIP, a ticker or part of a name. `q` alone resolves one security (the registry's `security` resolver). Each row carries the
        latest outstanding, float and Cede counts and the last SPR date."""
        p = params or FindSecuritiesIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("s.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.q:
            w.add("(s.name ILIKE {0} OR upper(s.cusip) = upper({1}) OR coalesce(s.ticker, '') ILIKE {0} OR upper(coalesce(s.isin, '')) = upper({1}))", like(p.q), p.q)
        rows = await page(q, f"""SELECT s.security_id, s.issuer_id, s.issuer_name, s.class, s.name, s.cusip, s.ticker, s.is_primary, s.outstanding, s.float_shares, s.cede_shares, s.last_position_date FROM mcp_securities s WHERE {w.sql()}""",
                          w.args, lim(p.limit), 0, "ORDER BY s.issuer_name, s.is_primary DESC, s.class, s.security_id")
        if resolving(p):
            rows = exact_first(rows, p.q, "cusip", "ticker", "name")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="share_counts", annotations={"title": "The share counts", **RO})
    @answers
    async def share_counts(params: ShareCountsIn | None = None) -> str:
        """The dated share counts of a security by kind and source (Q2): outstanding, float, authorized, Cede, DRS, certificated, plan … — the latest of each kind on or before a day (default today) with its date and source, and, with
        `history`, every dated row. A count is never interpolated: a kind with no row is absent."""
        p = params or ShareCountsIn()
        sec = await security_row(q, p.security, p.issuer)
        on = day(p.as_of, "as_of") or datetime.date.today()
        latest: dict = {}
        for r in await q(LATEST_COUNTS, sec["security_id"], on):
            if p.kind in (None, r["kind"]):
                latest[r["kind"]] = {"value": r["value"], "as_of": r["as_of"], "source": r["source"], "filing_id": r["filing_id"]}
        out: dict = {"security_id": sec["security_id"], "issuer_id": sec["issuer_id"], "cusip": sec["cusip"], "as_of": on, "latest": latest}
        if p.history:
            out["rows"] = await q("""SELECT c.share_count_id, c.as_of, c.kind, c.value, c.source, c.filing_id, c.import_id, c.xbrl_frame, c.note FROM mcp_share_counts c WHERE c.security_id = $1 AND ($2::text IS NULL OR c.kind = $2)
                                     ORDER BY c.as_of DESC, c.kind, c.recorded_at DESC LIMIT 500""", sec["security_id"], p.kind)
        return detail(**out)

    @mcp.tool(name="issuer_restrictions", annotations={"title": "Who each restricted issuer is limited to", **RO})
    @answers
    async def issuer_restrictions(params: RestrictionsIn | None = None) -> str:
        """Which issuers carry a restriction list and who is on it (Q42) — an advisory firm's client stays inside its list. A manager's: anyone else is told so, never shown the list."""
        p = params or RestrictionsIn()
        if not await is_manager(q):
            raise managers_only("The restriction lists")
        w = Where()
        if p.issuer not in (None, ""):
            w.add("r.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        rows = await q(f"SELECT r.issuer_id, r.member_id, r.display_name, r.added_by, r.added_at FROM mcp_issuer_members r WHERE {w.sql()} ORDER BY r.issuer_id, r.display_name LIMIT 500", *w.args)
        return listing(rows, 500)

    @mcp.tool(name="find_participants", annotations={"title": "DTC participants", **RO})
    @answers
    async def find_participants(params: FindParticipantsIn | None = None) -> str:
        """DTC participants (brokers, banks, market makers) (Q9): by number, name, alias or class. `q` alone resolves one (the registry's `participant` resolver). A participant belongs to the whole estate — no issuer wall — and
        carries its class with the source of that class (seeded, DTC's directory, or a person) so a reader can weigh it."""
        p = params or FindParticipantsIn()
        w = Where()
        if p.q:
            digits = "".join(c for c in p.q if c.isdigit())
            w.add("(m.number = {0} OR m.name ILIKE {1} OR EXISTS (SELECT 1 FROM unnest(m.aliases) a WHERE a ILIKE {1}))", digits.zfill(4) if digits else "x", like(p.q))
        if p.class_:
            w.add("m.class_key = {}", p.class_)
        if p.active is not None:
            w.add("m.active = {}", p.active)
        rows = await page(q, f"""SELECT m.participant_id, m.number, m.name, m.aliases, m.class_key, m.side, m.class_source, m.directory_kind, m.last_seen_in_directory, m.active, m.tags FROM mcp_participants m WHERE {w.sql()}""",
                          w.args, lim(p.limit), off(p.offset), "ORDER BY m.number")
        if resolving(p):
            rows = exact_first(rows, p.q, "number", "name")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_participant", annotations={"title": "One participant", **RO})
    @answers
    async def get_participant(params: GetParticipantIn) -> str:
        """One DTC participant (Q9): its class and the source of that class, its aliases, its latest position in a security with its share of the DTC total, the first and last date it appeared and how many dates. The positions are an
        issuer's, read only for a security you may see; the participant itself is estate-wide."""
        p = params
        part = await participant_row(q, p.participant)
        out: dict = {"participant": part}
        sec = await maybe_security(q, p.security, p.issuer)
        if sec is not None:
            pres = (await q("SELECT count(*) AS days, min(position_date) AS first_date, max(position_date) AS last_date FROM mcp_participant_positions WHERE security_id = $1 AND participant_id = $2", sec["security_id"], part["participant_id"]))[0]
            latest = None
            if pres["last_date"] is not None:
                row = (await q("""SELECT pp.position_date, pp.shares, round(100.0 * pp.shares / NULLIF(si_spr_total($1, pp.position_date), 0), 2) AS pct_of_total FROM mcp_participant_positions pp
                                  WHERE pp.security_id = $1 AND pp.participant_id = $2 AND pp.position_date = $3""", sec["security_id"], part["participant_id"], pres["last_date"]))[0]
                latest = row
            out.update(security_id=sec["security_id"], issuer_id=sec["issuer_id"], latest=latest, first_date=pres["first_date"], last_date=pres["last_date"], dates_present=pres["days"])
        return detail(**out)

    @mcp.tool(name="find_imports", annotations={"title": "Uploads", **RO})
    @answers
    async def find_imports(params: FindImportsIn | None = None) -> str:
        """The uploads (the DTC SPR, the NOBO list, the register, Broadridge's share ranges) by kind, issuer, security and tie (Q40), newest period first. `q` alone resolves one by its file name (the registry's `upload`
        resolver). An upload of an issuer you may not see does not exist for you; a tombstoned one is not listed."""
        p = params or FindImportsIn()
        w = Where().raw("NOT u.deleted")
        if p.issuer not in (None, ""):
            w.add("u.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.security not in (None, ""):
            w.add("u.security_id = {}", (await security_row(q, p.security, p.issuer))["security_id"])
        if p.kind:
            w.add("u.kind = {}", p.kind)
        if p.tied is not None:
            w.add("u.tied IS NOT DISTINCT FROM {}", p.tied)
        if p.q:
            w.add("u.file_name ILIKE {}", like(p.q))
        rows = await page(q, f"SELECT {IMPORT_COLUMNS} FROM mcp_imports u WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset), "ORDER BY coalesce(u.period_to, u.record_date) DESC NULLS LAST, u.created_at DESC, u.import_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "file_name")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_import", annotations={"title": "One upload", **RO})
    @answers
    async def get_import(params: UploadIn) -> str:
        """One upload in full (Q40): the totals the file states against the totals its rows sum to, whether it tied or the difference named, a manager's override, a NOBO list's job header, and — for an Analyst — how many
        rows the matcher took automatically, proposed, or left alone."""
        p = params
        u = await one_by_id(q, "upload", "mcp_imports", "import_id", p.upload)
        out: dict = {"import": {k: u[k] for k in ("import_id", "issuer_id", "security_id", "kind", "connector", "file_name", "record_date", "period_from", "period_to", "status", "rows_made", "rows_failed", "superseded_by", "created_by", "created_at", "finished_at", "deleted")},
                     "control_totals": u["control_totals"], "derived_totals": u["derived_totals"], "tied": u["tied"], "tie_note": u["tie_note"], "log": u["log"]}
        if u["override_at"] is not None:
            out["override"] = {"by": u["override_by"], "note": u["override_note"], "at": u["override_at"]}
        if u["kind"] == "nobo":
            jobs = await q("SELECT * FROM mcp_nobo_jobs WHERE import_id = $1 ORDER BY nobo_job_id LIMIT 1", u["import_id"])
            out["nobo_job"] = jobs[0] if jobs else None
        if u["kind"] in ("nobo", "register"):
            if await pii(q):
                if u["kind"] == "nobo":
                    m = (await q("""SELECT count(*) AS rows, count(*) FILTER (WHERE n.holder_id IS NOT NULL) AS matched, count(*) FILTER (WHERE n.error IS NOT NULL) AS failed,
                                           count(*) FILTER (WHERE n.holder_id IS NULL AND n.error IS NULL AND EXISTS (SELECT 1 FROM mcp_holder_match_proposals x WHERE x.row_kind = 'nobo' AND x.row_id = n.nobo_row_id AND x.status = 'proposed')) AS proposed,
                                           count(*) FILTER (WHERE n.holder_id IS NULL AND n.error IS NULL AND NOT EXISTS (SELECT 1 FROM mcp_holder_match_proposals x WHERE x.row_kind = 'nobo' AND x.row_id = n.nobo_row_id AND x.status = 'proposed')) AS unmatched,
                                           count(*) FILTER (WHERE EXISTS (SELECT 1 FROM mcp_holder_match_proposals x WHERE x.row_kind = 'nobo' AND x.row_id = n.nobo_row_id AND x.status = 'auto')) AS auto
                                      FROM mcp_nobo_rows n WHERE n.import_id = $1""", u["import_id"]))[0]
                else:
                    m = (await q("""SELECT count(*) AS rows, count(*) FILTER (WHERE g.holder_id IS NOT NULL) AS matched, count(*) FILTER (WHERE g.error IS NOT NULL) AS failed,
                                           count(*) FILTER (WHERE g.holder_id IS NULL AND g.error IS NULL AND NOT g.is_cede AND EXISTS (SELECT 1 FROM mcp_holder_match_proposals x WHERE x.row_kind = 'register' AND x.row_id = g.register_row_id AND x.status = 'proposed')) AS proposed,
                                           count(*) FILTER (WHERE g.holder_id IS NULL AND g.error IS NULL AND NOT g.is_cede AND NOT EXISTS (SELECT 1 FROM mcp_holder_match_proposals x WHERE x.row_kind = 'register' AND x.row_id = g.register_row_id AND x.status = 'proposed')) AS unmatched,
                                           count(*) FILTER (WHERE EXISTS (SELECT 1 FROM mcp_holder_match_proposals x WHERE x.row_kind = 'register' AND x.row_id = g.register_row_id AND x.status = 'auto')) AS auto
                                      FROM mcp_register_rows g WHERE g.import_id = $1""", u["import_id"]))[0]
                out["matcher"] = m
            else:
                out["matcher"] = None
                out["matcher_withheld"] = "The matcher's counts are an Analyst's."
        return detail(**out)

    @mcp.tool(name="import_rows", annotations={"title": "An upload's rows as read", **RO})
    @answers
    async def import_rows(params: ImportRowsIn) -> str:
        """An upload's rows as the parser read them, the ones whose cells failed to type first (Q40). An SPR's rows are brokers (number, name, date, shares) and open to everyone who may see the upload; a NOBO list's and a
        register's rows ARE the registrations of natural persons and are an Analyst's only (pii_withheld otherwise — `get_import` and `holders_by_type` give a Viewer the aggregate)."""
        p = params
        u = await one_by_id(q, "upload", "mcp_imports", "import_id", p.upload)
        n, o = lim(p.limit, 50), off(p.offset)
        if u["kind"] == "spr":
            w = Where().add("r.import_id = {}", u["import_id"])
            if p.failed_only:
                w.raw("r.error IS NOT NULL")
            rows = await page(q, f"SELECT r.row_no, r.participant_number, r.participant_name, r.position_date, r.shares, r.error FROM mcp_spr_rows r WHERE {w.sql()}", w.args, n, o, "ORDER BY (r.error IS NULL), r.row_no, r.position_date")
            return listing(rows, n, kind="spr", import_id=u["import_id"])
        if u["kind"] in ("nobo", "register"):
            if not await pii(q):
                raise pii_withheld("get_import", "A NOBO list's and a register's rows are the registrations of natural persons; they")
            if u["kind"] == "nobo":
                w = Where().add("r.import_id = {}", u["import_id"])
                if p.failed_only:
                    w.raw("r.error IS NOT NULL")
                rows = await page(q, f"SELECT r.nobo_row_id AS row_id, r.row_no, r.shares, r.line1, r.line2, r.line3, r.line4, r.line5, r.line6, r.line7, r.account_kind, r.custodian_participant_id, r.holder_id, r.error FROM mcp_nobo_rows r WHERE {w.sql()}",
                                  w.args, n, o, "ORDER BY (r.error IS NULL), r.row_no")
            else:
                w = Where().add("r.import_id = {}", u["import_id"])
                if p.failed_only:
                    w.raw("r.error IS NOT NULL")
                rows = await page(q, f"""SELECT r.register_row_id AS row_id, r.row_no, r.record_date, r.position_type, r.holder_ref, r.registration_name, r.city, r.state, r.shares, r.restriction_text, r.is_cede, r.holder_id, r.error
                                           FROM mcp_register_rows r WHERE {w.sql()}""", w.args, n, o, "ORDER BY (r.error IS NULL), r.row_no")
            return listing(rows, n, kind=u["kind"], import_id=u["import_id"])
        if u["kind"] == "share_range":
            rows = await q("""SELECT x.population, x.range_label, x.range_from, x.range_to, x.accounts, x.shares FROM mcp_share_range_rows x JOIN mcp_share_range_reports r ON r.share_range_report_id = x.share_range_report_id
                              WHERE r.import_id = $1 ORDER BY x.range_from, x.population LIMIT $2 OFFSET $3""", u["import_id"], n + 1, o)
            return listing(rows, n, kind="share_range", import_id=u["import_id"])
        raise Refusal("no_data", f"An upload of kind {u['kind']} keeps no rows to read.")
