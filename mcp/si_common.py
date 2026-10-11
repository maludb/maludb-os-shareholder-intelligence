"""Shared pieces for Shareholder Intelligence's tool modules: annotations, the input base, the where-builder, the refusals (errors are answers, not exceptions — surface "Errors"), the
issuer / security resolvers (through the mcp_* views, so a record the caller may not see does not exist for them), the list envelope the kernel's entity resolver reads, and the
walls' two questions asked of the database (si_sees_holder_pii(), si_has_right()). Every query runs as the asking member (db.fetch_scoped sets app.member_id from the verified bearer):
the views and the functions decide the rows and the PII wall; nothing here filters by who is asking. THE SECURITY DEFINER read functions (si_position_changes, si_holders_by_range, …)
have no wall of their own, so a tool proves the security it hands them visible first, through mcp_securities — exactly as the PHP does (holders_visible_security()).
"""
from __future__ import annotations

import datetime
import decimal
import functools
import re

import asyncpg
from pydantic import BaseModel, ConfigDict, Field

import db

RO = {"readOnlyHint": True, "openWorldHint": False}
Ref = int | str          # an id or a name / ticker / CUSIP / CIK


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


# Money is a string with two decimals (surface, conventions): these are the keys that carry it. A share count, a ratio or a percent never does.
MONEY_KEYS = {"value_dollars", "aggregate_market_value", "price", "open", "high", "low", "close", "price_at", "price_5d", "price_20d", "price_60d", "facility_amount", "remaining_amount", "exercise_price",
              "cost_basis"}


def _money(obj):
    if isinstance(obj, dict):
        return {k: (_fmt_money(v) if k in MONEY_KEYS and isinstance(v, (int, float, decimal.Decimal)) and not isinstance(v, bool) else _money(v)) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_money(x) for x in obj]
    if isinstance(obj, float) and obj.is_integer() and abs(obj) < 10 ** 15:
        return int(obj)                 # a document built in SQL (jsonb) keeps 17100000.0: shares are integers
    return obj


def _fmt_money(v) -> str:
    return f"{decimal.Decimal(str(v)).quantize(decimal.Decimal('0.01')):f}"


def dumps(obj) -> str:
    return db.to_json(_money(obj))


class Refusal(Exception):
    """An answer, not a crash: the code, the sentence, whether trying again could help, and any extra keys (fields, candidates)."""

    def __init__(self, code: str, message: str, retry: bool = False, **extra) -> None:
        super().__init__(message)
        self.code, self.message, self.retry, self.extra = code, message, retry, extra


def fail(code: str, message: str, retry: bool = False, **extra) -> str:
    return dumps({"ok": False, "error": {"code": code, "message": message, "retry": retry, **extra}})


def answers(fn):
    """Turn a Refusal (and the database's refusals) into the tool's error answer; the tool never raises."""
    @functools.wraps(fn)
    async def inner(*a, **k):
        try:
            return await fn(*a, **k)
        except Refusal as r:
            return fail(r.code, r.message, r.retry, **r.extra)
        except asyncpg.NoDataFoundError:
            return fail("not_found", "No record you can read matches that.")
        except asyncpg.InsufficientPrivilegeError as ex:
            return fail("managers_only", (getattr(ex, "message", "") or "That is for a manager.")[:200])
        except asyncpg.PostgresError as ex:
            if isinstance(ex, (asyncpg.DataError, asyncpg.InvalidTextRepresentationError, asyncpg.CheckViolationError, asyncpg.RaiseError)):
                msg = getattr(ex, "message", "") or ""
                return fail("invalid_argument", (msg[:200] if isinstance(ex, asyncpg.RaiseError) else "A parameter is out of range or not of the right kind."))
            return fail("server_error", "The question could not be answered: " + ex.__class__.__name__ + ".", retry=True)
    return inner


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


def off(n: int | None) -> int:
    return max(0, min(int(n or 0), 10000))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def like(s: str) -> str:
    return "%" + s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"


def resolving(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given: the kernel's entity resolver's call. It wants ONE row for an exact name or key — more than one is 'several match' and the action stops."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def exact_first(rows: list[dict], q: str | None, *cols: str) -> list[dict]:
    """The rows that equal q exactly (case-insensitive) in one of $cols, when there are any; else the rows as they are."""
    t = (q or "").strip().lower()
    hit = [r for r in rows if t and any(str(r.get(c) if r.get(c) is not None else "").lower() == t for c in cols)]
    return hit if hit else rows


def listing(rows: list[dict], limit: int, **more) -> str:
    """The list envelope: {ok, count, rows, truncated} — the kernel's resolver reads `rows`. $rows may hold one more than $limit (the probe for `truncated`)."""
    truncated = len(rows) > limit
    rows = rows[:limit]
    return dumps({"ok": True, "count": len(rows), "rows": rows, "truncated": truncated, **more})


def detail(**fields) -> str:
    return dumps({"ok": True, **fields})


async def page(q, sql: str, args: list, limit: int, offset: int = 0, order: str = "") -> list[dict]:
    """Run `sql` (no ORDER/LIMIT) for one page plus one probe row; the caller hands the result to listing()."""
    return await q(f"{sql} {order} LIMIT {int(limit) + 1} OFFSET {int(offset)}", *args)


def is_int(v) -> bool:
    return isinstance(v, int) or (isinstance(v, str) and v.strip().isdigit())


def cut(s: str | None, n: int) -> str | None:
    return None if s is None else (s if len(s) <= n else s[: n - 1].rstrip() + "…")


def day(s, what: str = "The date") -> datetime.date | None:
    """A YYYY-MM-DD date (None stays None)."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.date) and not isinstance(s, datetime.datetime):
        return s
    try:
        return datetime.date.fromisoformat(str(s).strip()[:10])
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02).", fields=[what]) from ex


def when(s, what: str = "The time", end_of_day: bool = False) -> datetime.datetime | None:
    """A YYYY-MM-DD day or an ISO 8601 time as an aware datetime (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.datetime):
        return s if s.tzinfo else s.replace(tzinfo=datetime.timezone.utc)
    t = str(s).strip()
    try:
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", t):
            d = datetime.date.fromisoformat(t)
            return datetime.datetime.combine(d + datetime.timedelta(days=1 if end_of_day else 0), datetime.time.min, tzinfo=datetime.timezone.utc)
        v = datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).", fields=[what]) from ex
    return v if v.tzinfo else v.replace(tzinfo=datetime.timezone.utc)


def ago(days: int) -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days)


def span(a, b, what_a: str = "from", what_b: str = "to") -> tuple[datetime.date, datetime.date]:
    """A window of two days, the earlier first (a reversed one is swapped, as the screens do)."""
    d1, d2 = day(a, what_a), day(b, what_b)
    if d1 is None or d2 is None:
        raise Refusal("invalid_argument", f"Give both {what_a} and {what_b} (YYYY-MM-DD).", fields=[what_a, what_b])
    return (d2, d1) if d1 > d2 else (d1, d2)


async def flag(q, sql: str, *args) -> bool:
    rows = await q("SELECT " + sql + " AS ok", *args)
    return bool(rows and rows[0]["ok"])


async def me(q) -> int | None:
    rows = await q("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m")
    return rows[0]["m"] if rows else None


async def has_right(q, key: str) -> bool:
    """Whether the asking member holds a right — the SQL's own answer (si_has_right), asked only to choose the words of a refusal or a field, never to filter rows."""
    return await flag(q, "si_has_right($1)", key)


async def pii(q) -> bool:
    """THE PII WALL: whether the caller is an Analyst or above (si_sees_holder_pii(), once per tool)."""
    return await flag(q, "si_sees_holder_pii()")


async def is_manager(q) -> bool:
    return await flag(q, "si_is_manager()")


async def is_admin(q) -> bool:
    return await flag(q, "si_is_admin()")


async def is_member(q) -> bool:
    return await flag(q, "si_is_member_here()")


def candidates(rows: list[dict], id_field: str, label_field: str) -> list[dict]:
    return [{"id": r[id_field], "label": r[label_field]} for r in rows[:6]]


def ambiguous(what: str, v, rows: list[dict], id_field: str, label_field: str) -> Refusal:
    return Refusal("ambiguous", f"More than one {what} matches '{v}'; ask which (or give its id).", candidates=candidates(rows, id_field, label_field))


def not_found(what: str = "record") -> Refusal:
    return Refusal("not_found", f"No {what} you can read matches that.")


def pii_withheld(aggregate_tool: str | None = None, what: str = "A natural person's details") -> Refusal:
    more = f" The aggregate form is {aggregate_tool}." if aggregate_tool else ""
    return Refusal("pii_withheld", f"{what} are an Analyst's to see.{more}")


def managers_only(what: str) -> Refusal:
    return Refusal("managers_only", f"{what} is a manager's.")


# ---- the current issuer and security: through the views, so what the caller may not see does not exist for them -------------------------------------

async def issuer_row(q, issuer: Ref | None = None, security: Ref | None = None) -> dict:
    """The mcp_issuers row (the wall's) a call is about. `issuer` is an id, a ticker, a CIK or a name; failing that the issuer of `security`; failing that the one issuer the caller may see.
    Several visible and none named → ambiguous with the candidates; one the wall hides → not_found, in the words of one that does not exist."""
    if issuer in (None, ""):
        if security not in (None, ""):
            return await issuer_of_security(q, security)
        rows = await q("SELECT * FROM mcp_issuers ORDER BY name LIMIT 7")
        if len(rows) == 1:
            return rows[0]
        if not rows:
            raise not_found("issuer")
        raise Refusal("ambiguous", "Several issuers are visible; name one (issuer: an id, a ticker, a CIK or a name).", candidates=candidates(rows, "issuer_id", "name"))
    s = str(issuer).strip()
    if is_int(issuer) and len(s) != 10:
        rows = await q("SELECT * FROM mcp_issuers WHERE issuer_id = $1", int(s))
        if rows:
            return rows[0]
    rows = await q("SELECT * FROM mcp_issuers WHERE upper(coalesce(ticker, '')) = upper($1) OR cik = lpad($1, 10, '0') AND $1 ~ '^[0-9]{1,10}$' OR lower(name) = lower($1) ORDER BY issuer_id LIMIT 7", s)
    if len(rows) > 1:
        raise ambiguous("issuer", s, rows, "issuer_id", "name")
    if rows:
        return rows[0]
    rows = await q("SELECT * FROM mcp_issuers WHERE name ILIKE $1 ORDER BY name LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("issuer", s, rows, "issuer_id", "name")
    if rows:
        return rows[0]
    raise not_found("issuer")


async def issuer_of_security(q, security: Ref) -> dict:
    sec = await security_row(q, security)
    rows = await q("SELECT * FROM mcp_issuers WHERE issuer_id = $1", sec["issuer_id"])
    if not rows:
        raise not_found("issuer")
    return rows[0]


async def security_row(q, security: Ref | None = None, issuer: Ref | None = None) -> dict:
    """The mcp_securities row (the wall's) a call is about: `security` is an id, a CUSIP, an ISIN or a ticker (narrowed to `issuer` when given); failing that the primary security of the issuer."""
    if security in (None, ""):
        iss = await issuer_row(q, issuer)
        if iss.get("primary_security_id") is None:
            raise Refusal("no_data", f"{iss['name']} has no security yet; a manager adds one.", fields=["security"])
        rows = await q("SELECT * FROM mcp_securities WHERE security_id = $1", iss["primary_security_id"])
        if not rows:
            raise not_found("security")
        return rows[0]
    s = str(security).strip()
    iss_id = None
    if issuer not in (None, ""):
        iss_id = (await issuer_row(q, issuer))["issuer_id"]
    tail = " AND issuer_id = $2" if iss_id is not None else ""
    extra = [iss_id] if iss_id is not None else []
    if is_int(security) and len(s) < 9:
        rows = await q(f"SELECT * FROM mcp_securities WHERE security_id = $1{tail}", int(s), *extra)
        if rows:
            return rows[0]
    rows = await q(f"SELECT * FROM mcp_securities WHERE (upper(cusip) = upper($1) OR upper(coalesce(isin, '')) = upper($1) OR upper(coalesce(ticker, '')) = upper($1)){tail} ORDER BY is_primary DESC, security_id LIMIT 7", s, *extra)
    if len(rows) > 1 and len({r["issuer_id"] for r in rows}) > 1:
        raise ambiguous("security", s, rows, "security_id", "name")
    if rows:
        return rows[0]
    if is_int(security):
        rows = await q(f"SELECT * FROM mcp_securities WHERE security_id = $1{tail}", int(s), *extra)
        if rows:
            return rows[0]
    rows = await q(f"SELECT * FROM mcp_securities WHERE name ILIKE $1{tail} ORDER BY is_primary DESC, security_id LIMIT 7", like(s), *extra)
    if len(rows) == 1:
        return rows[0]
    if rows:
        raise ambiguous("security", s, rows, "security_id", "name")
    raise not_found("security")


async def holder_row(q, holder: Ref, *, need_pii: bool = False) -> dict:
    """The mcp_holders row (both walls) of a holder named by id. A natural person's row is there for a Viewer, nulled; `need_pii` makes a withheld one a pii_withheld refusal."""
    if holder in (None, "") or not is_int(holder):
        raise Refusal("invalid_argument", "A holder is named by its id (find_holders resolves a name for an Analyst).", fields=["holder"])
    rows = await q("SELECT * FROM mcp_holders WHERE holder_id = $1", int(holder))
    if not rows:
        raise not_found("holder")
    if need_pii and rows[0].get("pii_withheld"):
        raise pii_withheld("holders_changes or holders_by_type")
    return rows[0]


async def participant_row(q, participant: Ref) -> dict:
    if participant in (None, ""):
        raise Refusal("invalid_argument", "Name the participant (its id, its DTC number or its name).", fields=["participant"])
    s = str(participant).strip()
    if is_int(participant) and len(s) != 4:
        rows = await q("SELECT * FROM mcp_participants WHERE participant_id = $1", int(s))
        if rows:
            return rows[0]
    if s.isdigit():
        rows = await q("SELECT * FROM mcp_participants WHERE number = lpad($1, 4, '0')", s)
        if rows:
            return rows[0]
    rows = await q("SELECT * FROM mcp_participants WHERE lower(name) = lower($1) OR EXISTS (SELECT 1 FROM unnest(aliases) a WHERE lower(a) = lower($1)) ORDER BY number LIMIT 7", s)
    if not rows:
        rows = await q("SELECT * FROM mcp_participants WHERE name ILIKE $1 ORDER BY number LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("participant", s, rows, "participant_id", "name")
    if rows:
        return rows[0]
    raise not_found("participant")


async def one_by_id(q, what: str, view: str, id_col: str, v) -> dict:
    if v in (None, "") or not is_int(v):
        raise Refusal("invalid_argument", f"A {what} is named by its id.", fields=[what.replace(" ", "_")])
    rows = await q(f"SELECT * FROM {view} WHERE {id_col} = $1", int(v))
    if not rows:
        raise not_found(what)
    return rows[0]


async def member_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the member (their id, or their name).", fields=["member"])
    if str(v).strip().lower() == "me":
        m = await me(q)
        if m is None:
            raise not_found("member")
        return int(m)
    if is_int(v):
        rows = await q("SELECT member_id FROM mcp_members WHERE member_id = $1", int(v))
        if rows:
            return int(rows[0]["member_id"])
    s = str(v).strip()
    rows = await q("SELECT member_id, display_name FROM mcp_members WHERE lower(display_name) = lower($1) LIMIT 7", s)
    if not rows:
        rows = await q("SELECT member_id, display_name FROM mcp_members WHERE display_name ILIKE $1 ORDER BY display_name LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("member", s, rows, "member_id", "display_name")
    if rows:
        return int(rows[0]["member_id"])
    raise not_found("member")


# ---- every field of every parameter model carries a description (the surface: "a described Field on every field") ----------------------------------

DESCRIBE = {
    "limit": "How many rows at most", "offset": "Skip this many rows (paging)", "issuer": "The issuer (id, ticker, CIK or name); omitted: the one issuer you may see",
    "security": "The security (id, CUSIP or ticker); omitted: the issuer's primary one", "q": "Words to look for (alone, also resolves one record by its name or key)",
    "from": "A day, YYYY-MM-DD", "to": "A day, YYYY-MM-DD", "date": "A day, YYYY-MM-DD", "since": "A day, YYYY-MM-DD — only what is newer", "holder": "A holder (id)", "participant": "A DTC participant (id, number or name)",
    "status": "The status", "kind": "The kind", "class": "The participant class key", "channel": "nobo (the NOBO list) or register (the transfer agent's register)", "record_date": "A record date, YYYY-MM-DD",
    "upload": "An upload (id)", "filing": "A filing (id)", "signal": "A signal (id)", "report": "A pack (id)", "rule": "A rule (key or id)", "member": "A person or an agent (id, name or me)",
    "institution": "An institution (id, CIK or name)", "period": "A quarter end, YYYY-MM-DD; omitted: the latest",
}


def describe_models(ns: dict) -> None:
    """Give every field without a description one (called at the foot of each tool module, before register() builds the tools)."""
    for obj in list(ns.values()):
        if isinstance(obj, type) and issubclass(obj, BaseModel) and obj.__module__ == ns["__name__"] and obj is not _Base:
            changed = False
            for name, f in obj.model_fields.items():
                if not f.description:
                    key = (f.alias or name).rstrip("_")
                    f.description = DESCRIBE.get(key) or name.replace("_", " ").capitalize()
                    changed = True
            if changed:
                obj.model_rebuild(force=True, _types_namespace=ns)


class SecIn(_Base):
    """The base of the tools about one security: `security` (+ `issuer` to narrow a ticker). Omitted, the issuer's primary security."""
    issuer: Ref | None = Field(None, description="The issuer (id, ticker, CIK or name); narrows a ticker, or names the issuer whose primary security is meant")
    security: Ref | None = Field(None, description="The security (id, CUSIP or ticker); omitted: the issuer's primary one (or the one issuer you may see)")


async def log_read(q, action: str, after: dict, issuer_id: int | None = None, request_id: str | None = None, run_id: int | None = None) -> None:
    """The one write a read server makes (db/028 si_log_mcp_read): never an answer, a holder's name, a credential or a token."""
    await q("SELECT si_log_mcp_read($1, $2::jsonb, $3, $4, $5)", action, after, issuer_id, request_id, run_id)      # the pool's jsonb codec encodes the dict
