-- 016: WHO SEES WHAT — the mcp_* views, the only thing the two read roles see (memory.md §1; design §3). Every WHERE clause IS the
-- row rule; the servers never filter in Python. The issuer wall is `issuer_id IN (SELECT si_my_issuers())` — the caller's set once per
-- statement; the PII wall is `(SELECT si_sees_holder_pii())` once per statement nulling a natural person's columns. Primary keys are
-- re-aliased <entity>_id; raw columns that hide internals are not selected: a token's hash NEVER, a credential's ciphertext NEVER, an
-- outbox body, a session hash, a registration's lines for a Viewer.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now() WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro, shareholder_intelligence_rw;
CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro, shareholder_intelligence_rw;

CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, m.status, m.capability, m.roles, si_member_roles(m.id) AS roles_resolved,
       m.job_title, m.timezone, m.email::text AS email, (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL AND (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at FROM departments d WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.edgar_contact_email IS NOT NULL AND s.business_name IS NOT NULL AS edgar_user_agent_set, s.default_exchange, s.market_timezone, s.market_holidays, s.edgar_rate_per_second,
       s.reconciliation_tolerance_shares, s.auto_match_threshold, s.pii_in_exports, s.morning_note_hour, s.morning_note_enabled, s.analyst_proposals, s.pack_day, s.signal_grace_days, s.tombstone_days,
       s.key_rate_per_minute, s.key_rate_per_day, s.max_upload_bytes, s.price_source_default, s.timezone, CASE WHEN (SELECT si_is_admin()) THEN s.escalate_high_to END AS escalate_high_to, s.updated_at
  FROM si_settings s WHERE (SELECT si_is_member_here());

CREATE OR REPLACE VIEW mcp_issuers WITH (security_barrier = true) AS
SELECT i.id AS issuer_id, i.name, i.cik, i.ticker, i.exchange, i.state_of_incorporation, i.fiscal_year_end, i.sic, i.transfer_agent, i.transfer_agent_connector, i.edgar_synced_at, i.timezone,
       si_issuer_is_restricted(i.id) AS restricted, (SELECT count(*) FROM securities s WHERE s.issuer_id = i.id AND s.active) AS securities_count,
       (SELECT s.id FROM securities s WHERE s.issuer_id = i.id AND s.is_primary) AS primary_security_id, i.notes, i.created_at, i.updated_at
  FROM issuers i WHERE i.deleted_at IS NULL AND i.id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_issuer_members WITH (security_barrier = true) AS
SELECT r.issuer_id, r.member_id, (SELECT display_name FROM members WHERE id = r.member_id) AS display_name, r.added_by, r.added_at FROM issuer_members r WHERE r.issuer_id IN (SELECT si_my_issuers()) AND (SELECT si_is_manager());
CREATE OR REPLACE VIEW mcp_securities WITH (security_barrier = true) AS
SELECT s.id AS security_id, s.issuer_id, (SELECT name FROM issuers WHERE id = s.issuer_id) AS issuer_name, s.class, s.name, s.cusip, s.isin, s.ticker, s.figi, s.par_value, s.is_primary, s.listed_on, s.delisted_on, s.active,
       si_share_count(s.id, 'outstanding') AS outstanding, si_share_count(s.id, 'float') AS float_shares, si_share_count(s.id, 'cede') AS cede_shares,
       (SELECT max(position_date) FROM participant_positions p WHERE p.security_id = s.id) AS last_position_date, s.created_at, s.updated_at
  FROM securities s WHERE s.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_share_counts WITH (security_barrier = true) AS
SELECT c.id AS share_count_id, c.security_id, c.as_of, c.kind, c.value, c.source, c.filing_id, c.import_id, c.xbrl_frame, c.note, c.recorded_by, c.recorded_at
  FROM share_counts c WHERE c.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));

CREATE OR REPLACE VIEW mcp_participant_classes WITH (security_barrier = true) AS
SELECT k.key AS class_key, k.name, k.description, k.side, k.sort_order FROM participant_classes k WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_participants WITH (security_barrier = true) AS
SELECT p.id AS participant_id, p.number, p.name, p.aliases, p.class_key, (SELECT side FROM participant_classes WHERE key = p.class_key) AS side, p.class_source, p.directory_kind,
       p.first_seen_in_directory, p.last_seen_in_directory, p.active, p.notes, si_tags_of('participant', p.id) AS tags, p.updated_at
  FROM participants p WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_participant_positions WITH (security_barrier = true) AS
SELECT pp.security_id, pp.participant_id, (SELECT number FROM participants WHERE id = pp.participant_id) AS number, (SELECT name FROM participants WHERE id = pp.participant_id) AS name,
       (SELECT class_key FROM participants WHERE id = pp.participant_id) AS class_key, pp.position_date, pp.shares, pp.import_id
  FROM participant_positions pp WHERE pp.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));

CREATE OR REPLACE VIEW mcp_imports WITH (security_barrier = true) AS
SELECT i.id AS import_id, i.kind, i.connector, i.file_name, i.issuer_id, i.security_id, i.record_date, i.period_from, i.period_to, i.status, i.rows_made, i.rows_failed, i.control_totals, i.derived_totals,
       i.tied, i.tie_note, i.override_by, i.override_note, i.override_at, i.superseded_by, i.attachment_id, i.log, i.created_by, i.created_at, i.finished_at, i.deleted_at IS NOT NULL AS deleted
  FROM imports i WHERE i.issuer_id IN (SELECT si_my_issuers()) AND (i.deleted_at IS NULL OR (SELECT si_is_manager()));
CREATE OR REPLACE VIEW mcp_nobo_jobs WITH (security_barrier = true) AS
SELECT j.id AS nobo_job_id, j.import_id, j.security_id, j.job_number, j.cusip, j.record_date, j.sort_order, j.nobo_count_header, j.nobo_count_rows, j.shares_total
  FROM nobo_jobs j JOIN imports i ON i.id = j.import_id WHERE i.deleted_at IS NULL AND j.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_nobo_broker_rows WITH (security_barrier = true) AS
SELECT b.id AS nobo_broker_row_id, b.job_id AS nobo_job_id, j.record_date, j.security_id, b.client, b.participant_id, b.nobo_count, b.shares
  FROM nobo_broker_rows b JOIN nobo_jobs j ON j.id = b.job_id JOIN imports i ON i.id = j.import_id WHERE i.deleted_at IS NULL AND j.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));

-- THE PII WALL: holders. A Viewer sees the row (it exists, with its kind, its aggregate facts) but a natural person's name, address and lines are nulled and the name reads "Individual holder".
CREATE OR REPLACE VIEW mcp_holders WITH (security_barrier = true) AS
SELECT h.id AS holder_id, h.issuer_id,
       CASE WHEN h.kind = 'person' AND NOT (SELECT si_sees_holder_pii()) THEN 'Individual holder' ELSE h.display_name END AS display_name,
       h.kind, h.entity_type,
       CASE WHEN h.kind <> 'person' OR (SELECT si_sees_holder_pii()) THEN h.address_line END AS address_line,
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
       CASE WHEN (SELECT si_sees_holder_pii()) OR (SELECT kind FROM holders WHERE id = a.holder_id) <> 'person' THEN a.registration_text END AS registration_text, a.holder_ref, a.first_seen_on, a.last_seen_on
  FROM holder_accounts a WHERE a.holder_id IN (SELECT holder_id FROM mcp_holders);
CREATE OR REPLACE VIEW mcp_holder_positions WITH (security_barrier = true) AS
SELECT hp.holder_id, hp.holder_account_id, hp.security_id, hp.record_date, hp.shares, hp.channel, hp.import_id FROM holder_positions hp WHERE hp.holder_id IN (SELECT holder_id FROM mcp_holders);
CREATE OR REPLACE VIEW mcp_holder_match_proposals WITH (security_barrier = true) AS
SELECT p.id AS proposal_id, p.row_kind, p.row_id, p.issuer_id, p.holder_id, p.score, p.evidence, p.proposed_by, p.status, p.decided_by, p.decided_at, p.created_at
  FROM holder_match_proposals p WHERE p.issuer_id IN (SELECT si_my_issuers()) AND (SELECT si_sees_holder_pii());
-- the raw rows: PII roles only (they ARE the registrations)
CREATE OR REPLACE VIEW mcp_nobo_rows WITH (security_barrier = true) AS
SELECT n.id AS nobo_row_id, n.job_id AS nobo_job_id, n.import_id, n.row_no, n.shares, n.line1, n.line2, n.line3, n.line4, n.line5, n.line6, n.line7, n.zip_sort, n.holder_id, n.holder_account_id, n.custodian_participant_id, n.account_kind, n.error
  FROM nobo_rows n JOIN nobo_jobs j ON j.id = n.job_id WHERE (SELECT si_sees_holder_pii()) AND j.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_register_rows WITH (security_barrier = true) AS
SELECT r.id AS register_row_id, r.import_id, r.security_id, r.record_date, r.row_no, r.section, r.position_id, r.position_type, r.holder_ref, r.registration_name, r.address_line, r.city, r.state, r.country, r.zip, r.issue_date,
       r.shares, r.cost_basis, r.restriction_text, r.percent_owned, r.is_employee, r.is_officer_director, r.is_us_citizen, r.is_cede, r.holder_id, r.holder_account_id, r.error
  FROM register_rows r WHERE (SELECT si_sees_holder_pii()) AND r.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));

CREATE OR REPLACE VIEW mcp_share_reconciliations WITH (security_barrier = true) AS
SELECT r.id AS reconciliation_id, r.security_id, r.as_of, r.outstanding, r.cede_registered, r.cede_source, r.register_date, r.spr_total, r.spr_date, r.drs_book, r.certificated, r.plan, r.registered_other,
       r.difference_registered, r.difference_spr, r.tolerance, r.tied_registered, r.tied_spr, r.tied, r.nobo_shares, r.nobo_date, r.obo_remainder, r.note, r.ran_by, r.ran_at
  FROM share_reconciliations r WHERE r.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));

CREATE OR REPLACE VIEW mcp_sources WITH (security_barrier = true) AS
SELECT s.id AS source_id, s.name, s.connector, s.role, s.base_url, s.settings, s.credential_id IS NOT NULL AS has_credential, s.schedule_minutes, s.rate_per_second, s.robots_state, s.last_ok_at, s.consecutive_failures,
       s.backoff_until, s.paused_at, s.paused_reason, s.active, s.updated_at
  FROM sources s WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_source_pulls WITH (security_barrier = true) AS
SELECT p.id AS pull_id, p.source_id, p.kind, p.started_at, p.finished_at, p.status, p.listings_seen AS items_seen, p.listings_new AS items_new, p.listings_changed AS items_changed, p.http_requests, p.bytes, p.error, p.policy, p.started_by
  FROM source_pulls p WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_source_credentials WITH (security_barrier = true) AS
SELECT c.id AS credential_id, c.source_id, c.kind, c.label, c.last4, c.rotated_at, c.created_at FROM source_credentials c WHERE (SELECT si_is_admin());

CREATE OR REPLACE VIEW mcp_filings WITH (security_barrier = true) AS
SELECT f.id AS filing_id, f.issuer_id, f.filer_cik, f.accession_number, f.form, f.filed_on, f.report_date, f.accepted_at, f.items, f.size, f.is_xbrl, f.is_inline_xbrl, f.primary_document, f.primary_doc_description, f.url, f.about_us,
       f.fetched_text_at IS NOT NULL AS has_text, f.char_count, f.structured_at, f.error, si_watched_form(f.form) AS watched
  FROM filings f WHERE f.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_filing_text WITH (security_barrier = true) AS
SELECT t.filing_id, t.text, t.extractor, t.extracted_at FROM filing_text t WHERE t.filing_id IN (SELECT filing_id FROM mcp_filings);
CREATE OR REPLACE VIEW mcp_events WITH (security_barrier = true) AS
SELECT e.id AS event_id, e.issuer_id, e.security_id, e.on_date, e.kind, e.title, e.body, e.url, e.filing_id, e.severity, e.source, si_tags_of('event', e.id) AS tags, e.created_by, e.created_at, e.updated_at
  FROM events e WHERE e.deleted_at IS NULL AND e.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_dilution_instruments WITH (security_barrier = true) AS
SELECT d.id AS instrument_id, d.issuer_id, d.security_id, d.kind, d.counterparty, d.filed_on, d.filing_id, d.facility_amount, d.currency, d.remaining_amount, d.price_terms, d.floating_price, d.shares_issuable_max, d.expires_on,
       d.status, d.proposed_by, d.watch_term, d.confirmed_by, d.confirmed_at, d.notes, d.updated_at
  FROM dilution_instruments d WHERE d.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_watch_hits WITH (security_barrier = true) AS
SELECT w.id AS watch_hit_id, w.issuer_id, w.filing_id, w.term, w.snippet, w.found_at, w.status, w.decided_by, w.decided_at FROM watch_hits w WHERE w.issuer_id IN (SELECT si_my_issuers());

CREATE OR REPLACE VIEW mcp_institutions WITH (security_barrier = true) AS
SELECT i.id AS institution_id, i.cik, i.name, i.kind, i.kind_source, i.is_activist, i.aliases, i.website, i.notes, si_tags_of('institution', i.id) AS tags, i.updated_at FROM institutions i WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_institutional_holdings WITH (security_barrier = true) AS
SELECT h.id AS holding_id, h.institution_id, (SELECT name FROM institutions WHERE id = h.institution_id) AS institution_name, h.security_id, h.period_end, h.filing_id, h.accession_number, h.value_dollars, h.shares, h.shares_type, h.put_call,
       h.discretion, h.other_managers, h.voting_sole, h.voting_shared, h.voting_none, h.is_amendment, h.loaded_at
  FROM institutional_holdings h WHERE h.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_beneficial_ownership WITH (security_barrier = true) AS
SELECT b.id AS beneficial_id, b.institution_id, (SELECT name FROM institutions WHERE id = b.institution_id) AS institution_name, b.security_id, b.filing_id, b.schedule, b.is_amendment, b.amendment_no, b.filed_on, b.event_date, b.percent_of_class,
       b.shares_beneficially_owned, b.sole_voting, b.shared_voting, b.sole_dispositive, b.shared_dispositive, b.purpose_text, b.group_members, b.filer_type, b.loaded_at
  FROM beneficial_ownership b WHERE b.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_insider_transactions WITH (security_barrier = true) AS
SELECT t.id AS transaction_id, t.issuer_id, t.security_id, t.filing_id, t.form, t.reporting_owner_cik, t.reporting_owner_name, t.holder_id, t.relationship, t.officer_title, t.transaction_date, t.transaction_code, t.shares, t.price,
       t.acquired_disposed, t.shares_owned_after, t.ownership, t.indirect_nature, t.security_title, t.is_derivative, t.exercise_price, t.expiration_date, t.footnotes, t.is_10b5_1, t.loaded_at
  FROM insider_transactions t WHERE t.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_form144_notices WITH (security_barrier = true) AS
SELECT n.id AS notice_id, n.issuer_id, n.security_id, n.filing_id, n.filed_on, n.seller_name, n.seller_cik, n.relationship, n.shares_to_sell, n.aggregate_market_value, n.approx_sale_date, n.broker, n.shares_outstanding_stated
  FROM form144_notices n WHERE n.issuer_id IN (SELECT si_my_issuers());

CREATE OR REPLACE VIEW mcp_short_interest WITH (security_barrier = true) AS
SELECT s.id AS short_interest_id, s.security_id, s.settlement_date, s.published_on, s.short_shares, s.previous_short_shares, s.change_shares, s.change_percent, s.avg_daily_volume, s.days_to_cover, s.revision_flag, s.market, s.source_id, s.observed_at
  FROM short_interest s WHERE s.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_daily_short_volume WITH (security_barrier = true) AS
SELECT d.security_id, d.trade_date, d.market, d.short_volume, d.short_exempt_volume, d.total_volume, d.ratio, 'Off-exchange only; not comparable with short interest (FINRA).'::text AS caveat
  FROM daily_short_volume d WHERE d.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_fails_to_deliver WITH (security_barrier = true) AS
SELECT f.security_id, f.settlement_date, f.quantity, f.price, f.file_half FROM fails_to_deliver f WHERE f.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_threshold_listings WITH (security_barrier = true) AS
SELECT t.security_id, t.list_date, t.list, t.flag, t.rule_3210 FROM threshold_listings t WHERE t.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_borrow_observations WITH (security_barrier = true) AS
SELECT b.id AS observation_id, b.security_id, b.observed_on, b.source, b.fee_rate_percent, b.utilization_percent, b.shares_on_loan, b.shares_available, b.note, b.recorded_by, b.recorded_at
  FROM borrow_observations b WHERE b.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_prices WITH (security_barrier = true) AS
SELECT p.security_id, p.trade_date, p.open, p.high, p.low, p.close, p.volume FROM prices p WHERE p.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));

CREATE OR REPLACE VIEW mcp_signal_rules WITH (security_barrier = true) AS
SELECT r.id AS rule_id, r.key, r.name, r.description, r.evidence_class, r.basis, r.severity_default, r.params, r.window_days, r.applies_to, r.enabled, r.sort_order,
       (SELECT max(raised_on) FROM signals WHERE rule_id = r.id) AS last_fired_on, r.updated_at
  FROM signal_rules r WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_signals WITH (security_barrier = true) AS
SELECT s.id AS signal_id, s.rule_id, (SELECT key FROM signal_rules WHERE id = s.rule_id) AS rule_key, (SELECT name FROM signal_rules WHERE id = s.rule_id) AS rule_name,
       (SELECT evidence_class FROM signal_rules WHERE id = s.rule_id) AS evidence_class, s.issuer_id, s.security_id, s.raised_on, s.window_from, s.window_to, s.severity, s.value, s.threshold, s.baseline, s.evidence, s.summary,
       s.status, s.disposition_reason, s.disposition_note, s.disposed_by, s.disposed_at, s.event_id, s.proposal_by, s.proposal_reason, s.proposal_note, s.proposal_event_id, s.proposal_at, s.repeat_count, s.expires_on, s.created_at, s.updated_at
  FROM signals s WHERE s.issuer_id IN (SELECT si_my_issuers());
CREATE OR REPLACE VIEW mcp_signal_outcomes WITH (security_barrier = true) AS
SELECT o.signal_id, o.price_at, o.price_5d, o.price_20d, o.price_60d, o.return_5d, o.return_20d, o.return_60d, o.updated_at FROM signal_outcomes o WHERE o.signal_id IN (SELECT signal_id FROM mcp_signals);

CREATE OR REPLACE VIEW mcp_reports WITH (security_barrier = true) AS
SELECT r.id AS report_id, r.issuer_id, r.kind, r.title, r.period_from, r.period_to, r.status, r.narrative, r.sections, r.rendered_path IS NOT NULL AS rendered, r.pdf_path IS NOT NULL AS has_pdf, r.drafted_by, r.approved_by, r.approved_at, r.created_at, r.updated_at
  FROM reports r WHERE r.deleted_at IS NULL AND r.issuer_id IN (SELECT si_my_issuers()) AND (r.status <> 'draft' OR (SELECT si_has_right('reports.run')));
CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, n.body, n.created_at FROM notes n
 WHERE (SELECT si_is_member_here()) AND (n.record_type <> 'holder' OR n.record_id IN (SELECT holder_id FROM mcp_holders WHERE NOT pii_withheld));
CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.uploaded_by, a.created_at FROM attachments a WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_tags WITH (security_barrier = true) AS
SELECT t.id AS tag_id, t.slug, t.label, t.usage_count FROM tags t WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_import_templates WITH (security_barrier = true) AS
SELECT t.id AS template_id, t.name, t.kind, t.connector, t.mapping, t.updated_at FROM import_templates t WHERE (SELECT si_is_member_here());
CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at, n.signal_id, n.issuer_id FROM notifications n WHERE n.member_id = app_current_member_id();
CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.record_type, d.record_id, d.agent_member_id, d.kind, d.via, d.acting_member_id, d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.signal_id, d.filing_id, d.import_id
  FROM agent_dispatches d WHERE (SELECT si_has_right('agents.settings')) OR d.agent_member_id = app_current_member_id();
CREATE OR REPLACE VIEW mcp_keys WITH (security_barrier = true) AS
SELECT t.id AS key_id, t.member_id, t.label, t.scope, t.rate_per_minute, t.rate_per_day, t.rotated_from, t.last_used_at, t.expires_at, t.revoked_at, t.created_at, si_key_calls_today(t.id) AS calls_today
  FROM mcp_access_tokens t WHERE t.scope = 'api' AND (t.member_id = app_current_member_id() OR (SELECT si_has_right('tokens.manage')));
CREATE OR REPLACE VIEW mcp_exports WITH (security_barrier = true) AS
SELECT e.id AS export_id, e.kind, e.format, e.status, e.byte_size, e.item_count, e.issuer_id, e.security_id, e.includes_pii, e.report_id, e.created_by, e.created_at, e.finished_at, e.expires_at, e.downloaded_at
  FROM exports e WHERE e.created_by = app_current_member_id() OR (SELECT si_has_right('export.shared'));

-- the activity server's window: the log for the issuers the caller may see, never a payload with PII (the funnel kept none)
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, (SELECT display_name FROM members WHERE id = a.actor_member_id) AS actor_name, a.source, a.action, a.screen, a.route, a.entity_type, a.entity_id,
       a.before, a.after, a.request_id, a.agent_run_id, a.department_id, a.issuer_id, a.security_id, a.import_id, a.signal_id, a.token_id
  FROM activity_log a WHERE (SELECT si_is_member_here()) AND (a.issuer_id IS NULL OR a.issuer_id IN (SELECT si_my_issuers()));

GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_issuers, mcp_issuer_members, mcp_securities, mcp_share_counts, mcp_participant_classes, mcp_participants, mcp_participant_positions, mcp_imports, mcp_nobo_jobs,
    mcp_nobo_broker_rows, mcp_holders, mcp_holder_accounts, mcp_holder_positions, mcp_holder_match_proposals, mcp_nobo_rows, mcp_register_rows, mcp_share_reconciliations, mcp_sources, mcp_source_pulls, mcp_source_credentials,
    mcp_filings, mcp_filing_text, mcp_events, mcp_dilution_instruments, mcp_watch_hits, mcp_institutions, mcp_institutional_holdings, mcp_beneficial_ownership, mcp_insider_transactions, mcp_form144_notices, mcp_short_interest,
    mcp_daily_short_volume, mcp_fails_to_deliver, mcp_threshold_listings, mcp_borrow_observations, mcp_prices, mcp_signal_rules, mcp_signals, mcp_signal_outcomes, mcp_reports, mcp_notes, mcp_attachments, mcp_tags,
    mcp_import_templates, mcp_notifications, mcp_agent_dispatches, mcp_keys, mcp_exports
  TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;
GRANT SELECT ON mcp_activity_log, mcp_members, mcp_departments, mcp_issuers, mcp_securities TO shareholder_intelligence_activity_ro;
GRANT SELECT ON mcp_activity_log TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;

COMMIT;
