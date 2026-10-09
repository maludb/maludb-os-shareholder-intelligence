-- 006: ISSUERS, SECURITIES, SHARE COUNTS, the ISSUER WALL, and the DTC PARTICIPANTS with their classes (design §3, §6.1; D3, D7).
--
-- An issuer is a company whose shares are tracked: one for the business tracking itself, one per client for an advisory firm. The
-- WALL (D3): an issuer is open to every role holder unless it carries a restriction list (issuer_members); then only the listed
-- members, managers and the admin see it and everything under it. si_can_see_issuer(id) is the one gate behind every view, tool,
-- share and export; the caller's facts are STABLE functions evaluated once per statement (the kernel's db/160 lesson).
--
-- A participant is a DTC member with a four-character number (Goldman "0005", Schwab "0164"). The public directory fetched monthly
-- upserts them; a CLASS buckets them (retail, prime, market maker, lending …) — a setting a manager corrects, never a fact: the signal
-- that reads classes says so (D7). The seeds below are design §6.1's table (DTCC's alphabetical listing of 2026-09-30).
--
-- THE ESTATE'S DATA MODEL (design §6.8): issuers, issuer_members, securities, share_counts, participants, participant_classes are NEW
-- CANONICAL — nothing in the estate is a listed company with CUSIPs, and nothing knows DTC.
BEGIN;

CREATE TABLE issuers (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                      text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    cik                       text UNIQUE CHECK (cik IS NULL OR cik ~ '^[0-9]{10}$'),          -- ten digits, zero-padded (EDGAR's)
    ticker                    text CHECK (ticker IS NULL OR ticker ~ '^[A-Z0-9.\-]{1,12}$'),   -- the primary class's
    exchange                  text NOT NULL DEFAULT 'nasdaq' CHECK (exchange IN ('nasdaq', 'nyse', 'nyse_american', 'otc', 'cse', 'tsx', 'tsxv', 'other')),
    state_of_incorporation    text,
    fiscal_year_end           text CHECK (fiscal_year_end IS NULL OR fiscal_year_end ~ '^(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])$'),  -- MMDD, EDGAR's
    sic                       text CHECK (sic IS NULL OR sic ~ '^[0-9]{4}$'),
    ein                       text,
    transfer_agent            text,                                                           -- the agent's name
    transfer_agent_connector  text NOT NULL DEFAULT 'generic' CHECK (transfer_agent_connector IN ('stc', 'computershare', 'continental', 'vstock', 'pacific', 'equiniti', 'generic')),
    edgar_entity              jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(edgar_entity) = 'object'),   -- the submissions JSON's scalars, addresses, former names
    edgar_synced_at           timestamptz,
    market_holidays           date[] NOT NULL DEFAULT '{}',                                   -- NULL-equivalent empty = the settings'
    timezone                  text,                                                           -- NULL = the settings' market timezone
    notes                     text,
    created_by                bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    deleted_at                timestamptz,
    deleted_by                bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX issuers_ticker_idx ON issuers (ticker) WHERE deleted_at IS NULL;
CREATE INDEX issuers_name_trgm_idx ON issuers USING gin (name gin_trgm_ops);
CREATE TRIGGER issuers_touch BEFORE UPDATE ON issuers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The restriction list (D3): any row = restricted to these members (+ managers, the admin).
CREATE TABLE issuer_members (
    issuer_id   bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    added_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    added_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (issuer_id, member_id)
);
CREATE INDEX issuer_members_member_idx ON issuer_members (member_id);

CREATE TABLE securities (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id     bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    class         text NOT NULL DEFAULT 'common' CHECK (class IN ('common', 'preferred', 'warrant', 'unit', 'note', 'other')),
    name          text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),                      -- "Common Stock", "Series A Preferred"
    cusip         text UNIQUE CHECK (cusip IS NULL OR cusip ~ '^[0-9A-Z]{9}$'),
    isin          text CHECK (isin IS NULL OR isin ~ '^[A-Z]{2}[0-9A-Z]{9}[0-9]$'),
    ticker        text CHECK (ticker IS NULL OR ticker ~ '^[A-Z0-9.\-]{1,12}$'),
    figi          text CHECK (figi IS NULL OR figi ~ '^[A-Z0-9]{12}$'),
    par_value     numeric(12,6),
    is_primary    boolean NOT NULL DEFAULT false,
    listed_on     date,
    delisted_on   date,
    active        boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (delisted_on IS NULL OR listed_on IS NULL OR delisted_on >= listed_on)
);
CREATE UNIQUE INDEX securities_one_primary ON securities (issuer_id) WHERE is_primary;
CREATE INDEX securities_issuer_idx ON securities (issuer_id);
CREATE TRIGGER securities_touch BEFORE UPDATE ON securities FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A share count is a dated fact from a source (§6.1): the XBRL cover fact, the agent's control total, the DTC letter, a person, the SPR.
CREATE TABLE share_counts (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    security_id   bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    as_of         date NOT NULL,
    kind          text NOT NULL CHECK (kind IN ('outstanding', 'float', 'authorized', 'restricted', 'unrestricted', 'treasury', 'cede', 'drs', 'certificated', 'plan', 'public_float_value')),
    value         numeric(20,2) NOT NULL CHECK (value >= 0),                                 -- shares (whole), or dollars for public_float_value
    source        text NOT NULL CHECK (source IN ('filing', 'transfer_agent', 'dtc_letter', 'manual', 'spr', 'register')),
    filing_id     bigint,                                                                     -- FK added in db/009
    import_id     bigint,                                                                     -- FK added in db/007
    xbrl_frame    text,                                                                       -- the companyfacts frame ("CY2026Q2I")
    note          text,
    recorded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    recorded_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (security_id, kind, as_of, source)
);
CREATE INDEX share_counts_lookup_idx ON share_counts (security_id, kind, as_of DESC);

-- The latest value of a kind at or before a date (the reconciliation, the rules). NULL when none.
CREATE OR REPLACE FUNCTION si_share_count(p_security bigint, p_kind text, p_on date DEFAULT CURRENT_DATE) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT value FROM share_counts WHERE security_id = p_security AND kind = p_kind AND as_of <= p_on
     ORDER BY as_of DESC, CASE source WHEN 'filing' THEN 1 WHEN 'transfer_agent' THEN 2 WHEN 'dtc_letter' THEN 3 WHEN 'register' THEN 4 WHEN 'manual' THEN 5 ELSE 6 END, recorded_at DESC
     LIMIT 1;
$$;

-- ---------------------------------------------------------------------------------------------
-- THE ISSUER WALL (D3). The caller's principal facts once per statement; the per-row test is an indexed EXISTS.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_issuer_is_restricted(p_issuer bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM issuer_members WHERE issuer_id = p_issuer);
$$;

CREATE OR REPLACE FUNCTION si_can_see_issuer(p_issuer bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN false; END IF;
    IF NOT EXISTS (SELECT 1 FROM issuers WHERE id = p_issuer AND deleted_at IS NULL) THEN RETURN false; END IF;
    IF NOT EXISTS (SELECT 1 FROM issuer_members WHERE issuer_id = p_issuer) THEN RETURN true; END IF;
    IF EXISTS (SELECT 1 FROM issuer_members WHERE issuer_id = p_issuer AND member_id = me) THEN RETURN true; END IF;
    RETURN si_is_manager();
END$$;

-- The issuers the caller may see, as a set (the views' WHERE issuer_id IN (SELECT si_my_issuers()) — one scan per statement).
CREATE OR REPLACE FUNCTION si_my_issuers() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); mgr boolean;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    mgr := si_is_manager();
    RETURN QUERY
        SELECT i.id FROM issuers i
         WHERE i.deleted_at IS NULL
           AND (mgr OR NOT EXISTS (SELECT 1 FROM issuer_members r WHERE r.issuer_id = i.id)
                    OR EXISTS (SELECT 1 FROM issuer_members r WHERE r.issuer_id = i.id AND r.member_id = me));
END$$;

CREATE OR REPLACE FUNCTION si_can_see_security(p_security bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT si_can_see_issuer((SELECT issuer_id FROM securities WHERE id = p_security));
$$;

-- ---------------------------------------------------------------------------------------------
-- DTC PARTICIPANTS and their CLASSES (D7)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE participant_classes (
    key          text PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name         text NOT NULL,
    description  text NOT NULL,
    side         text NOT NULL CHECK (side IN ('retail', 'institutional', 'intermediary', 'other')),   -- the flow signal's two sides: retail boxes vs the rest
    sort_order   integer NOT NULL DEFAULT 0
);
INSERT INTO participant_classes (key, name, description, side, sort_order) VALUES
 ('retail',          'Retail broker',        'A broker whose accounts are mostly individuals (Schwab, Fidelity''s National Financial, Robinhood, Apex, DriveWealth, IBKR''s retail box).', 'retail', 1),
 ('clearing_retail', 'Clearing (retail)',    'A clearing firm that carries retail brokers'' accounts (Pershing, Wedbush, StoneX, Wells Fargo Clearing).', 'retail', 2),
 ('prime',           'Prime / institutional','A prime broker or institutional dealer (Goldman, Morgan Stanley, BofA Securities, J.P. Morgan, UBS, Barclays, Clear Street).', 'institutional', 3),
 ('market_maker',    'Market maker',         'A market maker or principal trading firm (Citadel, Virtu, Jane Street, Instinet).', 'intermediary', 4),
 ('lending',         'Lending / safekeeping','A participant''s lending, stock-loan or safekeeping account (the "/LENDING", "/STOCK LOAN", "/SAFEKEEPING" names).', 'intermediary', 5),
 ('bank_custodian',  'Bank custodian',       'A custodian bank holding for institutions and funds (BNY, State Street, Northern Trust, Citibank).', 'institutional', 6),
 ('transfer_agent',  'Transfer agent',       'A transfer agent''s own DTC accounts (Computershare, DRS, plans).', 'other', 7),
 ('other',           'Other',                'Not yet classed; the directory''s name gave no clue.', 'other', 9);

CREATE TABLE participants (
    id                         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number                     text NOT NULL UNIQUE CHECK (number ~ '^[0-9]{4}$'),
    name                       text NOT NULL,                                                   -- the directory's current name
    aliases                    text[] NOT NULL DEFAULT '{}',                                     -- the names the directory and the files used before
    class_key                  text NOT NULL DEFAULT 'other' REFERENCES participant_classes(key),
    class_source               text NOT NULL DEFAULT 'seeded' CHECK (class_source IN ('seeded', 'directory_name', 'manual')),
    directory_kind             text NOT NULL DEFAULT 'participant' CHECK (directory_kind IN ('participant', 'settling_bank', 'pledgee', 'drs')),
    first_seen_in_directory    date,
    last_seen_in_directory     date,
    active                     boolean NOT NULL DEFAULT true,
    notes                      text,
    created_at                 timestamptz NOT NULL DEFAULT now(),
    updated_at                 timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX participants_class_idx ON participants (class_key);
CREATE INDEX participants_name_trgm_idx ON participants USING gin (name gin_trgm_ops);
CREATE TRIGGER participants_touch BEFORE UPDATE ON participants FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A class proposed from the directory's words (used for a NEW number; a person's manual class is never overwritten).
CREATE OR REPLACE FUNCTION si_participant_class_from_name(p_name text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN n ~ '(/LENDING|STOCK LOAN|/SEC LENDING|SAFEKEEPING|SFKPG|/SL\M|SECURITIES LENDING|/LEND)' THEN 'lending'
        WHEN n ~ '(COMPUTERSHARE|EQUINITI|CONTINENTAL STOCK|VSTOCK|PACIFIC STOCK|SECURITIES TRANSFER CORP|AMERICAN STOCK TRANSFER|BROADRIDGE CORPORATE ISSUER)' THEN 'transfer_agent'
        WHEN n ~ '(CITADEL|VIRTU|JANE STREET|INSTINET|SUSQUEHANNA|GTS SECURITIES|TWO SIGMA|HUDSON RIVER|FLOW TRADERS|IMC )' THEN 'market_maker'
        WHEN n ~ '(BANK OF NEW YORK|BANK OF NY|BNY MELLON|BNYM|BNY\M|STATE STREET|SSB&T|NORTHERN TRUST|NORTHERN TR|CITIBANK|BROWN BROTHERS|JPMORGAN CHASE BANK|JPMCB|U\.S\. BANK|US BANK N\.A|USB )' THEN 'bank_custodian'
        WHEN n ~ '(GOLDMAN SACHS|MORGAN STANLEY & CO|BOFA SECURITIES|MERRILL LYNCH|J\.P\. MORGAN SECURITIES|JPMORGAN SECURITIES|UBS SECURITIES|UBS FINANCIAL|BARCLAYS|CLEAR STREET|JEFFERIES|CREDIT SUISSE|DEUTSCHE BANK|BMO CAPITAL|RBC CAPITAL|CANTOR|NOMURA|MIZUHO|HSBC SECURITIES|BNP PARIBAS|SOCIETE GENERALE|COWEN|PIPER SANDLER|STIFEL|RAYMOND JAMES|OPPENHEIMER|B\. RILEY)' THEN 'prime'
        WHEN n ~ '(PERSHING|WEDBUSH|STONEX|WELLS FARGO CLEARING|AXOS CLEARING|HILLTOP SECURITIES|RBC CLEARING|VISION FINANCIAL|TRADESTATION)' THEN 'clearing_retail'
        WHEN n ~ '(CHARLES SCHWAB|TD AMERITRADE|NATIONAL FINANCIAL|FIDELITY|ROBINHOOD|APEX CLEARING|DRIVEWEALTH|INTERACTIVE BROKERS|VANGUARD|E\*TRADE|ETRADE|ALLY INVEST|WEBULL|SOFI SECURITIES|PUBLIC INVESTING|M1 FINANCE|TASTYTRADE|FIRSTRADE|LPL FINANCIAL|EDWARD JONES|AMERIPRISE|JANNEY|MORGAN STANLEY SMITH BARNEY|MERRILL LYNCH PIERCE|RBC WEALTH|BAIRD)' THEN 'retail'
        ELSE 'other' END
      FROM (SELECT upper(COALESCE(p_name, '')) AS n) x;
$$;

-- The directory's seeds (design §6.1; DTCC's listing of 2026-09-30). The fetcher upserts the rest; a rename lands in aliases.
INSERT INTO participants (number, name, class_key, class_source, directory_kind) VALUES
 ('0005', 'GOLDMAN SACHS & CO. LLC', 'prime', 'seeded', 'participant'),
 ('0015', 'MORGAN STANLEY SMITH BARNEY LLC', 'retail', 'seeded', 'participant'),
 ('0017', 'INTERACTIVE BROKERS LLC', 'prime', 'seeded', 'participant'),
 ('0050', 'MORGAN STANLEY & CO. LLC', 'prime', 'seeded', 'participant'),
 ('0062', 'VANGUARD MARKETING CORPORATION', 'retail', 'seeded', 'participant'),
 ('0063', 'VIRTU AMERICAS LLC', 'market_maker', 'seeded', 'participant'),
 ('0067', 'INSTINET, LLC', 'market_maker', 'seeded', 'participant'),
 ('0103', 'WEDBUSH SECURITIES INC.', 'clearing_retail', 'seeded', 'participant'),
 ('0141', 'WELLS FARGO CLEARING SERVICES, LLC', 'clearing_retail', 'seeded', 'participant'),
 ('0158', 'APEX CLEARING CORPORATION', 'retail', 'seeded', 'participant'),
 ('0161', 'BOFA SECURITIES, INC.', 'prime', 'seeded', 'participant'),
 ('0164', 'CHARLES SCHWAB & CO., INC.', 'retail', 'seeded', 'participant'),
 ('0187', 'J.P. MORGAN SECURITIES LLC', 'prime', 'seeded', 'participant'),
 ('0221', 'UBS FINANCIAL SERVICES INC.', 'prime', 'seeded', 'participant'),
 ('0226', 'NATIONAL FINANCIAL SERVICES LLC', 'retail', 'seeded', 'participant'),
 ('0229', 'BARCLAYS CAPITAL INC.', 'prime', 'seeded', 'participant'),
 ('0295', 'VIRTU AMERICAS LLC/NITE', 'market_maker', 'seeded', 'participant'),
 ('0352', 'J.P. MORGAN SECURITIES LLC/JPMC', 'prime', 'seeded', 'participant'),
 ('0395', 'CITADEL CLEARING LLC', 'market_maker', 'seeded', 'participant'),
 ('0443', 'PERSHING LLC', 'clearing_retail', 'seeded', 'participant'),
 ('0534', 'INTERACTIVE BROKERS LLC/RETAIL', 'retail', 'seeded', 'participant'),
 ('0551', 'BOFA SECURITIES, INC./PRIME', 'prime', 'seeded', 'participant'),
 ('0671', 'MERRILL LYNCH, PIERCE, FENNER & SMITH INCORPORATED', 'retail', 'seeded', 'participant'),
 ('0750', 'STONEX FINANCIAL INC.', 'clearing_retail', 'seeded', 'participant'),
 ('2330', 'COMPUTERSHARE TRUST COMPANY, N.A./OPTIONS', 'transfer_agent', 'seeded', 'participant'),
 ('2402', 'DRIVEWEALTH, LLC', 'retail', 'seeded', 'participant'),
 ('2415', 'COMPUTERSHARE TRUST COMPANY, N.A.', 'transfer_agent', 'seeded', 'participant'),
 ('2586', 'COMPUTERSHARE TRUST COMPANY, N.A./DRP', 'transfer_agent', 'seeded', 'participant'),
 ('2820', 'JANE STREET CAPITAL, LLC', 'market_maker', 'seeded', 'participant'),
 ('2963', 'CITADEL SECURITIES INSTITUTIONAL LLC', 'market_maker', 'seeded', 'participant'),
 ('3492', 'JANE STREET EXECUTION SERVICES, LLC', 'market_maker', 'seeded', 'participant'),
 ('3691', 'ROBINHOOD SECURITIES, LLC/LENDING', 'lending', 'seeded', 'participant'),
 ('4031', 'ROBINHOOD SECURITIES, LLC/CUSTODY', 'retail', 'seeded', 'participant'),
 ('5198', 'BOFA SECURITIES, INC./SAFEKEEPING', 'lending', 'seeded', 'participant'),
 ('6769', 'ROBINHOOD SECURITIES, LLC', 'retail', 'seeded', 'participant'),
 ('8862', 'MERRILL LYNCH, PIERCE, FENNER & SMITH INC/TS', 'retail', 'seeded', 'participant'),
 ('9132', 'CLEAR STREET LLC', 'prime', 'seeded', 'participant');

-- The directory's upsert (the fetcher, slice 1; the proof): a new number is classed from its name; a renamed one keeps its class
-- and gains the old name as an alias; a manual class is never touched. Returns inserted/renamed/unchanged.
CREATE OR REPLACE FUNCTION si_participant_upsert(p_number text, p_name text, p_kind text DEFAULT 'participant', p_seen date DEFAULT CURRENT_DATE) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p participants%ROWTYPE; nm text := upper(btrim(regexp_replace(COALESCE(p_name, ''), '\s+', ' ', 'g')));
BEGIN
    IF nm = '' THEN RAISE EXCEPTION 'A participant has a name' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO p FROM participants WHERE number = p_number FOR UPDATE;
    IF NOT FOUND THEN
        INSERT INTO participants (number, name, class_key, class_source, directory_kind, first_seen_in_directory, last_seen_in_directory)
        VALUES (p_number, nm, si_participant_class_from_name(nm), 'directory_name', p_kind, p_seen, p_seen);
        RETURN 'inserted';
    END IF;
    IF p.name <> nm THEN
        UPDATE participants SET name = nm, aliases = (SELECT array_agg(DISTINCT a) FROM unnest(aliases || ARRAY[p.name]) a WHERE a <> nm),
               last_seen_in_directory = p_seen, first_seen_in_directory = COALESCE(first_seen_in_directory, p_seen), active = true,
               class_key = CASE WHEN class_source = 'manual' THEN class_key WHEN class_source = 'seeded' THEN class_key ELSE si_participant_class_from_name(nm) END
         WHERE id = p.id;
        RETURN 'renamed';
    END IF;
    UPDATE participants SET last_seen_in_directory = p_seen, first_seen_in_directory = COALESCE(first_seen_in_directory, p_seen), active = true WHERE id = p.id;
    RETURN 'unchanged';
END$$;

-- A participant by number or by any name it has carried (the files' names drift from the directory's).
CREATE OR REPLACE FUNCTION si_participant_find(p_number text, p_name text DEFAULT NULL) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT id FROM participants WHERE number = p_number
    UNION ALL
    SELECT id FROM participants WHERE p_number IS NULL AND p_name IS NOT NULL
       AND (name = upper(btrim(p_name)) OR upper(btrim(p_name)) = ANY (aliases))
    LIMIT 1;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON issuers, issuer_members, securities, share_counts, participants TO shareholder_intelligence_rw;
GRANT SELECT ON participant_classes TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
GRANT EXECUTE ON FUNCTION si_share_count(bigint, text, date), si_issuer_is_restricted(bigint), si_can_see_issuer(bigint), si_my_issuers(), si_can_see_security(bigint),
    si_participant_class_from_name(text), si_participant_find(text, text) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_participant_upsert(text, text, text, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_participant_upsert(text, text, text, date) TO shareholder_intelligence_rw;

COMMIT;
