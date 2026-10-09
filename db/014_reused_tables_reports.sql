-- 014: the tables the estate already keeps and this application keeps rows of — attachments, notes, notifications, the person's
-- preferences, the outbox the worker sends, agent dispatches, tags, exports, worker passes — plus REPORTS (the packs), IMPORT TEMPLATES,
-- the TOMBSTONE rules and the Analyst's proposals as the signals and matches already carry them (design §6.7, §6.8; D14).
--
-- THE ESTATE'S DATA MODEL: `attachments` and `notes` are GL db/009 VERBATIM (record_type widened); `notifications`, `notification_prefs`,
-- `notification_outbox` are Spaces db/012 = DocCloud db/013 = Sales CRM db/013 VERBATIM (member-only; the kind lists ours; prefs append the
-- morning-note and signal settings); `agent_dispatches` is GL db/014 = CT db/013 verbatim (kinds ours; signal_id/filing_id/import_id
-- appended); `tags` is Help Desk db/009 verbatim and `record_tags` DocCloud's shape; `exports`, `worker_passes` Spaces db/016 (kinds ours).
BEGIN;

-- reuse: GL db/009 verbatim; record_type widened
CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('import', 'holder', 'institution', 'participant', 'event', 'signal', 'report', 'issuer', 'dilution_instrument', 'filing')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);
ALTER TABLE imports ADD CONSTRAINT imports_attachment_fk FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL;

-- reuse: GL db/009 verbatim
CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

-- reuse: Spaces db/012 verbatim (member-only); kinds ours; signal_id/issuer_id appended
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('signal_high', 'signal', 'signal_proposal', 'match_proposal', 'upload_done', 'upload_untied', 'reconciliation_untied', 'filing_13d', 'filing_watched',
                                               'watch_hit', 'source_blocked', 'source_failed', 'morning_note', 'pack_drafted', 'pack_approved', 'export_ready', 'mention', 'digest')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.7)
    signal_id    bigint REFERENCES signals(id) ON DELETE CASCADE,
    issuer_id    bigint REFERENCES issuers(id) ON DELETE CASCADE,
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);
CREATE INDEX notifications_member_all_idx ON notifications (member_id, created_at DESC);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,
    kinds          text[] NOT NULL DEFAULT '{signal_high,signal_proposal,match_proposal,upload_done,upload_untied,reconciliation_untied,filing_13d,morning_note,pack_drafted,pack_approved,export_ready,mention}',
    text_kinds     text[] NOT NULL DEFAULT '{signal_high}',
    updated_at     timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.7, D14)
    morning_note   boolean NOT NULL DEFAULT true,
    morning_hour   smallint CHECK (morning_hour IS NULL OR morning_hour BETWEEN 0 AND 23),      -- NULL = the settings'
    signals_min_severity text NOT NULL DEFAULT 'watch' CHECK (signals_min_severity IN ('info', 'watch', 'high')),
    issuer_ids     bigint[] NOT NULL DEFAULT '{}',                                               -- empty = every issuer they may see
    digest         boolean NOT NULL DEFAULT false
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);

-- One notification: the bell row, and the outbox rows the person's preferences ask for (the worker delivers). DocCloud's dc_notify.
CREATE OR REPLACE FUNCTION si_notify(p_member_id bigint, p_kind text, p_title text, p_body text, p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL,
                                     p_signal_id bigint DEFAULT NULL, p_issuer_id bigint DEFAULT NULL, p_actor bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; p notification_prefs%ROWTYPE; em citext;
BEGIN
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe) THEN RETURN NULL; END IF;
    IF p_issuer_id IS NOT NULL AND EXISTS (SELECT 1 FROM issuer_members WHERE issuer_id = p_issuer_id)
       AND NOT EXISTS (SELECT 1 FROM issuer_members WHERE issuer_id = p_issuer_id AND member_id = p_member_id)
       AND NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND ('manager' = ANY (si_member_roles(m.id)) OR 'admin' = ANY (si_member_roles(m.id)))) THEN
        RETURN NULL;                                                                               -- the wall: no notice about an issuer they may not see
    END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id, signal_id, issuer_id, actor_member_id)
    VALUES (p_member_id, p_kind, p_title, p_body, p_record_type, p_record_id, p_signal_id, p_issuer_id, p_actor) RETURNING id INTO nid;
    SELECT * INTO p FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO p; END IF;
    SELECT email INTO em FROM members WHERE id = p_member_id;
    IF p.email_enabled AND p_kind = ANY (p.kinds) AND em IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, em, p_kind, p_record_type, p_record_id, p_dedupe, p_title, COALESCE(p_body, p_title)) ON CONFLICT DO NOTHING;
    END IF;
    IF p.text_enabled AND p_kind = ANY (p.text_kinds) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END, p_title, left(COALESCE(p_body, p_title), 300)) ON CONFLICT DO NOTHING;
    END IF;
    RETURN nid;
END$$;

-- reuse: GL db/014 = CT db/013 verbatim; kinds ours; signal_id/filing_id/import_id appended
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('signal', 'filing', 'upload_done', 'match', 'pack', 'ask', 'duty_proposal')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §5)
    signal_id        bigint REFERENCES signals(id) ON DELETE SET NULL,
    filing_id        bigint REFERENCES filings(id) ON DELETE SET NULL,
    import_id        bigint REFERENCES imports(id) ON DELETE SET NULL,
    next_attempt_at  timestamptz
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);

-- The Analyst agent (the first active agent whose name says analyst), for the dispatches the triggers make.
CREATE OR REPLACE FUNCTION si_analyst_agent() RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT id FROM members WHERE member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL AND display_name ILIKE '%analyst%' ORDER BY id LIMIT 1;
$$;
-- A high signal: the escalation member notified, the Analyst dispatched (§6.6).
CREATE OR REPLACE FUNCTION si_signals_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE agent bigint; esc bigint; nm text;
BEGIN
    IF TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND NEW.severity = 'high' AND OLD.severity <> 'high') THEN
        SELECT name INTO nm FROM securities WHERE id = NEW.security_id;
        IF NEW.severity = 'high' THEN
            SELECT escalate_high_to INTO esc FROM si_settings WHERE id = 1;
            IF esc IS NOT NULL THEN PERFORM si_notify(esc, 'signal_high', 'High signal: ' || nm, NEW.summary, 'signal', NEW.id, NEW.id, NEW.issuer_id, NULL, 'signal_high:' || NEW.id); END IF;
            agent := si_analyst_agent();
            IF agent IS NOT NULL THEN
                INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, signal_id, detail) VALUES ('signal', NEW.id, agent, 'signal', 'chat', NEW.id, 'severity high');
            END IF;
        END IF;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER signals_after AFTER INSERT OR UPDATE OF severity ON signals FOR EACH ROW EXECUTE FUNCTION si_signals_after();
-- A 13D: the Analyst dispatched.
CREATE OR REPLACE FUNCTION si_filings_dispatch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE agent bigint := si_analyst_agent();
BEGIN
    IF agent IS NOT NULL AND NEW.form IN ('SC 13D', 'SC 13D/A') THEN
        INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, filing_id, detail) VALUES ('filing', NEW.id, agent, 'filing', 'chat', NEW.id, NEW.form);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER filings_dispatch AFTER INSERT ON filings FOR EACH ROW EXECUTE FUNCTION si_filings_dispatch();

-- reuse: Help Desk db/009 `tags` verbatim; record_tags DocCloud's shape
CREATE TABLE tags (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug         text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9-]{0,59}$'),
    label        text NOT NULL,
    usage_count  integer NOT NULL DEFAULT 0,
    created_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE record_tags (
    record_type  text NOT NULL CHECK (record_type IN ('holder', 'institution', 'participant', 'event', 'issuer', 'signal')),
    record_id    bigint NOT NULL,
    tag_id       bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    added_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    added_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (record_type, record_id, tag_id)
);
CREATE INDEX record_tags_tag_idx ON record_tags (tag_id);
CREATE OR REPLACE FUNCTION si_record_tags_count() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN UPDATE tags SET usage_count = usage_count + 1 WHERE id = NEW.tag_id; ELSE UPDATE tags SET usage_count = GREATEST(usage_count - 1, 0) WHERE id = OLD.tag_id; END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_count AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION si_record_tags_count();
CREATE OR REPLACE FUNCTION si_tags_of(p_type text, p_id bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(array_agg(t.label ORDER BY t.label), '{}') FROM record_tags r JOIN tags t ON t.id = r.tag_id WHERE r.record_type = p_type AND r.record_id = p_id;
$$;

-- reuse: Spaces db/016 (kinds ours; the Spaces targets kept nullable)
CREATE TABLE exports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('page', 'space', 'channel', 'database', 'all', 'positions_csv', 'holders_csv', 'institutions_csv', 'short_csv', 'signals_csv', 'events_csv', 'filings_csv', 'report_pdf', 'report_html')),
    format        text NOT NULL CHECK (format IN ('md', 'html', 'csv', 'json', 'zip', 'pdf')),
    page_id       uuid,
    space_id      bigint,
    channel_id    bigint,
    include_subpages boolean NOT NULL DEFAULT true,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    storage_path  text,
    byte_size     bigint,
    item_count    integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    expires_at    timestamptz NOT NULL DEFAULT now() + interval '7 days',
    downloaded_at timestamptz,
    -- appended (design §6.7, D4 (d)): what and whose; a holders export carries pii and is logged with its row count
    issuer_id     bigint REFERENCES issuers(id) ON DELETE CASCADE,
    security_id   bigint REFERENCES securities(id) ON DELETE SET NULL,
    params        jsonb NOT NULL DEFAULT '{}'::jsonb,
    includes_pii  boolean NOT NULL DEFAULT false,
    report_id     bigint
);
CREATE INDEX exports_queue_idx ON exports (status, created_at) WHERE status = 'queued';
CREATE INDEX exports_member_idx ON exports (created_by, created_at DESC);

CREATE TABLE worker_passes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    steps         jsonb NOT NULL DEFAULT '{}'::jsonb,
    error         text
);

-- REPORTS — the packs (new)
CREATE TABLE reports (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    issuer_id      bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    kind           text NOT NULL DEFAULT 'weekly_pack' CHECK (kind IN ('weekly_pack', 'monthly_pack', 'board_pack', 'record_date_pack', 'custom')),
    title          text NOT NULL,
    period_from    date NOT NULL,
    period_to      date NOT NULL,
    status         text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'published')),
    narrative      text,                                                           -- Markdown: the agent's draft, the person's edit
    sections       jsonb NOT NULL DEFAULT '["movement","flow","top_participants","holders_ranges","holders_geography","thirteen_f","beneficial","insiders","short_side","fails_threshold","reconciliation","signals","events"]'::jsonb,
    rendered_path  text,                                                           -- the HTML under storage/reports
    pdf_path       text,
    drafted_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_at    timestamptz,
    attachment_id  bigint REFERENCES attachments(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz,
    CHECK (period_to >= period_from),
    CHECK ((status = 'draft') = (approved_at IS NULL))
);
CREATE INDEX reports_issuer_idx ON reports (issuer_id, period_to DESC) WHERE deleted_at IS NULL;
CREATE TRIGGER reports_touch BEFORE UPDATE ON reports FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE exports ADD CONSTRAINT exports_report_fk FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE SET NULL;
-- a pack is approved by a manager; its narrative is frozen then
CREATE OR REPLACE FUNCTION si_report_approve(p_report bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT si_has_right('reports.approve') THEN RAISE EXCEPTION 'Approving a pack is the IR Manager''s' USING ERRCODE = 'insufficient_privilege'; END IF;
    UPDATE reports SET status = 'approved', approved_by = app_current_member_id(), approved_at = now() WHERE id = p_report AND status = 'draft' AND deleted_at IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'The pack is not a draft' USING ERRCODE = 'check_violation'; END IF;
END$$;
CREATE OR REPLACE FUNCTION si_reports_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' AND NEW.narrative IS DISTINCT FROM OLD.narrative THEN RAISE EXCEPTION 'An approved pack''s narrative is frozen' USING ERRCODE = 'check_violation'; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER reports_guard BEFORE UPDATE ON reports FOR EACH ROW EXECUTE FUNCTION si_reports_guard();

-- IMPORT TEMPLATES — a generic upload's saved column mapping
CREATE TABLE import_templates (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text NOT NULL UNIQUE,
    kind        text NOT NULL CHECK (kind IN ('spr', 'nobo', 'register', 'prices_csv', 'holders_csv')),
    connector   text NOT NULL DEFAULT 'generic_mapped',
    mapping     jsonb NOT NULL CHECK (jsonb_typeof(mapping) = 'object'),           -- {"registration_name": "E", "shares": "L", "header_rows": 7, "sheet": 1}
    sample      jsonb,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER import_templates_touch BEFORE UPDATE ON import_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- the fixtures' layouts, seeded as templates a person may copy for another agent's export
INSERT INTO import_templates (name, kind, connector, mapping) VALUES
 ('STC — Security Holder List, Position Detail', 'register', 'stc_position_detail', '{"header_rows": 7, "sections_by_title": true, "columns": {"position_id": "B", "position_type": "C", "holder_ref": "D", "registration_name": "E", "address_line": "F", "city": "G", "state": "H", "country": "I", "zip": "J", "issue_date": "K", "shares": "L", "cost_basis": "M", "restriction_text": "N", "percent_owned": "O", "is_employee": "P", "is_officer_director": "Q", "is_us_citizen": "R"}}'),
 ('Broadridge — NOBO list (xlsx)', 'nobo', 'broadridge_nobo_xlsx', '{"header": {"job_number": "A2", "cusip": "B2", "issuer_name": "C2", "record_date": "D2", "sort_order": "E2", "nobo_count": "F2"}, "shares_section_from_row": 5, "columns": {"shares": "A", "line1": "B", "line2": "C", "line3": "D", "line4": "E", "line5": "F", "line6": "G", "line7": "H", "zip_sort": "I", "cusip": "J"}, "clients_section_marker": "CUSIP", "clients_columns": {"cusip": "A", "client": "B", "nobo_count": "C", "shares": "D"}}'),
 ('DTC — SPR weekly grid (xlsx)', 'spr', 'dtc_spr_xlsx', '{"header_dates_row": 1, "columns": {"participant_number": "A", "participant_name": "B", "d1": "C", "d2": "D", "d3": "E", "d4": "F", "d5": "G"}, "confirm_on_first_real_file": true}');

-- ---------------------------------------------------------------------------------------------
-- THE TOMBSTONE (§3.5): an upload or a record waits tombstone_days; the worker purges.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_import_delete(p_import bigint, p_restore boolean DEFAULT false) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE im imports%ROWTYPE;
BEGIN
    SELECT * INTO im FROM imports WHERE id = p_import FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such upload'; END IF;
    IF p_restore THEN
        UPDATE imports SET deleted_at = NULL, deleted_by = NULL WHERE id = p_import;
        IF im.kind = 'spr' THEN PERFORM si_spr_load(p_import); END IF;     -- the positions come back from the rows
        IF im.kind IN ('nobo', 'register') THEN
            INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id)
            SELECT n.holder_id, n.holder_account_id, j.security_id, j.record_date, n.shares, 'nobo', n.import_id FROM nobo_rows n JOIN nobo_jobs j ON j.id = n.job_id WHERE n.import_id = p_import AND im.kind = 'nobo' AND n.holder_account_id IS NOT NULL AND n.shares IS NOT NULL
            UNION ALL
            SELECT r.holder_id, r.holder_account_id, r.security_id, r.record_date, r.shares, 'register', r.import_id FROM register_rows r WHERE r.import_id = p_import AND im.kind = 'register' AND r.holder_account_id IS NOT NULL AND r.shares IS NOT NULL
            ON CONFLICT DO NOTHING;
        END IF;
    ELSE
        UPDATE imports SET deleted_at = now(), deleted_by = app_current_member_id() WHERE id = p_import AND deleted_at IS NULL;
        -- the derived rows go now (the raw rows stay with the tombstone for the restore); a share count the file wrote goes too
        DELETE FROM participant_positions WHERE import_id = p_import;
        DELETE FROM holder_positions WHERE import_id = p_import;
        DELETE FROM share_counts WHERE import_id = p_import;
    END IF;
END$$;
CREATE OR REPLACE FUNCTION si_tombstones_purge(p_limit integer DEFAULT 100) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cutoff timestamptz := now() - make_interval(days => (SELECT tombstone_days FROM si_settings WHERE id = 1)); n integer := 0; r record;
BEGIN
    FOR r IN SELECT id FROM imports WHERE deleted_at < cutoff LIMIT p_limit LOOP DELETE FROM imports WHERE id = r.id; n := n + 1; END LOOP;
    FOR r IN SELECT id FROM holders WHERE deleted_at < cutoff AND merged_into_id IS NULL LIMIT p_limit LOOP DELETE FROM holders WHERE id = r.id; n := n + 1; END LOOP;
    FOR r IN SELECT id FROM events WHERE deleted_at < cutoff LIMIT p_limit LOOP DELETE FROM events WHERE id = r.id; n := n + 1; END LOOP;
    FOR r IN SELECT id FROM reports WHERE deleted_at < cutoff LIMIT p_limit LOOP DELETE FROM reports WHERE id = r.id; n := n + 1; END LOOP;
    FOR r IN SELECT id FROM issuers WHERE deleted_at < cutoff LIMIT p_limit LOOP DELETE FROM issuers WHERE id = r.id; n := n + 1; END LOOP;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON attachments, notes, notifications, notification_prefs, notification_outbox, agent_dispatches, tags, record_tags, exports, worker_passes, reports, import_templates TO shareholder_intelligence_rw;
GRANT SELECT ON tags, import_templates TO shareholder_intelligence_records_ro;
GRANT EXECUTE ON FUNCTION si_tags_of(text, bigint), si_analyst_agent() TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_notify(bigint, text, text, text, text, bigint, bigint, bigint, bigint, text), si_report_approve(bigint), si_import_delete(bigint, boolean), si_tombstones_purge(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_notify(bigint, text, text, text, text, bigint, bigint, bigint, bigint, text), si_report_approve(bigint), si_import_delete(bigint, boolean), si_tombstones_purge(integer) TO shareholder_intelligence_rw;

COMMIT;
