-- 010: THE OWNERSHIP FORMS — institutions (13F and 13D/13G filers), institutional holdings (the 13F grain), beneficial ownership
-- (13D/13G), insider transactions (Forms 3/4/5), Form 144 notices (design §6.5; D9).
--
-- The column names follow the SEC's own where one exists (the 13F data set's INFOTABLE: SSHPRNAMT → shares, SSHPRNAMTTYPE → shares_type,
-- PUTCALL → put_call, INVESTMENTDISCRETION → discretion, VOTING_AUTH_SOLE/SHARED/NONE → voting_*; the ownership XML's transactionCode,
-- sharesOwnedFollowingTransaction, directOrIndirectOwnership) so a reader of the SEC's readme recognises them. Every row names its filing.
--
-- THE ESTATE'S DATA MODEL (design §6.8): all NEW CANONICAL — nothing in the estate reads EDGAR.
BEGIN;

CREATE TABLE institutions (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cik          text NOT NULL UNIQUE CHECK (cik ~ '^[0-9]{10}$'),
    name         text NOT NULL,
    kind         text NOT NULL DEFAULT 'other' CHECK (kind IN ('investment_adviser', 'hedge_fund', 'bank', 'insurance', 'pension', 'mutual_fund', 'etf_sponsor', 'activist', 'individual', 'corporation', 'family_office', 'other')),
    kind_source  text NOT NULL DEFAULT 'proposed' CHECK (kind_source IN ('proposed', 'manual')),
    is_activist  boolean NOT NULL DEFAULT false,
    aliases      text[] NOT NULL DEFAULT '{}',
    website      text,
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX institutions_name_trgm_idx ON institutions USING gin (name gin_trgm_ops);
CREATE TRIGGER institutions_touch BEFORE UPDATE ON institutions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE holders ADD CONSTRAINT holders_institution_fk FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL;

-- A kind proposed from the name's words (a person corrects; manual is never overwritten).
CREATE OR REPLACE FUNCTION si_institution_kind_from_name(p_name text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN n ~ '(ETF|ISHARES|SPDR|INDEX FUND)' THEN 'etf_sponsor'
        WHEN n ~ '(MUTUAL FUND|FUNDS TRUST|INVESTMENT TRUST)' THEN 'mutual_fund'
        WHEN n ~ '(PENSION|RETIREMENT SYSTEM|TEACHERS|EMPLOYEES RETIREMENT|PUBLIC EMPLOYEES)' THEN 'pension'
        WHEN n ~ '(INSURANCE|ASSURANCE|LIFE CO)' THEN 'insurance'
        WHEN n ~ '(BANK|BANCORP|TRUST CO|TRUST COMPANY)' THEN 'bank'
        WHEN n ~ '(FAMILY OFFICE)' THEN 'family_office'
        WHEN n ~ '(CAPITAL MANAGEMENT|CAPITAL PARTNERS|CAPITAL LP|HEDGE|MASTER FUND|OPPORTUNITIES FUND)' THEN 'hedge_fund'
        WHEN n ~ '(ADVISORS|ADVISERS|ASSET MANAGEMENT|INVESTMENT MANAGEMENT|WEALTH MANAGEMENT|INVESTMENTS)' THEN 'investment_adviser'
        ELSE 'other' END
      FROM (SELECT upper(COALESCE(p_name, '')) AS n) x;
$$;
CREATE OR REPLACE FUNCTION si_institution_upsert(p_cik text, p_name text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE iid bigint; nm text := btrim(regexp_replace(COALESCE(p_name, ''), '\s+', ' ', 'g'));
BEGIN
    SELECT id INTO iid FROM institutions WHERE cik = p_cik FOR UPDATE;
    IF iid IS NULL THEN
        INSERT INTO institutions (cik, name, kind) VALUES (p_cik, nm, si_institution_kind_from_name(nm)) RETURNING id INTO iid;
    ELSIF nm <> '' AND nm <> (SELECT name FROM institutions WHERE id = iid) THEN
        UPDATE institutions SET aliases = (SELECT array_agg(DISTINCT a) FROM unnest(aliases || ARRAY[name]) a WHERE a <> nm), name = nm WHERE id = iid;
    END IF;
    RETURN iid;
END$$;

-- the 13F grain: one filer, one security, one quarter, one filing
CREATE TABLE institutional_holdings (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    institution_id    bigint NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    security_id       bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    period_end        date NOT NULL,                                                -- the quarter's last day (REPORTCALENDARORQUARTER)
    filing_id         bigint REFERENCES filings(id) ON DELETE SET NULL,
    accession_number  text,
    value_dollars     numeric(18,2),                                               -- VALUE (dollars since 2023-01-03; thousands before — the fetcher scales)
    shares            numeric(20,0) NOT NULL CHECK (shares >= 0),                   -- SSHPRNAMT
    shares_type       text NOT NULL DEFAULT 'SH' CHECK (shares_type IN ('SH', 'PRN')),
    put_call          text CHECK (put_call IS NULL OR put_call IN ('PUT', 'CALL')),
    discretion        text CHECK (discretion IS NULL OR discretion IN ('SOLE', 'DEFINED', 'OTHER')),
    other_managers    text,
    voting_sole       numeric(20,0),
    voting_shared     numeric(20,0),
    voting_none       numeric(20,0),
    is_amendment      boolean NOT NULL DEFAULT false,
    name_of_issuer    text,                                                        -- as filed
    title_of_class    text,
    cusip             text,
    figi              text,
    loaded_at         timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX institutional_holdings_uq ON institutional_holdings (institution_id, security_id, period_end, COALESCE(accession_number, ''), COALESCE(put_call, ''));
CREATE INDEX institutional_holdings_security_period_idx ON institutional_holdings (security_id, period_end DESC);
CREATE INDEX institutional_holdings_institution_idx ON institutional_holdings (institution_id, period_end DESC);

-- The quarter's holders (puts and calls aside) and the change against the prior quarter: new, out, up, down, same.
CREATE OR REPLACE FUNCTION si_13f_changes(p_security bigint, p_period date)
    RETURNS TABLE (institution_id bigint, name text, kind text, shares numeric, prior_shares numeric, net numeric, value_dollars numeric, state text, voting_sole numeric, pct_of_outstanding numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH prior AS (SELECT max(period_end) AS p FROM institutional_holdings WHERE security_id = p_security AND period_end < p_period AND put_call IS NULL),
         a AS (SELECT institution_id, sum(shares) AS s FROM institutional_holdings, prior WHERE security_id = p_security AND period_end = prior.p AND put_call IS NULL GROUP BY institution_id),
         b AS (SELECT institution_id, sum(shares) AS s, sum(value_dollars) AS v, sum(voting_sole) AS vs FROM institutional_holdings WHERE security_id = p_security AND period_end = p_period AND put_call IS NULL GROUP BY institution_id),
         o AS (SELECT si_share_count(p_security, 'outstanding', p_period) AS n)
    SELECT COALESCE(a.institution_id, b.institution_id), i.name, i.kind, b.s, a.s, COALESCE(b.s, 0) - COALESCE(a.s, 0), b.v,
           CASE WHEN a.institution_id IS NULL THEN 'new' WHEN b.institution_id IS NULL OR b.s = 0 THEN 'out' WHEN b.s > a.s THEN 'up' WHEN b.s < a.s THEN 'down' ELSE 'same' END,
           b.vs, CASE WHEN o.n > 0 THEN round(COALESCE(b.s, 0) / o.n * 100, 3) END
      FROM a FULL JOIN b ON b.institution_id = a.institution_id JOIN institutions i ON i.id = COALESCE(a.institution_id, b.institution_id), o
     ORDER BY COALESCE(b.s, 0) DESC, abs(COALESCE(b.s, 0) - COALESCE(a.s, 0)) DESC;
$$;
CREATE OR REPLACE FUNCTION si_13f_summary(p_security bigint, p_period date)
    RETURNS TABLE (period_end date, holders integer, shares numeric, value_dollars numeric, pct_of_outstanding numeric, top10_pct numeric, puts_shares numeric, calls_shares numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH h AS (SELECT institution_id, sum(shares) AS s, sum(value_dollars) AS v FROM institutional_holdings WHERE security_id = p_security AND period_end = p_period AND put_call IS NULL GROUP BY institution_id),
         r AS (SELECT s, row_number() OVER (ORDER BY s DESC) AS rn FROM h), o AS (SELECT si_share_count(p_security, 'outstanding', p_period) AS n)
    SELECT p_period, (SELECT count(*) FROM h)::integer, (SELECT sum(s) FROM h), (SELECT sum(v) FROM h),
           CASE WHEN o.n > 0 THEN round((SELECT sum(s) FROM h) / o.n * 100, 3) END,
           round((SELECT sum(s) FROM r WHERE rn <= 10) / NULLIF((SELECT sum(s) FROM h), 0) * 100, 2),
           (SELECT sum(shares) FROM institutional_holdings WHERE security_id = p_security AND period_end = p_period AND put_call = 'PUT'),
           (SELECT sum(shares) FROM institutional_holdings WHERE security_id = p_security AND period_end = p_period AND put_call = 'CALL')
      FROM o;
$$;

-- 13D / 13G
CREATE TABLE beneficial_ownership (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    institution_id            bigint NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,   -- a person files as an institution of kind individual
    security_id               bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    filing_id                 bigint REFERENCES filings(id) ON DELETE SET NULL,
    schedule                  text NOT NULL CHECK (schedule IN ('13D', '13G')),
    is_amendment              boolean NOT NULL DEFAULT false,
    amendment_no              integer,
    filed_on                  date NOT NULL,
    event_date                date,
    percent_of_class          numeric(7,3),
    shares_beneficially_owned numeric(20,0),
    sole_voting               numeric(20,0),
    shared_voting             numeric(20,0),
    sole_dispositive          numeric(20,0),
    shared_dispositive        numeric(20,0),
    purpose_text              text,                                                 -- item 4 (13D)
    group_members             text[] NOT NULL DEFAULT '{}',
    filer_type                text CHECK (filer_type IS NULL OR filer_type IN ('qii', 'passive', 'exempt', 'active')),
    raw_xml_sha               text,
    loaded_at                 timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX beneficial_ownership_uq ON beneficial_ownership (institution_id, security_id, filed_on, schedule, COALESCE(amendment_no, 0));
CREATE INDEX beneficial_ownership_security_idx ON beneficial_ownership (security_id, filed_on DESC);
-- The current 5% holders: the latest 13D/13G per filer, with percent.
CREATE OR REPLACE FUNCTION si_beneficial_owners(p_security bigint)
    RETURNS TABLE (institution_id bigint, name text, kind text, schedule text, percent_of_class numeric, shares numeric, filed_on date, event_date date, purpose_text text, filing_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT DISTINCT ON (b.institution_id) b.institution_id, i.name, i.kind, b.schedule, b.percent_of_class, b.shares_beneficially_owned, b.filed_on, b.event_date, left(b.purpose_text, 2000), b.filing_id
      FROM beneficial_ownership b JOIN institutions i ON i.id = b.institution_id WHERE b.security_id = p_security
     ORDER BY b.institution_id, b.filed_on DESC, b.amendment_no DESC NULLS LAST;
$$;

-- Forms 3 / 4 / 5
CREATE TABLE insider_transactions (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id              bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    security_id            bigint REFERENCES securities(id) ON DELETE SET NULL,
    filing_id              bigint REFERENCES filings(id) ON DELETE SET NULL,
    form                   text NOT NULL CHECK (form IN ('3', '4', '5', '3/A', '4/A', '5/A')),
    reporting_owner_cik    text NOT NULL CHECK (reporting_owner_cik ~ '^[0-9]{10}$'),
    reporting_owner_name   text NOT NULL,
    holder_id              bigint REFERENCES holders(id) ON DELETE SET NULL,       -- matched to the register
    relationship           text[] NOT NULL DEFAULT '{}',                             -- director, officer, ten_percent_owner, other
    officer_title          text,
    transaction_date       date,
    transaction_code       text CHECK (transaction_code IS NULL OR transaction_code ~ '^[A-Z]$'),   -- P S A M F G D C W J …
    shares                 numeric(20,4),
    price                  numeric(14,4),
    acquired_disposed      text CHECK (acquired_disposed IS NULL OR acquired_disposed IN ('A', 'D')),
    shares_owned_after     numeric(20,4),
    ownership              text CHECK (ownership IS NULL OR ownership IN ('D', 'I')),
    indirect_nature        text,
    security_title         text,
    is_derivative          boolean NOT NULL DEFAULT false,
    exercise_price         numeric(14,4),
    expiration_date        date,
    footnotes              text[] NOT NULL DEFAULT '{}',
    is_10b5_1              boolean,
    row_no                 integer NOT NULL DEFAULT 1,
    loaded_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (filing_id, is_derivative, row_no)
);
CREATE INDEX insider_transactions_issuer_idx ON insider_transactions (issuer_id, transaction_date DESC);
CREATE INDEX insider_transactions_owner_idx ON insider_transactions (reporting_owner_cik, transaction_date DESC);
-- An insider's filing marks the holder (when matched) and the issuer's holders by name key
CREATE OR REPLACE FUNCTION si_insider_mark() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.holder_id IS NULL THEN
        SELECT id INTO NEW.holder_id FROM holders WHERE issuer_id = NEW.issuer_id AND deleted_at IS NULL AND merged_into_id IS NULL AND kind <> 'entity'
           AND name_key = si_name_key(regexp_replace(NEW.reporting_owner_name, '^([^,]+),\s*(.+)$', '\2 \1'))  -- "SMITH ROBERT" / "Smith, Robert"
         ORDER BY last_seen_on DESC NULLS LAST LIMIT 1;
    END IF;
    IF NEW.holder_id IS NOT NULL THEN UPDATE holders SET is_insider = true, insider_cik = COALESCE(insider_cik, NEW.reporting_owner_cik) WHERE id = NEW.holder_id; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER insider_transactions_mark BEFORE INSERT ON insider_transactions FOR EACH ROW EXECUTE FUNCTION si_insider_mark();

CREATE TABLE form144_notices (
    id                         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id                  bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    security_id                bigint REFERENCES securities(id) ON DELETE SET NULL,
    filing_id                  bigint REFERENCES filings(id) ON DELETE SET NULL,
    filed_on                   date NOT NULL,
    seller_name                text NOT NULL,
    seller_cik                 text CHECK (seller_cik IS NULL OR seller_cik ~ '^[0-9]{10}$'),
    relationship               text,
    shares_to_sell             numeric(20,0),
    aggregate_market_value     numeric(18,2),
    approx_sale_date           date,
    broker                     text,
    shares_outstanding_stated  numeric(20,0),
    loaded_at                  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (filing_id)
);
CREATE INDEX form144_issuer_idx ON form144_notices (issuer_id, filed_on DESC);

-- Insider activity over a window (§7 Q21; the rule insider_sales).
CREATE OR REPLACE FUNCTION si_insider_summary(p_issuer bigint, p_from date, p_to date)
    RETURNS TABLE (reporting_owner_cik text, reporting_owner_name text, holder_id bigint, sales integer, sale_shares numeric, buys integer, buy_shares numeric, awards integer, last_owned_after numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT t.reporting_owner_cik, max(t.reporting_owner_name), max(t.holder_id),
           count(*) FILTER (WHERE t.transaction_code = 'S')::integer, COALESCE(sum(t.shares) FILTER (WHERE t.transaction_code = 'S'), 0),
           count(*) FILTER (WHERE t.transaction_code = 'P')::integer, COALESCE(sum(t.shares) FILTER (WHERE t.transaction_code = 'P'), 0),
           count(*) FILTER (WHERE t.transaction_code = 'A')::integer,
           (SELECT shares_owned_after FROM insider_transactions x WHERE x.reporting_owner_cik = t.reporting_owner_cik AND x.issuer_id = p_issuer AND NOT x.is_derivative ORDER BY transaction_date DESC NULLS LAST, id DESC LIMIT 1)
      FROM insider_transactions t WHERE t.issuer_id = p_issuer AND NOT t.is_derivative AND t.transaction_date BETWEEN p_from AND p_to
     GROUP BY t.reporting_owner_cik ORDER BY 5 DESC;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON institutions, institutional_holdings, beneficial_ownership, insider_transactions, form144_notices TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_institution_kind_from_name(text), si_13f_changes(bigint, date), si_13f_summary(bigint, date), si_beneficial_owners(bigint), si_insider_summary(bigint, date, date)
    TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_institution_upsert(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_institution_upsert(text, text) TO shareholder_intelligence_rw;

COMMIT;
