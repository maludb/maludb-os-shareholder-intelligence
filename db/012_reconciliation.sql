-- 012: THE SHARE RECONCILIATION (design §0.3, §6.4; D12) — the one fact that keeps the rest honest:
--   outstanding = Cede & Co. (the register's one line) + DRS/book + certificated + plan + other registered
--   Cede & Co. = the SPR's total across participants on the same date
-- A dated row per run with the components, the two differences and `tied`; an untied run is a DATA DEFECT before it is anything
-- else (the rule spr_untied / register_untied of db/013 reads this table), and the morning note says so.
--
-- THE ESTATE'S DATA MODEL (design §6.8): share_reconciliations takes the shape of the GL's bank_reconciliations (components, a
-- difference, tied) applied to shares — new.
BEGIN;

CREATE TABLE share_reconciliations (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    security_id             bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    as_of                   date NOT NULL,
    outstanding             numeric(20,0),
    outstanding_share_count_id bigint REFERENCES share_counts(id) ON DELETE SET NULL,
    cede_registered         numeric(20,0),                                         -- the register's Cede line, or the DTC letter, or a typed cede count
    cede_source             text,
    register_date           date,                                                  -- the register the components came from
    spr_total               numeric(20,0),
    spr_date                date,                                                  -- the nearest position date at or before as_of
    drs_book                numeric(20,0),
    certificated            numeric(20,0),
    plan                    numeric(20,0),
    registered_other        numeric(20,0),
    difference_registered   numeric(20,0),                                         -- outstanding − (cede + drs + cert + plan + other)
    difference_spr          numeric(20,0),                                         -- cede − spr_total
    tolerance               bigint NOT NULL DEFAULT 0,
    tied_registered         boolean,
    tied_spr                boolean,
    tied                    boolean GENERATED ALWAYS AS (COALESCE(tied_registered, true) AND COALESCE(tied_spr, true) AND (tied_registered IS NOT NULL OR tied_spr IS NOT NULL)) STORED,
    nobo_shares             numeric(20,0),
    nobo_date               date,
    obo_remainder           numeric(20,0),
    note                    text,
    ran_by                  bigint REFERENCES members(id) ON DELETE SET NULL,
    ran_at                  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX share_reconciliations_idx ON share_reconciliations (security_id, as_of DESC, ran_at DESC);

-- One run for a security as of a date (the worker after every upload and share count; a person's button; the proof).
CREATE OR REPLACE FUNCTION si_reconcile(p_security bigint, p_as_of date DEFAULT CURRENT_DATE, p_note text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE tol bigint := (SELECT reconciliation_tolerance_shares FROM si_settings WHERE id = 1);
        o numeric; oid bigint; reg_date date; cede numeric; cede_src text; drs numeric; cert numeric; pl numeric; oth numeric; spr numeric; spr_d date;
        d_reg numeric; d_spr numeric; t_reg boolean; t_spr boolean; nobo numeric; nobo_d date; rid bigint;
BEGIN
    SELECT value, id INTO o, oid FROM share_counts WHERE security_id = p_security AND kind = 'outstanding' AND as_of <= p_as_of
     ORDER BY as_of DESC, CASE source WHEN 'filing' THEN 1 WHEN 'transfer_agent' THEN 2 ELSE 3 END, recorded_at DESC LIMIT 1;
    -- the register's components: the latest usable register at or before as_of
    SELECT r.record_date INTO reg_date FROM register_rows r JOIN imports i ON i.id = r.import_id
     WHERE r.security_id = p_security AND r.record_date <= p_as_of AND i.deleted_at IS NULL AND i.status = 'done' AND (i.tied OR i.override_by IS NOT NULL)
     ORDER BY r.record_date DESC LIMIT 1;
    IF reg_date IS NOT NULL THEN
        SELECT sum(shares) FILTER (WHERE is_cede), sum(shares) FILTER (WHERE NOT is_cede AND upper(COALESCE(position_type, '')) ~ 'DRS|BOOK'),
               sum(shares) FILTER (WHERE NOT is_cede AND upper(COALESCE(position_type, '')) ~ 'CERT'),
               sum(shares) FILTER (WHERE NOT is_cede AND upper(COALESCE(position_type, '')) ~ 'PLAN|DRIP|DSPP'),
               sum(shares) FILTER (WHERE NOT is_cede AND NOT (upper(COALESCE(position_type, '')) ~ 'DRS|BOOK|CERT|PLAN|DRIP|DSPP'))
          INTO cede, drs, cert, pl, oth
          FROM register_rows r JOIN imports i ON i.id = r.import_id
         WHERE r.security_id = p_security AND r.record_date = reg_date AND i.deleted_at IS NULL AND r.shares IS NOT NULL;
        cede_src := 'register';
    END IF;
    IF cede IS NULL THEN
        SELECT value INTO cede FROM share_counts WHERE security_id = p_security AND kind = 'cede' AND source IN ('dtc_letter', 'transfer_agent', 'manual') AND as_of <= p_as_of ORDER BY as_of DESC, recorded_at DESC LIMIT 1;
        IF cede IS NOT NULL THEN cede_src := 'share_count'; END IF;
    END IF;
    SELECT max(position_date) INTO spr_d FROM participant_positions WHERE security_id = p_security AND position_date <= p_as_of;
    IF spr_d IS NOT NULL THEN spr := si_spr_total(p_security, spr_d); END IF;
    SELECT j.record_date, j.shares_total INTO nobo_d, nobo FROM nobo_jobs j JOIN imports i ON i.id = j.import_id WHERE j.security_id = p_security AND j.record_date <= p_as_of AND i.deleted_at IS NULL ORDER BY j.record_date DESC LIMIT 1;
    IF o IS NOT NULL AND reg_date IS NOT NULL THEN
        d_reg := o - (COALESCE(cede, 0) + COALESCE(drs, 0) + COALESCE(cert, 0) + COALESCE(pl, 0) + COALESCE(oth, 0)); t_reg := abs(d_reg) <= tol;
    END IF;
    IF cede IS NOT NULL AND spr IS NOT NULL THEN d_spr := cede - spr; t_spr := abs(d_spr) <= tol; END IF;
    INSERT INTO share_reconciliations (security_id, as_of, outstanding, outstanding_share_count_id, cede_registered, cede_source, register_date, spr_total, spr_date, drs_book, certificated, plan, registered_other,
                                       difference_registered, difference_spr, tolerance, tied_registered, tied_spr, nobo_shares, nobo_date, obo_remainder, note, ran_by)
    VALUES (p_security, p_as_of, o, oid, cede, cede_src, reg_date, spr, spr_d, drs, cert, pl, oth, d_reg, d_spr, tol, t_reg, t_spr, nobo, nobo_d, CASE WHEN spr IS NOT NULL AND nobo IS NOT NULL THEN spr - nobo END, p_note, app_current_member_id())
    RETURNING id INTO rid;
    RETURN rid;
END$$;

CREATE OR REPLACE FUNCTION si_reconciliation_latest(p_security bigint) RETURNS share_reconciliations
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT * FROM share_reconciliations WHERE security_id = p_security ORDER BY as_of DESC, ran_at DESC LIMIT 1;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON share_reconciliations TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_reconciliation_latest(bigint) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_reconcile(bigint, date, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_reconcile(bigint, date, text) TO shareholder_intelligence_rw;

COMMIT;
