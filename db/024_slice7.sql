-- 024: slice 7 — the signals (docs/build-specs/signals-rules.md "Built").
--
-- The rules themselves are db/013's and are not touched. What the worker needs to run them "after every pull and upload" (signals.md §3)
-- and once each market day at 06:45 is a memory of when it last ran them for each security:
--   * `rule_runs` — one row per security: the last run (by the worker, or `rules_run` on demand), the day it ran for, its counts, and
--     `daily_on`, the market day whose 06:45 run is done.
--   * `si_rules_due(limit)` — the securities whose inputs changed since their last run: an upload finished or tombstoned, short interest,
--     short volume, fails, a threshold list file read, prices, filings and the ownership forms, borrow, events, share counts, a dilution
--     instrument. A security never run is due once it has any of these.
BEGIN;

CREATE TABLE rule_runs (
    security_id  bigint PRIMARY KEY REFERENCES securities(id) ON DELETE CASCADE,
    ran_at       timestamptz NOT NULL DEFAULT now(),
    as_of        date NOT NULL,
    raised       integer NOT NULL DEFAULT 0,
    refreshed    integer NOT NULL DEFAULT 0,
    expired      integer NOT NULL DEFAULT 0,
    daily_on     date                                                      -- the market day whose 06:45 run covered this security
);
GRANT SELECT, INSERT, UPDATE, DELETE ON rule_runs TO shareholder_intelligence_rw;

CREATE OR REPLACE FUNCTION si_rules_due(p_limit integer DEFAULT 200) RETURNS TABLE (security_id bigint, changed_at timestamptz)
    LANGUAGE sql STABLE SET search_path = public AS $$
    SELECT x.id, x.changed_at FROM (
        SELECT s.id, GREATEST(
            (SELECT max(GREATEST(i.finished_at, i.deleted_at, i.override_at)) FROM imports i WHERE i.security_id = s.id),
            (SELECT max(observed_at) FROM short_interest t WHERE t.security_id = s.id),
            (SELECT max(loaded_at) FROM daily_short_volume t WHERE t.security_id = s.id),
            (SELECT max(loaded_at) FROM fails_to_deliver t WHERE t.security_id = s.id),
            (SELECT max(loaded_at) FROM threshold_listings t WHERE t.security_id = s.id),
            (SELECT max(loaded_at) FROM threshold_list_days),                                   -- a day off every list is news too
            (SELECT max(loaded_at) FROM prices t WHERE t.security_id = s.id),
            (SELECT max(GREATEST(f.created_at, f.structured_at)) FROM filings f WHERE f.issuer_id = s.issuer_id),
            (SELECT max(loaded_at) FROM beneficial_ownership t WHERE t.security_id = s.id),
            (SELECT max(loaded_at) FROM institutional_holdings t WHERE t.security_id = s.id),
            (SELECT max(loaded_at) FROM insider_transactions t WHERE t.issuer_id = s.issuer_id),
            (SELECT max(loaded_at) FROM form144_notices t WHERE t.issuer_id = s.issuer_id),
            (SELECT max(recorded_at) FROM borrow_observations t WHERE t.security_id = s.id),
            (SELECT max(GREATEST(e.created_at, e.updated_at, e.deleted_at)) FROM events e WHERE e.issuer_id = s.issuer_id),
            (SELECT max(recorded_at) FROM share_counts t WHERE t.security_id = s.id),
            (SELECT max(GREATEST(d.updated_at, d.confirmed_at)) FROM dilution_instruments d WHERE d.issuer_id = s.issuer_id),
            (SELECT max(ran_at) FROM share_reconciliations t WHERE t.security_id = s.id)
        ) AS changed_at
          FROM securities s
    ) x
    LEFT JOIN rule_runs r ON r.security_id = x.id
    WHERE x.changed_at IS NOT NULL AND (r.ran_at IS NULL OR x.changed_at > r.ran_at)
    ORDER BY x.changed_at
    LIMIT p_limit;
$$;
REVOKE ALL ON FUNCTION si_rules_due(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_rules_due(integer) TO shareholder_intelligence_rw;

COMMIT;
