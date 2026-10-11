-- 027: slice 10 — admin: sources, credentials, participant classes, templates, settings, tokens, purge (2026-10-11; docs/build-specs/admin-settings.md). Additive.
--
--  * mcp_all_tokens — everyone's MCP tokens AND API keys for the admin's Tokens screen (mcp_keys, db/016, shows keys only): the person, the label, the scope,
--    the limits, today's calls (a key's), the last use. Only a holder of tokens.manage sees a row. Never the hash.
--  * si_token_revoke_any(token) — one token revoked at once, anyone's (the admin's door; a person's own is the Tokens screen's). People only, tokens.manage.
--  * si_tombstones_purge_due(kind, record, limit) — the purge the worker and the admin share: it deletes for good ONLY what waited past
--    tombstone_days (the same cutoff as db/014's si_tombstones_purge), optionally of one kind and one record, together with the attachment rows of the
--    purged records, and answers what it removed — a row (kind, record_id) per record, and a row of kind 'file' for the stored file of an attachment
--    nothing else keeps (the caller unlinks it after the commit). The worker (no member) or a holder of records.purge may call it.
--  * the default price source follows the owner's 2026-10-11 decision (db/025 made Alpha Vantage the default but left the settings column on stooq).
BEGIN;

CREATE OR REPLACE VIEW mcp_all_tokens WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.member_id, m.display_name AS member_name, m.member_kind, t.label, t.scope, t.rate_per_minute, t.rate_per_day, t.rotated_from, t.last_used_at, t.expires_at, t.revoked_at, t.created_at,
       CASE WHEN t.scope = 'api' THEN si_key_calls_today(t.id) END AS calls_today
  FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id WHERE (SELECT si_has_right('tokens.manage'));

CREATE OR REPLACE FUNCTION si_token_revoke_any(p_token bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE;
BEGIN
    IF app_member_kind() <> 'human' OR NOT si_has_right('tokens.manage') THEN
        RAISE EXCEPTION 'Revoking anyone''s token is an administrator''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such token'; END IF;
    IF t.revoked_at IS NOT NULL THEN
        RETURN jsonb_build_object('member_id', t.member_id, 'label', t.label, 'scope', t.scope, 'was_revoked', true);
    END IF;
    UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = p_token;
    RETURN jsonb_build_object('member_id', t.member_id, 'label', t.label, 'scope', t.scope, 'was_revoked', false);
END$$;

CREATE OR REPLACE FUNCTION si_tombstones_purge_due(p_kind text DEFAULT NULL, p_record bigint DEFAULT NULL, p_limit integer DEFAULT 1000)
    RETURNS TABLE (kind text, record_id bigint, storage_path text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
    cutoff timestamptz := now() - make_interval(days => (SELECT tombstone_days FROM si_settings WHERE id = 1));
    lim integer := LEAST(GREATEST(COALESCE(p_limit, 1000), 1), 5000);
    r record; a record;
BEGIN
    IF app_current_member_id() IS NOT NULL AND NOT si_has_right('records.purge') THEN
        RAISE EXCEPTION 'Purging is an administrator''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_kind IS NOT NULL AND p_kind NOT IN ('upload', 'holder', 'event', 'report', 'issuer') THEN
        RAISE EXCEPTION 'Purge an upload, a holder, an event, a report or an issuer' USING ERRCODE = 'check_violation';
    END IF;
    IF p_record IS NOT NULL AND p_kind IS NULL THEN
        RAISE EXCEPTION 'Name the kind of the record' USING ERRCODE = 'check_violation';
    END IF;
    IF p_kind IS NULL OR p_kind = 'upload' THEN
        FOR r IN SELECT id FROM imports WHERE deleted_at < cutoff AND (p_record IS NULL OR id = p_record) ORDER BY id LIMIT lim LOOP
            FOR a IN DELETE FROM attachments WHERE record_type = 'import' AND attachments.record_id = r.id RETURNING attachments.storage_path AS sp LOOP
                IF NOT EXISTS (SELECT 1 FROM attachments x WHERE x.storage_path = a.sp) THEN kind := 'file'; record_id := r.id; storage_path := a.sp; RETURN NEXT; END IF;
            END LOOP;
            DELETE FROM imports WHERE id = r.id;
            kind := 'upload'; record_id := r.id; storage_path := NULL; RETURN NEXT;
        END LOOP;
    END IF;
    IF p_kind IS NULL OR p_kind = 'holder' THEN
        FOR r IN SELECT id FROM holders WHERE deleted_at < cutoff AND merged_into_id IS NULL AND (p_record IS NULL OR id = p_record) ORDER BY id LIMIT lim LOOP
            FOR a IN DELETE FROM attachments WHERE record_type = 'holder' AND attachments.record_id = r.id RETURNING attachments.storage_path AS sp LOOP
                IF NOT EXISTS (SELECT 1 FROM attachments x WHERE x.storage_path = a.sp) THEN kind := 'file'; record_id := r.id; storage_path := a.sp; RETURN NEXT; END IF;
            END LOOP;
            DELETE FROM holders WHERE id = r.id;
            kind := 'holder'; record_id := r.id; storage_path := NULL; RETURN NEXT;
        END LOOP;
    END IF;
    IF p_kind IS NULL OR p_kind = 'event' THEN
        FOR r IN SELECT id FROM events WHERE deleted_at < cutoff AND (p_record IS NULL OR id = p_record) ORDER BY id LIMIT lim LOOP
            FOR a IN DELETE FROM attachments WHERE record_type = 'event' AND attachments.record_id = r.id RETURNING attachments.storage_path AS sp LOOP
                IF NOT EXISTS (SELECT 1 FROM attachments x WHERE x.storage_path = a.sp) THEN kind := 'file'; record_id := r.id; storage_path := a.sp; RETURN NEXT; END IF;
            END LOOP;
            DELETE FROM events WHERE id = r.id;
            kind := 'event'; record_id := r.id; storage_path := NULL; RETURN NEXT;
        END LOOP;
    END IF;
    IF p_kind IS NULL OR p_kind = 'report' THEN
        FOR r IN SELECT id FROM reports WHERE deleted_at < cutoff AND (p_record IS NULL OR id = p_record) ORDER BY id LIMIT lim LOOP
            FOR a IN DELETE FROM attachments WHERE record_type = 'report' AND attachments.record_id = r.id RETURNING attachments.storage_path AS sp LOOP
                IF NOT EXISTS (SELECT 1 FROM attachments x WHERE x.storage_path = a.sp) THEN kind := 'file'; record_id := r.id; storage_path := a.sp; RETURN NEXT; END IF;
            END LOOP;
            DELETE FROM reports WHERE id = r.id;
            kind := 'report'; record_id := r.id; storage_path := NULL; RETURN NEXT;
        END LOOP;
    END IF;
    IF p_kind IS NULL OR p_kind = 'issuer' THEN
        FOR r IN SELECT id FROM issuers WHERE deleted_at < cutoff AND (p_record IS NULL OR id = p_record) ORDER BY id LIMIT lim LOOP
            FOR a IN DELETE FROM attachments WHERE record_type = 'issuer' AND attachments.record_id = r.id RETURNING attachments.storage_path AS sp LOOP
                IF NOT EXISTS (SELECT 1 FROM attachments x WHERE x.storage_path = a.sp) THEN kind := 'file'; record_id := r.id; storage_path := a.sp; RETURN NEXT; END IF;
            END LOOP;
            DELETE FROM issuers WHERE id = r.id;
            kind := 'issuer'; record_id := r.id; storage_path := NULL; RETURN NEXT;
        END LOOP;
    END IF;
END$$;

ALTER TABLE si_settings ALTER COLUMN price_source_default SET DEFAULT 'prices_alphavantage';
UPDATE si_settings SET price_source_default = 'prices_alphavantage' WHERE price_source_default = 'prices_stooq';

REVOKE ALL ON FUNCTION si_token_revoke_any(bigint), si_tombstones_purge_due(text, bigint, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_token_revoke_any(bigint), si_tombstones_purge_due(text, bigint, integer) TO shareholder_intelligence_rw;
GRANT SELECT ON mcp_all_tokens TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;

COMMIT;
