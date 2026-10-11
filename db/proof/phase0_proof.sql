-- Phase 0 proof of the schema (docs/shareholder-intelligence-design.md §10). Everything happens inside one transaction that is ROLLED
-- BACK: fixtures written as shareholder_intelligence_rw, reads as the read roles, acting members set through app.member_id exactly as
-- PHP and the MCP servers set it. The fixtures are tests/fixtures/ (the two reference files ANONYMISED, a synthetic SPR fortnight, the
-- public-record samples — nothing of a real person). Run as postgres on a SCRATCH database from the repository root (the \copy paths are
-- relative) after loading every migration in order (tests/phase0/run.sh does all of it):
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d si_dev0 -f db/proof/phase0_proof.sql; echo "exit $?"
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer: every actor of §2
SET ROLE shareholder_intelligence_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user', false, 'active', 'write', '{user}'),              -- an Analyst
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user', false, 'active', 'write', '{manager,user}'),      -- the IR Manager
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user', false, 'active', 'write', '{}'),                  -- roles not arrived
 (29, 'human', 'SMOKE Ann',     'ann@client.invalid',    'user', true,  'active', 'read',  '{viewer}'),            -- the client's Viewer (external)
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',  'user', false, 'active', 'write', '{user}'),              -- an Analyst on the restricted issuer
 (31, 'human', 'SMOKE Lee',     'lee@example.invalid',   'user', false, 'active', 'write', '{}'),
 (40, 'agent', 'SMOKE Expert',  NULL,                    'user', false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Watcher', NULL,                    'user', false, 'active', NULL,    '{}'),
 (42, 'agent', 'SMOKE Surveillance Analyst', NULL,       'user', false, 'active', 'write', '{user}');
INSERT INTO departments (id, name, is_system, system_key, manager_member_id) VALUES (3, 'Accounting', true, 'accounting', NULL), (5, 'IT', true, 'it', 1), (7, 'Investor Relations', false, NULL, 27), (8, 'Finance', false, NULL, 30);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES (1, 5, true, true), (26, 7, false, true), (27, 7, true, true), (28, 3, false, true), (30, 8, false, true), (31, 8, false, true);
UPDATE si_settings SET business_name = 'SMOKE Co', edgar_contact_email = 'ir@example.invalid', escalate_high_to = 27, market_holidays = '{2024-03-29}';

-- ---------------------------------------------------------------- the settings and the market clock
SELECT pg_temp.check(si_edgar_user_agent() = 'SMOKE Co ir@example.invalid', 'the EDGAR user-agent is "<business> <contact>" (the SEC''s shape)');
SELECT pg_temp.check(si_is_market_day('2024-04-04') AND NOT si_is_market_day('2024-03-29') AND NOT si_is_market_day('2024-04-06'), 'a market day is a weekday that is not a holiday (Good Friday 2024-03-29 seeded)');

-- ---------------------------------------------------------------- roles and the two walls (§3, D2–D4)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(si_is_admin() AND si_is_manager() AND si_sees_holder_pii() AND NOT si_is_viewer(), 'the super-admin: admin, manager, sees PII');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(si_has_right('uploads.write') AND si_has_right('matches.decide') AND si_sees_holder_pii() AND NOT si_is_manager(), 'an Analyst uploads, decides matches, sees PII, is no manager');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(si_is_viewer() AND si_has_right('holders.read_aggregate') AND NOT si_sees_holder_pii() AND NOT si_has_right('uploads.write'), 'a Viewer reads aggregates, never PII, uploads nothing');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check(NOT app_is_active_member() AND (SELECT count(*) FROM si_my_issuers()) = 0, 'an unadmitted agent sees no issuer');
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 4 AND (SELECT count(*) FROM mcp_app_roles WHERE is_admin) = 1 AND (SELECT count(*) FROM si_rights) = 35, 'four roles, one admin, thirty-five rights');

-- ---------------------------------------------------------------- issuers, securities, share counts (§6.1)
SELECT pg_temp.as_member(27);
INSERT INTO issuers (name, cik, ticker, exchange, transfer_agent, transfer_agent_connector, created_by) VALUES ('SMOKE ISSUER INC', '0000999999', 'SMOK', 'otc', 'Securities Transfer Corporation', 'stc', 27) RETURNING pg_temp.keep('iss', id);
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (pg_temp.i('iss'), 'common', 'Common Stock', '07831X206', 'SMOK', true) RETURNING pg_temp.keep('sec', id);
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (pg_temp.i('iss'), 'preferred', 'Series A Preferred', '07831X305', NULL, false) RETURNING pg_temp.keep('pref', id);
SELECT pg_temp.refused($$INSERT INTO securities (issuer_id, class, name, cusip, is_primary) VALUES (pg_temp.i('iss'), 'common', 'Another primary', '07831X404', true)$$, 'securities_one_primary', 'one primary security per issuer');
SELECT pg_temp.refused($$INSERT INTO issuers (name, cik) VALUES ('Bad CIK', '12345')$$, 'issuers_cik_check', 'a CIK is ten digits');
INSERT INTO issuers (name, cik, ticker, exchange, created_by) VALUES ('SMOKE SECOND CORP', '0000888888', 'SMOK2', 'nasdaq', 27) RETURNING pg_temp.keep('iss2', id);
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (pg_temp.i('iss2'), 'common', 'Common Stock', '88888X100', 'SMOK2', true) RETURNING pg_temp.keep('sec2', id);
INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (pg_temp.i('iss2'), 30, 27);
INSERT INTO share_counts (security_id, as_of, kind, value, source, xbrl_frame, recorded_by) VALUES (pg_temp.i('sec'), '2024-03-25', 'outstanding', 17053900, 'filing', 'CY2024Q1I', NULL), (pg_temp.i('sec'), '2024-03-25', 'float', 12000000, 'manual', NULL, 27);
SELECT pg_temp.check(si_share_count(pg_temp.i('sec'), 'outstanding', '2024-04-04') = 17053900 AND si_share_count(pg_temp.i('sec'), 'outstanding', '2024-03-01') IS NULL, 'si_share_count answers the latest value at or before a date, nothing before the first');
-- the issuer wall
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(si_can_see_issuer(pg_temp.i('iss')) AND NOT si_can_see_issuer(pg_temp.i('iss2')) AND (SELECT count(*) FROM mcp_issuers) = 1, 'an Analyst sees the open issuer and not the restricted one (the restriction list)');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(si_can_see_issuer(pg_temp.i('iss2')) AND (SELECT count(*) FROM mcp_issuers) = 2, 'a listed member sees the restricted issuer');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(si_can_see_issuer(pg_temp.i('iss2')) AND (SELECT restricted FROM mcp_issuers WHERE issuer_id = pg_temp.i('iss2')), 'a manager sees every issuer and the view says which is restricted');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_securities) = 2 AND (SELECT count(*) FROM mcp_issuer_members) = 0, 'a Viewer sees the open issuer''s securities and no restriction list');

-- ---------------------------------------------------------------- participants and the directory (§6.1, D7)
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM participants) = 37 AND (SELECT class_key FROM participants WHERE number = '0164') = 'retail' AND (SELECT class_key FROM participants WHERE number = '0005') = 'prime' AND (SELECT class_key FROM participants WHERE number = '3691') = 'lending', 'the directory seeds: thirty-seven participants in their classes');
CREATE TEMP TABLE t_dir (no text, name text);
\copy t_dir FROM 'tests/fixtures/dtcc_participants.csv' WITH (FORMAT csv, HEADER true)
SELECT pg_temp.keep('up1', CASE si_participant_upsert('7777', 'SMOKE NEW CLEARING LLC/STOCK LOAN', 'participant', '2024-09-30') WHEN 'inserted' THEN 1 ELSE 0 END);
SELECT pg_temp.check(pg_temp.i('up1') = 1 AND (SELECT class_key FROM participants WHERE number = '7777') = 'lending', 'a new number from the directory is classed from its name (STOCK LOAN → lending)');
SELECT pg_temp.keep('up2', CASE si_participant_upsert('0164', 'SCHWAB SECURITIES LLC', 'participant', '2024-09-30') WHEN 'renamed' THEN 1 ELSE 0 END);
SELECT pg_temp.check(pg_temp.i('up2') = 1 AND 'CHARLES SCHWAB & CO., INC.' = ANY ((SELECT aliases FROM participants WHERE number = '0164')::text[]) AND (SELECT class_key FROM participants WHERE number = '0164') = 'retail', 'a rename keeps the class and the old name as an alias');
SELECT pg_temp.check(si_participant_find(NULL, 'CHARLES SCHWAB & CO., INC.') = (SELECT id FROM participants WHERE number = '0164'), 'a participant is found by an old name');
UPDATE participants SET name = 'CHARLES SCHWAB & CO., INC.' WHERE number = '0164';
SELECT pg_temp.check(si_participant_class_from_name('WELLS FARGO CLEARING SERVICES') = 'clearing_retail' AND si_participant_class_from_name('BOFA SECURITIES, INC./SAFEKEEPING') = 'lending' AND si_participant_class_from_name('SOMETHING ELSE LTD') = 'other', 'the class proposal reads the directory''s words');

-- ---------------------------------------------------------------- THE SPR (§6.2, D6, D7) — the fixture fortnight
SELECT pg_temp.as_member(26);
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr_weekly.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'dtc_spr_xlsx', '{"shares_by_date": {"2024-04-04": 1581000}, "participants": 17}', 26) RETURNING pg_temp.keep('spr', id);
CREATE TEMP TABLE t_spr (row_no integer, participant_number text, participant_name text, position_date date, shares numeric);
\copy t_spr FROM 'tests/fixtures/spr_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares, raw) SELECT pg_temp.i('spr'), row_no, participant_number, participant_name, position_date, shares, jsonb_build_object('number', participant_number, 'shares', shares::text) FROM t_spr;
-- one row typed wrong on purpose: a cell that failed
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares, raw, error) VALUES (pg_temp.i('spr'), 999, NULL, 'GARBLED ROW', '2024-04-04', NULL, '{"number": "ABCD"}', 'participant number is not four digits');
SELECT * INTO TEMP t_load FROM si_spr_load(pg_temp.i('spr'));
SELECT pg_temp.check((SELECT positions FROM t_load) = 170 AND (SELECT rows_failed FROM t_load) = 1 AND NOT (SELECT tied FROM t_load) AND (SELECT tie_note FROM t_load) ILIKE '%1 row(s) failed%', 'the SPR loads 170 positions (17 participants × 10 dates); a garbled row is counted, kept and names the file untied');
DELETE FROM spr_rows WHERE row_no = 999; UPDATE imports SET status = 'running' WHERE id = pg_temp.i('spr');
SELECT * INTO TEMP t_load2 FROM si_spr_load(pg_temp.i('spr'));
SELECT pg_temp.check((SELECT tied FROM t_load2) AND (SELECT tie_note FROM t_load2) IS NULL AND si_import_usable(pg_temp.i('spr')), 'without it the file ties: the rows'' total on the last date equals the file''s and the participant count matches');
SELECT pg_temp.check(si_spr_total(pg_temp.i('sec'), '2024-04-04') = 1581000 AND si_spr_total(pg_temp.i('sec'), '2024-03-22') = 1557280 AND si_spr_total(pg_temp.i('sec'), '2024-03-23') IS NULL, 'si_spr_total: the day''s total, NULL on a day with no file (absent, not zero)');
SELECT pg_temp.check((SELECT value FROM share_counts WHERE security_id = pg_temp.i('sec') AND kind = 'cede' AND source = 'spr') = 1581000, 'a tied SPR writes the Cede figure its last date implies as a share count');
SELECT pg_temp.check((SELECT count(*) FROM si_position_dates(pg_temp.i('sec'), '2024-03-15', '2024-04-30')) = 10, 'ten position dates in the fortnight');
SELECT pg_temp.check((SELECT net FROM si_position_changes(pg_temp.i('sec'), '2024-03-22', '2024-04-04') WHERE number = '0005') > 0 AND (SELECT net FROM si_position_changes(pg_temp.i('sec'), '2024-03-22', '2024-04-04') WHERE number = '0164') < 0
    AND (SELECT largest_move FROM si_position_changes(pg_temp.i('sec'), '2024-03-22', '2024-04-04') WHERE number = '0005') >= 1581000 * 0.015, 'si_position_changes: Goldman net up with the crafted jump as its largest move, Schwab net down');
SELECT pg_temp.check((SELECT sum(net) FROM si_participant_flow(pg_temp.i('sec'), '2024-03-22', '2024-04-04') WHERE side = 'retail') < -1581000 * 0.025
    AND (SELECT sum(net) FROM si_participant_flow(pg_temp.i('sec'), '2024-03-22', '2024-04-04') WHERE side IN ('institutional', 'intermediary')) > 1581000 * 0.025, 'si_participant_flow: the retail side shed about 3% of the total, the prime/lending/market-maker side gained it');
SELECT pg_temp.check((SELECT count(*) FROM si_participants_new_lost(pg_temp.i('sec'), '2024-04-01', '2024-04-04')) = 0, 'no participant new or lost inside the fortnight');
SELECT pg_temp.check((SELECT top5_pct BETWEEN 40 AND 100 AND hhi > 0 AND participants = 17 FROM si_concentration(pg_temp.i('sec'), '2024-04-04')), 'si_concentration: top-5 share and a Herfindahl on the last date');
SELECT pg_temp.check((SELECT count(*) FROM si_participant_series(pg_temp.i('sec'), (SELECT id FROM participants WHERE number = '0005'), '2024-03-22', '2024-04-04')) = 10, 'one participant''s series has the ten dates');
-- an earlier one-day file (the month's SPR of 2024-03-01): the positions of 03-22 a month earlier
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr_march1.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'dtc_spr_xlsx', '{}', 26) RETURNING pg_temp.keep('spr0', id);
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('spr0'), row_no, participant_number, participant_name, '2024-03-01', shares FROM t_spr WHERE position_date = '2024-03-22';
SELECT si_spr_load(pg_temp.i('spr0'));
SELECT pg_temp.check((SELECT count(*) FROM participant_positions WHERE security_id = pg_temp.i('sec')) = 187 AND si_spr_total(pg_temp.i('sec'), '2024-03-01') = 1557280, 'a one-day SPR adds its day (187 positions now)');
-- a corrected file for the last week replaces its dates and supersedes the earlier import for them
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr_week2_corrected.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'dtc_spr_xlsx', '{}', 26) RETURNING pg_temp.keep('spr2', id);
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('spr2'), row_no, participant_number, participant_name, position_date, shares + CASE WHEN participant_number = '0005' THEN 1000 ELSE 0 END FROM t_spr WHERE position_date >= '2024-04-01';
SELECT * INTO TEMP t_load3 FROM si_spr_load(pg_temp.i('spr2'));
SELECT pg_temp.check((SELECT positions FROM t_load3) = 68 AND si_spr_total(pg_temp.i('sec'), '2024-04-04') = 1582000 AND (SELECT superseded_by FROM imports WHERE id = pg_temp.i('spr')) = pg_temp.i('spr2') AND (SELECT count(*) FROM participant_positions WHERE security_id = pg_temp.i('sec')) = 187, 'a corrected SPR replaces its dates'' positions (68 rows), supersedes the first file for them, and the count stays 187');
SELECT si_import_delete(pg_temp.i('spr2'));
SELECT pg_temp.check((SELECT count(*) FROM participant_positions WHERE security_id = pg_temp.i('sec') AND position_date >= '2024-04-01') = 0, 'deleting the corrected upload to the tombstone takes its positions with it');
UPDATE imports SET superseded_by = NULL, status = 'running' WHERE id = pg_temp.i('spr'); SELECT si_spr_load(pg_temp.i('spr'));
SELECT pg_temp.check(si_spr_total(pg_temp.i('sec'), '2024-04-04') = 1581000 AND (SELECT count(*) FROM participant_positions WHERE security_id = pg_temp.i('sec')) = 187, '…and reloading the first file restores the fortnight');

-- ---------------------------------------------------------------- THE NOBO LIST (§6.2–6.3, D8)
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, control_totals, created_by) VALUES ('nobo', 'nobo_list.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'broadridge_nobo_xlsx', '2024-04-04', '{"nobo_count": 855}', 26) RETURNING pg_temp.keep('nobo', id);
INSERT INTO nobo_jobs (import_id, security_id, job_number, cusip, issuer_name_as_filed, record_date, sort_order, nobo_count_header) VALUES (pg_temp.i('nobo'), pg_temp.i('sec'), 'O08888', '07831X206', 'SMOKE ISSUER INC', '2024-04-04', 'D', 855) RETURNING pg_temp.keep('job', id);
CREATE TEMP TABLE t_nobo (row_no integer, shares numeric, line1 text, line2 text, line3 text, line4 text, line5 text, line6 text, line7 text, zip_sort text, cusip text);
\copy t_nobo FROM 'tests/fixtures/nobo_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip) SELECT pg_temp.i('job'), pg_temp.i('nobo'), row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip FROM t_nobo;
CREATE TEMP TABLE t_clients (row_no integer, cusip text, client text, nobo_count integer, shares numeric);
\copy t_clients FROM 'tests/fixtures/nobo_clients.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO nobo_broker_rows (job_id, row_no, client, participant_id, nobo_count, shares) SELECT pg_temp.i('job'), row_no, btrim(client), si_participant_find(NULL, btrim(client)), nobo_count, shares FROM t_clients;
INSERT INTO nobo_broker_rows (job_id, row_no, client, participant_id, nobo_count, shares) VALUES (pg_temp.i('job'), 9999, 'CHARLES SCHWAB & CO., INC.', si_participant_find(NULL, 'CHARLES SCHWAB & CO., INC.'), 120, 300000);
UPDATE nobo_jobs SET nobo_count_rows = (SELECT count(*) FROM nobo_rows WHERE job_id = pg_temp.i('job')), shares_total = (SELECT sum(shares) FROM nobo_rows WHERE job_id = pg_temp.i('job')) WHERE id = pg_temp.i('job');
UPDATE imports SET status = 'done', finished_at = now(), rows_made = 841, derived_totals = jsonb_build_object('nobo_count', 841, 'shares', 1450101), tied = false, tie_note = 'the header says 855 NOBOs, the SHARES section has 841 rows' WHERE id = pg_temp.i('nobo');
SELECT pg_temp.check((SELECT nobo_count_rows = 841 AND shares_total = 1450101 AND nobo_count_header = 855 FROM nobo_jobs WHERE id = pg_temp.i('job')), 'the NOBO fixture: 841 rows, 1,450,101 shares; the header''s 855 is a difference the tie names');
SELECT pg_temp.check(NOT si_import_usable(pg_temp.i('nobo')), 'an untied upload is not usable');
SELECT pg_temp.as_member(26);
SELECT pg_temp.as_member(27);
UPDATE imports SET override_by = 27, override_at = now(), override_note = 'Broadridge counts fourteen suppressed rows in the header; confirmed by phone' WHERE id = pg_temp.i('nobo');
SELECT pg_temp.check(si_import_usable(pg_temp.i('nobo')), 'a manager''s override with a note makes it usable');
-- the lines parser
SELECT pg_temp.check((SELECT name_line = 'FRANK U FOSTER' AND street = '8117 SPRUCE ST' AND city_state_zip = 'WINCHESTER NV 72752' AND legend_lines = 'CHARLES SCHWAB & CO INC CUST IRA CONTRIBUTORY' FROM si_nobo_lines_parse('FRANK U FOSTER', 'CHARLES SCHWAB & CO INC CUST', 'IRA CONTRIBUTORY', '8117 SPRUCE ST', 'WINCHESTER NV 72752', '', '')), 'the seven lines split into the name, the custodian legends, the street and the city/state/zip');
SELECT pg_temp.check(si_account_kind_from_lines('CHARLES SCHWAB & CO INC CUST IRA CONTRIBUTORY') = 'ira' AND si_account_kind_from_lines('DESIGNATED BENE PLAN/TOD') = 'tod' AND si_account_kind_from_lines('JOHN DOE TTEE THE DOE FAMILY TRUST') = 'trust' AND si_account_kind_from_lines('ACME HOLDINGS LLC') = 'corporate' AND si_account_kind_from_lines('JANE ROE') = 'individual', 'the account kind reads the legends (IRA, TOD, trust, corporate, individual)');
SELECT pg_temp.check(si_custodian_from_lines('CHARLES SCHWAB & CO INC CUST') = (SELECT id FROM participants WHERE number = '0164') AND si_custodian_from_lines('FMT CO TTEE FRP PS A/C') = (SELECT id FROM participants WHERE number = '0226'), 'the custodian is parsed from the lines (Schwab → 0164, FMT Co → National Financial 0226)');
SELECT pg_temp.check(si_name_key('FRANK U FOSTER TOD') = 'FRANK U FOSTER' AND si_name_key('JOHN DOE TTEE') = 'JOHN DOE' AND si_name_key('Mary-Ann O''Neil') = 'MARY ANN O NEIL', 'the name key strips legends and punctuation');
-- the matcher over the job
SELECT * INTO TEMP t_m FROM si_match_import(pg_temp.i('nobo'));
SELECT pg_temp.check((SELECT new_holders FROM t_m) > 500 AND (SELECT auto FROM t_m) > 0 AND (SELECT proposed FROM t_m) > 0 AND (SELECT failed FROM t_m) = 0 AND (SELECT count(*) FROM nobo_rows WHERE job_id = pg_temp.i('job') AND holder_id IS NULL) = (SELECT proposed FROM t_m),
    format('the matcher over the list: %s new holders, %s repeat registrations accepted automatically, %s namesakes at another address left for an Analyst, none failed', (SELECT new_holders FROM t_m), (SELECT auto FROM t_m), (SELECT proposed FROM t_m)));
-- the Analyst takes the namesakes as new holders (the third button), so the list is whole
SELECT si_match_new_holder('nobo', n.id) FROM nobo_rows n WHERE n.job_id = pg_temp.i('job') AND n.holder_id IS NULL;
SELECT pg_temp.check((SELECT count(*) FROM nobo_rows WHERE job_id = pg_temp.i('job') AND holder_id IS NULL) = 0 AND (SELECT count(*) FROM holder_match_proposals WHERE status = 'proposed') = 0, 'after the Analyst''s decisions every row has its holder and no proposal is open');
SELECT pg_temp.check((SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job') AND row_no = 7) = (SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job') AND row_no = 8) AND (SELECT count(*) FROM holder_accounts WHERE holder_id = (SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job') AND row_no = 7)) = 3, 'one person''s TOD account, Schwab IRA and plain account are three accounts of ONE holder (the reference implementation made three holders)');
SELECT pg_temp.check((SELECT account_kind FROM nobo_rows WHERE job_id = pg_temp.i('job') AND row_no = 8) = 'ira' AND (SELECT custodian_participant_id FROM nobo_rows WHERE job_id = pg_temp.i('job') AND row_no = 8) = (SELECT id FROM participants WHERE number = '0164'), 'the row carries its account kind and custodian');
SELECT pg_temp.check((SELECT sum(shares) FROM holder_positions WHERE security_id = pg_temp.i('sec') AND channel = 'nobo' AND record_date = '2024-04-04') = 1450101, 'the holder positions sum to the list');
SELECT pg_temp.check((SELECT count(*) FROM holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'entity') > 0 AND (SELECT count(*) FROM holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person') > 500, 'holders are persons and entities');
-- a second job: the crafted cases
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by) VALUES ('nobo', 'nobo_march.xlsx', 'done', pg_temp.i('iss'), pg_temp.i('sec'), 'broadridge_nobo_xlsx', '2024-03-01', true, 26) RETURNING pg_temp.keep('nobo2', id);
INSERT INTO nobo_jobs (import_id, security_id, job_number, cusip, record_date, nobo_count_header) VALUES (pg_temp.i('nobo2'), pg_temp.i('sec'), 'O07777', '07831X206', '2024-03-01', 4) RETURNING pg_temp.keep('job2', id);
INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort) VALUES
 (pg_temp.i('job2'), pg_temp.i('nobo2'), 1, 90000, 'FRANK U FOSTER', 'DESIGNATED BENE PLAN/TOD', '8117 SPRUCE ST', 'WINCHESTER NV 72752', '', '', '', '72752'),   -- the same registration again → auto 1.0
 (pg_temp.i('job2'), pg_temp.i('nobo2'), 2, 500,   'FRANK U FOSTER', '1 OTHER ST', 'ELSEWHERE TX 77777', '', '', '', '', '77777'),                              -- the name at another address → 0.6 → proposed
 (pg_temp.i('job2'), pg_temp.i('nobo2'), 3, 1000,  'FRANK U FOSTAR', '8117 SPRUCE ST', 'WINCHESTER NV 72752', '', '', '', '', '72752'),                         -- a typo at the same address → 0.95 → auto
 (pg_temp.i('job2'), pg_temp.i('nobo2'), 4, 25000, 'ZETA HOLDINGS LLC', '100 MAIN ST', 'AUSTIN TX 78701', '', '', '', '', '78701');                             -- an entity → new, visible to a Viewer by name
INSERT INTO nobo_broker_rows (job_id, row_no, client, participant_id, nobo_count, shares) VALUES (pg_temp.i('job2'), 1, 'CHARLES SCHWAB & CO., INC.', si_participant_find('0164'), 240, 600000);
UPDATE nobo_jobs SET nobo_count_rows = 4, shares_total = 116500 WHERE id = pg_temp.i('job2');
SELECT pg_temp.keep('h_frank', (SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job') AND row_no = 7));
SELECT * INTO TEMP t_m2 FROM si_match_import(pg_temp.i('nobo2'));
SELECT pg_temp.check((SELECT auto FROM t_m2) = 2 AND (SELECT proposed FROM t_m2) = 1 AND (SELECT new_holders FROM t_m2) = 1, 'the crafted job: two automatic (the repeat and the typo at the same address), one proposal (the name elsewhere), one new (the entity)');
SELECT pg_temp.check((SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 3) = pg_temp.i('h_frank')
    AND (SELECT score FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = (SELECT id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 3)) = 0.950, 'the typo at the same address is the same holder at 0.95, logged as auto');
SELECT pg_temp.check((SELECT status FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = (SELECT id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 2)) = 'proposed' AND (SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 2) IS NULL, 'the name at another address waits for an Analyst (0.6)');
SELECT pg_temp.as_member(26);
SELECT si_match_decide((SELECT id FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id = (SELECT id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 2)), false);
SELECT pg_temp.keep('nh', si_match_new_holder('nobo', (SELECT id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 2)));
SELECT pg_temp.check(pg_temp.i('nh') IS NOT NULL AND (SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 2) = pg_temp.i('nh') AND pg_temp.i('nh') <> pg_temp.i('h_frank'), 'the Analyst rejects it and asks for a new holder: a second Frank Foster, apart');
SELECT pg_temp.keep('h_frank2', (SELECT holder_id FROM nobo_rows WHERE job_id = pg_temp.i('job2') AND row_no = 2));
SELECT pg_temp.check((SELECT kind FROM holders WHERE issuer_id = pg_temp.i('iss') AND name_key = 'ZETA HOLDINGS LLC') = 'entity', 'the LLC is an entity');
SELECT pg_temp.check((SELECT state FROM si_holders_changes(pg_temp.i('sec'), 'nobo', '2024-03-01', '2024-04-04') WHERE holder_id = pg_temp.i('h_frank')) = 'up' AND (SELECT count(*) FROM si_holders_changes(pg_temp.i('sec'), 'nobo', '2024-03-01', '2024-04-04') WHERE state = 'lost') >= 1 AND (SELECT count(*) FROM si_holders_changes(pg_temp.i('sec'), 'nobo', '2024-03-01', '2024-04-04') WHERE state = 'new') > 500, 'si_holders_changes between record dates: Frank up (one account in March, three in April), the entity lost, hundreds new');
SELECT pg_temp.check((SELECT sum(holders) FROM si_holders_by_range(pg_temp.i('sec'), 'nobo', '2024-04-04')) = (SELECT count(DISTINCT holder_id) FROM holder_positions WHERE security_id = pg_temp.i('sec') AND channel = 'nobo' AND record_date = '2024-04-04'), 'si_holders_by_range counts every holder once');
SELECT pg_temp.check((SELECT count(*) FROM si_holders_by_geography(pg_temp.i('sec'), 'nobo', '2024-04-04')) > 5 AND (SELECT count(*) FROM si_holders_by_custodian(pg_temp.i('sec'), '2024-04-04')) > 1, 'geography and custodians answer');
SELECT pg_temp.check((SELECT obo_remainder FROM si_obo_remainder(pg_temp.i('sec'), '2024-04-04') WHERE number = '0164') = (SELECT shares FROM participant_positions WHERE security_id = pg_temp.i('sec') AND position_date = '2024-04-04' AND participant_id = si_participant_find('0164')) - 300000, 'the OBO remainder at Schwab = its SPR position − the Bank/Broker listing''s NOBO shares');
-- the merge
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('ff1', pg_temp.i('h_frank'));
SELECT pg_temp.keep('ff2', pg_temp.i('h_frank2'));
SELECT pg_temp.refused($$SELECT si_holders_merge(pg_temp.i('ff1'), (SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'))$$, 'person is not merged with an entity', 'a person is never merged with an entity');
SELECT pg_temp.keep('acc_before', (SELECT count(*) FROM holder_accounts WHERE holder_id = pg_temp.i('ff1')) + (SELECT count(*) FROM holder_accounts WHERE holder_id = pg_temp.i('ff2')));
SELECT si_holders_merge(pg_temp.i('ff1'), pg_temp.i('ff2'));
SELECT pg_temp.check((SELECT merged_into_id FROM holders WHERE id = pg_temp.i('ff2')) = pg_temp.i('ff1') AND (SELECT count(*) FROM holder_accounts WHERE holder_id = pg_temp.i('ff1')) = pg_temp.i('acc_before') AND (SELECT count(*) FROM nobo_rows WHERE holder_id = pg_temp.i('ff2')) = 0, 'a merge re-points every account and row and tombstones the loser with merged_into_id');

-- ---------------------------------------------------------------- THE REGISTER (§6.2) and THE RECONCILIATION (§6.4, D12)
SELECT pg_temp.as_member(26);
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, control_totals, created_by) VALUES ('register', 'register_stc.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'stc_position_detail', '2024-04-04', '{"shares": 17053900}', 26) RETURNING pg_temp.keep('reg', id);
CREATE TEMP TABLE t_reg (row_no integer, section text, position_id text, position_type text, holder_ref text, registration_name text, address_line text, city text, state text, country text, zip text, issue_date text, shares numeric, cost_basis text, restriction_text text, percent_owned text, is_employee text, is_officer_director text, is_us_citizen text);
\copy t_reg FROM 'tests/fixtures/register_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO register_rows (import_id, security_id, record_date, row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, issue_date, shares, cost_basis, restriction_text, percent_owned, is_employee, is_officer_director, is_us_citizen)
SELECT pg_temp.i('reg'), pg_temp.i('sec'), '2024-04-04', row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, NULLIF(issue_date, '')::date, shares, NULLIF(replace(cost_basis, ',', ''), '')::numeric, NULLIF(restriction_text, ''), NULLIF(replace(percent_owned, ',', ''), '')::numeric, is_employee = 'Y', is_officer_director = 'Y', is_us_citizen = 'Y' FROM t_reg;
UPDATE imports SET status = 'done', finished_at = now(), rows_made = 842, derived_totals = '{"shares": 17053900}', tied = true WHERE id = pg_temp.i('reg');
SELECT pg_temp.check((SELECT count(*) FROM register_rows WHERE import_id = pg_temp.i('reg')) = 842 AND (SELECT sum(shares) FROM register_rows WHERE import_id = pg_temp.i('reg')) = 17053900 AND (SELECT count(*) FROM register_rows WHERE import_id = pg_temp.i('reg') AND is_cede) = 2 AND (SELECT sum(shares) FROM register_rows WHERE import_id = pg_temp.i('reg') AND is_cede) = 1581000, 'the register fixture: 842 rows, 17,053,900 shares, Cede & Co flagged (two lines, 1,581,000)');
SELECT * INTO TEMP t_mr FROM si_match_import(pg_temp.i('reg'));
SELECT si_match_new_holder('register', g.id) FROM register_rows g WHERE g.import_id = pg_temp.i('reg') AND g.holder_id IS NULL AND NOT g.is_cede;
SELECT pg_temp.check((SELECT new_holders FROM t_mr) > 300 AND (SELECT auto FROM t_mr) > 0 AND (SELECT count(*) FROM register_rows WHERE import_id = pg_temp.i('reg') AND holder_id IS NULL AND NOT is_cede) = 0, format('the register''s holders are matched: %s new, %s repeats of one registration accepted (several certificates, one holder, one account), %s namesakes decided', (SELECT new_holders FROM t_mr), (SELECT auto FROM t_mr), (SELECT proposed FROM t_mr)));
SELECT pg_temp.check((SELECT count(*) FROM si_holders_by_type(pg_temp.i('sec'), '2024-04-04')) >= 1, 'holders by position type answers');
SELECT pg_temp.keep('rec1', si_reconcile(pg_temp.i('sec'), '2024-04-04'));
SELECT pg_temp.check((SELECT tied_registered AND tied_spr AND tied AND difference_registered = 0 AND difference_spr = 0 AND outstanding = 17053900 AND cede_registered = 1581000 AND spr_total = 1581000 AND nobo_shares = 1450101 AND obo_remainder = 130899 FROM share_reconciliations WHERE id = pg_temp.i('rec1')),
    'THE RECONCILIATION TIES: outstanding = Cede + the register''s rest; Cede = the SPR total; the OBO remainder = SPR − NOBO');
INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by, note) VALUES (pg_temp.i('sec'), '2024-04-05', 'outstanding', 17100000, 'manual', 27, 'a typo on purpose');
SELECT pg_temp.keep('rec2', si_reconcile(pg_temp.i('sec'), '2024-04-05'));
SELECT pg_temp.check((SELECT NOT tied_registered AND tied_spr AND NOT tied AND difference_registered = 46100 FROM share_reconciliations WHERE id = pg_temp.i('rec2')), 'a wrong share count unties the register side by the named difference (46,100)');
INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by) VALUES (pg_temp.i('sec'), '2024-03-28', 'cede', 1500000, 'dtc_letter', 27);
SELECT pg_temp.keep('rec3', si_reconcile(pg_temp.i('sec'), '2024-03-29'));
SELECT pg_temp.check((SELECT tied_registered IS NULL AND NOT tied_spr AND difference_spr = 1500000 - 1557278 AND cede_source = 'share_count' FROM share_reconciliations WHERE id = pg_temp.i('rec3')), 'before the register exists Cede comes from the DTC letter; against the SPR of 03-28 it is untied by the named amount');
SELECT pg_temp.check((si_reconciliation_latest(pg_temp.i('sec'))).id = pg_temp.i('rec2'), 'si_reconciliation_latest is the latest as_of');

-- ---------------------------------------------------------------- THE PUBLIC RECORD: sources, filings, events, dilution (§6.5)
-- db/023 (slice 6) seeds Alpha Vantage, Polygon (paused until a key) and the price upload's source (no schedule), and pauses stooq (it refuses machines)
SELECT pg_temp.check((SELECT count(*) FROM sources) = 18 AND (SELECT count(*) FROM si_sources_due()) = 14, 'eighteen sources seeded, the fourteen scheduled and unpaused due at first');
SELECT pg_temp.keep('src_edgar', (SELECT id FROM sources WHERE connector = 'edgar_submissions'));
INSERT INTO source_pulls (source_id, kind) VALUES (pg_temp.i('src_edgar'), 'scheduled') RETURNING pg_temp.keep('pull', id);
SELECT si_pull_finish(pg_temp.i('pull'), 'ok', NULL, 5, 5, 0, 1, 20480, '{"user_agent": "SMOKE Co ir@example.invalid", "rate": 2, "if_modified_since": true}');
SELECT pg_temp.check((SELECT last_ok_at IS NOT NULL AND consecutive_failures = 0 AND last_pull_id = pg_temp.i('pull') FROM sources WHERE id = pg_temp.i('src_edgar')) AND (SELECT count(*) FROM si_sources_due()) = 13, 'a pull that ends ok stamps the source and takes it off the due list');
INSERT INTO source_pulls (source_id, kind) VALUES (pg_temp.i('src_edgar'), 'scheduled') RETURNING pg_temp.keep('pull2', id);
SELECT si_pull_finish(pg_temp.i('pull2'), 'blocked', 'HTTP 403: Undeclared Automated Tool', 0, 0, 0, 1, 0, '{}');
SELECT pg_temp.check((SELECT backoff_until > now() + interval '55 minutes' AND consecutive_failures = 1 FROM sources WHERE id = pg_temp.i('src_edgar')) AND NOT EXISTS (SELECT 1 FROM si_sources_due() WHERE id = pg_temp.i('src_edgar')), 'a 403 backs the source off an hour');
INSERT INTO source_pulls (source_id, kind) VALUES (pg_temp.i('src_edgar'), 'scheduled') RETURNING pg_temp.keep('pull3', id);
SELECT si_pull_finish(pg_temp.i('pull3'), 'failed', 'timeout');
SELECT pg_temp.check((SELECT consecutive_failures = 2 AND backoff_until BETWEEN now() + interval '14 minutes' AND now() + interval '16 minutes' FROM sources WHERE id = pg_temp.i('src_edgar')), 'a second failure climbs the back-off ladder (15 minutes)');
SELECT pg_temp.refused($$INSERT INTO sources (name, connector) VALUES ('bad', 'scrape_vendor')$$, 'sources_connector_check', 'an unknown connector is refused');
SELECT pg_temp.refused($$INSERT INTO source_pulls (source_id, error) VALUES (pg_temp.i('src_edgar'), repeat('x', 201))$$, 'source_pulls_error_check', 'a pull''s error is 200 characters at most');
-- filings from the index fixture (the events trigger)
CREATE TEMP TABLE t_fil (accession text, form text, filed_on date, report_date text, items text, primary_document text, primary_doc_description text, about_us text, filer_cik text);
\copy t_fil FROM 'tests/fixtures/filings_index.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, report_date, items, primary_document, primary_doc_description, about_us, source_pull_id)
SELECT pg_temp.i('iss'), filer_cik, accession, form, filed_on, NULLIF(report_date, '')::date, CASE WHEN COALESCE(items, '') = '' THEN '{}'::text[] ELSE string_to_array(items, ';') END, primary_document, primary_doc_description, about_us = '1', pg_temp.i('pull') FROM t_fil;
SELECT pg_temp.check((SELECT count(*) FROM filings WHERE issuer_id = pg_temp.i('iss')) = 5 AND (SELECT url FROM filings WHERE accession_number = '0000999999-24-000005') = 'https://www.sec.gov/Archives/edgar/data/999999/000099999924000005/smok-8k_20240314.htm', 'five filings indexed; the URL is generated the EDGAR way');
SELECT pg_temp.check((SELECT count(*) FROM events WHERE issuer_id = pg_temp.i('iss') AND kind IN ('filing', 'insider_transaction')) = 5 AND (SELECT severity FROM events WHERE filing_id = (SELECT id FROM filings WHERE form = 'SC 13D')) = 'high'
    AND (SELECT severity FROM events WHERE filing_id = (SELECT id FROM filings WHERE form = '8-K')) = 'watch' AND (SELECT kind FROM events WHERE filing_id = (SELECT id FROM filings WHERE form = '144')) = 'insider_transaction', 'every watched form makes its event: a 13D high, an 8-K with item 3.02 watch, a 144 an insider event');
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE kind = 'filing' AND agent_member_id = 42) = 1, 'a 13D wakes the Surveillance Analyst (a dispatch)');
INSERT INTO filing_text (filing_id, text, extractor) VALUES ((SELECT id FROM filings WHERE form = '8-K'), 'Item 1.01 Entry into a Material Definitive Agreement. On March 14, 2024, SMOKE ISSUER INC entered into an equity purchase agreement (the "Equity Line") with SMOKE EPSILON CAPITAL LLC under which the Company may sell up to $10,000,000 of common stock at 95% of the lowest VWAP of the five trading days before each purchase. Item 3.02 Unregistered Sales of Equity Securities.', 'html');
UPDATE filings SET fetched_text_at = now(), char_count = 400 WHERE form = '8-K';
SELECT pg_temp.check((SELECT count(*) FROM si_filings_search(pg_temp.i('iss'), 'equity purchase agreement')) = 1 AND (SELECT headline FROM si_filings_search(pg_temp.i('iss'), '"equity line"')) ILIKE '%<b>Equity</b>%', 'the full-text search finds the 8-K and highlights the words');
INSERT INTO watch_hits (issuer_id, filing_id, term, snippet) VALUES (pg_temp.i('iss'), (SELECT id FROM filings WHERE form = '8-K'), 'equity purchase agreement', '…entered into an equity purchase agreement…');
INSERT INTO dilution_instruments (issuer_id, security_id, kind, counterparty, filed_on, filing_id, facility_amount, price_terms, floating_price, status, proposed_by, watch_term)
VALUES (pg_temp.i('iss'), pg_temp.i('sec'), 'eloc', 'SMOKE EPSILON CAPITAL LLC', '2024-03-15', (SELECT id FROM filings WHERE form = '8-K'), 10000000, '95% of the lowest VWAP of the five trading days before each purchase', true, 'proposed', 'watch', 'equity purchase agreement') RETURNING pg_temp.keep('eloc', id);
SELECT pg_temp.as_member(26);
UPDATE dilution_instruments SET status = 'active', confirmed_by = 26, confirmed_at = now() WHERE id = pg_temp.i('eloc');
UPDATE watch_hits SET status = 'confirmed', decided_by = 26, decided_at = now() WHERE issuer_id = pg_temp.i('iss');
SELECT pg_temp.check((SELECT status = 'active' AND floating_price FROM dilution_instruments WHERE id = pg_temp.i('eloc')), 'the watch''s proposal, confirmed by an Analyst, is an active floating-price instrument');
-- an activist short report, recorded by a person
INSERT INTO events (issuer_id, security_id, on_date, kind, title, url, severity, source, created_by) VALUES (pg_temp.i('iss'), pg_temp.i('sec'), '2024-04-03', 'activist_short_report', 'SMOKE Short Research: "SMOK — the equity line nobody read"', 'https://example.invalid/report', 'high', 'person', 26) RETURNING pg_temp.keep('ev_short', id);

-- ---------------------------------------------------------------- the ownership forms (§6.5)
CREATE TEMP TABLE t_13f (accession text, sk text, nameofissuer text, titleofclass text, cusip text, figi text, value numeric, sshprnamt numeric, sshprnamttype text, putcall text, discretion text, othermanager text, vsole numeric, vshared numeric, vnone numeric, filer_cik text, filer_name text, period date);
\copy t_13f FROM 'tests/fixtures/13f_infotable.tsv' WITH (FORMAT csv, DELIMITER E'\t', HEADER true)
INSERT INTO institutional_holdings (institution_id, security_id, period_end, accession_number, value_dollars, shares, shares_type, put_call, discretion, voting_sole, voting_shared, voting_none, name_of_issuer, title_of_class, cusip)
SELECT si_institution_upsert(filer_cik, filer_name), pg_temp.i('sec'), period, accession, value, sshprnamt, sshprnamttype, NULLIF(putcall, ''), discretion, vsole, vshared, vnone, nameofissuer, titleofclass, cusip FROM t_13f;
SELECT pg_temp.check((SELECT count(*) FROM institutions) = 3 AND (SELECT kind FROM institutions WHERE cik = '0001333333') = 'pension' AND (SELECT kind FROM institutions WHERE cik = '0001111111') = 'hedge_fund', 'three institutions from the 13F set, their kinds proposed from their names');
SELECT pg_temp.check((SELECT state FROM si_13f_changes(pg_temp.i('sec'), '2024-03-31') WHERE name LIKE 'SMOKE ALPHA%') = 'down' AND (SELECT state FROM si_13f_changes(pg_temp.i('sec'), '2024-03-31') WHERE name LIKE 'SMOKE BETA%') = 'out' AND (SELECT state FROM si_13f_changes(pg_temp.i('sec'), '2024-03-31') WHERE name LIKE 'SMOKE GAMMA%') = 'new', 'si_13f_changes: Alpha down, Beta out (its put is not a holding), Gamma new');
SELECT pg_temp.check((SELECT holders = 2 AND shares = 60000 AND puts_shares = 30000 FROM si_13f_summary(pg_temp.i('sec'), '2024-03-31')), 'si_13f_summary: two holders, 60,000 shares, a 30,000-share put reported apart');
CREATE TEMP TABLE t_bo (filer_cik text, filer_name text, schedule text, is_amendment text, amendment_no text, filed_on date, event_date date, pct numeric, shares numeric, purpose text, filer_type text, accession text);
\copy t_bo FROM 'tests/fixtures/beneficial.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO beneficial_ownership (institution_id, security_id, filing_id, schedule, is_amendment, amendment_no, filed_on, event_date, percent_of_class, shares_beneficially_owned, purpose_text, filer_type)
SELECT si_institution_upsert(filer_cik, filer_name), pg_temp.i('sec'), (SELECT id FROM filings WHERE accession_number = t_bo.accession), schedule, is_amendment = '1', NULLIF(amendment_no, '')::integer, filed_on, event_date, pct, shares, NULLIF(purpose, ''), filer_type FROM t_bo;
SELECT pg_temp.check((SELECT count(*) FROM si_beneficial_owners(pg_temp.i('sec'))) = 2 AND (SELECT schedule FROM si_beneficial_owners(pg_temp.i('sec')) WHERE name LIKE 'SMOKE DELTA%') = '13D' AND (SELECT percent_of_class FROM si_beneficial_owners(pg_temp.i('sec')) WHERE name LIKE 'SMOKE DELTA%') = 6.2, 'the 5% holders: Delta''s 13D at 6.2% with its purpose, Alpha''s 13G');
CREATE TEMP TABLE t_f4 (accession text, form text, cik text, name text, relationship text, title text, tdate date, code text, shares numeric, price numeric, ad text, after_ numeric, own text, deriv text, row_no integer);
\copy t_f4 FROM 'tests/fixtures/form4.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO insider_transactions (issuer_id, security_id, filing_id, form, reporting_owner_cik, reporting_owner_name, relationship, officer_title, transaction_date, transaction_code, shares, price, acquired_disposed, shares_owned_after, ownership, is_derivative, row_no)
SELECT pg_temp.i('iss'), pg_temp.i('sec'), NULL, form, cik, name, ARRAY[relationship], NULLIF(title, ''), tdate, code, shares, price, ad, after_, own, deriv = '1', row_no FROM t_f4;
SELECT pg_temp.check((SELECT sales = 2 AND sale_shares = 35000 AND last_owned_after = 465000 FROM si_insider_summary(pg_temp.i('iss'), '2024-03-01', '2024-04-05') WHERE reporting_owner_cik = '0000099990'), 'si_insider_summary: the CEO''s two sales, 35,000 shares, 465,000 owned after');
INSERT INTO form144_notices (issuer_id, security_id, filing_id, filed_on, seller_name, seller_cik, relationship, shares_to_sell, aggregate_market_value, approx_sale_date, broker, shares_outstanding_stated)
VALUES (pg_temp.i('iss'), pg_temp.i('sec'), (SELECT id FROM filings WHERE form = '144'), '2024-04-01', 'SMITH ROBERT A', '0000099990', 'officer', 100000, 410000, '2024-04-05', 'SMOKE BROKER LLC', 17053900);

-- ---------------------------------------------------------------- the short side and prices (§6.5, D10, D13)
CREATE TEMP TABLE t_si (sd date, pub date, cur numeric, prev numeric, chg numeric, adv numeric, dtc numeric, rev text);
\copy t_si FROM 'tests/fixtures/short_interest.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO short_interest (security_id, settlement_date, published_on, short_shares, previous_short_shares, change_shares, avg_daily_volume, days_to_cover, revision_flag, market, source_id)
SELECT pg_temp.i('sec'), sd, pub, cur, prev, chg, adv, dtc, rev = 'Y', 'OTC', (SELECT id FROM sources WHERE connector = 'finra_short_interest') FROM t_si;
INSERT INTO short_interest (security_id, settlement_date, published_on, short_shares, avg_daily_volume, days_to_cover, revision_flag, observed_at) VALUES (pg_temp.i('sec'), '2024-03-28', '2024-04-10', 530000, 47000, 11.28, true, now() + interval '1 second');
SELECT pg_temp.check((SELECT count(*) FROM si_short_interest_series(pg_temp.i('sec'), '2024-01-01', '2024-04-30')) = 5 AND (SELECT short_shares FROM si_short_interest_series(pg_temp.i('sec'), '2024-01-01', '2024-04-30') WHERE settlement_date = '2024-03-28') = 530000
    AND (SELECT count(*) FROM short_interest WHERE security_id = pg_temp.i('sec') AND settlement_date = '2024-03-28') = 2, 'a revision is a new row; the series shows the latest observation per settlement date (five dates, six rows)');
SELECT pg_temp.check((SELECT pct_of_float = 4.42 AND pct_of_outstanding = 3.11 AND days_to_cover = 11.28 FROM si_short_vs_float(pg_temp.i('sec'), '2024-04-05')), 'si_short_vs_float: 530,000 short = 4.42% of the float, 3.11% of outstanding, 11.28 days to cover');
CREATE TEMP TABLE t_sv (d text, sym text, sv numeric, sev numeric, tv numeric, mkt text);
\copy t_sv FROM 'tests/fixtures/CNMSshvol.txt' WITH (FORMAT csv, DELIMITER '|', HEADER true)
INSERT INTO daily_short_volume (security_id, trade_date, market, short_volume, short_exempt_volume, total_volume, source_id) SELECT pg_temp.i('sec'), to_date(d, 'YYYYMMDD'), 'CNMS', sv, sev, tv, (SELECT id FROM sources WHERE connector = 'finra_daily_short_volume') FROM t_sv;
SELECT pg_temp.check((SELECT count(*) FROM daily_short_volume WHERE security_id = pg_temp.i('sec')) = 60 AND (SELECT mean_5 > 0.6 AND caveat ILIKE '%not comparable with short interest%' FROM si_daily_short_volume_series(pg_temp.i('sec'), 'CNMS', '2024-04-04', '2024-04-04') WHERE trade_date = '2024-04-04'), 'sixty days of off-exchange short volume; the 5-day mean and FINRA''s caveat on every row');
CREATE TEMP TABLE t_ftd (sd text, cusip text, sym text, q numeric, descr text, price numeric);
\copy t_ftd FROM 'tests/fixtures/cnsfails.txt' WITH (FORMAT csv, DELIMITER '|', HEADER true)
INSERT INTO fails_to_deliver (security_id, settlement_date, quantity, price, file_half, source_id) SELECT pg_temp.i('sec'), to_date(sd, 'YYYYMMDD'), q, price, 'a', (SELECT id FROM sources WHERE connector = 'sec_ftd') FROM t_ftd;
SELECT pg_temp.check((SELECT max(quantity) FROM si_fails_series(pg_temp.i('sec'), '2024-03-01', '2024-04-05')) = 95000 AND (SELECT pct_of_outstanding FROM si_fails_series(pg_temp.i('sec'), '2024-03-01', '2024-04-05') WHERE quantity = 95000) > 0.5, 'the fails series: the crafted spike of 95,000 is over 0.5% of outstanding');
CREATE TEMP TABLE t_th (d date, l text, f text);
\copy t_th FROM 'tests/fixtures/threshold_days.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO threshold_listings (security_id, list_date, list, flag, source_id) SELECT pg_temp.i('sec'), d, l, f, (SELECT id FROM sources WHERE connector = 'threshold_nasdaq') FROM t_th;
SELECT pg_temp.check((SELECT on_list AND consecutive_days = 6 AND since = '2024-03-27' AND list = 'nasdaq' FROM si_threshold_status(pg_temp.i('sec'))), 'the threshold status: on the Nasdaq list six consecutive list days since 2024-03-27');
SELECT pg_temp.check((SELECT NOT on_list FROM si_threshold_status(pg_temp.i('sec2'))), '…and the other security is not on it');
INSERT INTO borrow_observations (security_id, observed_on, source, fee_rate_percent, utilization_percent, note, recorded_by) VALUES (pg_temp.i('sec'), '2024-04-04', 'broker_quote', 35.5, 92, 'indicative from the prime''s desk', 26);
CREATE TEMP TABLE t_px (d date, o numeric, h numeric, l numeric, c numeric, v numeric);
\copy t_px FROM 'tests/fixtures/prices_stooq.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO prices (security_id, trade_date, open, high, low, close, volume, source_id) SELECT pg_temp.i('sec'), d, o, h, l, c, v, (SELECT id FROM sources WHERE connector = 'prices_stooq') FROM t_px;
INSERT INTO prices (security_id, trade_date, open, high, low, close, volume) SELECT pg_temp.i('sec2'), d, o, h, l, c, v FROM t_px;
SELECT pg_temp.check((SELECT count(*) FROM prices WHERE security_id = pg_temp.i('sec')) = 60 AND si_price_on(pg_temp.i('sec'), '2024-04-06') = (SELECT close FROM prices WHERE security_id = pg_temp.i('sec') AND trade_date = '2024-04-04'), 'sixty prices; si_price_on takes the last close at or before a date');
SELECT pg_temp.check((SELECT count(*) FROM si_short_side_overlay(pg_temp.i('sec'), '2024-03-01', '2024-04-04')) >= 20 AND (SELECT on_threshold AND fails IS NOT NULL AND borrow_fee = 35.5 AND short_volume_ratio_5 IS NOT NULL FROM si_short_side_overlay(pg_temp.i('sec'), '2024-04-04', '2024-04-04')), 'the overlay aligns price, fails, the ratio, the threshold and the borrow by date for one chart');

-- ---------------------------------------------------------------- THE SIGNALS (§6.6, D11)
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM signal_rules) = 19 AND (SELECT count(*) FROM signal_rules WHERE evidence_class = 'lore') = 4 AND (SELECT count(*) FROM signal_rules WHERE evidence_class = 'data_defect') = 2 AND (SELECT count(*) FROM signal_rules WHERE evidence_class = 'contested') = 3, 'nineteen rules: ten established, three contested, four lore, two data defects');
SELECT * INTO TEMP t_run FROM si_rules_run(pg_temp.i('sec'), '2024-04-05');
SELECT pg_temp.check((SELECT raised FROM t_run) >= 12, format('the first run raises the crafted signals (%s)', (SELECT raised FROM t_run)));
CREATE TEMP VIEW fired AS SELECT r.key, s.severity, s.evidence_class_ FROM (SELECT sg.*, (SELECT evidence_class FROM signal_rules WHERE id = sg.rule_id) AS evidence_class_ FROM signals sg WHERE sg.security_id = pg_temp.i('sec') AND sg.status = 'open') s JOIN signal_rules r ON r.id = s.rule_id;
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'si_over_float' AND severity = 'high'), 'si_over_float: 11.28 days to cover is high (established)');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'si_change'), 'si_change: +29% settlement over settlement on flat volume');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'borrow_fee'), 'borrow_fee: the 35.5% quote');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'ftd_spike' AND severity = 'high' AND evidence_class_ = 'contested'), 'ftd_spike: the 95,000 fail is high — and marked contested');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'threshold_list' AND severity = 'high'), 'threshold_list: six consecutive days');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'short_volume_ratio' AND severity = 'watch') AND (SELECT summary FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'short_volume_ratio') AND security_id = pg_temp.i('sec')) ILIKE '%Off-exchange%', 'short_volume_ratio: watch at most, and the caveat is in the sentence');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'prime_migration' AND evidence_class_ = 'lore') AND (SELECT summary FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'prime_migration') AND security_id = pg_temp.i('sec')) ILIKE '%lore%', 'prime_migration: the crafted 3% flow fires, says it is lore');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'participant_jump'), 'participant_jump: Goldman''s 1.5% day');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'nobo_drain'), 'nobo_drain: Schwab''s NOBO shares halved between record dates while its DTC position held');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'register_untied' AND severity = 'high' AND evidence_class_ = 'data_defect'), 'register_untied: the 46,100 difference is a high data defect');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'new_13d' AND severity = 'high'), 'new_13d: Delta''s 13D');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'holder_5pct'), 'holder_5pct: a new filer above 5%');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'top_holder_exit'), 'top_holder_exit: Alpha cut by 60%');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'insider_sales'), 'insider_sales: the Form 144 for 0.59% of outstanding');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'activist_short_report' AND severity = 'high'), 'activist_short_report: the recorded report');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM fired WHERE key = 'price_volume_anomaly'), 'price_volume_anomaly is silent here: events are recorded in the window');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM fired WHERE key = 'dilution_overlap' AND severity = 'high'), 'dilution_overlap: the floating-price ELOC beside the open si_over_float (the rules run in order; the short-side rules come first)');
SELECT * INTO TEMP t_run2 FROM si_rules_run(pg_temp.i('sec'), '2024-04-05');
SELECT pg_temp.check((SELECT raised FROM t_run2) = 0 AND (SELECT refreshed FROM t_run2) >= 13 AND (SELECT max(repeat_count) FROM signals WHERE security_id = pg_temp.i('sec')) = 1, 'the second run raises nothing new and refreshes every open signal (repeat_count 1): one open signal per rule per security');
SELECT pg_temp.check((SELECT count(*) FROM signals WHERE security_id = pg_temp.i('sec') AND status = 'open') = (SELECT count(DISTINCT rule_id) FROM signals WHERE security_id = pg_temp.i('sec') AND status = 'open'), 'never two open signals of one rule on one security');
SELECT pg_temp.refused($$INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, summary, expires_on) VALUES ((SELECT id FROM signal_rules WHERE key = 'new_13d'), pg_temp.i('iss'), pg_temp.i('sec'), '2024-04-05', '2024-04-05', '2024-04-05', 'high', 'dup', '2024-04-20')$$, 'signals_one_open', '…the database refuses a second');
SELECT * INTO TEMP t_run_s2 FROM si_rules_run(pg_temp.i('sec2'), '2024-04-05');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.security_id = pg_temp.i('sec2') AND r.key = 'price_volume_anomaly'), 'price_volume_anomaly fires on the second issuer: a 20% drop on 4× volume with no event recorded');
-- notifications and dispatches from a high signal
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'signal_high') >= 5 AND (SELECT count(*) FROM agent_dispatches WHERE kind = 'signal' AND agent_member_id = 42) >= 5, 'every high signal notifies the escalation member and wakes the Surveillance Analyst');
-- dispositions
SELECT pg_temp.as_member(42);
SELECT si_signal_propose((SELECT id FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'threshold_list') AND security_id = pg_temp.i('sec') AND status = 'open'), 'explained_by_event', 'the ELOC''s first draw settled late; the fails cleared on the 5th', pg_temp.i('ev_short'));
SELECT pg_temp.check((SELECT proposal_by = 42 AND status = 'open' FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'threshold_list') AND security_id = pg_temp.i('sec') AND status = 'open'), 'an agent''s proposal does not dispose: the signal stays open with the proposal on it');
SELECT pg_temp.as_member(26);
SELECT si_signal_dispose((SELECT id FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'threshold_list') AND security_id = pg_temp.i('sec') AND status = 'open'), 'dismissed', 'explained_by_event', 'taken from the Analyst''s proposal', pg_temp.i('ev_short'));
SELECT pg_temp.check((SELECT status = 'dismissed' AND disposed_by = 26 AND event_id = pg_temp.i('ev_short') FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'threshold_list') AND security_id = pg_temp.i('sec') ORDER BY id DESC LIMIT 1), 'an Analyst dismisses it with the reason and the explaining event');
SELECT pg_temp.refused($$SELECT si_signal_dispose((SELECT id FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'new_13d') AND security_id = pg_temp.i('sec') AND status = 'open'), 'dismissed', 'explained_by_event', NULL, NULL)$$, 'names the event', 'explained_by_event needs the event');
SELECT si_signal_dispose((SELECT id FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'new_13d') AND security_id = pg_temp.i('sec') AND status = 'open'), 'escalated', 'escalated', 'counsel to review item 4');
SELECT pg_temp.check((SELECT status FROM signals WHERE rule_id = (SELECT id FROM signal_rules WHERE key = 'new_13d') AND security_id = pg_temp.i('sec') ORDER BY id DESC LIMIT 1) = 'escalated', 'a signal is escalated');
SELECT * INTO TEMP t_run3 FROM si_rules_run(pg_temp.i('sec'), '2024-08-01');
SELECT pg_temp.check((SELECT expired FROM t_run3) >= 8 AND (SELECT count(*) FROM signals WHERE security_id = pg_temp.i('sec') AND status = 'expired') >= 8, 'a run months later expires the open signals past their window and grace');
SELECT pg_temp.keep('oc', si_signal_outcomes_update());
SELECT pg_temp.check(pg_temp.i('oc') > 0 AND (SELECT count(*) FROM signal_outcomes o JOIN signals s ON s.id = o.signal_id WHERE s.security_id = pg_temp.i('sec') AND o.price_at IS NOT NULL) > 0, 'signal_outcomes records the price at every signal (the days after as they arrive)');
-- the rules' settings: a per-issuer override and a disabled rule
UPDATE signal_rules SET enabled = false WHERE key = 'participant_jump';
INSERT INTO signal_rule_overrides (rule_id, issuer_id, params, enabled, updated_by) VALUES ((SELECT id FROM signal_rules WHERE key = 'si_over_float'), pg_temp.i('iss'), '{"dtc_high": 50}', true, 27);
SELECT pg_temp.check(NOT si_rule_enabled('participant_jump', pg_temp.i('iss')) AND (si_rule_params('si_over_float', pg_temp.i('iss')) ->> 'dtc_high')::numeric = 50 AND (si_rule_params('si_over_float', pg_temp.i('iss2')) ->> 'dtc_high')::numeric = 10, 'a rule disabled; a per-issuer threshold override that leaves the other issuer alone');

-- ---------------------------------------------------------------- the PII wall in the views (§3.2, D4) and the search (§6.8)
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_holders WHERE issuer_id = pg_temp.i('iss')) > 600 AND (SELECT count(*) FROM mcp_holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person' AND display_name <> 'Individual holder') = 0
    AND (SELECT count(*) FROM mcp_holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person' AND (address_line IS NOT NULL OR city IS NOT NULL OR notes IS NOT NULL)) = 0 AND (SELECT bool_and(pii_withheld) FROM mcp_holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person'),
    'A VIEWER sees every holder row but a natural person is "Individual holder" with no address');
SELECT pg_temp.check((SELECT display_name FROM mcp_holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'entity' AND display_name = 'Zeta Holdings Llc') IS NOT NULL, '…and sees an entity by name');
SELECT pg_temp.check((SELECT count(*) FROM mcp_nobo_rows) = 0 AND (SELECT count(*) FROM mcp_register_rows) = 0 AND (SELECT count(*) FROM mcp_holder_match_proposals) = 0 AND (SELECT count(*) FROM mcp_holder_accounts WHERE registration_text IS NOT NULL AND holder_id IN (SELECT holder_id FROM mcp_holders WHERE kind = 'person')) = 0, '…and no raw registration, proposal or a person''s account text');
SELECT pg_temp.check((SELECT count(*) FROM si_search('FRANK')) = 0 AND (SELECT count(*) FROM si_search('ZETA')) = 1 AND (SELECT count(*) FROM si_search('GOLDMAN', '{participant}')) >= 1, 'the search answers a Viewer no person, an entity, a participant');
SELECT pg_temp.check((SELECT sum(holders) FROM si_holders_by_range(pg_temp.i('sec'), 'nobo', '2024-04-04')) > 500 AND (SELECT count(*) FROM mcp_signals WHERE issuer_id = pg_temp.i('iss')) > 10 AND (SELECT count(*) FROM mcp_short_interest) = 6, '…and the aggregates, the signals and the short side in full');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person' AND display_name = 'Individual holder') = 0 AND (SELECT count(*) FROM mcp_nobo_rows) = 845 AND (SELECT count(*) FROM si_search('FRANK')) >= 1, 'an Analyst sees the names, the raw rows and finds a person by name');
SELECT pg_temp.check((SELECT count(*) FROM mcp_holders WHERE issuer_id = pg_temp.i('iss2')) = 0 AND (SELECT count(*) FROM mcp_signals WHERE issuer_id = pg_temp.i('iss2')) = 0 AND (SELECT count(*) FROM mcp_prices WHERE security_id = pg_temp.i('sec2')) = 0, '…and nothing of the restricted issuer');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT count(*) FROM mcp_signals WHERE issuer_id = pg_temp.i('iss2')) = 1 AND (SELECT count(*) FROM mcp_prices WHERE security_id = pg_temp.i('sec2')) = 60, 'the listed member sees the restricted issuer''s signal and prices');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(si_notify(26, 'signal', 'about the restricted issuer', 'x', 'signal', 1, NULL, pg_temp.i('iss2')) IS NULL AND si_notify(30, 'signal', 'about the restricted issuer', 'x', 'signal', 1, NULL, pg_temp.i('iss2')) IS NOT NULL, 'si_notify never tells a member about an issuer they may not see');
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) >= 0 AND (SELECT count(*) FROM mcp_source_credentials) = 0, 'the activity window and the credentials view answer the manager (no credential exists)');

-- ---------------------------------------------------------------- reports (§6.7), tags, notes, the tombstone
SELECT pg_temp.as_member(42);
INSERT INTO reports (issuer_id, kind, title, period_from, period_to, narrative, drafted_by) VALUES (pg_temp.i('iss'), 'weekly_pack', 'Week to 2024-04-05', '2024-03-29', '2024-04-05', 'Draft: the retail boxes shed 3% of the DTC total while prime and lending boxes gained it (lore); days to cover 11.3; on the threshold list six days; Delta''s 13D at 6.2%.', 42) RETURNING pg_temp.keep('rep', id);
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT si_report_approve(pg_temp.i('rep'))$$, 'IR Manager', 'an Analyst may not approve a pack');
SELECT pg_temp.as_member(27);
SELECT si_report_approve(pg_temp.i('rep'));
SELECT pg_temp.check((SELECT status = 'approved' AND approved_by = 27 FROM reports WHERE id = pg_temp.i('rep')), 'the IR Manager approves the pack');
SELECT pg_temp.refused($$UPDATE reports SET narrative = 'changed after approval' WHERE id = pg_temp.i('rep')$$, 'frozen', 'an approved pack''s narrative is frozen');
INSERT INTO tags (slug, label, created_by) VALUES ('watch-list', 'Watch list', 27) RETURNING pg_temp.keep('tag', id);
INSERT INTO record_tags (record_type, record_id, tag_id, added_by) VALUES ('holder', (SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'), pg_temp.i('tag'), 27);
SELECT pg_temp.check((SELECT usage_count FROM tags WHERE id = pg_temp.i('tag')) = 1 AND 'Watch list' = ANY (si_tags_of('holder', (SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'))) AND (SELECT count(*) FROM si_search('watch list')) = 1, 'a tag counts its uses and is searchable');
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('holder', (SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'), 27, 'Called their CFO; they bought on the dip.');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_notes WHERE record_type = 'holder') = 1, 'a Viewer reads a note on an entity holder');
SELECT pg_temp.as_member(27);
UPDATE si_settings SET tombstone_days = 1;
UPDATE imports SET deleted_at = now() - interval '2 days' WHERE id = pg_temp.i('spr2');
SELECT pg_temp.keep('pg', si_tombstones_purge());
SELECT pg_temp.check(pg_temp.i('pg') >= 1 AND NOT EXISTS (SELECT 1 FROM imports WHERE id = pg_temp.i('spr2')), 'the purge removes what waited past the tombstone window');
SELECT pg_temp.check((SELECT count(*) FROM mcp_imports WHERE deleted) = 0, 'a manager''s view lists no tombstoned upload once purged');

-- ---------------------------------------------------------------- THE OWNER'S REAL FILES (2026-10-09; design §0.2, §16): DTC's SPR in both layouts,
-- the holiday sentinel, Broadridge's Share Range Analysis, and the real reconciliation — an SPR names only brokers, a share-range report
-- only counts; nothing of a person is here.
SELECT pg_temp.as_member(27);
INSERT INTO issuers (name, cik, ticker, exchange, transfer_agent, transfer_agent_connector, created_by) VALUES ('SMOKE REAL ISSUER (the owner''s files)', '0000777777', 'SMKR', 'nyse_american', 'the owner''s agent', 'generic', 27) RETURNING pg_temp.keep('iss3', id);
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (pg_temp.i('iss3'), 'common', 'Common Stock', '09175A107', 'SMKR', true) RETURNING pg_temp.keep('sec3', id);
SELECT pg_temp.as_member(26);
CREATE TEMP TABLE t_real (row_no integer, participant_number text, participant_name text, position_date date, shares numeric, col text);
-- the weekly file of 2023-07-28: Opening (the previous Friday) + Monday..Friday, the Total row per date, a Share Change of 80,000
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'Bitmine Immersion SPR 07282023.csv', 'running', pg_temp.i('iss3'), pg_temp.i('sec3'), 'dtc_spr_csv',
  '{"shares_by_date": {"2023-07-21": 2439800, "2023-07-24": 2519800, "2023-07-25": 2519800, "2023-07-26": 2519800, "2023-07-27": 2519800, "2023-07-28": 2519800}, "participants": 52}', 26) RETURNING pg_temp.keep('r1', id);
\copy t_real FROM 'tests/fixtures/spr_real_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares, raw) SELECT pg_temp.i('r1'), row_no, participant_number, participant_name, position_date, shares, jsonb_build_object('column', col) FROM t_real;
SELECT * INTO TEMP t_lr1 FROM si_spr_load(pg_temp.i('r1'));
SELECT pg_temp.check((SELECT positions = 312 AND tied AND rows_failed = 0 FROM t_lr1) AND si_spr_total(pg_temp.i('sec3'), '2023-07-28') = 2519800 AND si_spr_total(pg_temp.i('sec3'), '2023-07-21') = 2439800,
    'THE REAL WEEKLY SPR (2023-07-28): 52 participants × 6 dates (the Opening column is the previous Friday) load and TIE to the file''s own Total row on every date');
SELECT pg_temp.check(si_spr_total(pg_temp.i('sec3'), '2023-07-28') - si_spr_total(pg_temp.i('sec3'), '2023-07-21') = 80000, '…and the file''s Share Change (80,000) is the Friday-minus-Opening the series computes');
SELECT pg_temp.check((SELECT count(*) FROM participants WHERE number IN ('0756', '8072', '0901')) = 3 AND (SELECT class_key FROM participants WHERE number = '0901') = 'bank_custodian', 'participants the file named and the directory had not (AEIS 0756, Alpine 8072, Bank of NY 0901 → bank custodian) were made from the file');
SELECT pg_temp.check((SELECT net FROM si_position_changes(pg_temp.i('sec3'), '2023-07-21', '2023-07-28') ORDER BY abs(net) DESC LIMIT 1) <> 0 AND (SELECT count(*) FROM si_participant_flow(pg_temp.i('sec3'), '2023-07-21', '2023-07-28')) >= 3, 'the series read the real week: the largest mover and the flow by class');
-- the week of 2024-06-21: Juneteenth (Wednesday 06-19) is DTC's -9999999999999 — absent, not zero
DELETE FROM t_real;
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'auto-import_SPR_06-21-2024_09175A107.csv', 'running', pg_temp.i('iss3'), pg_temp.i('sec3'), 'dtc_spr_csv',
  '{"shares_by_date": {"2024-06-14": 3397800, "2024-06-17": 3397800, "2024-06-18": 3397800, "2024-06-20": 3397800, "2024-06-21": 3397800}, "participants": 45}', 26) RETURNING pg_temp.keep('r2', id);
\copy t_real FROM 'tests/fixtures/spr_real2_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('r2'), row_no, participant_number, participant_name, position_date, shares FROM t_real;
SELECT * INTO TEMP t_lr2 FROM si_spr_load(pg_temp.i('r2'));
SELECT pg_temp.check((SELECT positions = 225 AND tied FROM t_lr2) AND si_spr_total(pg_temp.i('sec3'), '2024-06-19') IS NULL AND (SELECT count(*) FROM si_position_dates(pg_temp.i('sec3'), '2024-06-14', '2024-06-21')) = 5,
    'the week with a holiday: DTC''s -9999999999999 on Juneteenth is ABSENT — five dates load and tie, the Wednesday is no row, not zero');
-- the next week's Opening overlaps the previous Friday: the same figure, replaced in place
DELETE FROM t_real;
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'auto-import_SPR_06-28-2024_09175A107.csv', 'running', pg_temp.i('iss3'), pg_temp.i('sec3'), 'dtc_spr_csv', '{"shares_by_date": {"2024-06-28": 3397800}}', 26) RETURNING pg_temp.keep('r3', id);
\copy t_real FROM 'tests/fixtures/spr_real3_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('r3'), row_no, participant_number, participant_name, position_date, shares FROM t_real;
SELECT * INTO TEMP t_lr3 FROM si_spr_load(pg_temp.i('r3'));
SELECT pg_temp.check((SELECT tied FROM t_lr3) AND si_spr_total(pg_temp.i('sec3'), '2024-06-21') = 3397800 AND (SELECT count(*) FROM participant_positions WHERE security_id = pg_temp.i('sec3') AND position_date = '2024-06-21') = 45 AND (SELECT superseded_by FROM imports WHERE id = pg_temp.i('r2')) = pg_temp.i('r3'),
    'the next week''s Opening (06-21) overlaps the previous Friday: the date is replaced in place with the same figure and the earlier file is marked superseded for it');
-- the first weeks were DTC's ONE-TIME report ("Position as of"): one date, no Total row, participant numbers without leading zeros
DELETE FROM t_real;
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'Bitmine Immersion SPR 05192023 - First SPR.csv', 'running', pg_temp.i('iss3'), pg_temp.i('sec3'), 'dtc_spr_onetime_csv', '{"shares_by_date": {"2023-05-19": 1934800}, "participants": 51}', 26) RETURNING pg_temp.keep('r0', id);
\copy t_real FROM 'tests/fixtures/spr_real0_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('r0'), row_no, participant_number, participant_name, position_date, shares FROM t_real;
SELECT * INTO TEMP t_lr0 FROM si_spr_load(pg_temp.i('r0'));
SELECT pg_temp.check((SELECT positions = 51 AND tied FROM t_lr0) AND si_spr_total(pg_temp.i('sec3'), '2023-05-19') = 1934800 AND (SELECT count(*) FROM participant_positions WHERE security_id = pg_temp.i('sec3') AND participant_id = si_participant_find('0756') AND position_date = '2023-05-19') = 1,
    'DTC''s one-time report (the first SPR, 2023-05-19): 51 participants, the numbers zero-padded ("756" is 0756), the control the sum of the rows');
-- Broadridge's Share Range Analysis: every holder (pre-suppression) and the OBOs by range; the record date and job in the footer
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, control_totals, derived_totals, tied, created_by) VALUES ('share_range', 'Share Range Analysis_BITMINE IMMERSION TE_12152023.xls', 'done', pg_temp.i('iss3'), pg_temp.i('sec3'), 'broadridge_share_range_xls', '2023-12-15',
  '{"all_accounts": 962, "all_shares": 2645039, "obo_accounts": 274, "obo_shares": 1095203}', '{}', true, 26) RETURNING pg_temp.keep('sr', id);
INSERT INTO share_range_reports (import_id, security_id, record_date, job_number) VALUES (pg_temp.i('sr'), pg_temp.i('sec3'), '2023-12-15', 'N96192') RETURNING pg_temp.keep('srr', id);
CREATE TEMP TABLE t_sr (population text, range_label text, range_from numeric, range_to numeric, accounts integer, shares numeric);
\copy t_sr FROM 'tests/fixtures/share_range_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO share_range_rows (report_id, population, range_label, range_from, range_to, accounts, shares) SELECT pg_temp.i('srr'), population, range_label, range_from, range_to, accounts, shares FROM t_sr;
SELECT pg_temp.check((SELECT count(*) FROM share_range_rows WHERE report_id = pg_temp.i('srr') AND population = 'all') = 23 AND (SELECT sum(accounts) FROM share_range_rows WHERE report_id = pg_temp.i('srr') AND population = 'all') = 962 AND (SELECT sum(shares) FROM share_range_rows WHERE report_id = pg_temp.i('srr') AND population = 'obo') = 1095203,
    'Broadridge''s Share Range Analysis (2023-12-15): twenty-three ranges, 962 accounts and 2,645,039 shares in all, 274 OBO accounts with 1,095,203 shares — the rows sum to the sheet''s TOTALS');
SELECT pg_temp.check((SELECT sum(nobo_accounts) = 688 AND sum(nobo_shares) = 1549836 FROM si_share_ranges(pg_temp.i('sec3'))) AND (SELECT obo_pct_of_accounts FROM si_obo_totals(pg_temp.i('sec3'))) = 28.48,
    'si_share_ranges derives the NOBO population (all − OBO: 688 accounts, 1,549,836 shares); 28.48% of the accounts object to being known');
SELECT pg_temp.check((SELECT nobo_accounts FROM si_share_ranges(pg_temp.i('sec3')) WHERE range_from = 100) = 743 - 212, 'the 100–249 range: 743 accounts in all, 212 of them OBO, 531 NOBO');
-- THE REAL RECONCILIATION: the transfer agent's extract of 2024-06-30 carries Cede & Co (FAST) 3,397,800; the SPR of 2024-06-28 totals 3,397,800
INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by, note) VALUES (pg_temp.i('sec3'), '2024-06-30', 'cede', 3397800, 'transfer_agent', 27, 'the Shareholder Extract of 06-30-24: CEDE & CO (FAST), the one nominee/depository row');
INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by, note) VALUES (pg_temp.i('sec3'), '2024-06-30', 'outstanding', 49912607, 'transfer_agent', 27, 'the extract''s TOTAL SHARES over 158 rows (CERT + BOOK + DRS + BOOKR + PLAN1–4 = TOTAL on every row)');
SELECT pg_temp.keep('rec_real', si_reconcile(pg_temp.i('sec3'), '2024-06-30'));
SELECT pg_temp.check((SELECT tied_spr AND difference_spr = 0 AND spr_date = '2024-06-28' AND cede_source = 'share_count' FROM share_reconciliations WHERE id = pg_temp.i('rec_real')),
    'THE REAL RECONCILIATION TIES: Cede & Co on the transfer agent''s extract (2024-06-30) equals the SPR total of 2024-06-28 to the share — the design''s central fact, on the owner''s files');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_share_range_rows) = (SELECT count(*) FROM share_range_rows) AND (SELECT count(*) FROM mcp_participant_positions WHERE security_id = pg_temp.i('sec3')) = 51 + 312 + 225 + 270 - 45, 'a Viewer reads the ranges and the positions of the real files (no person in either)');

-- ---------------------------------------------------------------- PHASE 1's additions (db/018): the shares, the ownership summary, the proposals, the admin's reads
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((si_ownership_summary(pg_temp.i('sec'), '2024-04-04') ->> 'outstanding')::numeric = 17053900 AND (si_ownership_summary(pg_temp.i('sec'), '2024-04-04') -> 'dtc' ->> 'total')::numeric = 1581000
    AND (si_ownership_summary(pg_temp.i('sec'), '2024-04-04') -> 'short' ->> 'pct_of_float')::numeric = 4.42 AND jsonb_array_length(si_ownership_summary(pg_temp.i('sec'), '2024-04-04') -> 'beneficial_owners') = 2
    AND (si_ownership_summary(pg_temp.i('sec'), '2024-04-04') -> 'thirteen_f' ->> 'holders')::integer = 2 AND (si_ownership_summary(pg_temp.i('sec'), '2024-04-04') ->> 'insider_shares')::numeric = 585000,
    'si_ownership_summary: outstanding, the DTC total, short as a share of float, the 5% filers, the 13F holders and the insiders'' shares in one read');
SELECT pg_temp.check(si_ownership_summary(pg_temp.i('sec'), '2024-04-04')::text NOT ILIKE '%FOSTER%' AND si_ownership_summary(pg_temp.i('sec'), '2024-04-04')::text NOT ILIKE '%FRANK%', '…and no natural person in it');
SELECT pg_temp.check((si_ownership_summary(pg_temp.i('sec3'), '2024-06-30') -> 'obo' ->> 'accounts')::integer = 274, '…and the OBO population from the share-range report where one is loaded');
-- the shares, as the application
SELECT pg_temp.check((si_share_filings_index('helpdesk', 'SMOK') ->> 'total')::integer = 5 AND (si_share_filings_index('helpdesk', '0000999999') ->> 'total')::integer = 5 AND (si_share_filings_index('helpdesk', 'SMOK', NULL, '{8-K}') ->> 'total')::integer = 1,
    'filings_index: by ticker or CIK, filtered by form');
SELECT pg_temp.check(si_share_filings_index('helpdesk', 'SMOK2') ->> 'error' = 'not_found' AND si_share_ownership_summary('spaces', 'SMOK2') ->> 'error' = 'not_found' AND si_share_signals_open('spaces', 'SMOK2') ->> 'error' = 'not_found',
    'a RESTRICTED issuer is never shared (the advisory firm''s client stays inside its list)');
SELECT pg_temp.check(si_share_filings_index('', 'SMOK') ->> 'error' = 'consumer_unknown', 'no consumer header: nothing shared');
SELECT pg_temp.check(si_share_filing_text('knowledge', (SELECT id FROM filings WHERE form = '8-K' AND issuer_id = pg_temp.i('iss'))) ->> 'text' ILIKE '%equity purchase agreement%'
    AND si_share_filing_text('knowledge', (SELECT id FROM filings WHERE form = '10-K' AND issuer_id = pg_temp.i('iss'))) ->> 'error' = 'no_text', 'filing_text: the 8-K''s words for Knowledge; a filing whose text is not fetched says so');
SELECT pg_temp.check(si_share_ownership_summary('spaces', 'SMOK', '2024-04-04') ->> 'schema' = 'os.si-ownership/1' AND si_share_ownership_summary('spaces', 'SMOK', '2024-04-04')::text NOT ILIKE '%FRANK%', 'ownership_summary: the document, no person');
SELECT pg_temp.check(jsonb_array_length(si_share_signals_open('spaces', 'SMOK') -> 'signals') = (SELECT count(*) FROM signals WHERE issuer_id = pg_temp.i('iss') AND status = 'open')
    AND jsonb_array_length(si_share_events_index('spaces', 'SMOK') -> 'events') = (SELECT count(*) FROM events WHERE issuer_id = pg_temp.i('iss') AND deleted_at IS NULL), 'signals_open and events_index carry every open signal and every live event');
-- an agent's proposal of a holder's kind; the Analyst's queue; the Viewer sees none of it
SELECT pg_temp.as_member(42);
SELECT pg_temp.keep('kp', si_holder_kind_propose((SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'), 'entity', 'corporation', 'An LLC on line 1, no FBO legend'));
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM si_analyst_proposals(pg_temp.i('iss')) WHERE kind = 'holder_kind') = 1, 'the Analyst''s queue shows the agent''s kind proposal');
SELECT si_holder_kind_decide(pg_temp.i('kp'), true);
SELECT pg_temp.check((SELECT entity_type FROM holders WHERE name_key = 'ZETA HOLDINGS LLC') = 'corporation' AND (SELECT status FROM holder_kind_proposals WHERE id = pg_temp.i('kp')) = 'accepted', 'accepting it sets the holder''s entity type');
SELECT pg_temp.as_member(29);
SELECT pg_temp.refused($$SELECT si_holder_kind_propose((SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'), 'person', NULL, 'x')$$, 'Analyst', 'a Viewer proposes no kind');
SELECT pg_temp.check((SELECT count(*) FROM si_analyst_proposals(NULL, 'holder_match')) = 0 AND (SELECT count(*) FROM mcp_holder_kind_proposals) = 0, 'a Viewer sees no holder proposal');
SELECT pg_temp.check((SELECT holders FROM si_holders_changes_summary(pg_temp.i('sec'), 'nobo', '2024-03-01', '2024-04-04') WHERE state = 'new') > 500, 'the Viewer''s changes are counts by state, never a holder');
SELECT pg_temp.check((SELECT count(*) FROM si_tombstones()) = 0, 'a Viewer reads no tombstone');
SELECT pg_temp.as_member(27);
UPDATE events SET deleted_at = now(), deleted_by = 27 WHERE id = pg_temp.i('ev_short');
SELECT pg_temp.check((SELECT count(*) FROM si_tombstones() WHERE kind = 'event' AND record_id = pg_temp.i('ev_short')) = 1, 'a manager reads the tombstones with their purge date');
UPDATE events SET deleted_at = NULL, deleted_by = NULL WHERE id = pg_temp.i('ev_short');
SELECT pg_temp.check((SELECT count(*) FROM si_source_health()) = 18 AND (SELECT bool_and(user_agent_set) FROM si_source_health() WHERE needs_user_agent) AND (SELECT consecutive_failures FROM si_source_health() WHERE connector = 'edgar_submissions') = 2,
    'si_source_health: eighteen sources, the EDGAR user-agent set, the submissions source''s two failures');
UPDATE agent_dispatches SET status = 'cancelled' WHERE id = (SELECT min(id) FROM agent_dispatches);
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE status = 'cancelled') = 1, 'a dispatch may be cancelled');

-- ---------------------------------------------------------------- SLICE 1's additions (db/020): one gate for any record, notes behind both walls, tags by label
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(si_can_see_record('issuer', pg_temp.i('iss')) AND NOT si_can_see_record('issuer', pg_temp.i('iss2')) AND NOT si_can_see_record('galaxy', 1)
    AND si_can_see_record('participant', (SELECT id FROM participants WHERE number = '0005')), 'si_can_see_record: the open issuer yes, the restricted one no (Priya is off its list), a participant yes, an unknown kind no');
SELECT pg_temp.check(si_can_see_record('holder', (SELECT id FROM holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person' LIMIT 1)), 'an Analyst may see a person-holder''s record');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(NOT si_can_see_record('holder', (SELECT id FROM holders WHERE issuer_id = pg_temp.i('iss') AND kind = 'person' LIMIT 1))
    AND si_can_see_record('holder', (SELECT id FROM holders WHERE name_key = 'ZETA HOLDINGS LLC')), 'a Viewer: no person-holder''s record (the PII wall), an entity''s yes');
SELECT pg_temp.as_member(27);
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('issuer', pg_temp.i('iss2'), 27, 'SMOKE: a note on the restricted issuer'), ('issuer', pg_temp.i('iss'), 27, 'SMOKE: a note on the open issuer');
SELECT pg_temp.check((SELECT count(*) FROM mcp_notes WHERE record_type = 'issuer' AND record_id = pg_temp.i('iss2')) = 1, 'a manager reads the note on the restricted issuer');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_notes WHERE record_type = 'issuer' AND record_id = pg_temp.i('iss2')) = 0 AND (SELECT count(*) FROM mcp_notes WHERE record_type = 'issuer' AND record_id = pg_temp.i('iss')) = 1,
    'mcp_notes walls a restricted issuer''s note from someone off its list (db/016 did not)');
SELECT pg_temp.keep('tg', si_tag_ensure('Watch List', 26));
SELECT pg_temp.check(si_tag_ensure('watch   list', 26) = pg_temp.i('tg') AND (SELECT slug FROM tags WHERE id = pg_temp.i('tg')) = 'watch-list', 'si_tag_ensure: one tag per label in any case and spacing, slugged');
INSERT INTO tags (slug, label) VALUES ('short-report', 'Short report (old)');
SELECT pg_temp.keep('tg2', si_tag_ensure('Short Report', 26));
SELECT pg_temp.check((SELECT slug FROM tags WHERE id = pg_temp.i('tg2')) = 'short-report-2', 'a slug another label holds is numbered');
SELECT pg_temp.refused($$SELECT si_tag_ensure('   ', 26)$$, '1 to 60', 'an empty tag is refused');

-- ---------------------------------------------------------------- the read roles see only the views
RESET ROLE; SET ROLE shareholder_intelligence_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_issuers) = 2 AND (SELECT count(*) FROM mcp_signals) > 10 AND (SELECT count(*) FROM si_search('FRANK')) >= 1, 'the records role reads the views and the search as the member (the two open issuers, not the restricted one)');
SELECT pg_temp.refused($$SELECT count(*) FROM holders$$, 'permission denied', 'the records role has no table');
SELECT pg_temp.refused($$SELECT count(*) FROM mcp_access_tokens$$, 'permission denied', 'the records role never sees a token');
SELECT pg_temp.refused($$SELECT si_rules_run(1, CURRENT_DATE)$$, 'permission denied', 'the records role runs no rule');
RESET ROLE; SET ROLE shareholder_intelligence_activity_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) >= 0 AND (SELECT count(*) FROM mcp_members) = 9, 'the activity role reads the log window and the names');
SELECT pg_temp.refused($$SELECT count(*) FROM mcp_holders$$, 'permission denied', 'the activity role sees no holder');
RESET ROLE;

-- ---------------------------------------------------------------- the count
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'phase0 proof: % check(s) failed', (SELECT count(*) FROM proof WHERE NOT ok); END IF; END $$;
ROLLBACK;
