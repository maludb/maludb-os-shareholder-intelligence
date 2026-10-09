-- 018: what the Phase 1 tool surface needed that the schema lacked (2026-10-09; docs/shareholder-intelligence-mcp-tool-surface.md,
-- "What the schema lacked"). Additive.
--
--  * THE FIVE K7 SHARES as SECURITY DEFINER functions (design §8, D13): they run as the application for the kernel's token, with the
--    consumer's catalog key from X-OS-Consumer (K26). A RESTRICTED ISSUER IS NEVER SHARED (an advisory firm's client stays inside its
--    list); NO NATURAL PERSON CROSSES (holders appear only as counts, ranges and entities; institutions and insiders are the public
--    record); a filing's text crosses whole (it is public) up to 5 MB.
--  * si_ownership_summary(): the ownership picture in one read (the share `ownership_summary` and the home's tiles).
--  * holder_kind_proposals: where an agent's `holder_kind_propose` lands (the matcher's own proposals are holder_match_proposals).
--  * si_analyst_proposals(): every open proposal an agent made, in one read (the proposals screen, `analyst_proposals`).
--  * si_holders_changes_summary(): the Viewer's form of the changes between record dates — counts and shares by state, never a holder.
--  * si_source_health(), si_tombstones(): the admin's two reads.
--  * agent_dispatches.status admits 'cancelled' (`dispatch_cancel`).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- An agent's proposal of a holder's kind (D8: an agent proposes, an Analyst decides)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE holder_kind_proposals (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    holder_id     bigint NOT NULL REFERENCES holders(id) ON DELETE CASCADE,
    kind          text NOT NULL CHECK (kind IN ('person', 'entity', 'custodian_account')),
    entity_type   text CHECK (entity_type IS NULL OR entity_type IN ('trust', 'corporation', 'fund', 'partnership', 'estate', 'bank', 'broker', 'government', 'nonprofit', 'other')),
    evidence      text CHECK (evidence IS NULL OR length(evidence) <= 500),
    proposed_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    status        text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'rejected')),
    decided_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX holder_kind_proposals_one_open ON holder_kind_proposals (holder_id) WHERE status = 'proposed';

CREATE OR REPLACE FUNCTION si_holder_kind_propose(p_holder bigint, p_kind text, p_entity_type text, p_evidence text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid bigint;
BEGIN
    IF NOT si_sees_holder_pii() THEN RAISE EXCEPTION 'A holder''s kind is proposed by an Analyst or an agent holding Analyst' USING ERRCODE = 'insufficient_privilege'; END IF;
    IF NOT si_can_see_issuer((SELECT issuer_id FROM holders WHERE id = p_holder)) THEN RAISE EXCEPTION 'No such holder' USING ERRCODE = 'no_data_found'; END IF;
    UPDATE holder_kind_proposals SET kind = p_kind, entity_type = p_entity_type, evidence = p_evidence, proposed_by = app_current_member_id(), created_at = now()
     WHERE holder_id = p_holder AND status = 'proposed' RETURNING id INTO pid;
    IF pid IS NULL THEN
        INSERT INTO holder_kind_proposals (holder_id, kind, entity_type, evidence, proposed_by) VALUES (p_holder, p_kind, p_entity_type, p_evidence, app_current_member_id()) RETURNING id INTO pid;
    END IF;
    RETURN pid;
END$$;
-- A person's decision: accept sets the holder's kind; reject closes the proposal.
CREATE OR REPLACE FUNCTION si_holder_kind_decide(p_proposal bigint, p_accept boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p holder_kind_proposals%ROWTYPE;
BEGIN
    SELECT * INTO p FROM holder_kind_proposals WHERE id = p_proposal AND status = 'proposed' FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'The proposal is not open' USING ERRCODE = 'check_violation'; END IF;
    IF p_accept THEN UPDATE holders SET kind = p.kind, entity_type = COALESCE(p.entity_type, entity_type) WHERE id = p.holder_id; END IF;
    UPDATE holder_kind_proposals SET status = CASE WHEN p_accept THEN 'accepted' ELSE 'rejected' END, decided_by = app_current_member_id(), decided_at = now() WHERE id = p_proposal;
END$$;

-- Every open proposal an agent made, for the issuers the caller may see; holder rows only for the PII roles.
CREATE OR REPLACE FUNCTION si_analyst_proposals(p_issuer bigint DEFAULT NULL, p_kind text DEFAULT NULL)
    RETURNS TABLE (kind text, proposal_id bigint, issuer_id bigint, record_type text, record_id bigint, title text, reason text, note text, proposed_by bigint, proposed_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pii boolean := si_sees_holder_pii();
BEGIN
    IF NOT app_is_active_member() THEN RETURN; END IF;
    RETURN QUERY
    SELECT 'signal'::text, s.id, s.issuer_id, 'signal'::text, s.id, (SELECT name FROM signal_rules WHERE id = s.rule_id) || ': ' || left(s.summary, 160), s.proposal_reason, s.proposal_note, s.proposal_by, s.proposal_at
      FROM signals s
     WHERE s.status = 'open' AND s.proposal_by IS NOT NULL AND s.issuer_id IN (SELECT si_my_issuers())
       AND (p_issuer IS NULL OR s.issuer_id = p_issuer) AND (p_kind IS NULL OR p_kind = 'signal')
    UNION ALL
    SELECT 'holder_match', m.id, m.issuer_id, m.row_kind, m.row_id, 'Match a ' || m.row_kind || ' row to ' || h.display_name || ' (' || m.score || ')', m.evidence ->> 'note', NULL, m.proposed_by, m.created_at
      FROM holder_match_proposals m JOIN holders h ON h.id = m.holder_id
     WHERE pii AND m.status = 'proposed' AND m.proposed_by IS NOT NULL AND m.issuer_id IN (SELECT si_my_issuers())
       AND (p_issuer IS NULL OR m.issuer_id = p_issuer) AND (p_kind IS NULL OR p_kind = 'holder_match')
    UNION ALL
    SELECT 'holder_kind', k.id, h.issuer_id, 'holder', k.holder_id, h.display_name || ' is ' || k.kind || COALESCE(' (' || k.entity_type || ')', ''), k.evidence, NULL, k.proposed_by, k.created_at
      FROM holder_kind_proposals k JOIN holders h ON h.id = k.holder_id
     WHERE pii AND k.status = 'proposed' AND h.issuer_id IN (SELECT si_my_issuers())
       AND (p_issuer IS NULL OR h.issuer_id = p_issuer) AND (p_kind IS NULL OR p_kind = 'holder_kind')
    ORDER BY 10 DESC;
END$$;

-- The Viewer's changes: counts and shares by state between two record dates — never a holder.
CREATE OR REPLACE FUNCTION si_holders_changes_summary(p_security bigint, p_channel text, p_date_a date, p_date_b date)
    RETURNS TABLE (state text, holders integer, shares_a numeric, shares_b numeric, net numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.state, count(*)::integer, COALESCE(sum(c.shares_a), 0), COALESCE(sum(c.shares_b), 0), COALESCE(sum(c.net), 0)
      FROM si_holders_changes(p_security, p_channel, p_date_a, p_date_b) c
     WHERE si_can_see_security(p_security)
     GROUP BY c.state ORDER BY CASE c.state WHEN 'new' THEN 1 WHEN 'up' THEN 2 WHEN 'same' THEN 3 WHEN 'down' THEN 4 ELSE 5 END;
$$;

-- ---------------------------------------------------------------------------------------------
-- THE OWNERSHIP PICTURE in one read (the share, the home's tiles, the pack's first paragraph). No natural person.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_ownership_summary(p_security bigint, p_as_of date DEFAULT CURRENT_DATE) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sec securities%ROWTYPE; spr_d date; per date; nobo_d date; si record; rec share_reconciliations; conc record; flow jsonb; f13 record; bo jsonb; ins numeric; obo record;
BEGIN
    SELECT * INTO sec FROM securities WHERE id = p_security;
    IF NOT FOUND THEN RETURN NULL; END IF;
    SELECT max(position_date) INTO spr_d FROM participant_positions WHERE security_id = p_security AND position_date <= p_as_of;
    SELECT max(period_end) INTO per FROM institutional_holdings WHERE security_id = p_security AND period_end <= p_as_of;
    SELECT max(record_date) INTO nobo_d FROM nobo_jobs WHERE security_id = p_security AND record_date <= p_as_of;
    SELECT * INTO si FROM si_short_vs_float(p_security, p_as_of);
    rec := si_reconciliation_latest(p_security);
    SELECT * INTO conc FROM si_concentration(p_security, spr_d);          -- always assigned (NULLs when there is no date) so the CASEs below may read it
    SELECT * INTO f13 FROM si_13f_summary(p_security, per);
    IF spr_d IS NOT NULL THEN
        SELECT jsonb_object_agg(k.side, k.s) INTO flow FROM (
            SELECT pc.side, sum(pp.shares) AS s FROM participant_positions pp JOIN participants p ON p.id = pp.participant_id JOIN participant_classes pc ON pc.key = p.class_key
             WHERE pp.security_id = p_security AND pp.position_date = spr_d GROUP BY pc.side) k;
    END IF;
    SELECT jsonb_agg(jsonb_build_object('name', b.name, 'schedule', b.schedule, 'percent_of_class', b.percent_of_class, 'shares', b.shares, 'filed_on', b.filed_on) ORDER BY b.percent_of_class DESC NULLS LAST)
      INTO bo FROM si_beneficial_owners(p_security) b;
    SELECT sum(x.after_) INTO ins FROM (
        SELECT DISTINCT ON (t.reporting_owner_cik) t.shares_owned_after AS after_ FROM insider_transactions t
         WHERE t.security_id = p_security AND NOT t.is_derivative AND t.transaction_date <= p_as_of ORDER BY t.reporting_owner_cik, t.transaction_date DESC, t.id DESC) x;
    SELECT * INTO obo FROM si_obo_totals(p_security) o WHERE o.record_date <= p_as_of ORDER BY o.record_date DESC LIMIT 1;
    RETURN jsonb_build_object(
        'security_id', sec.id, 'cusip', sec.cusip, 'ticker', sec.ticker, 'as_of', p_as_of,
        'outstanding', si_share_count(p_security, 'outstanding', p_as_of), 'float', si_share_count(p_security, 'float', p_as_of),
        'short', CASE WHEN si.settlement_date IS NULL THEN NULL ELSE jsonb_build_object('settlement_date', si.settlement_date, 'short_shares', si.short_shares, 'pct_of_float', si.pct_of_float, 'pct_of_outstanding', si.pct_of_outstanding, 'days_to_cover', si.days_to_cover) END,
        'dtc', CASE WHEN spr_d IS NULL THEN NULL ELSE jsonb_build_object('position_date', spr_d, 'total', si_spr_total(p_security, spr_d), 'by_side', COALESCE(flow, '{}'::jsonb), 'top5_pct', conc.top5_pct, 'hhi', conc.hhi) END,
        'reconciliation', CASE WHEN rec.id IS NULL THEN NULL ELSE jsonb_build_object('as_of', rec.as_of, 'tied', rec.tied, 'difference_registered', rec.difference_registered, 'difference_spr', rec.difference_spr) END,
        'thirteen_f', CASE WHEN per IS NULL THEN NULL ELSE jsonb_build_object('period_end', per, 'holders', f13.holders, 'shares', f13.shares, 'pct_of_outstanding', f13.pct_of_outstanding, 'top10_pct', f13.top10_pct) END,
        'beneficial_owners', COALESCE(bo, '[]'::jsonb),
        'insider_shares', ins,
        'nobo', CASE WHEN nobo_d IS NULL THEN NULL ELSE jsonb_build_object('record_date', nobo_d, 'holders', (SELECT nobo_count_rows FROM nobo_jobs WHERE security_id = p_security AND record_date = nobo_d ORDER BY id DESC LIMIT 1),
                                                                             'shares', (SELECT shares_total FROM nobo_jobs WHERE security_id = p_security AND record_date = nobo_d ORDER BY id DESC LIMIT 1)) END,
        'obo', CASE WHEN obo.record_date IS NULL THEN NULL ELSE jsonb_build_object('record_date', obo.record_date, 'accounts', obo.obo_accounts, 'shares', obo.obo_shares, 'pct_of_accounts', obo.obo_pct_of_accounts) END
    );
END$$;

-- ---------------------------------------------------------------------------------------------
-- THE FIVE SHARES (K7). As the application; an issuer with a restriction list is never shared; no natural person crosses.
-- An issuer is named by id, ticker or CIK.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_share_issuer(p_issuer text) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT i.id FROM issuers i
     WHERE i.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM issuer_members r WHERE r.issuer_id = i.id)
       AND (i.id::text = p_issuer OR upper(i.ticker) = upper(p_issuer) OR i.cik = lpad(p_issuer, 10, '0'))
     ORDER BY i.id LIMIT 1;
$$;
CREATE OR REPLACE FUNCTION si_share_filings_index(p_consumer text, p_issuer text, p_since date DEFAULT NULL, p_forms text[] DEFAULT NULL, p_limit integer DEFAULT 100, p_offset integer DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE iss bigint := si_share_issuer(p_issuer); rows_ jsonb; tot bigint;
BEGIN
    IF p_consumer IS NULL OR p_consumer = '' THEN RETURN jsonb_build_object('error', 'consumer_unknown'); END IF;
    IF iss IS NULL THEN RETURN jsonb_build_object('error', 'not_found'); END IF;
    SELECT count(*) INTO tot FROM filings WHERE issuer_id = iss AND (p_since IS NULL OR filed_on >= p_since) AND (p_forms IS NULL OR form = ANY (p_forms));
    SELECT COALESCE(jsonb_agg(jsonb_build_object('filing_id', f.id, 'accession_number', f.accession_number, 'form', f.form, 'filed_on', f.filed_on, 'report_date', f.report_date,
               'title', COALESCE(f.primary_doc_description, f.form), 'items', f.items, 'url', f.url, 'about_us', f.about_us, 'has_text', f.fetched_text_at IS NOT NULL, 'char_count', f.char_count) ORDER BY f.filed_on DESC, f.id DESC), '[]'::jsonb)
      INTO rows_ FROM (SELECT * FROM filings WHERE issuer_id = iss AND (p_since IS NULL OR filed_on >= p_since) AND (p_forms IS NULL OR form = ANY (p_forms))
                        ORDER BY filed_on DESC, id DESC LIMIT LEAST(GREATEST(COALESCE(p_limit, 100), 1), 500) OFFSET GREATEST(COALESCE(p_offset, 0), 0)) f;
    RETURN jsonb_build_object('schema', 'os.si-filings/1', 'application', 'shareholder_intelligence', 'issuer', (SELECT jsonb_build_object('issuer_id', id, 'name', name, 'ticker', ticker, 'cik', cik) FROM issuers WHERE id = iss),
                              'filings', rows_, 'total', tot);
END$$;
CREATE OR REPLACE FUNCTION si_share_filing_text(p_consumer text, p_filing bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE f filings%ROWTYPE; t filing_text%ROWTYPE;
BEGIN
    IF p_consumer IS NULL OR p_consumer = '' THEN RETURN jsonb_build_object('error', 'consumer_unknown'); END IF;
    SELECT * INTO f FROM filings WHERE id = p_filing;
    IF NOT FOUND OR si_share_issuer(f.issuer_id::text) IS NULL THEN RETURN jsonb_build_object('error', 'not_found'); END IF;
    SELECT * INTO t FROM filing_text WHERE filing_id = p_filing;
    IF NOT FOUND THEN RETURN jsonb_build_object('error', 'no_text', 'filing_id', p_filing); END IF;
    RETURN jsonb_build_object('schema', 'os.si-filing-text/1', 'application', 'shareholder_intelligence', 'filing_id', f.id, 'issuer_id', f.issuer_id, 'form', f.form, 'filed_on', f.filed_on,
                              'url', f.url, 'extractor', t.extractor, 'truncated', length(t.text) > 5000000, 'text', left(t.text, 5000000));
END$$;
CREATE OR REPLACE FUNCTION si_share_ownership_summary(p_consumer text, p_issuer text, p_as_of date DEFAULT CURRENT_DATE) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE iss bigint := si_share_issuer(p_issuer); sec bigint;
BEGIN
    IF p_consumer IS NULL OR p_consumer = '' THEN RETURN jsonb_build_object('error', 'consumer_unknown'); END IF;
    IF iss IS NULL THEN RETURN jsonb_build_object('error', 'not_found'); END IF;
    SELECT id INTO sec FROM securities WHERE issuer_id = iss AND is_primary;
    IF sec IS NULL THEN RETURN jsonb_build_object('error', 'no_primary_security'); END IF;
    RETURN jsonb_build_object('schema', 'os.si-ownership/1', 'application', 'shareholder_intelligence', 'issuer_id', iss) || si_ownership_summary(sec, COALESCE(p_as_of, CURRENT_DATE));
END$$;
CREATE OR REPLACE FUNCTION si_share_signals_open(p_consumer text, p_issuer text) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE iss bigint := si_share_issuer(p_issuer); rows_ jsonb;
BEGIN
    IF p_consumer IS NULL OR p_consumer = '' THEN RETURN jsonb_build_object('error', 'consumer_unknown'); END IF;
    IF iss IS NULL THEN RETURN jsonb_build_object('error', 'not_found'); END IF;
    SELECT COALESCE(jsonb_agg(jsonb_build_object('signal_id', s.id, 'rule', r.key, 'rule_name', r.name, 'evidence_class', r.evidence_class, 'severity', s.severity, 'raised_on', s.raised_on,
               'window_from', s.window_from, 'window_to', s.window_to, 'summary', s.summary) ORDER BY CASE s.severity WHEN 'high' THEN 1 WHEN 'watch' THEN 2 ELSE 3 END, s.raised_on DESC), '[]'::jsonb)
      INTO rows_ FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.issuer_id = iss AND s.status = 'open';
    RETURN jsonb_build_object('schema', 'os.si-signals/1', 'application', 'shareholder_intelligence', 'issuer_id', iss, 'signals', rows_);
END$$;
CREATE OR REPLACE FUNCTION si_share_events_index(p_consumer text, p_issuer text, p_from date DEFAULT NULL, p_to date DEFAULT NULL, p_limit integer DEFAULT 100) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE iss bigint := si_share_issuer(p_issuer); rows_ jsonb;
BEGIN
    IF p_consumer IS NULL OR p_consumer = '' THEN RETURN jsonb_build_object('error', 'consumer_unknown'); END IF;
    IF iss IS NULL THEN RETURN jsonb_build_object('error', 'not_found'); END IF;
    SELECT COALESCE(jsonb_agg(jsonb_build_object('event_id', e.id, 'on_date', e.on_date, 'kind', e.kind, 'title', e.title, 'severity', e.severity, 'url', e.url, 'filing_id', e.filing_id) ORDER BY e.on_date DESC, e.id DESC), '[]'::jsonb)
      INTO rows_ FROM (SELECT * FROM events WHERE issuer_id = iss AND deleted_at IS NULL AND (p_from IS NULL OR on_date >= p_from) AND (p_to IS NULL OR on_date <= p_to)
                        ORDER BY on_date DESC, id DESC LIMIT LEAST(GREATEST(COALESCE(p_limit, 100), 1), 500)) e;
    RETURN jsonb_build_object('schema', 'os.si-events/1', 'application', 'shareholder_intelligence', 'issuer_id', iss, 'from', p_from, 'to', p_to, 'events', rows_);
END$$;

-- ---------------------------------------------------------------------------------------------
-- The admin's reads
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_source_health()
    RETURNS TABLE (source_id bigint, name text, connector text, active boolean, paused boolean, due boolean, last_ok_at timestamptz, last_status text, last_error text,
                   consecutive_failures integer, backoff_until timestamptz, has_credential boolean, needs_user_agent boolean, user_agent_set boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE ua boolean := si_edgar_user_agent() IS NOT NULL;
BEGIN
    IF NOT si_is_member_here() THEN RETURN; END IF;
    RETURN QUERY
    SELECT s.id, s.name, s.connector, s.active, s.paused_at IS NOT NULL, EXISTS (SELECT 1 FROM si_sources_due() d WHERE d.id = s.id), s.last_ok_at,
           (SELECT p.status FROM source_pulls p WHERE p.id = s.last_pull_id), (SELECT p.error FROM source_pulls p WHERE p.id = s.last_pull_id),
           s.consecutive_failures, s.backoff_until, s.credential_id IS NOT NULL, s.connector LIKE 'edgar_%', ua
      FROM sources s ORDER BY s.id;
END$$;
CREATE OR REPLACE FUNCTION si_tombstones()
    RETURNS TABLE (kind text, record_id bigint, label text, issuer_id bigint, deleted_at timestamptz, deleted_by bigint, purge_after timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE days integer := (SELECT tombstone_days FROM si_settings WHERE id = 1);
BEGIN
    IF NOT si_is_manager() THEN RETURN; END IF;
    RETURN QUERY
    SELECT 'upload', i.id, i.kind || ': ' || i.file_name, i.issuer_id, i.deleted_at, i.deleted_by, i.deleted_at + make_interval(days => days) FROM imports i WHERE i.deleted_at IS NOT NULL
    UNION ALL SELECT 'holder', h.id, CASE WHEN si_sees_holder_pii() OR h.kind <> 'person' THEN h.display_name ELSE 'Individual holder' END, h.issuer_id, h.deleted_at, h.deleted_by, h.deleted_at + make_interval(days => days)
                FROM holders h WHERE h.deleted_at IS NOT NULL AND h.merged_into_id IS NULL
    UNION ALL SELECT 'event', e.id, e.title, e.issuer_id, e.deleted_at, e.deleted_by, e.deleted_at + make_interval(days => days) FROM events e WHERE e.deleted_at IS NOT NULL
    UNION ALL SELECT 'report', r.id, r.title, r.issuer_id, r.deleted_at, NULL::bigint, r.deleted_at + make_interval(days => days) FROM reports r WHERE r.deleted_at IS NOT NULL
    UNION ALL SELECT 'issuer', x.id, x.name, x.id, x.deleted_at, x.deleted_by, x.deleted_at + make_interval(days => days) FROM issuers x WHERE x.deleted_at IS NOT NULL
    ORDER BY 5 DESC;
END$$;

-- dispatch_cancel
ALTER TABLE agent_dispatches DROP CONSTRAINT agent_dispatches_status_check;
ALTER TABLE agent_dispatches ADD CONSTRAINT agent_dispatches_status_check CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval', 'cancelled'));

CREATE OR REPLACE VIEW mcp_holder_kind_proposals WITH (security_barrier = true) AS
SELECT k.id AS proposal_id, k.holder_id, k.kind, k.entity_type, k.evidence, k.proposed_by, k.status, k.decided_by, k.decided_at, k.created_at
  FROM holder_kind_proposals k WHERE (SELECT si_sees_holder_pii()) AND k.holder_id IN (SELECT holder_id FROM mcp_holders);

GRANT SELECT, INSERT, UPDATE, DELETE ON holder_kind_proposals TO shareholder_intelligence_rw;
GRANT SELECT ON mcp_holder_kind_proposals TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_analyst_proposals(bigint, text), si_holders_changes_summary(bigint, text, date, date), si_ownership_summary(bigint, date), si_source_health(), si_tombstones()
    TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
-- the shares run as the application for the kernel's token (the records server's kernel gate); never as a member
REVOKE ALL ON FUNCTION si_share_issuer(text), si_share_filings_index(text, text, date, text[], integer, integer), si_share_filing_text(text, bigint), si_share_ownership_summary(text, text, date),
    si_share_signals_open(text, text), si_share_events_index(text, text, date, date, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_share_issuer(text), si_share_filings_index(text, text, date, text[], integer, integer), si_share_filing_text(text, bigint), si_share_ownership_summary(text, text, date),
    si_share_signals_open(text, text), si_share_events_index(text, text, date, date, integer) TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;
REVOKE ALL ON FUNCTION si_holder_kind_propose(bigint, text, text, text), si_holder_kind_decide(bigint, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_holder_kind_propose(bigint, text, text, text), si_holder_kind_decide(bigint, boolean) TO shareholder_intelligence_rw;

COMMIT;
