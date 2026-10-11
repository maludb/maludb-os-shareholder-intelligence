-- 023: slice 6 — the short side (docs/build-specs/short-side.md "Built").
--
-- What the first live calls found (2026-10-10), written into the seeded sources:
--   * FINRA's short interest for every market is the dataset `consolidatedShortInterest` (group otcMarket) — keyless, the fields the fixture
--     carries (symbolCode, currentShortPositionQuantity, daysToCoverQuantity, revisionFlag); `equityShortInterest` is the OTC-only one with
--     other names. The OTC threshold list is FINRA's `thresholdList` dataset (keyless).
--   * The SEC's fails come as half-month zips listed on its fails-to-deliver page; each file ends with its own trailer (the record count and
--     the total quantity) — the connector checks the rows against it.
--   * stooq's robots.txt disallows every agent but Google's and Bing's and its pages answer a JavaScript proof-of-work: under the policy no
--     machine reads it. The source is paused with that reason; prices come from Alpha Vantage or Polygon with a key (seeded, paused until the
--     admin sets one), or an upload (`prices_manual`, the price CSV through the upload pipeline).
-- A DEFECT of db/011 fixed: si_threshold_status() counted "consecutive list days" among the dates that have a ROW — but a row is written only
-- for a tracked security on the list, so a day the security was off every list left no date at all and two runs merged into one. The
-- calendar is now the days a list FILE was read (`threshold_list_days`, one row per list and date, written by the connector even when no
-- tracked security is on it), per list; a listing loaded without its day (the Phase 0 proof's) still counts as a day of its list.
-- si_threshold_runs() answers every run of a security on each list (the threshold screen and `threshold_history`) on the same calendar.
BEGIN;

CREATE TABLE threshold_list_days (
    list         text NOT NULL CHECK (list IN ('nasdaq', 'nyse', 'nyse_american', 'nyse_arca', 'otc')),
    list_date    date NOT NULL,
    entries      integer NOT NULL DEFAULT 0 CHECK (entries >= 0),          -- the file's rows (every security on it, tracked or not)
    source_id    bigint REFERENCES sources(id) ON DELETE SET NULL,
    loaded_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (list, list_date)
);
GRANT SELECT, INSERT, UPDATE, DELETE ON threshold_list_days TO shareholder_intelligence_rw;
GRANT SELECT ON threshold_list_days TO shareholder_intelligence_records_ro;

CREATE OR REPLACE FUNCTION si_threshold_status(p_security bigint)
    RETURNS TABLE (on_list boolean, list text, since date, consecutive_days integer, latest_list_date date)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH cal AS (SELECT d.list, d.list_date FROM threshold_list_days d UNION SELECT t.list, t.list_date FROM threshold_listings t),
         calr AS (SELECT c.list, c.list_date, row_number() OVER (PARTITION BY c.list ORDER BY c.list_date) AS rn FROM cal c),
         latest AS (SELECT c.list, max(c.list_date) AS d FROM cal c GROUP BY c.list),
         mine AS (SELECT t.list, t.list_date, r.rn - row_number() OVER (PARTITION BY t.list ORDER BY t.list_date) AS grp
                    FROM threshold_listings t JOIN calr r ON r.list = t.list AND r.list_date = t.list_date WHERE t.security_id = p_security),
         cur AS (SELECT m.list, min(m.list_date) AS since, count(*)::integer AS n, max(m.list_date) AS last FROM mine m GROUP BY m.list, m.grp)
    SELECT true, c.list, c.since, c.n, l.d FROM cur c JOIN latest l ON l.list = c.list AND c.last = l.d
    UNION ALL
    SELECT false, NULL, NULL, 0, (SELECT max(d) FROM latest) WHERE NOT EXISTS (SELECT 1 FROM cur c JOIN latest l ON l.list = c.list AND c.last = l.d)
    ORDER BY 4 DESC LIMIT 1;
$$;

UPDATE sources SET base_url = 'https://api.finra.org/data/group/otcMarket/name/consolidatedShortInterest',
       settings = settings || '{"dataset": "consolidatedShortInterest", "calendar": "publication_days"}'
 WHERE connector = 'finra_short_interest' AND base_url LIKE '%equityShortInterest%';
UPDATE sources SET settings = settings || '{"backfill_days": 60}' WHERE connector = 'finra_daily_short_volume' AND NOT settings ? 'backfill_days';
UPDATE sources SET settings = settings || '{"listing_url": "https://www.sec.gov/data-research/sec-markets-data/fails-deliver-data", "backfill_halves": 24}'
 WHERE connector = 'sec_ftd' AND NOT settings ? 'listing_url';
UPDATE sources SET settings = settings || '{"backfill_days": 10}' WHERE connector IN ('threshold_nasdaq', 'threshold_nyse', 'threshold_otc') AND NOT settings ? 'backfill_days';
UPDATE sources SET base_url = 'https://api.finra.org/data/group/otcMarket/name/thresholdList' WHERE connector = 'threshold_otc' AND base_url LIKE '%otce.finra.org%';
UPDATE sources SET paused_at = now(), paused_reason = 'stooq''s robots.txt disallows every agent but Google''s and Bing''s, and its pages answer a JavaScript proof-of-work (found 2026-10-10): no machine may read it. Prices come from Alpha Vantage or Polygon with a key, or an upload.'
 WHERE connector = 'prices_stooq' AND paused_at IS NULL;

INSERT INTO sources (name, connector, role, base_url, schedule_minutes, rate_per_second, settings, paused_at, paused_reason)
SELECT v.name, v.connector, v.role, v.base_url, v.schedule_minutes, 1, v.settings::jsonb, v.paused_at, v.paused_reason
  FROM (VALUES
    ('Prices — Alpha Vantage (a key)', 'prices_alphavantage', 'market_data', 'https://www.alphavantage.co/query', 1440, '{"function": "TIME_SERIES_DAILY"}', now(), 'Needs an Alpha Vantage key: the admin sets it on the source.'),
    ('Prices — Polygon (a key)', 'prices_polygon', 'market_data', 'https://api.polygon.io/v2/aggs/ticker/', 1440, '{}', now(), 'Needs a Polygon key: the admin sets it on the source.'),
    ('Prices — uploaded', 'prices_manual', 'manual', NULL, NULL, '{}', NULL, NULL)
  ) AS v(name, connector, role, base_url, schedule_minutes, settings, paused_at, paused_reason)
 WHERE NOT EXISTS (SELECT 1 FROM sources s WHERE s.connector = v.connector);

CREATE OR REPLACE FUNCTION si_threshold_runs(p_security bigint)
    RETURNS TABLE (list text, from_date date, to_date date, list_days integer, current boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH cal AS (SELECT d.list, d.list_date FROM threshold_list_days d UNION SELECT t.list, t.list_date FROM threshold_listings t),
         calr AS (SELECT c.list, c.list_date, row_number() OVER (PARTITION BY c.list ORDER BY c.list_date) AS rn FROM cal c),
         latest AS (SELECT c.list, max(c.list_date) AS d FROM cal c GROUP BY c.list),
         mine AS (SELECT t.list, t.list_date, r.rn - row_number() OVER (PARTITION BY t.list ORDER BY t.list_date) AS grp
                    FROM threshold_listings t JOIN calr r ON r.list = t.list AND r.list_date = t.list_date WHERE t.security_id = p_security)
    SELECT m.list, min(m.list_date), max(m.list_date), count(*)::integer, max(m.list_date) = (SELECT l.d FROM latest l WHERE l.list = m.list)
      FROM mine m GROUP BY m.list, m.grp ORDER BY min(m.list_date) DESC, m.list;
$$;
GRANT EXECUTE ON FUNCTION si_threshold_runs(bigint) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;

COMMIT;
