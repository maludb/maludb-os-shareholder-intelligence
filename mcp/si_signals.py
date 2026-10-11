"""Signals and rules (Q34, Q37, Q38, Q53): docs/shareholder-intelligence-mcp-tool-surface.md "Signals and rules". Every signal read goes through mcp_signals (the issuer wall); the rules' catalogue is estate-wide, a per-issuer
override is read only after the issuer is shown by mcp_issuers. A signal carries its rule's EVIDENCE CLASS (established / contested / lore / data defect) — and its evidence rows are public-record figures (participants,
institutions, insiders), never a natural person of the register. Signals advise: nothing here disposes of one."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from si_common import RO, Ref, Where, _Base, answers, day, describe_models, detail, exact_first, issuer_row, like, listing, lim, not_found, off, page, resolving, security_row

STATUSES = Literal["open", "acknowledged", "dismissed", "escalated", "expired"]
SEVERITIES = Literal["info", "watch", "high"]
CLASSES = Literal["established", "contested", "lore", "data_defect"]
SIGNAL_COLS = """s.signal_id, s.issuer_id, s.rule_key, s.rule_name, s.evidence_class, s.security_id, s.raised_on, s.window_from, s.window_to, s.severity, s.value, s.threshold, s.summary, s.status, s.proposal_reason, s.proposal_at,
                 s.repeat_count"""


class FindSignalsIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's signals (id, ticker, CIK or name)")
    security: Ref | None = Field(None, description="Only this security's signals (id, CUSIP or ticker)")
    status: list[STATUSES] | None = Field(None, description="Only these states; default: the ones that still need a person (open and escalated)")
    severity: list[SEVERITIES] | None = Field(None, description="Only these severities")
    rule: str | None = Field(None, max_length=60, description="Only this rule (its key)")
    class_: CLASSES | None = Field(None, alias="class", description="Only rules of this evidence class")
    q: str | None = Field(None, max_length=200, description="Part of the summary or the rule's name; alone it resolves one signal")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class SignalIn(_Base):
    signal: int = Field(..., ge=1, description="The signal (id)")


class HistoryIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's signals")
    from_: str = Field(..., alias="from", description="Raised on or after this day, YYYY-MM-DD")
    to: str = Field(..., description="Raised on or before this day, YYYY-MM-DD")
    status: list[STATUSES] | None = Field(None, description="Only these states (default: all)")
    rule: str | None = Field(None, max_length=60, description="Only this rule (its key)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class CatalogueIn(_Base):
    issuer: Ref | None = Field(None, description="With an issuer: its overrides and the thresholds in effect")
    class_: CLASSES | None = Field(None, alias="class", description="Only rules of this evidence class")


class FindRulesIn(_Base):
    q: str = Field(..., min_length=1, max_length=100, description="A rule's key or part of its name")


class OutcomesIn(_Base):
    issuer: Ref | None = Field(None, description="Only this issuer's signals")
    rule: str | None = Field(None, max_length=60, description="Only this rule (its key)")
    class_: CLASSES | None = Field(None, alias="class", description="Only rules of this evidence class")
    from_: str | None = Field(None, alias="from", description="Raised on or after this day")
    to: str | None = Field(None, description="Raised on or before this day")
    limit: int = Field(50, ge=1, le=100, description="How many signals at most")


describe_models(globals())


def fate(s: dict) -> str | None:
    """Whether a person took the agent's proposal: waiting, accepted, rejected, lapsed — or None when there was none (as app/features/signals/queries.php proposal_fate())."""
    if s["proposal_reason"] is None:
        return None
    if s["status"] in ("open", "expired"):
        return "waiting" if s["status"] == "open" else "lapsed"
    pr = str(s["proposal_reason"])
    taken = {"escalated": pr == "escalate", "acknowledged": pr in ("known", "watching") and pr == s["disposition_reason"],
             "dismissed": pr == s["disposition_reason"] and (pr != "explained_by_event" or s["event_id"] == s["proposal_event_id"])}.get(s["status"], False)
    return "accepted" if taken else "rejected"


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_signals", annotations={"title": "Signals", **RO})
    @answers
    async def find_signals(params: FindSignalsIn | None = None) -> str:
        """The signals the rules raised (Q34), the ones that still need a person first — open and escalated — by severity then newest: by issuer, security, state, severity, rule or evidence class. EVERY SIGNAL PRINTS ITS RULE'S
        EVIDENCE CLASS (established, contested, lore, data defect): weigh a 'lore' signal as lore. `q` alone resolves one signal (the registry's `signal` resolver), whatever its state."""
        p = params or FindSignalsIn()
        w = Where()
        if p.issuer not in (None, ""):
            w.add("s.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.security not in (None, ""):
            w.add("s.security_id = {}", (await security_row(q, p.security, p.issuer))["security_id"])
        if p.status:
            w.add("s.status = ANY ({}::text[])", list(p.status))
        elif not resolving(p):
            w.raw("s.status IN ('open', 'escalated')")
        if p.severity:
            w.add("s.severity = ANY ({}::text[])", list(p.severity))
        if p.rule:
            w.add("s.rule_key = {}", p.rule)
        if p.class_:
            w.add("s.evidence_class = {}", p.class_)
        if p.q:
            w.add("(s.summary ILIKE {0} OR s.rule_name ILIKE {0})", like(p.q))
        rows = await page(q, f"SELECT {SIGNAL_COLS} FROM mcp_signals s WHERE {w.sql()}", w.args, lim(p.limit), off(p.offset),
                          "ORDER BY CASE s.severity WHEN 'high' THEN 1 WHEN 'watch' THEN 2 ELSE 3 END, s.raised_on DESC, s.signal_id DESC")
        if resolving(p):
            rows = exact_first(rows, p.q, "summary", "rule_name")
        return listing(rows, lim(p.limit))

    @mcp.tool(name="get_signal", annotations={"title": "One signal whole", **RO})
    @answers
    async def get_signal(params: SignalIn) -> str:
        """One signal whole (Q34): the rule's basis and the thresholds in effect for this issuer, the evidence rows it was raised on, the window, the disposition (who, why, the note), an agent's proposal and what became of it,
        the explaining event, the events of the window, and — once prices allow — the outcome after 5, 20 and 60 trading days."""
        rows = await q("SELECT s.* FROM mcp_signals s WHERE s.signal_id = $1", params.signal)
        if not rows:
            raise not_found("signal")
        s = rows[0]
        rule = (await q("SELECT rule_id, key, name, description, evidence_class, basis, severity_default, params, window_days FROM mcp_signal_rules WHERE rule_id = $1", s["rule_id"]))[0]
        eff = (await q("SELECT si_rule_params($1, $2) AS p", rule["key"], s["issuer_id"]))[0]["p"]
        event = None
        if s["event_id"]:
            e = await q("SELECT event_id, on_date, kind, title, severity, filing_id, url FROM mcp_events WHERE event_id = $1", s["event_id"])
            event = e[0] if e else None
        proposal = None
        if s["proposal_reason"] is not None:
            by = await q("SELECT display_name, member_kind FROM mcp_members WHERE member_id = $1", s["proposal_by"]) if s["proposal_by"] else []
            pe = await q("SELECT event_id, on_date, title FROM mcp_events WHERE event_id = $1", s["proposal_event_id"]) if s["proposal_event_id"] else []
            proposal = {"by": s["proposal_by"], "by_name": by[0]["display_name"] if by else None, "by_kind": by[0]["member_kind"] if by else None, "reason": s["proposal_reason"], "note": s["proposal_note"],
                        "event": pe[0] if pe else None, "at": s["proposal_at"], "fate": fate(s)}
        outcome = await q("SELECT price_at, price_5d, price_20d, price_60d, return_5d, return_20d, return_60d, updated_at FROM mcp_signal_outcomes WHERE signal_id = $1", s["signal_id"])
        window_events = await q("""SELECT event_id, on_date, kind, title, severity FROM mcp_events WHERE issuer_id = $1 AND (security_id IS NULL OR security_id = $2) AND on_date BETWEEN $3::date - 14 AND greatest($4::date, $5::date) + 7
                                    ORDER BY on_date DESC, event_id DESC LIMIT 30""", s["issuer_id"], s["security_id"], s["window_from"], s["window_to"], s["raised_on"])
        keep = ("signal_id", "issuer_id", "security_id", "rule_key", "rule_name", "evidence_class", "raised_on", "window_from", "window_to", "severity", "value", "threshold", "baseline", "summary", "status", "disposition_reason",
                "disposition_note", "disposed_by", "disposed_at", "repeat_count", "expires_on", "created_at")
        return detail(signal={k: s[k] for k in keep}, rule={"key": rule["key"], "name": rule["name"], "description": rule["description"], "evidence_class": rule["evidence_class"], "basis": rule["basis"],
                                                              "severity_default": rule["severity_default"], "window_days": rule["window_days"], "params": rule["params"], "effective_params": eff},
                      evidence=s["evidence"], event=event, window_events=window_events, proposal=proposal, outcome=outcome[0] if outcome else None)

    @mcp.tool(name="signals_history", annotations={"title": "Signals of a period with their dispositions", **RO})
    @answers
    async def signals_history(params: HistoryIn) -> str:
        """Signals raised in a period, every state, with their dispositions — who disposed of each, why, the note, the explaining event — and, where an agent proposed one, whether a person took the proposal (Q37)."""
        p = params
        a, b = day(p.from_, "from"), day(p.to, "to")
        w = Where().add("s.raised_on BETWEEN {} AND {}", min(a, b), max(a, b))
        if p.issuer not in (None, ""):
            w.add("s.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.status:
            w.add("s.status = ANY ({}::text[])", list(p.status))
        if p.rule:
            w.add("s.rule_key = {}", p.rule)
        rows = await page(q, f"""SELECT {SIGNAL_COLS}, s.disposition_reason, s.disposition_note, s.disposed_by, s.disposed_at, s.event_id, s.proposal_event_id FROM mcp_signals s WHERE {w.sql()}""",
                          w.args, lim(p.limit), off(p.offset), "ORDER BY s.raised_on DESC, s.signal_id DESC")
        for r in rows:
            r["proposal_fate"] = fate(r)
        return listing(rows, lim(p.limit))

    @mcp.tool(name="rules_catalogue", annotations={"title": "The rules", **RO})
    @answers
    async def rules_catalogue(params: CatalogueIn | None = None) -> str:
        """The nineteen rules (Q38): key, name, EVIDENCE CLASS, the basis (why it matters, and how far to trust it), the default severity, the thresholds, the window, whether it runs and when it last fired. With an issuer: that
        issuer's override and the thresholds and switch in effect for it."""
        p = params or CatalogueIn()
        iss = (await issuer_row(q, p.issuer))["issuer_id"] if p.issuer not in (None, "") else None
        rows = await q("""SELECT r.rule_id, r.key, r.name, r.evidence_class, r.basis, r.severity_default, r.params, r.window_days, r.enabled, r.last_fired_on,
                                 CASE WHEN $1::bigint IS NULL THEN NULL ELSE si_rule_params(r.key, $1) END AS effective_params,
                                 CASE WHEN $1::bigint IS NULL THEN NULL ELSE si_rule_enabled(r.key, $1) END AS effective_enabled,
                                 CASE WHEN $1::bigint IS NULL THEN NULL ELSE (SELECT to_jsonb(o) FROM (SELECT x.params, x.enabled, x.updated_at FROM mcp_signal_rule_overrides x WHERE x.rule_id = r.rule_id AND x.issuer_id = $1) o) END AS override
                            FROM mcp_signal_rules r WHERE ($2::text IS NULL OR r.evidence_class = $2) ORDER BY r.sort_order, r.key""", iss, p.class_)
        return listing(rows, 100, issuer_id=iss)

    @mcp.tool(name="find_rules", annotations={"title": "Find a rule", **RO})
    @answers
    async def find_rules(params: FindRulesIn) -> str:
        """A rule by its key or part of its name (the registry's `rule` resolver), with its evidence class."""
        p = params
        rows = await q("SELECT rule_id, key, name, evidence_class FROM mcp_signal_rules WHERE key ILIKE $1 OR name ILIKE $1 ORDER BY sort_order, key LIMIT 21", like(p.q))
        return listing(exact_first(rows, p.q, "key", "name"), 20)

    @mcp.tool(name="signal_outcomes", annotations={"title": "What happened after a signal", **RO})
    @answers
    async def signal_outcomes(params: OutcomesIn | None = None) -> str:
        """The price when a signal was raised and the return 5, 20 and 60 trading days after (Q53) — the Auditor's test of the 'lore' rules — with, per rule, how many signals have a 20-day return, the mean and the share
        that were negative. Fewer than 30 is 'too few to say': the count is returned so the caller can say so."""
        p = params or OutcomesIn()
        w = Where().raw("(o.return_20d IS NOT NULL OR o.price_at IS NOT NULL)")
        if p.issuer not in (None, ""):
            w.add("s.issuer_id = {}", (await issuer_row(q, p.issuer))["issuer_id"])
        if p.rule:
            w.add("s.rule_key = {}", p.rule)
        if p.class_:
            w.add("s.evidence_class = {}", p.class_)
        if p.from_:
            w.add("s.raised_on >= {}", day(p.from_, "from"))
        if p.to:
            w.add("s.raised_on <= {}", day(p.to, "to"))
        rows = await q(f"""SELECT s.signal_id, s.rule_key, s.evidence_class, s.raised_on, o.price_at, o.return_5d, o.return_20d, o.return_60d FROM mcp_signals s JOIN mcp_signal_outcomes o ON o.signal_id = s.signal_id
                            WHERE {w.sql()} ORDER BY s.raised_on DESC, s.signal_id DESC LIMIT {lim(p.limit, 50) + 1}""", *w.args)
        by_rule = await q(f"""SELECT s.rule_key, s.evidence_class, count(o.return_20d) AS n, round(avg(o.return_20d), 4) AS mean_return_20d,
                                     round(count(*) FILTER (WHERE o.return_20d < 0)::numeric / NULLIF(count(o.return_20d), 0), 4) AS share_negative_20d
                                FROM mcp_signals s JOIN mcp_signal_outcomes o ON o.signal_id = s.signal_id WHERE {w.sql()} GROUP BY s.rule_key, s.evidence_class ORDER BY s.rule_key""", *w.args)
        return listing(rows, lim(p.limit, 50), by_rule=by_rule, enough=30)
