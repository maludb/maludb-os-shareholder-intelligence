-- 011: THE SHORT SIDE — FINRA short interest (revisions as rows), daily short-sale volume, the SEC's fails-to-deliver, the Reg SHO
-- threshold lists, borrow observations — and PRICES; the Extended shapes (Form SHO, SLATE) created empty now (design §6.4–6.5; D10, D13).
--
-- Every number has a date and a source; a revision is a NEW ROW (observed_at); nothing interpolates. FINRA's and the SEC's field names
-- are kept (currentShortPositionQuantity → short_shares, daysToCoverQuantity → days_to_cover, ShortVolume/ShortExemptVolume/TotalVolume).
-- The daily short-volume ratio is OFF-EXCHANGE ONLY and never compared with short interest: the function that answers it says so.
--
-- THE ESTATE'S DATA MODEL (design §6.8): all NEW CANONICAL.
BEGIN;

CREATE TABLE short_interest (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    security_id            bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    settlement_date        date NOT NULL,
    published_on           date,
    short_shares           numeric(20,0) NOT NULL CHECK (short_shares >= 0),
    previous_short_shares  numeric(20,0),
    change_shares          numeric(20,0),
    change_percent         numeric(10,4),
    avg_daily_volume       numeric(20,0),
    days_to_cover          numeric(10,2),
    revision_flag          boolean NOT NULL DEFAULT false,
    market                 text,                                                   -- FINRA's marketClassCode
    source_id              bigint REFERENCES sources(id) ON DELETE SET NULL,
    observed_at            timestamptz NOT NULL DEFAULT now(),
    UNIQUE (security_id, settlement_date, observed_at)
);
CREATE INDEX short_interest_security_idx ON short_interest (security_id, settlement_date DESC, observed_at DESC);
-- A settlement date's latest observation (the series): one row per settlement date.
CREATE OR REPLACE FUNCTION si_short_interest_series(p_security bigint, p_from date, p_to date)
    RETURNS TABLE (settlement_date date, short_shares numeric, change_percent numeric, avg_daily_volume numeric, days_to_cover numeric, revision_flag boolean, pct_of_float numeric, pct_of_outstanding numeric, observed_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT DISTINCT ON (s.settlement_date) s.settlement_date, s.short_shares, s.change_percent, s.avg_daily_volume, s.days_to_cover, s.revision_flag,
           round(s.short_shares / NULLIF(si_share_count(p_security, 'float', s.settlement_date), 0) * 100, 2),
           round(s.short_shares / NULLIF(si_share_count(p_security, 'outstanding', s.settlement_date), 0) * 100, 2), s.observed_at
      FROM short_interest s WHERE s.security_id = p_security AND s.settlement_date BETWEEN p_from AND p_to
     ORDER BY s.settlement_date, s.observed_at DESC;
$$;
CREATE OR REPLACE FUNCTION si_short_vs_float(p_security bigint, p_on date DEFAULT CURRENT_DATE)
    RETURNS TABLE (settlement_date date, short_shares numeric, float_shares numeric, outstanding numeric, pct_of_float numeric, pct_of_outstanding numeric, days_to_cover numeric, avg_daily_volume numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT s.settlement_date, s.short_shares, si_share_count(p_security, 'float', s.settlement_date), si_share_count(p_security, 'outstanding', s.settlement_date),
           round(s.short_shares / NULLIF(si_share_count(p_security, 'float', s.settlement_date), 0) * 100, 2),
           round(s.short_shares / NULLIF(si_share_count(p_security, 'outstanding', s.settlement_date), 0) * 100, 2), s.days_to_cover, s.avg_daily_volume
      FROM short_interest s WHERE s.security_id = p_security AND s.settlement_date <= p_on ORDER BY s.settlement_date DESC, s.observed_at DESC LIMIT 1;
$$;

CREATE TABLE daily_short_volume (
    security_id          bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    trade_date           date NOT NULL,
    market               text NOT NULL CHECK (market IN ('CNMS', 'FORF', 'FNRA', 'FNYX', 'FNSQ', 'FNQC')),
    short_volume         numeric(20,0) NOT NULL CHECK (short_volume >= 0),
    short_exempt_volume  numeric(20,0) NOT NULL DEFAULT 0,
    total_volume         numeric(20,0) NOT NULL CHECK (total_volume >= 0),
    ratio                numeric(6,4) GENERATED ALWAYS AS (CASE WHEN total_volume > 0 THEN round(short_volume / total_volume, 4) END) STORED,
    source_id            bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at            timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (security_id, trade_date, market)
);
-- The series with 5- and 20-day means and FINRA's caveat as a column every reader sees.
CREATE OR REPLACE FUNCTION si_daily_short_volume_series(p_security bigint, p_market text, p_from date, p_to date)
    RETURNS TABLE (trade_date date, short_volume numeric, short_exempt_volume numeric, total_volume numeric, ratio numeric, mean_5 numeric, mean_20 numeric, caveat text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.trade_date, d.short_volume, d.short_exempt_volume, d.total_volume, d.ratio,
           round(avg(d.ratio) OVER (ORDER BY d.trade_date ROWS BETWEEN 4 PRECEDING AND CURRENT ROW), 4),
           round(avg(d.ratio) OVER (ORDER BY d.trade_date ROWS BETWEEN 19 PRECEDING AND CURRENT ROW), 4),
           'Off-exchange volume only; counts market-maker short sales and omits the offsetting buys (FINRA). Not comparable with short interest.'
      FROM daily_short_volume d WHERE d.security_id = p_security AND d.market = p_market AND d.trade_date BETWEEN p_from - 40 AND p_to
     ORDER BY d.trade_date;
$$;

CREATE TABLE fails_to_deliver (
    security_id      bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    settlement_date  date NOT NULL,
    quantity         numeric(20,0) NOT NULL CHECK (quantity >= 0),
    price            numeric(14,4),
    file_half        text CHECK (file_half IS NULL OR file_half IN ('a', 'b')),
    source_id        bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at        timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (security_id, settlement_date)
);
CREATE OR REPLACE FUNCTION si_fails_series(p_security bigint, p_from date, p_to date)
    RETURNS TABLE (settlement_date date, quantity numeric, price numeric, pct_of_outstanding numeric, median_60 numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT f.settlement_date, f.quantity, f.price, round(f.quantity / NULLIF(si_share_count(p_security, 'outstanding', f.settlement_date), 0) * 100, 4),
           (SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY g.quantity) FROM fails_to_deliver g WHERE g.security_id = p_security AND g.settlement_date BETWEEN f.settlement_date - 60 AND f.settlement_date - 1)
      FROM fails_to_deliver f WHERE f.security_id = p_security AND f.settlement_date BETWEEN p_from AND p_to ORDER BY f.settlement_date;
$$;

CREATE TABLE threshold_listings (
    security_id  bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    list_date    date NOT NULL,
    list         text NOT NULL CHECK (list IN ('nasdaq', 'nyse', 'nyse_american', 'nyse_arca', 'otc')),
    flag         text,
    rule_3210    text,
    source_id    bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (security_id, list_date, list)
);
-- The current run: on a list now (as of the latest list date loaded), since when, how many consecutive LIST DAYS (consecutive among
-- the list dates loaded, so a weekend or a holiday with no list does not break the run).
CREATE OR REPLACE FUNCTION si_threshold_status(p_security bigint)
    RETURNS TABLE (on_list boolean, list text, since date, consecutive_days integer, latest_list_date date)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH cal AS (SELECT DISTINCT list_date FROM threshold_listings),
         calr AS (SELECT list_date, row_number() OVER (ORDER BY list_date) AS rn FROM cal),
         latest AS (SELECT max(list_date) AS d FROM cal),
         mine AS (SELECT t.list, t.list_date, c.rn FROM threshold_listings t JOIN calr c ON c.list_date = t.list_date WHERE t.security_id = p_security),
         run AS (SELECT m.list, m.list_date, m.rn - row_number() OVER (PARTITION BY m.list ORDER BY m.list_date) AS grp FROM mine m),
         cur AS (SELECT r.list, min(r.list_date) AS since, count(*)::integer AS n, max(r.list_date) AS last FROM run r GROUP BY r.list, r.grp)
    SELECT true, c.list, c.since, c.n, (SELECT d FROM latest) FROM cur c, latest WHERE c.last = latest.d
    UNION ALL
    SELECT false, NULL, NULL, 0, (SELECT d FROM latest) WHERE NOT EXISTS (SELECT 1 FROM cur, latest WHERE cur.last = latest.d)
    LIMIT 1;
$$;

CREATE TABLE borrow_observations (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    security_id           bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    observed_on           date NOT NULL,
    source                text NOT NULL CHECK (source IN ('vendor', 'broker_quote', 'manual')),
    source_id             bigint REFERENCES sources(id) ON DELETE SET NULL,
    fee_rate_percent      numeric(8,3),
    utilization_percent   numeric(6,2),
    shares_on_loan        numeric(20,0),
    shares_available      numeric(20,0),
    note                  text,
    recorded_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    recorded_at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX borrow_observations_idx ON borrow_observations (security_id, observed_on DESC);

-- PRICES (new canonical): one daily series per security
CREATE TABLE prices (
    security_id  bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    trade_date   date NOT NULL,
    open         numeric(14,4),
    high         numeric(14,4),
    low          numeric(14,4),
    close        numeric(14,4) NOT NULL,
    volume       numeric(20,0),
    source_id    bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (security_id, trade_date)
);
CREATE OR REPLACE FUNCTION si_price_on(p_security bigint, p_date date) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT close FROM prices WHERE security_id = p_security AND trade_date <= p_date ORDER BY trade_date DESC LIMIT 1;
$$;
-- The overlay one chart reads (§7 Q33): price, volume, the short-side series aligned by date.
CREATE OR REPLACE FUNCTION si_short_side_overlay(p_security bigint, p_from date, p_to date)
    RETURNS TABLE (d date, close numeric, volume numeric, short_pct_float numeric, days_to_cover numeric, fails numeric, short_volume_ratio_5 numeric, on_threshold boolean, borrow_fee numeric, utilization numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p.trade_date, p.close, p.volume,
           (SELECT pct_of_float FROM si_short_interest_series(p_security, p_from - 45, p_to) s WHERE s.settlement_date = p.trade_date),
           (SELECT days_to_cover FROM si_short_interest_series(p_security, p_from - 45, p_to) s WHERE s.settlement_date = p.trade_date),
           (SELECT quantity FROM fails_to_deliver f WHERE f.security_id = p_security AND f.settlement_date = p.trade_date),
           (SELECT mean_5 FROM si_daily_short_volume_series(p_security, 'CNMS', p_from, p_to) v WHERE v.trade_date = p.trade_date),
           EXISTS (SELECT 1 FROM threshold_listings t WHERE t.security_id = p_security AND t.list_date = p.trade_date),
           (SELECT fee_rate_percent FROM borrow_observations b WHERE b.security_id = p_security AND b.observed_on = p.trade_date ORDER BY recorded_at DESC LIMIT 1),
           (SELECT utilization_percent FROM borrow_observations b WHERE b.security_id = p_security AND b.observed_on = p.trade_date ORDER BY recorded_at DESC LIMIT 1)
      FROM prices p WHERE p.security_id = p_security AND p.trade_date BETWEEN p_from AND p_to ORDER BY p.trade_date;
$$;

-- Extended shapes, empty until the SEC and FINRA publish (2028): the fetchers of then are columns, not migrations.
CREATE TABLE form_sho_aggregates (
    security_id          bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    month                date NOT NULL,                                             -- the month's first day
    gross_short_position numeric(20,0),
    daily_net_activity   jsonb NOT NULL DEFAULT '{}'::jsonb,
    source_id            bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at            timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (security_id, month)
);
CREATE TABLE securities_lending_events (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    security_id    bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    event_date     date NOT NULL,
    event_kind     text,
    quantity       numeric(20,0),
    rate_percent   numeric(8,3),
    term           text,
    collateral     text,
    raw            jsonb NOT NULL DEFAULT '{}'::jsonb,
    source_id      bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX securities_lending_events_idx ON securities_lending_events (security_id, event_date DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON short_interest, daily_short_volume, fails_to_deliver, threshold_listings, borrow_observations, prices, form_sho_aggregates, securities_lending_events TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_short_interest_series(bigint, date, date), si_short_vs_float(bigint, date), si_daily_short_volume_series(bigint, text, date, date), si_fails_series(bigint, date, date),
    si_threshold_status(bigint), si_price_on(bigint, date), si_short_side_overlay(bigint, date, date)
    TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;

COMMIT;
