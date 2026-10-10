-- 021: slice 3 — NOBO lists, the register, holders (docs/build-specs/holders.md "Built").
--
-- 1. si_match_row keeps a custodian the row already carries: Broadridge's names export names the custodian in a column of its own (the
--    loader matches it to a participant), not in a legend line — the lines' parse no longer blanks it.
-- 2. si_holder_positions_rebuild(import): an upload's holder positions from its rows, an account's rows SUMMED (several certificates under
--    one registration) — the restore of a deleted upload used one row per account and dropped the rest (ON CONFLICT DO NOTHING).
-- 3. si_import_delete's restore calls it.
-- 4. si_match_candidates finds its candidates through the trigram index (the exact name, then name_key % the name) and scores each once:
--    it compared every holder of the issuer for every row — 449,605 similarity calls for one list of 670 rows, growing with the square of
--    the holders. The scores and the evidence are the db/008 rules unchanged. The function sets enable_seqscan off: with a few hundred
--    holders the planner prices a sequential scan below the index, not counting what a similarity costs.
-- 5. si_row_attach touches the holder's first/last seen only when they move, and the search index is rebuilt on a holder's update only when
--    a column it reads changes (the trigger names its columns; the worker's catch-up pass still reconciles by updated_at).
-- 6. THE PII WALL, closer (found on the owner's real lists): an entity's address may name a person ("ATTN: …", "C/O …", "FBO …") and an
--    entity's registration lines may carry "FBO <a person>" — a Viewer now reads an entity's address only without such a line and no
--    account's registration lines at all; the entity's name, kind, city and state stay visible. Same columns, same order (a view replaced).
-- 7. Indexes on the rows' account (si_row_attach sums an account's rows on a date): without them every match scanned every NOBO row ever
--    loaded — 7 ms a row, growing with each list (found on the owner's 55 real lists).
BEGIN;

CREATE INDEX IF NOT EXISTS nobo_rows_account_idx ON nobo_rows (holder_account_id) WHERE holder_account_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS register_rows_account_idx ON register_rows (holder_account_id) WHERE holder_account_id IS NOT NULL;

CREATE OR REPLACE FUNCTION si_match_row(p_row_kind text, p_row_id bigint, p_force_new boolean DEFAULT false) RETURNS text
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
               custodian_participant_id = COALESCE(si_custodian_from_lines(COALESCE(p.legend_lines, '') || ' ' || COALESCE(p.name_line, '')), custodian_participant_id) WHERE id = p_row_id;
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
    FOR c IN SELECT * FROM si_match_candidates(iss, nk, ak, zp, kind) WHERE NOT p_force_new LOOP
        IF EXISTS (SELECT 1 FROM holder_match_proposals WHERE row_kind = p_row_kind AND row_id = p_row_id AND holder_id = c.holder_id AND status = 'rejected') THEN CONTINUE; END IF;
        IF c.score >= thr AND n_prop = 0 THEN
            INSERT INTO holder_match_proposals (row_kind, row_id, issuer_id, holder_id, score, evidence, status, decided_at) VALUES (p_row_kind, p_row_id, iss, c.holder_id, c.score, c.evidence, 'auto', now())
            ON CONFLICT (row_kind, row_id, holder_id) DO UPDATE SET status = 'auto', score = EXCLUDED.score, evidence = EXCLUDED.evidence, decided_at = now();
            PERFORM si_row_attach(p_row_kind, p_row_id, c.holder_id);
            UPDATE holders h SET address_key = COALESCE(h.address_key, ak), address_line = COALESCE(h.address_line, street), city = COALESCE(h.city, cty), zip = COALESCE(h.zip, zp)
             WHERE h.id = c.holder_id AND (h.address_key IS NULL OR h.address_line IS NULL OR h.city IS NULL OR h.zip IS NULL);
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
    IF p_row_kind = 'nobo' THEN UPDATE nobo_rows SET holder_id = p_holder, holder_account_id = acc WHERE id = p_row_id;
    ELSE UPDATE register_rows SET holder_id = p_holder, holder_account_id = acc WHERE id = p_row_id; END IF;
    -- the account's position on the date is the SUM of its rows on that list (several certificates, one registration)
    IF p_row_kind = 'nobo' THEN
        SELECT sum(n.shares) INTO sh FROM nobo_rows n JOIN nobo_jobs j ON j.id = n.job_id WHERE n.holder_account_id = acc AND j.security_id = sec AND j.record_date = rd AND n.shares IS NOT NULL;
    ELSE
        SELECT sum(g.shares) INTO sh FROM register_rows g WHERE g.holder_account_id = acc AND g.security_id = sec AND g.record_date = rd AND g.shares IS NOT NULL;
    END IF;
    IF sh IS NOT NULL THEN
        INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id)
        VALUES (p_holder, acc, sec, rd, sh, p_row_kind, imp)
        ON CONFLICT (holder_account_id, security_id, record_date, channel) DO UPDATE SET shares = EXCLUDED.shares, import_id = EXCLUDED.import_id, holder_id = EXCLUDED.holder_id;
    END IF;
    UPDATE holders SET first_seen_on = LEAST(COALESCE(first_seen_on, rd), rd), last_seen_on = GREATEST(COALESCE(last_seen_on, rd), rd)
     WHERE id = p_holder AND (first_seen_on IS NULL OR last_seen_on IS NULL OR rd < first_seen_on OR rd > last_seen_on);
    RETURN acc;
END$$;

CREATE OR REPLACE FUNCTION si_holder_positions_rebuild(p_import bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; n integer;
BEGIN
    SELECT kind INTO k FROM imports WHERE id = p_import;
    DELETE FROM holder_positions WHERE import_id = p_import;
    IF k = 'nobo' THEN
        INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id)
        SELECT a.holder_id, n2.holder_account_id, j.security_id, j.record_date, sum(n2.shares), 'nobo', p_import
          FROM nobo_rows n2 JOIN nobo_jobs j ON j.id = n2.job_id JOIN holder_accounts a ON a.id = n2.holder_account_id
         WHERE n2.import_id = p_import AND n2.shares IS NOT NULL GROUP BY a.holder_id, n2.holder_account_id, j.security_id, j.record_date
        ON CONFLICT (holder_account_id, security_id, record_date, channel) DO UPDATE SET shares = EXCLUDED.shares, import_id = EXCLUDED.import_id, holder_id = EXCLUDED.holder_id;
    ELSIF k = 'register' THEN
        INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id)
        SELECT a.holder_id, r.holder_account_id, r.security_id, r.record_date, sum(r.shares), 'register', p_import
          FROM register_rows r JOIN holder_accounts a ON a.id = r.holder_account_id
         WHERE r.import_id = p_import AND r.shares IS NOT NULL GROUP BY a.holder_id, r.holder_account_id, r.security_id, r.record_date
        ON CONFLICT (holder_account_id, security_id, record_date, channel) DO UPDATE SET shares = EXCLUDED.shares, import_id = EXCLUDED.import_id, holder_id = EXCLUDED.holder_id;
    END IF;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION si_import_delete(p_import bigint, p_restore boolean DEFAULT false) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE im imports%ROWTYPE;
BEGIN
    SELECT * INTO im FROM imports WHERE id = p_import FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such upload'; END IF;
    IF p_restore THEN
        UPDATE imports SET deleted_at = NULL, deleted_by = NULL WHERE id = p_import;
        IF im.kind = 'spr' THEN PERFORM si_spr_load(p_import); END IF;     -- the positions come back from the rows
        IF im.kind IN ('nobo', 'register') THEN PERFORM si_holder_positions_rebuild(p_import); END IF;
    ELSE
        UPDATE imports SET deleted_at = now(), deleted_by = app_current_member_id() WHERE id = p_import AND deleted_at IS NULL;
        -- the derived rows go now (the raw rows stay with the tombstone for the restore); a share count the file wrote goes too
        DELETE FROM participant_positions WHERE import_id = p_import;
        DELETE FROM holder_positions WHERE import_id = p_import;
        DELETE FROM share_counts WHERE import_id = p_import;
    END IF;
END$$;

CREATE OR REPLACE FUNCTION si_match_candidates(p_issuer bigint, p_name_key text, p_address_key text, p_zip text, p_kind text)
    RETURNS TABLE (holder_id bigint, score numeric, evidence jsonb)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public SET enable_seqscan = off AS $$
    WITH c AS (SELECT h.id FROM holders h WHERE h.issuer_id = p_issuer AND h.name_key = p_name_key
               UNION
               SELECT h.id FROM holders h WHERE h.issuer_id = p_issuer AND h.name_key % p_name_key),
         s AS (SELECT h.id, h.name_key, h.address_key, h.zip, h.last_seen_on, h.name_key = p_name_key AS exact, similarity(h.name_key, p_name_key) AS sim
                 FROM holders h JOIN c ON c.id = h.id
                WHERE h.deleted_at IS NULL AND h.merged_into_id IS NULL
                  AND (p_kind <> 'person' OR h.kind <> 'entity') AND (p_kind <> 'entity' OR h.kind <> 'person')),
         r AS (SELECT s.*, CASE
                 WHEN s.exact AND p_address_key IS NOT NULL AND s.address_key = p_address_key THEN 1.000
                 WHEN s.exact AND (p_address_key IS NULL OR s.address_key IS NULL) THEN 0.900
                 WHEN s.sim >= 0.60 AND p_address_key IS NOT NULL AND s.address_key = p_address_key THEN 0.950
                 WHEN s.sim >= 0.70 AND p_zip IS NOT NULL AND s.zip = p_zip THEN 0.800
                 WHEN s.exact THEN 0.600
                 ELSE 0 END AS sc FROM s)
    SELECT r.id, r.sc,
           jsonb_build_object('name', CASE WHEN r.exact THEN 'exact' ELSE round(r.sim::numeric, 2)::text END,
                              'address', CASE WHEN p_address_key IS NOT NULL AND r.address_key = p_address_key THEN 'exact' WHEN p_zip IS NOT NULL AND r.zip = p_zip THEN 'zip' ELSE 'different' END)
      FROM r WHERE r.sc > 0 ORDER BY r.sc DESC, r.last_seen_on DESC NULLS LAST LIMIT 5;
$$;

CREATE OR REPLACE VIEW mcp_holders WITH (security_barrier = true) AS
SELECT h.id AS holder_id, h.issuer_id,
       CASE WHEN h.kind = 'person' AND NOT (SELECT si_sees_holder_pii()) THEN 'Individual holder' ELSE h.display_name END AS display_name,
       h.kind, h.entity_type,
       CASE WHEN (SELECT si_sees_holder_pii()) THEN h.address_line
            WHEN h.kind <> 'person' AND h.address_line !~* '\m(ATTN|ATTENTION|C/O|CARE OF|FBO|F/B/O)\M' THEN h.address_line END AS address_line,
       CASE WHEN h.kind <> 'person' OR (SELECT si_sees_holder_pii()) THEN h.city END AS city,
       h.state, h.country, CASE WHEN h.kind <> 'person' OR (SELECT si_sees_holder_pii()) THEN h.zip ELSE left(h.zip, 3) END AS zip,
       h.is_insider, h.insider_cik, h.institution_id, h.first_seen_on, h.last_seen_on,
       (SELECT count(*) FROM holder_accounts a WHERE a.holder_id = h.id) AS accounts_count,
       (SELECT sum(hp.shares) FROM holder_positions hp WHERE hp.holder_id = h.id AND hp.record_date = h.last_seen_on) AS last_shares,
       CASE WHEN h.kind <> 'person' OR (SELECT si_sees_holder_pii()) THEN h.notes END AS notes, si_tags_of('holder', h.id) AS tags,
       (h.kind = 'person' AND NOT (SELECT si_sees_holder_pii())) AS pii_withheld, h.created_at, h.updated_at
  FROM holders h WHERE h.deleted_at IS NULL AND h.merged_into_id IS NULL AND h.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_holder_accounts WITH (security_barrier = true) AS
SELECT a.id AS holder_account_id, a.holder_id, a.channel, a.custodian_participant_id, (SELECT name FROM participants WHERE id = a.custodian_participant_id) AS custodian_name, a.account_kind,
       CASE WHEN (SELECT si_sees_holder_pii()) THEN a.registration_text END AS registration_text, a.holder_ref, a.first_seen_on, a.last_seen_on
  FROM holder_accounts a WHERE a.holder_id IN (SELECT holder_id FROM mcp_holders);

DROP TRIGGER IF EXISTS holders_index ON holders;
CREATE TRIGGER holders_index AFTER INSERT OR DELETE OR UPDATE OF display_name, city, state, zip, kind, entity_type, notes, issuer_id, deleted_at, merged_into_id ON holders
    FOR EACH ROW EXECUTE FUNCTION si_index_trigger('holder');

REVOKE ALL ON FUNCTION si_holder_positions_rebuild(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_holder_positions_rebuild(bigint) TO shareholder_intelligence_rw;

COMMIT;
