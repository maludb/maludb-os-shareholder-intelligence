-- 009: THE PUBLIC RECORD'S DOORS — sources, pulls and credentials (Inventory's model) — and EDGAR's FILINGS with their text, the
-- full-text WATCH, EVENTS and DILUTION INSTRUMENTS (design §6.4–6.5; D9; docs/build-specs/edgar-and-finra-fetchers.md).
--
-- A source is a place the worker reads from under a POLICY: a declared user-agent (EDGAR's "<business> <contact>" from si_settings),
-- the rate the host asks for, If-Modified-Since, a 403/429 backing the source off, robots honoured, every pull a source_pulls row.
-- Nothing fetched that the host did not publish for machines; no portal, no vendor page, no login. The three issuer files are never
-- sources (D6). A credential (a market-data key, a licensed short-data key) is libsodium ciphertext under SI_SECRETS_KEY, decrypted
-- in one PHP file.
--
-- THE ESTATE'S DATA MODEL (design §6.8): sources, source_pulls, source_credentials are Inventory db/009 VERBATIM — the canonical "a
-- place read from"; `connector` checks si_connectors(); `role` widened; supplier_id kept nullable with no FK (the Inventory
-- income_account_id precedent — recorded). filings, filing_text (the shape of Knowledge's source_text), events, dilution_instruments are
-- NEW CANONICAL: nothing in the estate reads EDGAR. The column names of filings follow the submissions JSON.
BEGIN;

CREATE OR REPLACE FUNCTION si_connectors() RETURNS text[] LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['edgar_submissions', 'edgar_companyfacts', 'edgar_fulltext', 'edgar_13f_dataset', 'edgar_13f_filing', 'edgar_13dg', 'edgar_ownership', 'edgar_144',
                 'finra_short_interest', 'finra_daily_short_volume', 'sec_ftd', 'threshold_nasdaq', 'threshold_nyse', 'threshold_otc', 'dtcc_participants',
                 'prices_stooq', 'prices_alphavantage', 'prices_polygon', 'prices_manual',
                 'edgar_nport', 'edgar_form_sho', 'finra_slate', 'ortex', 'breakout_point'];   -- the last five Extended: declared, not pulled in v1
$$;

-- reuse: Inventory db/009 verbatim (connector check ours; role widened; supplier_id kept, nullable, no FK)
CREATE TABLE sources (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,
    connector             text NOT NULL CHECK (connector = ANY (si_connectors())),
    role                  text NOT NULL DEFAULT 'public_data' CHECK (role IN ('supplier', 'reference', 'public_data', 'market_data', 'licensed', 'manual')),
    supplier_id           bigint,
    base_url              text,
    settings              jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings) = 'object'),   -- per connector: the issuers it serves (NULL = every issuer), the watch terms, a dataset name, a market list
    credential_id         bigint,
    schedule_minutes      integer CHECK (schedule_minutes IS NULL OR schedule_minutes >= 0),
    rate_per_second       numeric(4,2) NOT NULL DEFAULT 1 CHECK (rate_per_second > 0 AND rate_per_second <= 10),
    user_agent            text,
    robots_state          text NOT NULL DEFAULT 'unknown' CHECK (robots_state IN ('ok', 'blocked', 'unknown')),
    robots_checked_at     timestamptz,
    last_pull_id          bigint,
    last_ok_at            timestamptz,
    consecutive_failures  integer NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    backoff_until         timestamptz,
    paused_at             timestamptz,
    paused_reason         text,
    active                boolean NOT NULL DEFAULT true,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (role <> 'supplier' OR supplier_id IS NOT NULL)
);
CREATE INDEX sources_due_idx ON sources (active, paused_at, backoff_until);
CREATE TRIGGER sources_touch BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE source_pulls (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id         bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind              text NOT NULL DEFAULT 'scheduled' CHECK (kind IN ('scheduled', 'manual', 'search', 'probe')),
    started_at        timestamptz NOT NULL DEFAULT now(),
    finished_at       timestamptz,
    status            text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'partial', 'failed', 'blocked')),
    listings_seen     integer NOT NULL DEFAULT 0,          -- here: items seen (filings, rows, days)
    listings_new      integer NOT NULL DEFAULT 0,
    listings_changed  integer NOT NULL DEFAULT 0,
    variants_changed  integer NOT NULL DEFAULT 0,
    listings_removed  integer NOT NULL DEFAULT 0,
    http_requests     integer NOT NULL DEFAULT 0,
    bytes             bigint NOT NULL DEFAULT 0,
    error             text CHECK (error IS NULL OR length(error) <= 200),
    policy            jsonb NOT NULL DEFAULT '{}'::jsonb,
    query             text,
    started_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (query IS NULL OR length(query) <= 120)
);
CREATE INDEX source_pulls_source_idx ON source_pulls (source_id, started_at DESC);
ALTER TABLE sources ADD CONSTRAINT sources_last_pull_fk FOREIGN KEY (last_pull_id) REFERENCES source_pulls(id) ON DELETE SET NULL;

CREATE TABLE source_credentials (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id   bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('api_key', 'oauth_client', 'basic', 'sftp_password', 'sftp_key', 'rsa_signing', 'bearer')),
    label       text NOT NULL,
    last4       text NOT NULL CHECK (length(last4) BETWEEN 1 AND 4),
    ciphertext  bytea NOT NULL,
    rotated_at  timestamptz,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE sources ADD CONSTRAINT sources_credential_fk FOREIGN KEY (credential_id) REFERENCES source_credentials(id) ON DELETE SET NULL;
CREATE TRIGGER source_credentials_touch BEFORE UPDATE ON source_credentials FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The v1 sources, seeded (every installation; the admin pauses what it does not want). Schedules in minutes; the worker also honours
-- a connector's own calendar (the 13F sets quarterly, short interest on publication days, the fails on the 1st and 16th + 2).
INSERT INTO sources (name, connector, role, base_url, schedule_minutes, rate_per_second, settings) VALUES
 ('EDGAR — the issuers'' filing index', 'edgar_submissions', 'public_data', 'https://data.sec.gov/submissions/', 360, 2, '{"hours_et": [6, 18]}'),
 ('EDGAR — the cover share counts (companyfacts)', 'edgar_companyfacts', 'public_data', 'https://data.sec.gov/api/xbrl/companyfacts/', 1440, 2, '{"concepts": ["dei:EntityCommonStockSharesOutstanding", "dei:EntityPublicFloat"]}'),
 ('EDGAR — the full-text watch', 'edgar_fulltext', 'public_data', 'https://efts.sec.gov/LATEST/search-index', 60, 2, '{"terms": ["at-the-market", "equity line", "equity purchase agreement", "convertible", "warrant", "reverse split", "registered direct"], "forms": ["8-K", "S-1", "S-3", "424B5", "424B3", "424B4", "10-Q", "10-K", "SC 13D", "SC 13D/A", "SC 13G", "SC 13G/A"]}'),
 ('EDGAR — the quarterly 13F data sets', 'edgar_13f_dataset', 'public_data', 'https://www.sec.gov/files/structureddata/data/form-13f-data-sets/', 1440, 1, '{"calendar": "quarterly"}'),
 ('EDGAR — Schedules 13D and 13G', 'edgar_13dg', 'public_data', 'https://efts.sec.gov/LATEST/search-index', 360, 2, '{"forms": ["SC 13D", "SC 13D/A", "SC 13G", "SC 13G/A"]}'),
 ('EDGAR — Forms 3, 4 and 5', 'edgar_ownership', 'public_data', 'https://data.sec.gov/submissions/', 360, 2, '{"forms": ["3", "4", "5", "3/A", "4/A", "5/A"]}'),
 ('EDGAR — Form 144', 'edgar_144', 'public_data', 'https://efts.sec.gov/LATEST/search-index', 360, 2, '{"forms": ["144", "144/A"]}'),
 ('FINRA — short interest', 'finra_short_interest', 'public_data', 'https://api.finra.org/data/group/otcMarket/name/equityShortInterest', 1440, 1, '{"calendar": "publication_days"}'),
 ('FINRA — daily short-sale volume (CNMS, FORF)', 'finra_daily_short_volume', 'public_data', 'https://cdn.finra.org/equity/regsho/daily/', 60, 1, '{"markets": ["CNMS", "FORF"], "hour_et": 18, "minute": 30}'),
 ('SEC — fails-to-deliver', 'sec_ftd', 'public_data', 'https://www.sec.gov/files/data/fails-deliver-data/', 1440, 1, '{"calendar": "half_month"}'),
 ('Nasdaq — Reg SHO threshold list', 'threshold_nasdaq', 'public_data', 'https://www.nasdaqtrader.com/dynamic/symdir/regsho/', 1440, 1, '{"hour_et": 23}'),
 ('NYSE — Reg SHO threshold lists', 'threshold_nyse', 'public_data', 'https://www.nyse.com/api/regulatory/threshold-securities/download', 1440, 1, '{"markets": ["NYSE", "NYSE American", "NYSE Arca"], "hour_et": 23}'),
 ('FINRA — OTC threshold list', 'threshold_otc', 'public_data', 'https://otce.finra.org/otce/otcThreshold', 1440, 1, '{"hour_et": 23}'),
 ('DTCC — the participant directory', 'dtcc_participants', 'public_data', 'https://www.dtcc.com/support/dtc-directories', 43200, 1, '{"files": ["alphabetical", "numerical", "settling_banks", "pledgees", "drs"]}'),
 ('Prices — stooq (keyless daily CSV)', 'prices_stooq', 'market_data', 'https://stooq.com/q/d/l/', 1440, 1, '{"suffix": ".us"}');

-- The sources due now (the worker's fetch pass): active, not paused, not backed off, the schedule elapsed; a connector's own calendar
-- is the fetcher's to honour on top.
CREATE OR REPLACE FUNCTION si_sources_due(p_now timestamptz DEFAULT now()) RETURNS SETOF sources
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT s.* FROM sources s
     WHERE s.active AND s.paused_at IS NULL AND (s.backoff_until IS NULL OR s.backoff_until <= p_now)
       AND COALESCE(s.schedule_minutes, 0) > 0
       AND (s.last_ok_at IS NULL OR s.last_ok_at + make_interval(mins => s.schedule_minutes) <= p_now)
       AND s.connector NOT IN ('edgar_nport', 'edgar_form_sho', 'finra_slate', 'ortex', 'breakout_point', 'edgar_13f_filing')
     ORDER BY s.last_ok_at NULLS FIRST;
$$;
-- A pull's end: ok resets the failures; a failure counts and backs off on a ladder (5, 15, 60, 240 minutes); blocked backs off an hour and tells the admin (the worker notifies).
CREATE OR REPLACE FUNCTION si_pull_finish(p_pull bigint, p_status text, p_error text DEFAULT NULL, p_seen integer DEFAULT 0, p_new integer DEFAULT 0, p_changed integer DEFAULT 0, p_requests integer DEFAULT 0, p_bytes bigint DEFAULT 0, p_policy jsonb DEFAULT '{}') RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sid bigint; fails integer;
BEGIN
    UPDATE source_pulls SET finished_at = now(), status = p_status, error = left(p_error, 200), listings_seen = p_seen, listings_new = p_new, listings_changed = p_changed, http_requests = p_requests, bytes = p_bytes, policy = p_policy
     WHERE id = p_pull RETURNING source_id INTO sid;
    IF p_status IN ('ok', 'partial') THEN
        UPDATE sources SET last_pull_id = p_pull, last_ok_at = now(), consecutive_failures = 0, backoff_until = NULL WHERE id = sid;
    ELSIF p_status = 'blocked' THEN
        UPDATE sources SET last_pull_id = p_pull, consecutive_failures = consecutive_failures + 1, backoff_until = now() + interval '1 hour' WHERE id = sid;
    ELSE
        UPDATE sources SET last_pull_id = p_pull, consecutive_failures = consecutive_failures + 1 WHERE id = sid RETURNING consecutive_failures INTO fails;
        UPDATE sources SET backoff_until = now() + CASE WHEN fails <= 1 THEN interval '5 minutes' WHEN fails = 2 THEN interval '15 minutes' WHEN fails = 3 THEN interval '1 hour' ELSE interval '4 hours' END WHERE id = sid;
    END IF;
END$$;

-- ---------------------------------------------------------------------------------------------
-- FILINGS (the issuer's index, and filings by others about us) and their TEXT
-- ---------------------------------------------------------------------------------------------
CREATE TABLE filings (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id               bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    filer_cik               text NOT NULL CHECK (filer_cik ~ '^[0-9]{10}$'),       -- the issuer's, or another filer's when about us
    accession_number        text NOT NULL CHECK (accession_number ~ '^[0-9]{10}-[0-9]{2}-[0-9]{6}$'),
    form                    text NOT NULL,                                          -- "8-K", "SC 13D/A", "4", "10-Q"
    filed_on                date NOT NULL,
    report_date             date,
    accepted_at             timestamptz,
    act                     text,
    file_number             text,
    film_number             text,
    items                   text[] NOT NULL DEFAULT '{}',                            -- 8-K items ("1.01", "3.02", "5.02")
    size                    integer,
    is_xbrl                 boolean NOT NULL DEFAULT false,
    is_inline_xbrl          boolean NOT NULL DEFAULT false,
    primary_document        text,
    primary_doc_description text,
    url                     text GENERATED ALWAYS AS ('https://www.sec.gov/Archives/edgar/data/' || ltrim(filer_cik, '0') || '/' || replace(accession_number, '-', '') || '/' || COALESCE(primary_document, '')) STORED,
    about_us                boolean NOT NULL DEFAULT false,                          -- another filer's filing naming our CUSIP (a 13D, a 13F, a lawsuit)
    fetched_text_at         timestamptz,
    text_sha256             text CHECK (text_sha256 IS NULL OR text_sha256 ~ '^[0-9a-f]{64}$'),
    char_count              integer NOT NULL DEFAULT 0,
    structured_at           timestamptz,                                            -- the XML parsed into the ownership tables (db/010)
    source_pull_id          bigint REFERENCES source_pulls(id) ON DELETE SET NULL,
    error                   text,
    created_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (issuer_id, accession_number)
);
CREATE INDEX filings_issuer_form_idx ON filings (issuer_id, form, filed_on DESC);
CREATE INDEX filings_filed_idx ON filings (issuer_id, filed_on DESC);
CREATE INDEX filings_about_idx ON filings (issuer_id, filed_on DESC) WHERE about_us;
CREATE INDEX filings_text_due_idx ON filings (issuer_id) WHERE fetched_text_at IS NULL;
ALTER TABLE share_counts ADD CONSTRAINT share_counts_filing_fk FOREIGN KEY (filing_id) REFERENCES filings(id) ON DELETE SET NULL;

-- reuse (shape): Knowledge's source_text — the primary document's text, HTML stripped; the full-text index and Knowledge's K7 read
CREATE TABLE filing_text (
    filing_id     bigint PRIMARY KEY REFERENCES filings(id) ON DELETE CASCADE,
    text          text NOT NULL,
    extractor     text CHECK (extractor IS NULL OR extractor IN ('html', 'xml', 'pdf', 'txt')),
    extracted_at  timestamptz NOT NULL DEFAULT now(),
    tsv           tsvector GENERATED ALWAYS AS (to_tsvector('english', left(text, 1000000))) STORED
);
CREATE INDEX filing_text_tsv_idx ON filing_text USING gin (tsv);

-- The forms whose text is fetched on arrival and which make an event (the watched forms, §6.4); the rest on demand.
CREATE OR REPLACE FUNCTION si_watched_form(p_form text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT p_form IN ('8-K', '8-K/A', 'SC 13D', 'SC 13D/A', 'SC 13G', 'SC 13G/A', '4', '4/A', '144', '144/A', 'S-1', 'S-1/A', 'S-3', 'S-3/A', '424B3', '424B4', '424B5', 'DEF 14A', 'DEFA14A', '10-K', '10-K/A', '10-Q', '10-Q/A', 'SC TO-I', 'SC 13E3', 'S-8', 'EFFECT', '25', '25-NSE', '15-12G');
$$;

-- A filing's text, searched (the Analyst's filings_search; §7 Q25).
CREATE OR REPLACE FUNCTION si_filings_search(p_issuer bigint, p_q text, p_limit integer DEFAULT 20)
    RETURNS TABLE (filing_id bigint, form text, filed_on date, headline text, rank real)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q tsquery;
BEGIN
    IF NOT si_can_see_issuer(p_issuer) OR btrim(COALESCE(p_q, '')) = '' THEN RETURN; END IF;
    q := websearch_to_tsquery('english', p_q);
    RETURN QUERY
    SELECT f.id, f.form, f.filed_on, ts_headline('english', left(t.text, 200000), q, 'MaxFragments=2, MaxWords=30, MinWords=10'), ts_rank_cd(t.tsv, q)
      FROM filings f JOIN filing_text t ON t.filing_id = f.id
     WHERE f.issuer_id = p_issuer AND t.tsv @@ q
     ORDER BY 5 DESC, f.filed_on DESC LIMIT LEAST(COALESCE(p_limit, 20), 100);
END$$;

-- ---------------------------------------------------------------------------------------------
-- EVENTS — what happened on a day — and DILUTION INSTRUMENTS
-- ---------------------------------------------------------------------------------------------
CREATE TABLE events (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id      bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    security_id    bigint REFERENCES securities(id) ON DELETE SET NULL,
    on_date        date NOT NULL,
    kind           text NOT NULL CHECK (kind IN ('filing', 'financing', 'dilution_instrument', 'record_date', 'earnings', 'press', 'activist_short_report', 'lawsuit', 'exchange_notice', 'insider_transaction', 'share_count', 'threshold', 'note', 'other')),
    title          text NOT NULL CHECK (length(title) BETWEEN 1 AND 300),
    body           text,
    url            text,
    filing_id      bigint REFERENCES filings(id) ON DELETE SET NULL,
    severity       text NOT NULL DEFAULT 'info' CHECK (severity IN ('info', 'watch', 'high')),
    source         text NOT NULL DEFAULT 'person' CHECK (source IN ('worker', 'person', 'agent')),
    created_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz,
    deleted_by     bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX events_issuer_date_idx ON events (issuer_id, on_date DESC) WHERE deleted_at IS NULL;
CREATE INDEX events_filing_idx ON events (filing_id);
CREATE UNIQUE INDEX events_one_per_filing ON events (filing_id) WHERE kind = 'filing' AND deleted_at IS NULL;
CREATE TRIGGER events_touch BEFORE UPDATE ON events FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A filing of a watched form makes its event (the fetcher inserts the filing; this trigger makes the day's event).
CREATE OR REPLACE FUNCTION si_filings_event() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sev text; k text; t text;
BEGIN
    IF NOT si_watched_form(NEW.form) THEN RETURN NULL; END IF;
    sev := CASE WHEN NEW.form IN ('SC 13D', 'SC 13D/A') THEN 'high' WHEN NEW.form IN ('8-K', '8-K/A') AND NEW.items && ARRAY['3.02', '1.01', '2.03', '3.03', '5.02'] THEN 'watch'
                WHEN NEW.form IN ('S-1', 'S-3', '424B5', '424B3', '424B4', '144', 'SC 13G', 'SC 13G/A', '25', '25-NSE', '15-12G', 'SC TO-I', 'SC 13E3') THEN 'watch' ELSE 'info' END;
    k := CASE WHEN NEW.form IN ('4', '4/A', '144', '144/A') THEN 'insider_transaction' WHEN NEW.form IN ('25', '25-NSE', '15-12G') THEN 'exchange_notice' ELSE 'filing' END;
    t := NEW.form || CASE WHEN NEW.about_us THEN ' filed about the company' ELSE ' filed' END || CASE WHEN cardinality(NEW.items) > 0 THEN ' — items ' || array_to_string(NEW.items, ', ') ELSE '' END
         || CASE WHEN NEW.primary_doc_description IS NOT NULL THEN ': ' || left(NEW.primary_doc_description, 150) ELSE '' END;
    INSERT INTO events (issuer_id, on_date, kind, title, url, filing_id, severity, source) VALUES (NEW.issuer_id, NEW.filed_on, k, left(t, 300), NEW.url, NEW.id, sev, 'worker')
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END$$;
CREATE TRIGGER filings_event AFTER INSERT ON filings FOR EACH ROW EXECUTE FUNCTION si_filings_event();

CREATE TABLE dilution_instruments (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id            bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    security_id          bigint REFERENCES securities(id) ON DELETE SET NULL,
    kind                 text NOT NULL CHECK (kind IN ('atm', 'eloc', 'convertible_note', 'convertible_preferred', 'warrant', 'rights_offering', 'registered_direct', 'pipe', 'other')),
    counterparty         text,
    filed_on             date,
    filing_id            bigint REFERENCES filings(id) ON DELETE SET NULL,
    facility_amount      numeric(16,2),
    currency             char(3) NOT NULL DEFAULT 'USD',
    remaining_amount     numeric(16,2),                                            -- a person's update
    price_terms          text,                                                     -- "95% of the lowest VWAP of the 5 days before conversion"
    floating_price       boolean NOT NULL DEFAULT false,                           -- the death-spiral flag (§0.1)
    shares_issuable_max  numeric(20,0),
    expires_on           date,
    status               text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'active', 'exhausted', 'terminated', 'dismissed')),
    proposed_by          text CHECK (proposed_by IS NULL OR proposed_by IN ('watch', 'person', 'agent')),
    watch_term           text,                                                     -- the term the full-text watch matched
    confirmed_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    confirmed_at         timestamptz,
    notes                text,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX dilution_issuer_idx ON dilution_instruments (issuer_id, status);
CREATE TRIGGER dilution_touch BEFORE UPDATE ON dilution_instruments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The watch's hits (the full-text search's answers): a term, a filing, a snippet; a dilution term also proposes an instrument.
CREATE TABLE watch_hits (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id     bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    filing_id     bigint REFERENCES filings(id) ON DELETE CASCADE,
    term          text NOT NULL,
    snippet       text,
    found_at      timestamptz NOT NULL DEFAULT now(),
    status        text NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'confirmed', 'dismissed')),
    decided_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at    timestamptz,
    UNIQUE (issuer_id, filing_id, term)
);
CREATE INDEX watch_hits_new_idx ON watch_hits (issuer_id, found_at DESC) WHERE status = 'new';

GRANT SELECT, INSERT, UPDATE, DELETE ON sources, source_pulls, source_credentials, filings, filing_text, events, dilution_instruments, watch_hits TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_connectors(), si_watched_form(text), si_filings_search(bigint, text, integer) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_sources_due(timestamptz), si_pull_finish(bigint, text, text, integer, integer, integer, integer, bigint, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_sources_due(timestamptz), si_pull_finish(bigint, text, text, integer, integer, integer, integer, bigint, jsonb) TO shareholder_intelligence_rw;

COMMIT;
