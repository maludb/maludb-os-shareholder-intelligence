-- 007: UPLOADS — the three issuer files kept whole and cut into proven rows — and the DTC POSITIONS (design §6.2; D6, D7).
--
-- An upload is an `imports` row (Spaces' table, reused; the upload columns appended): the file itself is an attachments row (db/014)
-- kept whole; the parser (PHP, app/si/parse/<connector>.php — slices 2 and 3) writes the RAW ROWS with the cell text as read and the
-- typed columns beside, in ONE TRANSACTION, then si_import_tie() compares what the file said of itself (control_totals) with what the
-- rows sum to (derived_totals). Nothing downstream is computed from an upload that is not tied unless a manager overrides with a note.
--
-- The SPR's grain is one row per participant per POSITION DATE: the weekly grid (five dates in the header, five positions per
-- participant row — the reference implementation's d1…d5 / monday…friday) is UNPIVOTED on load. A participant absent on a date is
-- ABSENT, not zero; a participant at zero in the file is zero. The series read the positions; nothing is stored per window.
--
-- THE ESTATE'S DATA MODEL (design §6.8): `imports` is Spaces db/016 verbatim with this application's columns appended and `kind`
-- widened (the Spaces target columns kept nullable for the verbatim rule); spr_rows / nobo_* / register_rows take the shape of
-- Knowledge's sections (row number, the text as read, typed beside) applied to tabular files; participant_positions is NEW CANONICAL.
BEGIN;

-- reuse: Spaces db/016 verbatim; kind widened; the Spaces targets kept (nullable, unused); the upload columns appended
CREATE TABLE imports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('markdown_zip', 'notion_zip', 'csv', 'markdown', 'spr', 'nobo', 'register', 'prices_csv', 'holders_csv', 'voted_unvoted')),
    file_name     text NOT NULL,
    target_space_id bigint,
    target_page_id  uuid,
    target_database_id uuid,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    pages_made    integer NOT NULL DEFAULT 0,
    rows_made     integer NOT NULL DEFAULT 0,
    blocks_made   integer NOT NULL DEFAULT 0,
    unsupported   integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    -- appended (design §6.2): the upload
    issuer_id        bigint REFERENCES issuers(id) ON DELETE CASCADE,
    security_id      bigint REFERENCES securities(id) ON DELETE CASCADE,
    connector        text CHECK (connector IS NULL OR connector IN ('dtc_spr_xlsx', 'dtc_spr_ccf', 'broadridge_nobo_xlsx', 'broadridge_nobo_csv', 'stc_position_detail', 'computershare_holder_list', 'generic_mapped', 'prices_csv')),
    mapping          jsonb,                                                        -- a generic upload's column mapping (from import_templates)
    record_date      date,                                                         -- a NOBO job's or a register's record date
    period_from      date,                                                         -- an SPR file's first position date
    period_to        date,
    attachment_id    bigint,                                                       -- the file, kept whole (FK in db/014)
    control_totals   jsonb NOT NULL DEFAULT '{}'::jsonb,                           -- what the file says of itself: {"rows":855,"shares":12345678,"participants":41}
    derived_totals   jsonb NOT NULL DEFAULT '{}'::jsonb,                           -- what the rows sum to
    tied             boolean,                                                      -- NULL = not compared yet
    tie_note         text,                                                         -- the difference, named
    override_by      bigint REFERENCES members(id) ON DELETE SET NULL,             -- a manager's override of an untied upload
    override_note    text,
    override_at      timestamptz,
    rows_failed      integer NOT NULL DEFAULT 0,                                   -- rows whose cells failed to type (kept, flagged)
    superseded_by    bigint REFERENCES imports(id) ON DELETE SET NULL,
    deleted_at       timestamptz,
    deleted_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (kind NOT IN ('spr', 'nobo', 'register', 'prices_csv', 'holders_csv', 'voted_unvoted') OR security_id IS NOT NULL),
    CHECK ((override_by IS NULL) = (override_at IS NULL))
);
CREATE INDEX imports_security_idx ON imports (security_id, kind, created_at DESC);
CREATE INDEX imports_issuer_idx ON imports (issuer_id, created_at DESC);
CREATE INDEX imports_queue_idx ON imports (status, created_at) WHERE status = 'queued';
ALTER TABLE share_counts ADD CONSTRAINT share_counts_import_fk FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE SET NULL;

-- An upload whose rows may be used: tied, or overridden by a manager with a note.
CREATE OR REPLACE FUNCTION si_import_usable(p_import bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM imports WHERE id = p_import AND deleted_at IS NULL AND status = 'done' AND (tied OR override_by IS NOT NULL));
$$;

-- ---------------------------------------------------------------------------------------------
-- THE SPR
-- ---------------------------------------------------------------------------------------------
CREATE TABLE spr_rows (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id            bigint NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    row_no               integer NOT NULL CHECK (row_no >= 1),                     -- the file's row
    participant_number   text,                                                     -- typed: four digits, or NULL when the cell failed
    participant_name     text,                                                     -- as read
    position_date        date,
    shares               numeric(20,0) CHECK (shares IS NULL OR shares >= 0),
    raw                  jsonb NOT NULL DEFAULT '{}'::jsonb,                       -- the cells as read: {"number":"0164","name":"CHARLES SCHWAB & CO INC","date":"10/06/2026","shares":"1,234,567"}
    error                text,                                                     -- why the row failed to type
    UNIQUE (import_id, row_no, position_date)
);
CREATE INDEX spr_rows_import_idx ON spr_rows (import_id);

-- new canonical: one row per participant per date
CREATE TABLE participant_positions (
    security_id      bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    participant_id   bigint NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
    position_date    date NOT NULL,
    shares           numeric(20,0) NOT NULL CHECK (shares >= 0),
    import_id        bigint NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    PRIMARY KEY (security_id, participant_id, position_date)
);
CREATE INDEX participant_positions_date_idx ON participant_positions (security_id, position_date);
CREATE INDEX participant_positions_participant_idx ON participant_positions (participant_id, position_date);

-- The SPR load (one transaction with the parse, slice 2; the proof): typed rows → positions (an unknown number is a new participant
-- named as the file names it, classed from the name); the control totals compared; the import marked done and tied or not.
-- A second file for the same dates REPLACES those dates' positions (a corrected SPR) and supersedes the earlier import for them.
CREATE OR REPLACE FUNCTION si_spr_load(p_import bigint) RETURNS TABLE (positions integer, participants_new integer, rows_failed integer, tied boolean, tie_note text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE im imports%ROWTYPE; r record; pid bigint; n_pos integer := 0; n_new integer := 0; n_fail integer; ctrl jsonb; der jsonb; ok boolean := true; note text := '';
        d_from date; d_to date; tot numeric; cnt integer;
BEGIN
    SELECT * INTO im FROM imports WHERE id = p_import AND kind = 'spr' FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'si_spr_load: import % is not an SPR upload', p_import; END IF;
    SELECT count(*) INTO n_fail FROM spr_rows WHERE import_id = p_import AND (participant_number IS NULL OR position_date IS NULL OR shares IS NULL);
    SELECT min(position_date), max(position_date) INTO d_from, d_to FROM spr_rows WHERE import_id = p_import AND position_date IS NOT NULL;
    FOR r IN SELECT participant_number, max(participant_name) AS pname FROM spr_rows WHERE import_id = p_import AND participant_number IS NOT NULL GROUP BY participant_number LOOP
        SELECT id INTO pid FROM participants WHERE number = r.participant_number;
        IF pid IS NULL THEN
            INSERT INTO participants (number, name, class_key, class_source, directory_kind)
            VALUES (r.participant_number, upper(btrim(COALESCE(r.pname, 'PARTICIPANT ' || r.participant_number))), si_participant_class_from_name(r.pname), 'directory_name', 'participant')
            RETURNING id INTO pid;
            n_new := n_new + 1;
        ELSIF r.pname IS NOT NULL AND upper(btrim(r.pname)) <> (SELECT name FROM participants WHERE id = pid) AND NOT (upper(btrim(r.pname)) = ANY ((SELECT aliases FROM participants WHERE id = pid)::text[])) THEN
            UPDATE participants SET aliases = aliases || ARRAY[upper(btrim(r.pname))] WHERE id = pid;   -- the file's name, kept as an alias
        END IF;
    END LOOP;
    -- the earlier positions of these dates go (a corrected file); their import is superseded for them
    UPDATE imports SET superseded_by = p_import WHERE id IN (SELECT DISTINCT import_id FROM participant_positions WHERE security_id = im.security_id AND position_date BETWEEN d_from AND d_to AND import_id <> p_import) AND superseded_by IS NULL;
    DELETE FROM participant_positions WHERE security_id = im.security_id AND position_date BETWEEN d_from AND d_to;
    INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id)
    SELECT im.security_id, p.id, s.position_date, sum(s.shares), p_import
      FROM spr_rows s JOIN participants p ON p.number = s.participant_number
     WHERE s.import_id = p_import AND s.position_date IS NOT NULL AND s.shares IS NOT NULL
     GROUP BY p.id, s.position_date;
    GET DIAGNOSTICS n_pos = ROW_COUNT;
    -- the tie: the file's own totals (per date when given, else overall) against the rows
    ctrl := im.control_totals;
    SELECT jsonb_build_object('positions', n_pos, 'rows', (SELECT count(*) FROM spr_rows WHERE import_id = p_import), 'rows_failed', n_fail,
                              'participants', (SELECT count(DISTINCT participant_id) FROM participant_positions WHERE import_id = p_import),
                              'shares_by_date', (SELECT jsonb_object_agg(position_date::text, shares) FROM (SELECT position_date, sum(shares) AS shares FROM participant_positions WHERE import_id = p_import GROUP BY position_date) x))
      INTO der;
    IF n_fail > 0 THEN ok := false; note := n_fail || ' row(s) failed to type. '; END IF;
    IF ctrl ? 'shares_by_date' THEN
        FOR r IN SELECT key AS d, value::numeric AS v FROM jsonb_each_text(ctrl -> 'shares_by_date') LOOP
            tot := (der -> 'shares_by_date' ->> r.d)::numeric;
            IF tot IS DISTINCT FROM r.v THEN ok := false; note := note || format('%s: file %s, rows %s. ', r.d, r.v, COALESCE(tot::text, 'none')); END IF;
        END LOOP;
    ELSIF ctrl ? 'shares' THEN
        SELECT sum(shares) INTO tot FROM participant_positions WHERE import_id = p_import AND position_date = d_to;
        IF tot IS DISTINCT FROM (ctrl ->> 'shares')::numeric THEN ok := false; note := note || format('shares on %s: file %s, rows %s. ', d_to, ctrl ->> 'shares', COALESCE(tot::text, 'none')); END IF;
    END IF;
    IF ctrl ? 'participants' THEN
        cnt := (der ->> 'participants')::integer;
        IF cnt IS DISTINCT FROM (ctrl ->> 'participants')::integer THEN ok := false; note := note || format('participants: file %s, rows %s. ', ctrl ->> 'participants', cnt); END IF;
    END IF;
    UPDATE imports SET status = 'done', finished_at = now(), rows_made = n_pos, rows_failed = n_fail, derived_totals = der, tied = ok, tie_note = NULLIF(btrim(note), ''),
           period_from = d_from, period_to = d_to WHERE id = p_import;
    INSERT INTO share_counts (security_id, as_of, kind, value, source, import_id, note)
    SELECT im.security_id, d_to, 'cede', sum(shares), 'spr', p_import, 'the SPR total on its last date'
      FROM participant_positions WHERE import_id = p_import AND position_date = d_to
    HAVING ok AND count(*) > 0
    ON CONFLICT (security_id, kind, as_of, source) DO UPDATE SET value = EXCLUDED.value, import_id = EXCLUDED.import_id, recorded_at = now();
    RETURN QUERY SELECT n_pos, n_new, n_fail, ok, NULLIF(btrim(note), '');
END$$;

-- ---- the series (§6.2): every one reads participant_positions; a window is [from, to]
-- The SPR's total on a date (the Cede figure the file implies); NULL when no row that day.
CREATE OR REPLACE FUNCTION si_spr_total(p_security bigint, p_date date) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT sum(shares) FROM participant_positions WHERE security_id = p_security AND position_date = p_date;
$$;
-- The position dates in a window.
CREATE OR REPLACE FUNCTION si_position_dates(p_security bigint, p_from date, p_to date) RETURNS SETOF date
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT DISTINCT position_date FROM participant_positions WHERE security_id = p_security AND position_date BETWEEN p_from AND p_to ORDER BY 1;
$$;
-- Per participant over a window: the first and last positions, the net, the largest one-day move, the days present.
CREATE OR REPLACE FUNCTION si_position_changes(p_security bigint, p_from date, p_to date)
    RETURNS TABLE (participant_id bigint, number text, name text, class_key text, open_shares numeric, close_shares numeric, net numeric, largest_move numeric, largest_move_date date, days_present integer, open_date date, close_date date)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH w AS (SELECT pp.participant_id, pp.position_date, pp.shares,
                      pp.shares - lag(pp.shares) OVER (PARTITION BY pp.participant_id ORDER BY pp.position_date) AS d
                 FROM participant_positions pp WHERE pp.security_id = p_security AND pp.position_date BETWEEN p_from AND p_to),
         a AS (SELECT participant_id, min(position_date) AS od, max(position_date) AS cd, count(*) AS n FROM w GROUP BY participant_id),
         m AS (SELECT DISTINCT ON (participant_id) participant_id, d, position_date FROM w WHERE d IS NOT NULL ORDER BY participant_id, abs(d) DESC, position_date)
    SELECT a.participant_id, p.number, p.name, p.class_key,
           (SELECT shares FROM w WHERE w.participant_id = a.participant_id AND w.position_date = a.od),
           (SELECT shares FROM w WHERE w.participant_id = a.participant_id AND w.position_date = a.cd),
           (SELECT shares FROM w WHERE w.participant_id = a.participant_id AND w.position_date = a.cd) - (SELECT shares FROM w WHERE w.participant_id = a.participant_id AND w.position_date = a.od),
           m.d, m.position_date, a.n::integer, a.od, a.cd
      FROM a JOIN participants p ON p.id = a.participant_id LEFT JOIN m ON m.participant_id = a.participant_id
     ORDER BY abs((SELECT shares FROM w WHERE w.participant_id = a.participant_id AND w.position_date = a.cd) - (SELECT shares FROM w WHERE w.participant_id = a.participant_id AND w.position_date = a.od)) DESC NULLS LAST;
$$;
-- Net flow by class over a window (D7 — lore: the signal that reads it says so): the class's total on the first and last dates.
CREATE OR REPLACE FUNCTION si_participant_flow(p_security bigint, p_from date, p_to date)
    RETURNS TABLE (class_key text, side text, open_shares numeric, close_shares numeric, net numeric, share_of_total_close numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH d AS (SELECT min(position_date) AS od, max(position_date) AS cd FROM participant_positions WHERE security_id = p_security AND position_date BETWEEN p_from AND p_to),
         o AS (SELECT p.class_key, sum(pp.shares) AS s FROM participant_positions pp JOIN participants p ON p.id = pp.participant_id, d WHERE pp.security_id = p_security AND pp.position_date = d.od GROUP BY p.class_key),
         c AS (SELECT p.class_key, sum(pp.shares) AS s FROM participant_positions pp JOIN participants p ON p.id = pp.participant_id, d WHERE pp.security_id = p_security AND pp.position_date = d.cd GROUP BY p.class_key)
    SELECT k.key, k.side, COALESCE(o.s, 0), COALESCE(c.s, 0), COALESCE(c.s, 0) - COALESCE(o.s, 0),
           CASE WHEN (SELECT sum(s) FROM c) > 0 THEN round(COALESCE(c.s, 0) / (SELECT sum(s) FROM c) * 100, 2) END
      FROM participant_classes k LEFT JOIN o ON o.class_key = k.key LEFT JOIN c ON c.class_key = k.key
     WHERE o.s IS NOT NULL OR c.s IS NOT NULL
     ORDER BY k.sort_order;
$$;
-- Participants new to the file in a window (not present before p_from) and lost (present before, absent at p_to or at zero).
CREATE OR REPLACE FUNCTION si_participants_new_lost(p_security bigint, p_from date, p_to date)
    RETURNS TABLE (participant_id bigint, number text, name text, class_key text, state text, shares numeric, on_date date)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH last_before AS (SELECT max(position_date) AS d FROM participant_positions WHERE security_id = p_security AND position_date < p_from),
         last_in AS (SELECT max(position_date) AS d FROM participant_positions WHERE security_id = p_security AND position_date BETWEEN p_from AND p_to),
         before AS (SELECT participant_id, shares FROM participant_positions, last_before WHERE security_id = p_security AND position_date = last_before.d AND shares > 0),
         now_ AS (SELECT participant_id, shares FROM participant_positions, last_in WHERE security_id = p_security AND position_date = last_in.d AND shares > 0)
    SELECT n.participant_id, p.number, p.name, p.class_key, 'new', n.shares, (SELECT d FROM last_in)
      FROM now_ n JOIN participants p ON p.id = n.participant_id WHERE NOT EXISTS (SELECT 1 FROM before b WHERE b.participant_id = n.participant_id)
    UNION ALL
    SELECT b.participant_id, p.number, p.name, p.class_key, 'lost', b.shares, (SELECT d FROM last_before)
      FROM before b JOIN participants p ON p.id = b.participant_id WHERE NOT EXISTS (SELECT 1 FROM now_ n WHERE n.participant_id = b.participant_id)
    ORDER BY 5, 6 DESC;
$$;
-- Concentration on a date: the top-5, top-10 and top-20 shares of the total and a Herfindahl index (0–10,000).
CREATE OR REPLACE FUNCTION si_concentration(p_security bigint, p_date date)
    RETURNS TABLE (position_date date, total numeric, participants integer, top5_pct numeric, top10_pct numeric, top20_pct numeric, hhi numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH r AS (SELECT shares, row_number() OVER (ORDER BY shares DESC) AS rn, sum(shares) OVER () AS tot FROM participant_positions WHERE security_id = p_security AND position_date = p_date AND shares > 0)
    SELECT p_date, max(tot), count(*)::integer,
           round(sum(shares) FILTER (WHERE rn <= 5) / NULLIF(max(tot), 0) * 100, 2),
           round(sum(shares) FILTER (WHERE rn <= 10) / NULLIF(max(tot), 0) * 100, 2),
           round(sum(shares) FILTER (WHERE rn <= 20) / NULLIF(max(tot), 0) * 100, 2),
           round(sum((shares / NULLIF(tot, 0) * 100) ^ 2), 0)
      FROM r HAVING count(*) > 0;
$$;
-- One participant's series.
CREATE OR REPLACE FUNCTION si_participant_series(p_security bigint, p_participant bigint, p_from date, p_to date)
    RETURNS TABLE (position_date date, shares numeric, change numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT position_date, shares, shares - lag(shares) OVER (ORDER BY position_date)
      FROM participant_positions WHERE security_id = p_security AND participant_id = p_participant AND position_date BETWEEN p_from AND p_to ORDER BY position_date;
$$;

-- ---------------------------------------------------------------------------------------------
-- THE NOBO LIST (Broadridge's job file; the fixture's layout) and THE REGISTER (the agent's export). The raw rows; holders in db/008.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE nobo_jobs (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id           bigint NOT NULL UNIQUE REFERENCES imports(id) ON DELETE CASCADE,
    security_id         bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    job_number          text,                                                      -- Broadridge's ("O08888")
    cusip               text,
    issuer_name_as_filed text,
    record_date         date NOT NULL,
    sort_order          text,                                                      -- (D)escending shares / (Z)ip
    nobo_count_header   integer,                                                   -- NUMBER OF NOBOS in the header
    nobo_count_rows     integer NOT NULL DEFAULT 0,
    shares_total        numeric(20,0) NOT NULL DEFAULT 0
);
CREATE INDEX nobo_jobs_security_idx ON nobo_jobs (security_id, record_date DESC);

CREATE TABLE nobo_rows (
    id                       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    job_id                   bigint NOT NULL REFERENCES nobo_jobs(id) ON DELETE CASCADE,
    import_id                bigint NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    row_no                   integer NOT NULL,
    shares                   numeric(20,0) CHECK (shares IS NULL OR shares >= 0),
    line1 text, line2 text, line3 text, line4 text, line5 text, line6 text, line7 text,   -- the seven lines of name and address, as read
    zip_sort                 text,
    cusip                    text,
    holder_id                bigint,                                               -- the match (FK in db/008)
    holder_account_id        bigint,                                               -- FK in db/008
    custodian_participant_id bigint REFERENCES participants(id) ON DELETE SET NULL, -- parsed from the lines ("CHARLES SCHWAB & CO INC CUST")
    account_kind             text NOT NULL DEFAULT 'unknown' CHECK (account_kind IN ('ira', 'tod', 'trust', 'joint', 'custodial', 'corporate', 'individual', 'unknown')),
    raw                      jsonb NOT NULL DEFAULT '{}'::jsonb,
    error                    text,
    UNIQUE (job_id, row_no)
);
CREATE INDEX nobo_rows_job_idx ON nobo_rows (job_id);
CREATE INDEX nobo_rows_holder_idx ON nobo_rows (holder_id);
CREATE INDEX nobo_rows_unmatched_idx ON nobo_rows (job_id) WHERE holder_id IS NULL;

-- the CLIENTS section: the bank/broker listing per participant
CREATE TABLE nobo_broker_rows (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    job_id          bigint NOT NULL REFERENCES nobo_jobs(id) ON DELETE CASCADE,
    row_no          integer NOT NULL,
    client          text NOT NULL,                                                 -- as read ("CHARLES SCHWAB & CO., INC.")
    participant_id  bigint REFERENCES participants(id) ON DELETE SET NULL,         -- matched by name or alias
    nobo_count      integer,
    shares          numeric(20,0),
    UNIQUE (job_id, row_no)
);

CREATE TABLE register_rows (
    id                       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id                bigint NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    security_id              bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    record_date              date NOT NULL,
    row_no                   integer NOT NULL,
    section                  text,                                                 -- the class as the agent labels it ("Common 2")
    position_id              text,                                                 -- the agent's position id
    position_type            text,                                                 -- the agent's: cert, book, DRS, plan, restricted
    holder_ref               text,                                                 -- the agent's holder id
    registration_name        text,
    address_line             text,
    city                     text,
    state                    text,
    country                  text,
    zip                      text,
    issue_date               date,
    shares                   numeric(20,0) CHECK (shares IS NULL OR shares >= 0),
    cost_basis               numeric(14,4),
    restriction_text         text,
    percent_owned            numeric(8,4),
    is_employee              boolean,
    is_officer_director      boolean,
    is_us_citizen            boolean,
    is_cede                  boolean GENERATED ALWAYS AS (upper(COALESCE(registration_name, '')) ~ '^CEDE\s*(&|AND)\s*CO') STORED,
    holder_id                bigint,                                               -- FK in db/008
    holder_account_id        bigint,
    raw                      jsonb NOT NULL DEFAULT '{}'::jsonb,
    error                    text,
    UNIQUE (import_id, row_no)
);
CREATE INDEX register_rows_import_idx ON register_rows (import_id);
CREATE INDEX register_rows_security_idx ON register_rows (security_id, record_date DESC);
CREATE INDEX register_rows_holder_idx ON register_rows (holder_id);
CREATE INDEX register_rows_unmatched_idx ON register_rows (import_id) WHERE holder_id IS NULL AND NOT is_cede;

-- The OBO remainder per participant on a record date: the SPR position (the nearest position date at or before) minus the NOBO shares
-- the Bank/Broker listing attributes to it. NULL shares where one side is missing.
CREATE OR REPLACE FUNCTION si_obo_remainder(p_security bigint, p_record_date date)
    RETURNS TABLE (participant_id bigint, number text, name text, class_key text, spr_shares numeric, spr_date date, nobo_shares numeric, nobo_count integer, obo_remainder numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH d AS (SELECT max(position_date) AS pd FROM participant_positions WHERE security_id = p_security AND position_date <= p_record_date),
         spr AS (SELECT participant_id, shares FROM participant_positions, d WHERE security_id = p_security AND position_date = d.pd),
         nobo AS (SELECT b.participant_id, sum(b.shares) AS shares, sum(b.nobo_count)::integer AS cnt
                    FROM nobo_broker_rows b JOIN nobo_jobs j ON j.id = b.job_id JOIN imports i ON i.id = j.import_id
                   WHERE j.security_id = p_security AND j.record_date = p_record_date AND b.participant_id IS NOT NULL AND i.deleted_at IS NULL
                   GROUP BY b.participant_id)
    SELECT p.id, p.number, p.name, p.class_key, s.shares, (SELECT pd FROM d), n.shares, n.cnt, s.shares - COALESCE(n.shares, 0)
      FROM participants p LEFT JOIN spr s ON s.participant_id = p.id LEFT JOIN nobo n ON n.participant_id = p.id
     WHERE s.participant_id IS NOT NULL OR n.participant_id IS NOT NULL
     ORDER BY s.shares DESC NULLS LAST;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON imports, spr_rows, participant_positions, nobo_jobs, nobo_rows, nobo_broker_rows, register_rows TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_import_usable(bigint), si_spr_total(bigint, date), si_position_dates(bigint, date, date), si_position_changes(bigint, date, date), si_participant_flow(bigint, date, date),
    si_participants_new_lost(bigint, date, date), si_concentration(bigint, date), si_participant_series(bigint, bigint, date, date), si_obo_remainder(bigint, date)
    TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_spr_load(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_spr_load(bigint) TO shareholder_intelligence_rw;

COMMIT;
