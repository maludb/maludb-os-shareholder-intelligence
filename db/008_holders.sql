-- 008: HOLDERS — the identity the NOBO list, the register and the insider filings share (design §6.3; D4, D8; docs/build-specs/holder-identity.md).
--
-- A HOLDER is a normalised person or entity; an ACCOUNT is one registration of theirs on a list (a TOD account and a Schwab IRA are
-- two accounts of one holder); a POSITION is an account's shares at a record date in a channel. The MATCHER scores a raw row's
-- registration against the holders (name key exact; trigram with the address; trigram with the zip; name exact at another address;
-- an entity against the institutions) and writes a PROPOSAL; at or above the threshold (0.95) it is accepted and logged `auto`;
-- below, an Analyst decides; a rejected pair is remembered; a MERGE is a manager's. A person is never matched to an institution.
-- THE PII WALL (D4): holders.kind = person is a natural person — the name, address and lines are seen by si_sees_holder_pii() alone
-- (the mcp_* views of db/017 null them for a Viewer and say "Individual holder").
--
-- THE ESTATE'S DATA MODEL (design §6.8): holders, holder_accounts, holder_positions, holder_match_proposals are NEW CANONICAL —
-- compared with the Sales CRM's contacts (a relationship who may reach us) and Help Desk's requesters: a holder is a registration on a
-- list. merged_into_id is requesters'; the list's own words (state, zip) are kept.
BEGIN;

CREATE TABLE holders (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id        bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    display_name     text NOT NULL,                                                -- the registration's name, tidied ("Brian Polan")
    name_key         text NOT NULL,                                                -- normalised: upper, punctuation and legends stripped, the custodian removed
    kind             text NOT NULL DEFAULT 'unknown' CHECK (kind IN ('person', 'entity', 'custodian_account', 'unknown')),
    entity_type      text CHECK (entity_type IS NULL OR entity_type IN ('trust', 'corporation', 'fund', 'partnership', 'estate', 'bank', 'broker', 'government', 'nonprofit', 'other')),
    address_key      text,                                                         -- normalised street + zip
    address_line     text,
    city             text,
    state            text,
    country          text,
    zip              text,
    is_insider       boolean NOT NULL DEFAULT false,                               -- a Form 3/4/5 reporting person matched
    insider_cik      text CHECK (insider_cik IS NULL OR insider_cik ~ '^[0-9]{10}$'),
    institution_id   bigint,                                                       -- a 13F/13D filer matched (FK in db/010)
    first_seen_on    date,
    last_seen_on     date,
    merged_into_id   bigint REFERENCES holders(id) ON DELETE SET NULL,
    notes            text,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    deleted_at       timestamptz,
    deleted_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (merged_into_id IS NULL OR merged_into_id <> id)
);
CREATE INDEX holders_issuer_idx ON holders (issuer_id) WHERE deleted_at IS NULL AND merged_into_id IS NULL;
CREATE INDEX holders_name_key_idx ON holders (issuer_id, name_key);
CREATE INDEX holders_name_trgm_idx ON holders USING gin (name_key gin_trgm_ops);
CREATE INDEX holders_address_idx ON holders (issuer_id, address_key);
CREATE INDEX holders_kind_idx ON holders (issuer_id, kind);
CREATE TRIGGER holders_touch BEFORE UPDATE ON holders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE holder_accounts (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    holder_id                 bigint NOT NULL REFERENCES holders(id) ON DELETE CASCADE,
    channel                   text NOT NULL CHECK (channel IN ('nobo', 'register')),
    custodian_participant_id  bigint REFERENCES participants(id) ON DELETE SET NULL,
    account_kind              text NOT NULL DEFAULT 'unknown' CHECK (account_kind IN ('ira', 'tod', 'trust', 'joint', 'custodial', 'corporate', 'individual', 'cert', 'book', 'drs', 'plan', 'restricted', 'unknown')),
    account_key               text NOT NULL,                                       -- the registration normalised, whole (the exact account)
    registration_text         text,                                                -- the lines joined, as read (PII)
    holder_ref                text,                                                -- the agent's holder id (register)
    first_seen_on             date,
    last_seen_on              date,
    created_at                timestamptz NOT NULL DEFAULT now(),
    UNIQUE (holder_id, channel, account_key)
);
CREATE INDEX holder_accounts_holder_idx ON holder_accounts (holder_id);
CREATE INDEX holder_accounts_custodian_idx ON holder_accounts (custodian_participant_id);

-- new canonical: an account's shares at a record date in a channel
CREATE TABLE holder_positions (
    holder_id          bigint NOT NULL REFERENCES holders(id) ON DELETE CASCADE,
    holder_account_id  bigint NOT NULL REFERENCES holder_accounts(id) ON DELETE CASCADE,
    security_id        bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    record_date        date NOT NULL,
    shares             numeric(20,0) NOT NULL CHECK (shares >= 0),
    channel            text NOT NULL CHECK (channel IN ('nobo', 'register')),
    import_id          bigint NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    PRIMARY KEY (holder_account_id, security_id, record_date, channel)
);
CREATE INDEX holder_positions_holder_idx ON holder_positions (holder_id, record_date);
CREATE INDEX holder_positions_security_idx ON holder_positions (security_id, channel, record_date);

CREATE TABLE holder_match_proposals (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    row_kind         text NOT NULL CHECK (row_kind IN ('nobo', 'register', 'insider')),
    row_id           bigint NOT NULL,                                               -- nobo_rows.id / register_rows.id / insider_transactions.id
    issuer_id        bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    holder_id        bigint NOT NULL REFERENCES holders(id) ON DELETE CASCADE,     -- the candidate
    score            numeric(4,3) NOT NULL CHECK (score BETWEEN 0 AND 1),
    evidence         jsonb NOT NULL DEFAULT '{}'::jsonb,                            -- {"name_key":"exact","address_key":"exact"} …
    proposed_by      bigint REFERENCES members(id) ON DELETE SET NULL,             -- NULL = the matcher; an agent otherwise
    status           text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'rejected', 'auto')),
    decided_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at       timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (row_kind, row_id, holder_id)
);
CREATE INDEX holder_match_proposals_open_idx ON holder_match_proposals (issuer_id, created_at DESC) WHERE status = 'proposed';
CREATE INDEX holder_match_proposals_row_idx ON holder_match_proposals (row_kind, row_id);
ALTER TABLE nobo_rows ADD CONSTRAINT nobo_rows_holder_fk FOREIGN KEY (holder_id) REFERENCES holders(id) ON DELETE SET NULL;
ALTER TABLE nobo_rows ADD CONSTRAINT nobo_rows_account_fk FOREIGN KEY (holder_account_id) REFERENCES holder_accounts(id) ON DELETE SET NULL;
ALTER TABLE register_rows ADD CONSTRAINT register_rows_holder_fk FOREIGN KEY (holder_id) REFERENCES holders(id) ON DELETE SET NULL;
ALTER TABLE register_rows ADD CONSTRAINT register_rows_account_fk FOREIGN KEY (holder_account_id) REFERENCES holder_accounts(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------------------------
-- NORMALISATION — the registration lines → a custodian, an account kind, a name key, an address key
-- ---------------------------------------------------------------------------------------------
-- The legends a registration line carries (IRA CONTRIBUTORY, DESIGNATED BENE PLAN/TOD, TTEE, JT TEN, CUST, FBO …) → the account kind.
CREATE OR REPLACE FUNCTION si_account_kind_from_lines(p_lines text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN l ~ '\m(IRA|ROTH|SEP|SIMPLE IRA|RETIREMENT|401K|401\(K\)|403B)\M' THEN 'ira'
        WHEN l ~ '(TOD\M|TRANSFER ON DEATH|DESIGNATED BENE)' THEN 'tod'
        WHEN l ~ '\m(TTEE|TRUSTEE|TRUST|U/A|UA DTD|LIVING TR|REVOCABLE|REV TR)\M' THEN 'trust'
        WHEN l ~ '\m(JT TEN|JTWROS|JT WROS|JOINT|TEN COM|TEN ENT|COMMUNITY PROPERTY)\M' THEN 'joint'
        WHEN l ~ '\m(CUST|CUSTODIAN|UTMA|UGMA|FBO)\M' THEN 'custodial'
        WHEN l ~ '\m(INC|LLC|L\.L\.C|CORP|CORPORATION|LTD|LP|L\.P|PARTNERS|FUND|CAPITAL|HOLDINGS|FOUNDATION|ASSOCIATES|COMPANY|CO\.|PLC|GMBH|SA|AG)\M' THEN 'corporate'
        ELSE 'individual' END
      FROM (SELECT upper(COALESCE(p_lines, '')) AS l) x;
$$;

-- The custodian named in the lines → a participant (by the common words a broker's custodial line carries).
CREATE OR REPLACE FUNCTION si_custodian_from_lines(p_lines text) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE
        WHEN l ~ 'CHARLES SCHWAB|SCHWAB & CO|TD AMERITRADE' THEN (SELECT id FROM participants WHERE number = '0164')
        WHEN l ~ 'FMT CO|FMTC|FIDELITY|NATIONAL FINANCIAL' THEN (SELECT id FROM participants WHERE number = '0226')
        WHEN l ~ 'ROBINHOOD' THEN (SELECT id FROM participants WHERE number = '6769')
        WHEN l ~ 'APEX CLEARING' THEN (SELECT id FROM participants WHERE number = '0158')
        WHEN l ~ 'INTERACTIVE BROKERS' THEN (SELECT id FROM participants WHERE number = '0534')
        WHEN l ~ 'VANGUARD' THEN (SELECT id FROM participants WHERE number = '0062')
        WHEN l ~ 'E\*TRADE|ETRADE|MORGAN STANLEY SMITH|MSSB' THEN (SELECT id FROM participants WHERE number = '0015')
        WHEN l ~ 'MERRILL LYNCH|MERRILL EDGE' THEN (SELECT id FROM participants WHERE number = '0671')
        WHEN l ~ 'PERSHING' THEN (SELECT id FROM participants WHERE number = '0443')
        WHEN l ~ 'WELLS FARGO' THEN (SELECT id FROM participants WHERE number = '0141')
        WHEN l ~ 'J\.?P\.? ?MORGAN|CHASE' THEN (SELECT id FROM participants WHERE number = '0187')
        WHEN l ~ 'UBS' THEN (SELECT id FROM participants WHERE number = '0221')
        WHEN l ~ 'GOLDMAN' THEN (SELECT id FROM participants WHERE number = '0005')
        WHEN l ~ 'DRIVEWEALTH' THEN (SELECT id FROM participants WHERE number = '2402')
        WHEN l ~ 'STONEX|INTL FCSTONE' THEN (SELECT id FROM participants WHERE number = '0750')
        WHEN l ~ 'WEDBUSH' THEN (SELECT id FROM participants WHERE number = '0103')
        ELSE NULL END
      FROM (SELECT upper(COALESCE(p_lines, '')) AS l) x;
$$;

-- The name key: the FIRST line, upper, without punctuation, legends, custodian words and account words; the person's name alone.
CREATE OR REPLACE FUNCTION si_name_key(p_line1 text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT NULLIF(btrim(regexp_replace(regexp_replace(regexp_replace(upper(COALESCE(p_line1, '')),
             '\m(TOD|TTEE|TRUSTEE|CUST|CUSTODIAN|FBO|IRA|ROTH IRA|SEP IRA|JT TEN|JTWROS|JT WROS|TEN COM|U/A|UA DTD|DTD|ACCT|A/C|C/O|UTMA|UGMA|DBA|P/ADM|ADM|EXEC|ESTATE OF|EST OF|FRP PS)\M', ' ', 'g'),
             '[^A-Z0-9& ]', ' ', 'g'), '\s+', ' ', 'g')), '');
$$;
-- The address key: the first line with a digit (the street) + the zip, upper, squeezed.
CREATE OR REPLACE FUNCTION si_address_key(p_street text, p_zip text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT NULLIF(btrim(regexp_replace(upper(COALESCE(p_street, '')), '[^A-Z0-9]', '', 'g') || '|' || left(regexp_replace(COALESCE(p_zip, ''), '[^0-9A-Z]', '', 'g'), 5)), '|');
$$;
-- A NOBO row's lines split: the name, the custodian lines, the street (the first line with a digit after the name), the city/state/zip line.
CREATE OR REPLACE FUNCTION si_nobo_lines_parse(l1 text, l2 text, l3 text, l4 text, l5 text, l6 text, l7 text)
    RETURNS TABLE (name_line text, street text, city_state_zip text, legend_lines text, all_lines text)
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE ls text[] := ARRAY[l1, l2, l3, l4, l5, l6, l7]; i integer; street_i integer := NULL; csz text; legends text := '';
BEGIN
    ls := ARRAY(SELECT NULLIF(btrim(x), '') FROM unnest(ls) x WHERE NULLIF(btrim(x), '') IS NOT NULL);
    FOR i IN 2 .. COALESCE(array_length(ls, 1), 0) LOOP
        IF street_i IS NULL AND ls[i] ~ '^\d' THEN street_i := i; END IF;
    END LOOP;
    IF street_i IS NOT NULL THEN
        FOR i IN 2 .. street_i - 1 LOOP legends := legends || ' ' || ls[i]; END LOOP;
        csz := ls[street_i + 1];
        IF csz IS NOT NULL AND csz ~ '^\d+$' AND ls[street_i + 2] IS NOT NULL THEN csz := ls[street_i + 2]; END IF;   -- an apartment number on its own line
    ELSE
        FOR i IN 2 .. COALESCE(array_length(ls, 1), 0) LOOP legends := legends || ' ' || ls[i]; END LOOP;
    END IF;
    RETURN QUERY SELECT ls[1], CASE WHEN street_i IS NOT NULL THEN ls[street_i] END, csz, NULLIF(btrim(legends), ''), array_to_string(ls, ' | ');
END$$;

-- ---------------------------------------------------------------------------------------------
-- THE MATCHER (D8). For one raw row: the candidates with scores and evidence; the best is proposed, or accepted at or above the threshold.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_match_candidates(p_issuer bigint, p_name_key text, p_address_key text, p_zip text, p_kind text)
    RETURNS TABLE (holder_id bigint, score numeric, evidence jsonb)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT h.id,
           CASE
             WHEN h.name_key = p_name_key AND p_address_key IS NOT NULL AND h.address_key = p_address_key THEN 1.000
             WHEN h.name_key = p_name_key AND (p_address_key IS NULL OR h.address_key IS NULL) THEN 0.900
             WHEN similarity(h.name_key, p_name_key) >= 0.85 AND p_address_key IS NOT NULL AND h.address_key = p_address_key THEN 0.950
             WHEN similarity(h.name_key, p_name_key) >= 0.90 AND p_zip IS NOT NULL AND h.zip = p_zip THEN 0.800
             WHEN h.name_key = p_name_key THEN 0.600
             ELSE 0
           END AS score,
           jsonb_build_object('name', CASE WHEN h.name_key = p_name_key THEN 'exact' ELSE round(similarity(h.name_key, p_name_key)::numeric, 2)::text END,
                              'address', CASE WHEN p_address_key IS NOT NULL AND h.address_key = p_address_key THEN 'exact' WHEN p_zip IS NOT NULL AND h.zip = p_zip THEN 'zip' ELSE 'different' END) AS evidence
      FROM holders h
     WHERE h.issuer_id = p_issuer AND h.deleted_at IS NULL AND h.merged_into_id IS NULL
       AND (p_kind <> 'person' OR h.kind <> 'entity') AND (p_kind <> 'entity' OR h.kind <> 'person')      -- a person is never matched to an entity
       AND (h.name_key = p_name_key OR h.name_key % p_name_key)
       AND CASE
             WHEN h.name_key = p_name_key AND p_address_key IS NOT NULL AND h.address_key = p_address_key THEN 1.000
             WHEN h.name_key = p_name_key AND (p_address_key IS NULL OR h.address_key IS NULL) THEN 0.900
             WHEN similarity(h.name_key, p_name_key) >= 0.85 AND p_address_key IS NOT NULL AND h.address_key = p_address_key THEN 0.950
             WHEN similarity(h.name_key, p_name_key) >= 0.90 AND p_zip IS NOT NULL AND h.zip = p_zip THEN 0.800
             WHEN h.name_key = p_name_key THEN 0.600
             ELSE 0 END > 0
     ORDER BY 2 DESC, h.last_seen_on DESC NULLS LAST
     LIMIT 5;
$$;

-- Make a holder from a registration (no candidate, or the Analyst said "new").
CREATE OR REPLACE FUNCTION si_holder_make(p_issuer bigint, p_display_name text, p_name_key text, p_kind text, p_street text, p_city text, p_state text, p_country text, p_zip text, p_seen date)
    RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE hid bigint;
BEGIN
    INSERT INTO holders (issuer_id, display_name, name_key, kind, entity_type, address_key, address_line, city, state, country, zip, first_seen_on, last_seen_on, created_by)
    VALUES (p_issuer, p_display_name, p_name_key, p_kind,
            CASE WHEN p_kind = 'entity' THEN CASE WHEN upper(p_display_name) ~ '\m(TRUST|TTEE|TR)\M' THEN 'trust' WHEN upper(p_display_name) ~ '\m(FUND|CAPITAL|PARTNERS|LP)\M' THEN 'fund' WHEN upper(p_display_name) ~ '\m(INC|CORP|LLC|LTD|CO)\M' THEN 'corporation' ELSE 'other' END END,
            si_address_key(p_street, p_zip), p_street, p_city, p_state, p_country, p_zip, p_seen, p_seen, app_current_member_id())
    RETURNING id INTO hid;
    RETURN hid;
END$$;

-- Attach a raw row to a holder: the account (found or made), the position, the row's pointers; the holder's dates.
CREATE OR REPLACE FUNCTION si_row_attach(p_row_kind text, p_row_id bigint, p_holder bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE acc bigint; r record; akey text; ak text; cust bigint; reg text; sec bigint; rd date; imp bigint; sh numeric; href text;
BEGIN
    IF p_row_kind = 'nobo' THEN
        SELECT n.*, j.security_id AS sec, j.record_date AS rd, (SELECT all_lines FROM si_nobo_lines_parse(n.line1, n.line2, n.line3, n.line4, n.line5, n.line6, n.line7)) AS lines INTO r FROM nobo_rows n JOIN nobo_jobs j ON j.id = n.job_id WHERE n.id = p_row_id;
        akey := regexp_replace(upper(r.lines), '[^A-Z0-9|]', '', 'g'); ak := r.account_kind; cust := r.custodian_participant_id; reg := r.lines; sec := r.sec; rd := r.rd; imp := r.import_id; sh := r.shares; href := NULL;
    ELSIF p_row_kind = 'register' THEN
        SELECT * INTO r FROM register_rows WHERE id = p_row_id;
        akey := regexp_replace(upper(COALESCE(r.holder_ref, '') || '|' || COALESCE(r.registration_name, '') || '|' || COALESCE(r.position_type, '')), '[^A-Z0-9|]', '', 'g');
        ak := CASE WHEN upper(COALESCE(r.position_type, '')) ~ 'CERT' THEN 'cert' WHEN upper(COALESCE(r.position_type, '')) ~ 'DRS' THEN 'drs' WHEN upper(COALESCE(r.position_type, '')) ~ 'PLAN|DRIP|DSPP' THEN 'plan'
                   WHEN upper(COALESCE(r.position_type, '')) ~ 'BOOK' THEN 'book' WHEN COALESCE(r.restriction_text, '') <> '' THEN 'restricted' ELSE 'book' END;
        cust := NULL; reg := concat_ws(' | ', r.registration_name, r.address_line, concat_ws(' ', r.city, r.state, r.zip)); sec := r.security_id; rd := r.record_date; imp := r.import_id; sh := r.shares; href := r.holder_ref;
    ELSE RAISE EXCEPTION 'si_row_attach: %', p_row_kind; END IF;
    SELECT id INTO acc FROM holder_accounts WHERE holder_id = p_holder AND channel = p_row_kind AND account_key = akey;
    IF acc IS NULL THEN
        INSERT INTO holder_accounts (holder_id, channel, custodian_participant_id, account_kind, account_key, registration_text, holder_ref, first_seen_on, last_seen_on)
        VALUES (p_holder, p_row_kind, cust, ak, akey, reg, href, rd, rd) RETURNING id INTO acc;
    ELSE
        UPDATE holder_accounts SET last_seen_on = GREATEST(last_seen_on, rd), first_seen_on = LEAST(first_seen_on, rd) WHERE id = acc;
    END IF;
    IF sh IS NOT NULL THEN
        INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id)
        VALUES (p_holder, acc, sec, rd, sh, p_row_kind, imp)
        ON CONFLICT (holder_account_id, security_id, record_date, channel) DO UPDATE SET shares = EXCLUDED.shares, import_id = EXCLUDED.import_id, holder_id = EXCLUDED.holder_id;
    END IF;
    IF p_row_kind = 'nobo' THEN UPDATE nobo_rows SET holder_id = p_holder, holder_account_id = acc WHERE id = p_row_id;
    ELSE UPDATE register_rows SET holder_id = p_holder, holder_account_id = acc WHERE id = p_row_id; END IF;
    UPDATE holders SET first_seen_on = LEAST(COALESCE(first_seen_on, rd), rd), last_seen_on = GREATEST(COALESCE(last_seen_on, rd), rd) WHERE id = p_holder;
    RETURN acc;
END$$;

-- The matcher over one raw row: parse → kind, custodian → candidates → auto / proposal / new holder. Returns what it did.
CREATE OR REPLACE FUNCTION si_match_row(p_row_kind text, p_row_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; p record; iss bigint; nk text; ak text; zp text; kind text; disp text; c record; thr numeric := (SELECT auto_match_threshold FROM si_settings WHERE id = 1);
        street text; cty text; st text; ctry text; hid bigint; n_prop integer := 0; rd date;
BEGIN
    IF p_row_kind = 'nobo' THEN
        SELECT n.*, j.record_date AS rd, s.issuer_id AS iss INTO r FROM nobo_rows n JOIN nobo_jobs j ON j.id = n.job_id JOIN securities s ON s.id = j.security_id WHERE n.id = p_row_id AND n.holder_id IS NULL;
        IF NOT FOUND THEN RETURN 'skipped'; END IF;
        SELECT * INTO p FROM si_nobo_lines_parse(r.line1, r.line2, r.line3, r.line4, r.line5, r.line6, r.line7);
        IF p.name_line IS NULL THEN UPDATE nobo_rows SET error = COALESCE(error, 'no name line') WHERE id = p_row_id; RETURN 'failed'; END IF;
        iss := r.iss; disp := initcap(lower(si_name_key(p.name_line))); nk := si_name_key(p.name_line);
        zp := left(regexp_replace(COALESCE(r.zip_sort, substring(p.city_state_zip FROM '(\d{5})(?:-\d{4})?\s*$')), '[^0-9]', '', 'g'), 5);
        street := p.street; cty := NULLIF(btrim(regexp_replace(COALESCE(p.city_state_zip, ''), '\s+[A-Z]{2}\s+\d{5}.*$', '')), ''); st := substring(COALESCE(p.city_state_zip, '') FROM '\s([A-Z]{2})\s+\d{5}'); ctry := 'US'; rd := r.rd;
        kind := CASE si_account_kind_from_lines(COALESCE(p.name_line, '') || ' ' || COALESCE(p.legend_lines, '')) WHEN 'corporate' THEN 'entity' ELSE 'person' END;
        IF si_account_kind_from_lines(p.name_line) = 'corporate' AND p.legend_lines IS NULL THEN kind := 'entity'; END IF;
        UPDATE nobo_rows SET account_kind = CASE WHEN kind = 'entity' THEN 'corporate' ELSE si_account_kind_from_lines(COALESCE(p.legend_lines, '') || ' ' || COALESCE(p.name_line, '')) END,
               custodian_participant_id = si_custodian_from_lines(COALESCE(p.legend_lines, '') || ' ' || COALESCE(p.name_line, '')) WHERE id = p_row_id;
    ELSIF p_row_kind = 'register' THEN
        SELECT g.*, s.issuer_id AS iss INTO r FROM register_rows g JOIN securities s ON s.id = g.security_id WHERE g.id = p_row_id AND g.holder_id IS NULL AND NOT g.is_cede;
        IF NOT FOUND THEN RETURN 'skipped'; END IF;
        IF NULLIF(btrim(COALESCE(r.registration_name, '')), '') IS NULL THEN UPDATE register_rows SET error = COALESCE(error, 'no registration name') WHERE id = p_row_id; RETURN 'failed'; END IF;
        iss := r.iss; nk := si_name_key(split_part(r.registration_name, E'\n', 1)); disp := initcap(lower(nk));
        zp := left(regexp_replace(COALESCE(r.zip, ''), '[^0-9]', '', 'g'), 5); street := r.address_line; cty := r.city; st := r.state; ctry := r.country; rd := r.record_date;
        kind := CASE si_account_kind_from_lines(r.registration_name) WHEN 'corporate' THEN 'entity' ELSE 'person' END;
    ELSE RAISE EXCEPTION 'si_match_row: %', p_row_kind; END IF;
    IF nk IS NULL THEN RETURN 'failed'; END IF;
    ak := si_address_key(street, zp);
    FOR c IN SELECT * FROM si_match_candidates(iss, nk, ak, zp, kind) LOOP
        IF EXISTS (SELECT 1 FROM holder_match_proposals WHERE row_kind = p_row_kind AND row_id = p_row_id AND holder_id = c.holder_id AND status = 'rejected') THEN CONTINUE; END IF;
        IF c.score >= thr AND n_prop = 0 THEN
            INSERT INTO holder_match_proposals (row_kind, row_id, issuer_id, holder_id, score, evidence, status, decided_at) VALUES (p_row_kind, p_row_id, iss, c.holder_id, c.score, c.evidence, 'auto', now())
            ON CONFLICT (row_kind, row_id, holder_id) DO UPDATE SET status = 'auto', score = EXCLUDED.score, evidence = EXCLUDED.evidence, decided_at = now();
            PERFORM si_row_attach(p_row_kind, p_row_id, c.holder_id);
            UPDATE holders h SET address_key = COALESCE(h.address_key, ak), address_line = COALESCE(h.address_line, street), city = COALESCE(h.city, cty), zip = COALESCE(h.zip, zp) WHERE h.id = c.holder_id;
            RETURN 'auto';
        END IF;
        INSERT INTO holder_match_proposals (row_kind, row_id, issuer_id, holder_id, score, evidence) VALUES (p_row_kind, p_row_id, iss, c.holder_id, c.score, c.evidence)
        ON CONFLICT (row_kind, row_id, holder_id) DO UPDATE SET score = EXCLUDED.score, evidence = EXCLUDED.evidence WHERE holder_match_proposals.status = 'proposed';
        n_prop := n_prop + 1;
    END LOOP;
    IF n_prop > 0 THEN RETURN 'proposed'; END IF;
    hid := si_holder_make(iss, disp, nk, kind, street, cty, st, ctry, zp, rd);
    PERFORM si_row_attach(p_row_kind, p_row_id, hid);
    RETURN 'new';
END$$;

-- An Analyst's decision on a proposal: accept (attach; the row's other proposals are closed), or reject (remembered).
CREATE OR REPLACE FUNCTION si_match_decide(p_proposal bigint, p_accept boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p holder_match_proposals%ROWTYPE;
BEGIN
    SELECT * INTO p FROM holder_match_proposals WHERE id = p_proposal AND status = 'proposed' FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'The proposal is not open' USING ERRCODE = 'check_violation'; END IF;
    IF p_accept THEN
        UPDATE holder_match_proposals SET status = 'accepted', decided_by = app_current_member_id(), decided_at = now() WHERE id = p_proposal;
        UPDATE holder_match_proposals SET status = 'rejected', decided_by = app_current_member_id(), decided_at = now() WHERE row_kind = p.row_kind AND row_id = p.row_id AND status = 'proposed';
        PERFORM si_row_attach(p.row_kind, p.row_id, p.holder_id);
    ELSE
        UPDATE holder_match_proposals SET status = 'rejected', decided_by = app_current_member_id(), decided_at = now() WHERE id = p_proposal;
    END IF;
END$$;

-- "New holder" for a row with only rejected or no proposals (the Analyst's third button).
CREATE OR REPLACE FUNCTION si_match_new_holder(p_row_kind text, p_row_id bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE hid bigint;
BEGIN
    UPDATE holder_match_proposals SET status = 'rejected', decided_by = app_current_member_id(), decided_at = now() WHERE row_kind = p_row_kind AND row_id = p_row_id AND status = 'proposed';
    -- a row with every candidate rejected falls through si_match_row to a new holder (rejected pairs are skipped)
    RETURN CASE si_match_row(p_row_kind, p_row_id) WHEN 'new' THEN (SELECT holder_id FROM nobo_rows WHERE p_row_kind = 'nobo' AND id = p_row_id UNION ALL SELECT holder_id FROM register_rows WHERE p_row_kind = 'register' AND id = p_row_id LIMIT 1) END;
END$$;

-- The matcher over an upload (the worker; the proof): every unmatched row.
CREATE OR REPLACE FUNCTION si_match_import(p_import bigint) RETURNS TABLE (auto integer, proposed integer, new_holders integer, failed integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; k text; a integer := 0; p integer := 0; n integer := 0; f integer := 0; res text;
BEGIN
    SELECT kind INTO k FROM imports WHERE id = p_import;
    IF k = 'nobo' THEN
        FOR r IN SELECT n2.id FROM nobo_rows n2 WHERE n2.import_id = p_import AND n2.holder_id IS NULL AND n2.shares IS NOT NULL ORDER BY n2.row_no LOOP
            res := si_match_row('nobo', r.id);
            IF res = 'auto' THEN a := a + 1; ELSIF res = 'proposed' THEN p := p + 1; ELSIF res = 'new' THEN n := n + 1; ELSIF res = 'failed' THEN f := f + 1; END IF;
        END LOOP;
    ELSIF k = 'register' THEN
        FOR r IN SELECT g.id FROM register_rows g WHERE g.import_id = p_import AND g.holder_id IS NULL AND NOT g.is_cede AND g.shares IS NOT NULL ORDER BY g.row_no LOOP
            res := si_match_row('register', r.id);
            IF res = 'auto' THEN a := a + 1; ELSIF res = 'proposed' THEN p := p + 1; ELSIF res = 'new' THEN n := n + 1; ELSIF res = 'failed' THEN f := f + 1; END IF;
        END LOOP;
    END IF;
    RETURN QUERY SELECT a, p, n, f;
END$$;

-- THE MERGE (a manager's): the survivor keeps its values and takes the loser's where empty; every account, position, row pointer and
-- proposal is re-pointed; the loser is tombstoned with merged_into_id.
CREATE OR REPLACE FUNCTION si_holders_merge(p_survivor bigint, p_loser bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF p_survivor = p_loser THEN RAISE EXCEPTION 'A holder is not merged into itself' USING ERRCODE = 'check_violation'; END IF;
    IF (SELECT issuer_id FROM holders WHERE id = p_survivor) <> (SELECT issuer_id FROM holders WHERE id = p_loser) THEN RAISE EXCEPTION 'Holders of two issuers are not merged' USING ERRCODE = 'check_violation'; END IF;
    IF (SELECT kind FROM holders WHERE id = p_survivor) = 'person' AND (SELECT kind FROM holders WHERE id = p_loser) = 'entity' OR (SELECT kind FROM holders WHERE id = p_survivor) = 'entity' AND (SELECT kind FROM holders WHERE id = p_loser) = 'person' THEN
        RAISE EXCEPTION 'A person is not merged with an entity' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM 1 FROM holders WHERE id IN (p_survivor, p_loser) AND deleted_at IS NULL AND merged_into_id IS NULL FOR UPDATE;
    UPDATE holders s SET address_key = COALESCE(s.address_key, l.address_key), address_line = COALESCE(s.address_line, l.address_line), city = COALESCE(s.city, l.city), state = COALESCE(s.state, l.state),
           country = COALESCE(s.country, l.country), zip = COALESCE(s.zip, l.zip), is_insider = s.is_insider OR l.is_insider, insider_cik = COALESCE(s.insider_cik, l.insider_cik),
           institution_id = COALESCE(s.institution_id, l.institution_id), entity_type = COALESCE(s.entity_type, l.entity_type),
           kind = CASE WHEN s.kind = 'unknown' THEN l.kind ELSE s.kind END,
           first_seen_on = LEAST(s.first_seen_on, l.first_seen_on), last_seen_on = GREATEST(s.last_seen_on, l.last_seen_on), notes = concat_ws(E'\n', s.notes, l.notes)
      FROM holders l WHERE s.id = p_survivor AND l.id = p_loser;
    UPDATE holder_accounts a SET holder_id = p_survivor WHERE holder_id = p_loser AND NOT EXISTS (SELECT 1 FROM holder_accounts x WHERE x.holder_id = p_survivor AND x.channel = a.channel AND x.account_key = a.account_key);
    UPDATE holder_positions hp SET holder_id = p_survivor, holder_account_id = (SELECT x.id FROM holder_accounts x JOIN holder_accounts o ON o.id = hp.holder_account_id WHERE x.holder_id = p_survivor AND x.channel = o.channel AND x.account_key = o.account_key)
     WHERE holder_id = p_loser AND EXISTS (SELECT 1 FROM holder_accounts x JOIN holder_accounts o ON o.id = hp.holder_account_id WHERE x.holder_id = p_survivor AND x.channel = o.channel AND x.account_key = o.account_key AND x.id <> o.id);
    UPDATE holder_positions SET holder_id = p_survivor WHERE holder_id = p_loser;
    DELETE FROM holder_accounts WHERE holder_id = p_loser;
    UPDATE nobo_rows SET holder_id = p_survivor WHERE holder_id = p_loser;
    UPDATE register_rows SET holder_id = p_survivor WHERE holder_id = p_loser;
    UPDATE holder_match_proposals SET holder_id = p_survivor WHERE holder_id = p_loser AND NOT EXISTS (SELECT 1 FROM holder_match_proposals x WHERE x.row_kind = holder_match_proposals.row_kind AND x.row_id = holder_match_proposals.row_id AND x.holder_id = p_survivor);
    DELETE FROM holder_match_proposals WHERE holder_id = p_loser;
    UPDATE holders SET merged_into_id = p_survivor, deleted_at = now(), deleted_by = me WHERE id = p_loser;
END$$;

-- ---- the holder series (§6.3)
CREATE OR REPLACE FUNCTION si_holder_history(p_holder bigint)
    RETURNS TABLE (record_date date, channel text, holder_account_id bigint, account_kind text, custodian_participant_id bigint, security_id bigint, shares numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT hp.record_date, hp.channel, hp.holder_account_id, a.account_kind, a.custodian_participant_id, hp.security_id, hp.shares
      FROM holder_positions hp JOIN holder_accounts a ON a.id = hp.holder_account_id WHERE hp.holder_id = p_holder ORDER BY hp.record_date, hp.channel, hp.holder_account_id;
$$;
-- Holders between two record dates in a channel: new, lost, up, down, same (the reference implementation's analytics over record dates).
CREATE OR REPLACE FUNCTION si_holders_changes(p_security bigint, p_channel text, p_date_a date, p_date_b date)
    RETURNS TABLE (holder_id bigint, state text, shares_a numeric, shares_b numeric, net numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH a AS (SELECT holder_id, sum(shares) AS s FROM holder_positions WHERE security_id = p_security AND channel = p_channel AND record_date = p_date_a GROUP BY holder_id),
         b AS (SELECT holder_id, sum(shares) AS s FROM holder_positions WHERE security_id = p_security AND channel = p_channel AND record_date = p_date_b GROUP BY holder_id)
    SELECT COALESCE(a.holder_id, b.holder_id),
           CASE WHEN a.holder_id IS NULL THEN 'new' WHEN b.holder_id IS NULL OR b.s = 0 THEN 'lost' WHEN b.s > a.s THEN 'up' WHEN b.s < a.s THEN 'down' ELSE 'same' END,
           a.s, b.s, COALESCE(b.s, 0) - COALESCE(a.s, 0)
      FROM a FULL JOIN b ON b.holder_id = a.holder_id
     ORDER BY abs(COALESCE(b.s, 0) - COALESCE(a.s, 0)) DESC;
$$;
-- The Viewer's aggregates: share ranges, geography, custodians, position types — never a name.
CREATE OR REPLACE FUNCTION si_holders_by_range(p_security bigint, p_channel text, p_record_date date)
    RETURNS TABLE (range_label text, range_from numeric, holders integer, shares numeric, share_pct numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH h AS (SELECT holder_id, sum(shares) AS s FROM holder_positions WHERE security_id = p_security AND channel = p_channel AND record_date = p_record_date GROUP BY holder_id),
         r AS (SELECT * FROM (VALUES ('1–99', 1::numeric, 99::numeric), ('100–999', 100, 999), ('1,000–4,999', 1000, 4999), ('5,000–9,999', 5000, 9999), ('10,000–49,999', 10000, 49999), ('50,000–99,999', 50000, 99999), ('100,000–499,999', 100000, 499999), ('500,000+', 500000, 1e15)) v(l, lo, hi))
    SELECT r.l, r.lo, count(h.holder_id)::integer, COALESCE(sum(h.s), 0), round(COALESCE(sum(h.s), 0) / NULLIF((SELECT sum(s) FROM h), 0) * 100, 2)
      FROM r LEFT JOIN h ON h.s BETWEEN r.lo AND r.hi GROUP BY r.l, r.lo ORDER BY r.lo;
$$;
CREATE OR REPLACE FUNCTION si_holders_by_geography(p_security bigint, p_channel text, p_record_date date)
    RETURNS TABLE (country text, state text, holders integer, shares numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(h.country, 'US'), h.state, count(DISTINCT hp.holder_id)::integer, sum(hp.shares)
      FROM holder_positions hp JOIN holders h ON h.id = hp.holder_id WHERE hp.security_id = p_security AND hp.channel = p_channel AND hp.record_date = p_record_date
     GROUP BY 1, 2 ORDER BY 4 DESC;
$$;
CREATE OR REPLACE FUNCTION si_holders_by_custodian(p_security bigint, p_record_date date)
    RETURNS TABLE (participant_id bigint, number text, name text, class_key text, account_kind text, holders integer, shares numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.custodian_participant_id, p.number, p.name, p.class_key, a.account_kind, count(DISTINCT hp.holder_id)::integer, sum(hp.shares)
      FROM holder_positions hp JOIN holder_accounts a ON a.id = hp.holder_account_id LEFT JOIN participants p ON p.id = a.custodian_participant_id
     WHERE hp.security_id = p_security AND hp.channel = 'nobo' AND hp.record_date = p_record_date
     GROUP BY 1, 2, 3, 4, 5 ORDER BY 7 DESC;
$$;
CREATE OR REPLACE FUNCTION si_holders_by_type(p_security bigint, p_record_date date)
    RETURNS TABLE (account_kind text, holders integer, shares numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.account_kind, count(DISTINCT hp.holder_id)::integer, sum(hp.shares)
      FROM holder_positions hp JOIN holder_accounts a ON a.id = hp.holder_account_id
     WHERE hp.security_id = p_security AND hp.channel = 'register' AND hp.record_date = p_record_date GROUP BY 1 ORDER BY 3 DESC;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON holders, holder_accounts, holder_positions, holder_match_proposals TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_account_kind_from_lines(text), si_custodian_from_lines(text), si_name_key(text), si_address_key(text, text), si_nobo_lines_parse(text, text, text, text, text, text, text),
    si_match_candidates(bigint, text, text, text, text), si_holder_history(bigint), si_holders_changes(bigint, text, date, date), si_holders_by_range(bigint, text, date),
    si_holders_by_geography(bigint, text, date), si_holders_by_custodian(bigint, date), si_holders_by_type(bigint, date)
    TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_holder_make(bigint, text, text, text, text, text, text, text, text, date), si_row_attach(text, bigint, bigint), si_match_row(text, bigint), si_match_decide(bigint, boolean),
    si_match_new_holder(text, bigint), si_match_import(bigint), si_holders_merge(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_holder_make(bigint, text, text, text, text, text, text, text, text, date), si_row_attach(text, bigint, bigint), si_match_row(text, bigint), si_match_decide(bigint, boolean),
    si_match_new_holder(text, bigint), si_match_import(bigint), si_holders_merge(bigint, bigint) TO shareholder_intelligence_rw;

COMMIT;
