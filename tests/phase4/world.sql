-- The Phase 4 world, on top of slice 9's store (SMOKE Alpha / Beta / Gamma) and slice 7's crafted fixtures (SMOKE ISSUER INC, SMOKE SECOND CORP): what the tools read that neither made.
-- Run by tests/phase4/lib.php p4_world() as postgres from the repository root (the \copy paths are relative), after both seeds:
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d <scratch> -f tests/phase4/world.sql
-- Everything is a scratch row; nothing of a real person (the register and NOBO fixtures are the anonymised ones). The last line is the ids.
\set QUIET on
\set ON_ERROR_STOP on
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
GRANT EXECUTE ON FUNCTION pg_temp.i(text), pg_temp.keep(text, bigint) TO PUBLIC;
SET ROLE shareholder_intelligence_rw;
SELECT set_config('app.member_id', '27', false);

-- ---- SMOKE DELTA CORP: an issuer whose restriction list names Marco alone (Priya, Dana, Bea and Ann cannot see it) with something in every table the tools read
INSERT INTO issuers (name, cik, ticker, exchange, transfer_agent, created_by) VALUES ('SMOKE DELTA CORP', '0000999004', 'SMKD', 'nasdaq', 'SMOKE Transfer Agent', 27) RETURNING pg_temp.keep('d_iss', id);
INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (pg_temp.i('d_iss'), 27, 27);
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (pg_temp.i('d_iss'), 'common', 'Common stock', '99900D101', 'SMKD', true) RETURNING pg_temp.keep('d_sec', id);
INSERT INTO share_counts (security_id, as_of, kind, value, source) VALUES (pg_temp.i('d_sec'), CURRENT_DATE - 30, 'outstanding', 8000000, 'filing'), (pg_temp.i('d_sec'), CURRENT_DATE - 30, 'float', 5000000, 'manual');
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, period_from, period_to, rows_made, tied, tie_note, created_by, finished_at)
VALUES ('spr', 'SMOKE-DELTA-SPR.csv', 'done', pg_temp.i('d_iss'), pg_temp.i('d_sec'), 'dtc_spr_csv', CURRENT_DATE - 10, CURRENT_DATE - 3, 4, true, 'ties to its Total row', 27, now()) RETURNING pg_temp.keep('d_spr', id);
INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id)
SELECT pg_temp.i('d_sec'), x.id, d.d, x.shares + CASE WHEN d.d = CURRENT_DATE - 3 THEN 100000 ELSE 0 END, pg_temp.i('d_spr')
  FROM ((SELECT id, 4000000 AS shares FROM participants WHERE class_key = 'retail' ORDER BY number LIMIT 1) UNION ALL (SELECT id, 2500000 FROM participants WHERE class_key = 'prime' ORDER BY number LIMIT 1)) x,
       (VALUES (CURRENT_DATE - 10), (CURRENT_DATE - 3)) d (d);
INSERT INTO holders (issuer_id, display_name, name_key, kind, city, state, zip, last_seen_on) VALUES (pg_temp.i('d_iss'), 'SMOKE Delta Person', si_name_key('SMOKE Delta Person'), 'person', 'Delta City', 'IL', '60601', CURRENT_DATE - 3) RETURNING pg_temp.keep('d_hp', id);
INSERT INTO holders (issuer_id, display_name, name_key, kind, last_seen_on) VALUES (pg_temp.i('d_iss'), 'SMOKE Delta Holdings LLC', si_name_key('SMOKE Delta Holdings LLC'), 'entity', CURRENT_DATE - 3) RETURNING pg_temp.keep('d_he', id);
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by, finished_at) VALUES ('register', 'SMOKE-DELTA-register.xlsx', 'done', pg_temp.i('d_iss'), pg_temp.i('d_sec'), 'stc_position_detail', CURRENT_DATE - 3, true, 27, now()) RETURNING pg_temp.keep('d_reg', id);
INSERT INTO holder_accounts (holder_id, channel, account_key, registration_text, first_seen_on, last_seen_on) VALUES (pg_temp.i('d_hp'), 'register', 'acct-d1', 'SMOKE DELTA PERSON, 9 Delta Way', CURRENT_DATE - 3, CURRENT_DATE - 3) RETURNING pg_temp.keep('d_a1', id);
INSERT INTO holder_accounts (holder_id, channel, account_key, registration_text, first_seen_on, last_seen_on) VALUES (pg_temp.i('d_he'), 'register', 'acct-d2', 'SMOKE DELTA HOLDINGS LLC', CURRENT_DATE - 3, CURRENT_DATE - 3) RETURNING pg_temp.keep('d_a2', id);
INSERT INTO holder_positions (holder_id, holder_account_id, security_id, record_date, shares, channel, import_id) VALUES
    (pg_temp.i('d_hp'), pg_temp.i('d_a1'), pg_temp.i('d_sec'), CURRENT_DATE - 3, 1500, 'register', pg_temp.i('d_reg')), (pg_temp.i('d_he'), pg_temp.i('d_a2'), pg_temp.i('d_sec'), CURRENT_DATE - 3, 900000, 'register', pg_temp.i('d_reg'));
INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, primary_document, primary_doc_description, about_us, fetched_text_at, char_count)
VALUES (pg_temp.i('d_iss'), '0000999004', '0000999004-24-000001', '8-K', CURRENT_DATE - 2, 'd8k.htm', 'Delta current report', false, now(), 55) RETURNING pg_temp.keep('d_fil', id);
INSERT INTO filing_text (filing_id, extractor, text) VALUES (pg_temp.i('d_fil'), 'html', 'SMOKE DELTA SECRET TEXT: the board approved a thing nobody else may read.');
INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity, source, created_by) VALUES (pg_temp.i('d_iss'), pg_temp.i('d_sec'), CURRENT_DATE - 1, 'press', 'SMOKE DELTA SECRET EVENT', 'watch', 'person', 27) RETURNING pg_temp.keep('d_ev', id);
INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, value, threshold, summary, expires_on, evidence)
SELECT r.id, pg_temp.i('d_iss'), pg_temp.i('d_sec'), CURRENT_DATE - 1, CURRENT_DATE - 15, CURRENT_DATE - 1, 'high', 25, 20, 'SMOKE DELTA SECRET SIGNAL: short interest is 25.0% of the float', CURRENT_DATE + 20, '{}'::jsonb FROM signal_rules r WHERE r.key = 'si_over_float' RETURNING pg_temp.keep('d_sig', id);
INSERT INTO dilution_instruments (issuer_id, security_id, kind, counterparty, filed_on, status, facility_amount) VALUES (pg_temp.i('d_iss'), pg_temp.i('d_sec'), 'atm', 'SMOKE DELTA SECRET COUNTERPARTY', CURRENT_DATE - 5, 'active', 3000000);
INSERT INTO short_interest (security_id, settlement_date, short_shares, avg_daily_volume, days_to_cover) VALUES (pg_temp.i('d_sec'), CURRENT_DATE - 15, 1500000, 100000, 15);
INSERT INTO reports (issuer_id, kind, title, period_from, period_to, status, narrative, sections, drafted_by, approved_by, approved_at) VALUES (pg_temp.i('d_iss'), 'weekly_pack', 'SMOKE DELTA SECRET PACK', CURRENT_DATE - 7, CURRENT_DATE, 'approved', 'secret', '["movement"]', 27, 27, now()) RETURNING pg_temp.keep('d_rep', id);
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('issuer', pg_temp.i('d_iss'), 27, 'SMOKE DELTA SECRET NOTE');

-- ---- SMOK: the holders the matcher makes from the register (its 842 rows), a second register date, the NOBO rows of both jobs, Broadridge's share ranges
SELECT set_config('app.member_id', '26', false);
SELECT pg_temp.keep('smok_sec', (SELECT id FROM securities WHERE cusip = '07831X206'));
SELECT pg_temp.keep('smok_iss', (SELECT issuer_id FROM securities WHERE cusip = '07831X206'));
SELECT pg_temp.keep('reg', (SELECT id FROM imports WHERE kind = 'register' AND security_id = pg_temp.i('smok_sec') ORDER BY id LIMIT 1));
SELECT * FROM si_match_import(pg_temp.i('reg'));
-- the register of the month before: the first 120 rows, a tenth of them cut, two dropped (so holders go "lost", "down", "same")
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by, finished_at) VALUES ('register', 'register_stc_march.xlsx', 'done', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), 'stc_position_detail', '2024-03-04', true, 26, now()) RETURNING pg_temp.keep('reg2', id);
INSERT INTO register_rows (import_id, security_id, record_date, row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, issue_date, shares)
SELECT pg_temp.i('reg2'), security_id, '2024-03-04', row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, issue_date,
       CASE WHEN row_no % 10 = 0 THEN round(shares * 0.9) ELSE shares END FROM register_rows WHERE import_id = pg_temp.i('reg') AND row_no > 20 AND row_no <= 160 AND row_no NOT IN (30, 31);
INSERT INTO register_rows (import_id, security_id, record_date, row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, shares) VALUES
 (pg_temp.i('reg2'), pg_temp.i('smok_sec'), '2024-03-04', 9001, 'Common', '1', 'PHY', 'L1', 'SMOKE LOST HOLDER ONE', '1 Lost Street', 'LOSTVILLE', 'IL', 'US', '60000', 100),
 (pg_temp.i('reg2'), pg_temp.i('smok_sec'), '2024-03-04', 9002, 'Common', '1', 'PHY', 'L2', 'SMOKE LOST HOLDER TWO LLC', '2 Lost Street', 'LOSTVILLE', 'IL', 'US', '60000', 50000);
SELECT * FROM si_match_import(pg_temp.i('reg2'));
-- the NOBO rows (the anonymised fixture): the April job's first 120 rows, the March job's first 70 (50 holders gone)
CREATE TEMP TABLE t_nobo (row_no integer, shares numeric, line1 text, line2 text, line3 text, line4 text, line5 text, line6 text, line7 text, zip_sort text, cusip text);
\copy t_nobo FROM 'tests/fixtures/nobo_rows.csv' WITH (FORMAT csv, HEADER true)
SELECT pg_temp.keep('nobo', (SELECT import_id FROM nobo_jobs WHERE job_number = 'O08888'));
SELECT pg_temp.keep('nobo2', (SELECT import_id FROM nobo_jobs WHERE job_number = 'O07777'));
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip)
SELECT (SELECT id FROM nobo_jobs WHERE job_number = 'O08888'), pg_temp.i('nobo'), row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip FROM t_nobo WHERE row_no <= 130;
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip)
SELECT (SELECT id FROM nobo_jobs WHERE job_number = 'O07777'), pg_temp.i('nobo2'), row_no, CASE WHEN row_no % 7 = 0 THEN round(shares * 1.5) ELSE shares END, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip FROM t_nobo WHERE row_no <= 80;
UPDATE nobo_jobs SET nobo_count_rows = (SELECT count(*) FROM nobo_rows n WHERE n.job_id = nobo_jobs.id), shares_total = (SELECT sum(shares) FROM nobo_rows n WHERE n.job_id = nobo_jobs.id);
SELECT * FROM si_match_import(pg_temp.i('nobo'));
SELECT * FROM si_match_import(pg_temp.i('nobo2'));
-- the rows the matcher leaves: one with no holder and no candidate, one with a candidate waiting for a person, one that failed to type
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, zip_sort, cusip) SELECT j.id, j.import_id, 9001, 777, 'SMOKE UNMATCHED PERSON ZZZ', '1 Nowhere Rd', 'NOWHERE ZZ 00000', '00000', '07831X206' FROM nobo_jobs j WHERE job_number = 'O08888';
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, zip_sort, cusip) SELECT j.id, j.import_id, 9002, 555, 'SMOKE CANDIDATE PERSON ZZ', '2 Nowhere Rd', 'NOWHERE ZZ 00000', '00000', '07831X206' FROM nobo_jobs j WHERE job_number = 'O08888';
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, error, cusip) SELECT j.id, j.import_id, 9003, NULL, NULL, 'no name line', '07831X206' FROM nobo_jobs j WHERE job_number = 'O08888';
INSERT INTO holder_match_proposals (row_kind, row_id, issuer_id, holder_id, score, evidence, proposed_by) SELECT 'nobo', n.id, pg_temp.i('smok_iss'), (SELECT id FROM holders WHERE issuer_id = pg_temp.i('smok_iss') AND kind = 'person' ORDER BY id LIMIT 1), 0.8, '{"name": "0.8", "address": "zip"}'::jsonb, 42
  FROM nobo_rows n WHERE n.row_no = 9002;
-- an agent's kind proposal
INSERT INTO holder_kind_proposals (holder_id, kind, entity_type, evidence, proposed_by) SELECT id, 'entity', 'trust', 'the name ends in Trust', 42 FROM holders WHERE issuer_id = pg_temp.i('smok_iss') AND kind = 'person' ORDER BY id LIMIT 1;
-- Broadridge's Share Range Analysis
CREATE TEMP TABLE t_sr (population text, range_label text, range_from numeric, range_to numeric, accounts integer, shares numeric);
\copy t_sr FROM 'tests/fixtures/share_range_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by, finished_at) VALUES ('share_range', 'share_range_april.xls', 'done', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), 'broadridge_share_range_xls', '2024-04-04', true, 26, now()) RETURNING pg_temp.keep('sr', id);
INSERT INTO share_range_reports (import_id, security_id, record_date, job_number, source_kind) VALUES (pg_temp.i('sr'), pg_temp.i('smok_sec'), '2024-04-04', 'N96192', 'broadridge_xls') RETURNING pg_temp.keep('srr', id);
INSERT INTO share_range_rows (report_id, population, range_label, range_from, range_to, accounts, shares) SELECT pg_temp.i('srr'), population, range_label, range_from, range_to, accounts, shares FROM t_sr;

-- ---- the rules run as of the day the fixtures were crafted for; one signal proposed by the Analyst agent, one disposed by Priya; their outcomes
SELECT set_config('app.member_id', '27', false);
SELECT * FROM si_rules_run(pg_temp.i('smok_sec'), '2024-04-05');
SELECT * FROM si_rules_run((SELECT id FROM securities WHERE cusip = '88888X100'), '2024-04-05');
SELECT pg_temp.keep('sig_open', (SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.security_id = pg_temp.i('smok_sec') AND r.key = 'si_over_float' AND s.status = 'open' ORDER BY s.id LIMIT 1));
SELECT pg_temp.keep('sig_prop', (SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.security_id = pg_temp.i('smok_sec') AND r.key = 'ftd_spike' AND s.status = 'open' ORDER BY s.id LIMIT 1));
SELECT pg_temp.keep('sig_disp', (SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.security_id = pg_temp.i('smok_sec') AND r.key = 'insider_sales' AND s.status = 'open' ORDER BY s.id LIMIT 1));
SELECT set_config('app.member_id', '42', false);
SELECT si_signal_propose(pg_temp.i('sig_prop'), 'known', 'p4: the agent proposes it is known', NULL);
SELECT set_config('app.member_id', '26', false);
SELECT si_signal_dispose(pg_temp.i('sig_disp'), 'acknowledged', 'known', 'p4: Priya knows');
SELECT si_signal_outcomes_update(100);

-- ---- notes, a tag, packs, an export, a key and its usage, dispatches
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('issuer', pg_temp.i('smok_iss'), 26, 'p4 note on SMOK'), ('signal', pg_temp.i('sig_open'), 26, 'p4 note on a signal'),
    ('holder', (SELECT id FROM holders WHERE issuer_id = pg_temp.i('smok_iss') AND kind = 'person' ORDER BY id LIMIT 1), 26, 'p4 note on a person holder'),
    ('holder', (SELECT id FROM holders WHERE issuer_id = pg_temp.i('smok_iss') AND kind = 'entity' ORDER BY id LIMIT 1), 26, 'p4 note on an entity holder');
SELECT si_tag_ensure('p4 watch', 26);
INSERT INTO record_tags (record_type, record_id, tag_id, added_by) SELECT 'issuer', pg_temp.i('smok_iss'), id, 26 FROM tags WHERE label = 'p4 watch';
INSERT INTO record_tags (record_type, record_id, tag_id, added_by) SELECT 'holder', (SELECT id FROM holders WHERE issuer_id = pg_temp.i('smok_iss') AND kind = 'entity' ORDER BY id LIMIT 1), id, 26 FROM tags WHERE label = 'p4 watch';
INSERT INTO reports (issuer_id, kind, title, period_from, period_to, status, narrative, sections, drafted_by) VALUES (pg_temp.i('smok_iss'), 'weekly_pack', 'p4 DRAFT weekly pack', '2024-03-29', '2024-04-05', 'draft', 'a draft narrative', '["movement", "signals"]', 42) RETURNING pg_temp.keep('rep_draft', id);
INSERT INTO reports (issuer_id, kind, title, period_from, period_to, status, narrative, sections, drafted_by, approved_by, approved_at) VALUES (pg_temp.i('smok_iss'), 'monthly_pack', 'p4 approved monthly pack', '2024-03-01', '2024-03-31', 'approved', 'an approved narrative', '["movement", "flow", "signals"]', 42, 27, now()) RETURNING pg_temp.keep('rep_appr', id);
INSERT INTO exports (kind, format, status, item_count, byte_size, created_by, issuer_id, security_id, includes_pii, expires_at) VALUES ('positions_csv', 'csv', 'done', 20, 4096, 26, pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), false, now() + interval '7 days') RETURNING pg_temp.keep('exp', id);
INSERT INTO exports (kind, format, status, item_count, byte_size, created_by, issuer_id, security_id, includes_pii, expires_at) VALUES ('holders_csv', 'csv', 'done', 100, 8192, 27, pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), true, now() + interval '7 days');
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day) VALUES (26, 'p4 priya key', repeat('a', 64), 'api', 60, 5000) RETURNING pg_temp.keep('key', id);
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day) VALUES (27, 'p4 marco key', repeat('b', 64), 'api', 60, 5000);
INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls, refused) SELECT pg_temp.i('key'), 'day', date_trunc('day', now()) - make_interval(days => n), 10 + n, n FROM generate_series(0, 3) n;
INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, reply_excerpt, answered_at, signal_id, attempts) VALUES
    ('signal', pg_temp.i('sig_open'), 42, 'signal', 'chat', 42, 'answered', 'p4 I read it: short interest is over the float.', now(), pg_temp.i('sig_open'), 1),
    ('signal', pg_temp.i('sig_prop'), 40, 'ask', 'chat', 27, 'failed', NULL, NULL, pg_temp.i('sig_prop'), 3);
-- an inactive source with a failed pull (the sources' health)
INSERT INTO source_pulls (source_id, kind, started_at, finished_at, status, error) SELECT id, 'scheduled', now() - interval '1 hour', now() - interval '59 minutes', 'failed', 'p4 the host answered 503' FROM sources WHERE connector = 'finra_short_interest' LIMIT 1;
-- the full-text watch: a hit on the ELOC filing
INSERT INTO watch_hits (issuer_id, filing_id, term, snippet, status) SELECT pg_temp.i('smok_iss'), id, 'equity line of credit', 'p4 ... an equity line of credit with ...', 'new' FROM filings WHERE issuer_id = pg_temp.i('smok_iss') AND form = '8-K' ORDER BY id LIMIT 1;
-- a filing of SMOK with kept text (the tools read it by window; the share by whole)
UPDATE filings SET fetched_text_at = now(), char_count = 9000 WHERE id = (SELECT id FROM filings WHERE issuer_id = pg_temp.i('smok_iss') AND form = '8-K' ORDER BY id LIMIT 1);
INSERT INTO filing_text (filing_id, extractor, text) SELECT id, 'html', repeat('The company entered into an equity line of credit with a counterparty. ', 100) || ' MARKER-NEEDLE ' || repeat('Boilerplate sentence number two. ', 50) FROM filings
 WHERE issuer_id = pg_temp.i('smok_iss') AND form = '8-K' ORDER BY id LIMIT 1 RETURNING pg_temp.keep('ftext', filing_id);
-- ---- the trail (the writers' vocabulary: ids, counts, rule keys; never a name) — Delta's rows are behind the wall with it
INSERT INTO activity_log (occurred_at, actor_member_id, source, action, entity_type, entity_id, after, issuer_id, security_id, import_id, signal_id) VALUES
 (now() - interval '3 days', 26, 'web', 'upload.create', 'import', pg_temp.i('nobo'), '{"kind": "nobo", "rows": 133}', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), pg_temp.i('nobo'), NULL),
 (now() - interval '3 days' + interval '1 minute', 26, 'web', 'upload.tie', 'import', pg_temp.i('nobo'), '{"tied": true}', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), pg_temp.i('nobo'), NULL),
 (now() - interval '2 days', NULL, 'cron', 'signal.raise', 'signal', pg_temp.i('sig_open'), '{"rule": "si_over_float", "severity": "high"}', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), NULL, pg_temp.i('sig_open')),
 (now() - interval '1 day', 42, 'agent', 'signal.propose', 'signal', pg_temp.i('sig_prop'), '{"reason": "known"}', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), NULL, pg_temp.i('sig_prop')),
 (now() - interval '1 day' + interval '1 hour', 26, 'web', 'signal.acknowledge', 'signal', pg_temp.i('sig_disp'), '{"reason": "known"}', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), NULL, pg_temp.i('sig_disp')),
 (now() - interval '5 hours', 42, 'agent', 'report.draft', 'report', pg_temp.i('rep_draft'), '{"title": "p4 DRAFT weekly pack"}', pg_temp.i('smok_iss'), pg_temp.i('smok_sec'), NULL, NULL),
 (now() - interval '4 hours', 26, 'web', 'member.sign_on', 'member', 26, '{}', NULL, NULL, NULL, NULL),
 (now() - interval '2 days', 27, 'web', 'upload.tie', 'import', pg_temp.i('d_spr'), '{"tied": true}', pg_temp.i('d_iss'), pg_temp.i('d_sec'), pg_temp.i('d_spr'), NULL),
 (now() - interval '1 day', 27, 'web', 'signal.raise', 'signal', pg_temp.i('d_sig'), '{"rule": "si_over_float", "title": "SMOKE DELTA SECRET SIGNAL"}', pg_temp.i('d_iss'), pg_temp.i('d_sec'), NULL, pg_temp.i('d_sig'));
INSERT INTO activity_log (occurred_at, actor_member_id, source, action, entity_type, entity_id, after, issuer_id, security_id)
 VALUES (now() - interval '1 hour', 27, 'web', 'holder.merge', 'holder', pg_temp.i('d_he'), '{"survivor": 1}', pg_temp.i('d_iss'), pg_temp.i('d_sec'));
SELECT si_search_catch_up(5000);
RESET ROLE;
\set QUIET off
SELECT 'p4world' AS done, pg_temp.i('d_iss') AS d_iss, pg_temp.i('d_sec') AS d_sec, pg_temp.i('d_hp') AS d_hp, pg_temp.i('d_he') AS d_he, pg_temp.i('d_sig') AS d_sig, pg_temp.i('d_fil') AS d_fil, pg_temp.i('d_ev') AS d_ev,
       pg_temp.i('d_rep') AS d_rep, pg_temp.i('sig_open') AS sig_open, pg_temp.i('sig_prop') AS sig_prop, pg_temp.i('sig_disp') AS sig_disp, pg_temp.i('rep_draft') AS rep_draft, pg_temp.i('rep_appr') AS rep_appr, pg_temp.i('exp') AS exp,
       pg_temp.i('key') AS key, pg_temp.i('nobo') AS nobo, pg_temp.i('reg') AS reg, pg_temp.i('reg2') AS reg2, (SELECT id FROM imports WHERE kind = 'spr' AND security_id = pg_temp.i('smok_sec') ORDER BY id LIMIT 1) AS spr, pg_temp.i('ftext') AS ftext;
