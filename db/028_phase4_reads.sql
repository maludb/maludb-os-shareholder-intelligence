-- 028: what the Phase 4 servers needed that the schema lacked (2026-10-11; docs/shareholder-intelligence-mcp-tool-surface.md). Additive.
--
--  * mcp_key_usage — a key's calls and refusals by day and minute (`key_usage_summary`): the buckets of the keys the caller may read (mcp_keys decides, db/016).
--  * mcp_spr_rows — an SPR upload's rows as read, the ones that failed to type with their reason (`import_rows`): participant names and numbers only (brokers, never a
--    person), behind the upload's own wall (mcp_imports). The records role has no base table, so this is its only door to them.
--  * si_log_mcp_read() — the one write the read servers make: `share.read` (the kernel's token asked a share; source application, no actor, the consumer in `after`)
--    and `records.search` (the guarded long tail; source mcp, the asking member). The records role cannot write the trail (db/002), so a narrow SECURITY DEFINER
--    function does; any other action is refused. The answer to a share is never logged — ids, counts and milliseconds only.
--  * mcp_signal_rule_overrides — an issuer's rule overrides behind the issuer wall (`rules_catalogue` read the base table, which has none).
--  * THE READ ROLE'S DIRECT GRANTS ON signals, signal_outcomes, signal_rule_overrides AND search_index ARE REVOKED (db/013, db/015 gave them): each is a base table with no wall of its own — a connection as the records role
--    could have read a restricted issuer's signals or the search index's person titles with a bare SELECT. Every tool reads mcp_signals / mcp_signal_outcomes / si_search() (SECURITY DEFINER) instead.
--  * si_analyst_proposals() is granted to the activity role (`agent_activity` reads the proposals nobody took); it has its own wall (db/018).
BEGIN;

CREATE OR REPLACE VIEW mcp_key_usage WITH (security_barrier = true) AS
SELECT u.token_id AS key_id, u.bucket_kind, u.bucket_start, u.calls, u.refused
  FROM key_usage u WHERE u.token_id IN (SELECT key_id FROM mcp_keys);

CREATE OR REPLACE VIEW mcp_spr_rows WITH (security_barrier = true) AS
SELECT r.id AS spr_row_id, r.import_id, r.row_no, r.participant_number, r.participant_name, r.position_date, r.shares, r.error
  FROM spr_rows r WHERE r.import_id IN (SELECT import_id FROM mcp_imports);

CREATE OR REPLACE FUNCTION si_log_mcp_read(p_action text, p_after jsonb, p_issuer bigint DEFAULT NULL, p_request_id text DEFAULT NULL, p_run_id bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_action = 'share.read' THEN
        IF app_current_member_id() IS NOT NULL THEN RAISE EXCEPTION 'A share read is the kernel''s: no member logs one' USING ERRCODE = 'insufficient_privilege'; END IF;
        INSERT INTO activity_log (actor_member_id, source, action, entity_type, after, kernel_request_id, issuer_id)
        VALUES (NULL, 'application', 'share.read', 'share', p_after, left(p_request_id, 80), p_issuer);
    ELSIF p_action = 'records.search' THEN
        IF NOT si_is_member_here() THEN RAISE EXCEPTION 'Only a member of the business searches the records' USING ERRCODE = 'insufficient_privilege'; END IF;
        INSERT INTO activity_log (actor_member_id, source, action, entity_type, after, request_id, agent_run_id, issuer_id)
        VALUES (app_current_member_id(), CASE WHEN p_run_id IS NULL THEN 'mcp' ELSE 'agent' END, 'records.search', 'view', p_after, left(p_request_id, 80), p_run_id, p_issuer);
    ELSE
        RAISE EXCEPTION 'Not an action the read servers log: %', p_action USING ERRCODE = 'check_violation';
    END IF;
END$$;
REVOKE ALL ON FUNCTION si_log_mcp_read(text, jsonb, bigint, text, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_log_mcp_read(text, jsonb, bigint, text, bigint) TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;

CREATE OR REPLACE VIEW mcp_signal_rule_overrides WITH (security_barrier = true) AS
SELECT o.rule_id, o.issuer_id, o.params, o.enabled, o.updated_by, o.updated_at FROM signal_rule_overrides o WHERE o.issuer_id IN (SELECT si_my_issuers());

REVOKE SELECT ON signals, signal_outcomes, signal_rule_overrides, search_index FROM shareholder_intelligence_records_ro;

GRANT SELECT ON mcp_key_usage, mcp_spr_rows, mcp_signal_rule_overrides TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_analyst_proposals(bigint, text) TO shareholder_intelligence_activity_ro;

COMMIT;
