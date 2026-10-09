-- 020: what slice 1 needs that the schema lacked (docs/build-specs/issuers-participants.md) — additive.
--   si_record_issuer(kind, id): the issuer a record belongs to (NULL for the estate-wide kinds: a participant, an institution).
--   si_can_see_record(kind, id): the ISSUER WALL for any record kind, and the PII WALL for a natural-person holder — the one gate notes
--     and tags use, in SQL and in PHP.
--   mcp_notes re-created behind both walls (db/016 walled only a person-holder's notes: a note on a restricted issuer's record was
--     readable by anyone admitted — found by slice 1).
--   si_tag_ensure(label): the tag of a label, made when new (slug from the label, a numbered slug when taken by another label).
BEGIN;

CREATE OR REPLACE FUNCTION si_record_issuer(p_kind text, p_id bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE p_kind
        WHEN 'issuer' THEN p_id
        WHEN 'security' THEN (SELECT issuer_id FROM securities WHERE id = p_id)
        WHEN 'holder' THEN (SELECT issuer_id FROM holders WHERE id = p_id)
        WHEN 'event' THEN (SELECT issuer_id FROM events WHERE id = p_id)
        WHEN 'signal' THEN (SELECT issuer_id FROM signals WHERE id = p_id)
        WHEN 'report' THEN (SELECT issuer_id FROM reports WHERE id = p_id)
        WHEN 'dilution_instrument' THEN (SELECT issuer_id FROM dilution_instruments WHERE id = p_id)
        WHEN 'filing' THEN (SELECT issuer_id FROM filings WHERE id = p_id)
        WHEN 'import' THEN (SELECT issuer_id FROM imports WHERE id = p_id)
        ELSE NULL END;
$$;

CREATE OR REPLACE FUNCTION si_can_see_record(p_kind text, p_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE iss bigint;
BEGIN
    IF NOT si_is_member_here() THEN RETURN false; END IF;
    CASE p_kind
        WHEN 'participant' THEN RETURN EXISTS (SELECT 1 FROM participants WHERE id = p_id);
        WHEN 'institution' THEN RETURN EXISTS (SELECT 1 FROM institutions WHERE id = p_id);
        WHEN 'issuer', 'security', 'holder', 'event', 'signal', 'report', 'dilution_instrument', 'filing', 'import' THEN NULL;
        ELSE RETURN false;
    END CASE;
    iss := si_record_issuer(p_kind, p_id);
    IF iss IS NULL OR NOT si_can_see_issuer(iss) THEN RETURN false; END IF;
    IF p_kind = 'holder' AND (SELECT kind FROM holders WHERE id = p_id) = 'person' AND NOT si_sees_holder_pii() THEN RETURN false; END IF;
    RETURN true;
END$$;

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, n.body, n.created_at FROM notes n
 WHERE (SELECT si_is_member_here()) AND si_can_see_record(n.record_type, n.record_id);

CREATE OR REPLACE FUNCTION si_tag_ensure(p_label text, p_by bigint DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lbl text := btrim(regexp_replace(COALESCE(p_label, ''), '\s+', ' ', 'g')); base text; s text; n integer := 1; tid bigint;
BEGIN
    IF lbl = '' OR length(lbl) > 60 THEN RAISE EXCEPTION 'A tag is 1 to 60 characters' USING ERRCODE = 'check_violation'; END IF;
    SELECT id INTO tid FROM tags WHERE lower(label) = lower(lbl) LIMIT 1;
    IF tid IS NOT NULL THEN RETURN tid; END IF;
    base := left(btrim(regexp_replace(lower(unaccent(lbl)), '[^a-z0-9]+', '-', 'g'), '-'), 55);
    IF base = '' THEN base := 'tag'; END IF;
    s := base;
    WHILE EXISTS (SELECT 1 FROM tags WHERE slug = s) LOOP n := n + 1; s := base || '-' || n; END LOOP;
    INSERT INTO tags (slug, label, created_by) VALUES (s, lbl, p_by) RETURNING id INTO tid;
    RETURN tid;
END$$;

GRANT EXECUTE ON FUNCTION si_record_issuer(text, bigint), si_can_see_record(text, bigint) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
GRANT SELECT ON mcp_notes TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
REVOKE ALL ON FUNCTION si_tag_ensure(text, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_tag_ensure(text, bigint) TO shareholder_intelligence_rw;
COMMIT;
