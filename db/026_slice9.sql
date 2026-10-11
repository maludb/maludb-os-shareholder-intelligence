-- 026: slice 9 — agents, proposals, notifications, K7 and the API (2026-10-11; docs/build-specs/agents-k7-api.md). Additive.
--
--  * si_settings.analyst_issuer_ids — the issuers the Surveillance Analyst covers (the agents screen's "the Analyst's issuers"; EMPTY = every issuer):
--    the dispatches pass sends the Analyst only the issuers it covers, the Friday draft is made for them alone, and the agent reads the list from
--    mcp_settings (appended last) before it writes the morning note.
--  * si_analyst_covers(issuer) — the one rule, in SQL: empty list = every issuer.
--  * si_agents_settings_save() — the agents screen's one write: the morning note on or off and its hour, proposals on or off, the pack's day, the Analyst's
--    issuers, the escalation member. The database checks the right (agents.settings, people only), that the issuers exist and that the escalation member is an
--    admitted person; PHP passes the whole new state (a field left out stays as it was) and logs the difference.
--  * agent_dispatches.status admitted 'cancelled' in db/018; nothing else about the table changes. A dispatch to wait on a kernel run keeps its run id.
BEGIN;

ALTER TABLE si_settings ADD COLUMN analyst_issuer_ids bigint[] NOT NULL DEFAULT '{}';

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.edgar_contact_email IS NOT NULL AND s.business_name IS NOT NULL AS edgar_user_agent_set, s.default_exchange, s.market_timezone, s.market_holidays, s.edgar_rate_per_second,
       s.reconciliation_tolerance_shares, s.auto_match_threshold, s.pii_in_exports, s.morning_note_hour, s.morning_note_enabled, s.analyst_proposals, s.pack_day, s.signal_grace_days, s.tombstone_days,
       s.key_rate_per_minute, s.key_rate_per_day, s.max_upload_bytes, s.price_source_default, s.timezone, CASE WHEN (SELECT si_is_admin()) THEN s.escalate_high_to END AS escalate_high_to, s.updated_at,
       s.analyst_issuer_ids
  FROM si_settings s WHERE (SELECT si_is_member_here());

-- Does the Surveillance Analyst cover this issuer? An empty list covers every issuer.
CREATE OR REPLACE FUNCTION si_analyst_covers(p_issuer bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT cardinality(analyst_issuer_ids) = 0 OR p_issuer = ANY (analyst_issuer_ids) FROM si_settings WHERE id = 1;
$$;

-- The agents screen's write. A person's (the handler calls require_human); the right is the database's.
CREATE OR REPLACE FUNCTION si_agents_settings_save(p_note_enabled boolean, p_note_hour smallint, p_proposals boolean, p_pack_day smallint, p_issuers bigint[], p_escalate bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE bad integer;
BEGIN
    IF app_member_kind() <> 'human' OR NOT si_has_right('agents.settings') THEN
        RAISE EXCEPTION 'The agents'' settings are an IR Manager''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_note_hour NOT BETWEEN 0 AND 23 THEN RAISE EXCEPTION 'The morning note''s hour is 0 to 23' USING ERRCODE = 'check_violation'; END IF;
    IF p_pack_day NOT BETWEEN 1 AND 7 THEN RAISE EXCEPTION 'The pack''s day is 1 (Monday) to 7 (Sunday)' USING ERRCODE = 'check_violation'; END IF;
    p_issuers := COALESCE(p_issuers, '{}');
    SELECT count(*) INTO bad FROM unnest(p_issuers) x WHERE NOT EXISTS (SELECT 1 FROM issuers i WHERE i.id = x AND i.deleted_at IS NULL);
    IF bad > 0 THEN RAISE EXCEPTION 'One of the Analyst''s issuers is not an issuer here' USING ERRCODE = 'check_violation'; END IF;
    IF p_escalate IS NOT NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_escalate AND m.member_kind = 'human' AND m.status = 'active' AND m.capability IS NOT NULL) THEN
        RAISE EXCEPTION 'The escalation member is a person admitted here' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE si_settings SET morning_note_enabled = p_note_enabled, morning_note_hour = p_note_hour, analyst_proposals = p_proposals, pack_day = p_pack_day,
                           analyst_issuer_ids = (SELECT COALESCE(array_agg(DISTINCT x ORDER BY x), '{}') FROM unnest(p_issuers) x), escalate_high_to = p_escalate
     WHERE id = 1;
END$$;

REVOKE ALL ON FUNCTION si_agents_settings_save(boolean, smallint, boolean, smallint, bigint[], bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_agents_settings_save(boolean, smallint, boolean, smallint, bigint[], bigint) TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_analyst_covers(bigint) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;

COMMIT;
