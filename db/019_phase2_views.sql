-- 019: what Phase 2's shell reads that the views lacked (docs/build-specs/sso-shell.md) — additive: a view re-created with a column
-- appended last, its grants unchanged.
--   mcp_activity_log gains actor_is_agent (the trail says "(agent)" beside an agent's name; the activity server answers the same).
BEGIN;
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, (SELECT display_name FROM members WHERE id = a.actor_member_id) AS actor_name, a.source, a.action, a.screen, a.route, a.entity_type, a.entity_id,
       a.before, a.after, a.request_id, a.agent_run_id, a.department_id, a.issuer_id, a.security_id, a.import_id, a.signal_id, a.token_id,
       COALESCE((SELECT m.member_kind = 'agent' FROM members m WHERE m.id = a.actor_member_id), false) AS actor_is_agent
  FROM activity_log a WHERE (SELECT si_is_member_here()) AND (a.issuer_id IS NULL OR a.issuer_id IN (SELECT si_my_issuers()));
COMMIT;
