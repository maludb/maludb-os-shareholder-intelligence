-- Slice 7's crafted fixtures (docs/build-specs/signals-rules.md "Proof"): the Phase 0 proof's public record and position files for the SMOKE
-- issuer (CUSIP 07831X206, the fixtures' own), written so that a run of the rules as of 2024-04-05 raises eighteen of the nineteen on its
-- security and the nineteenth (price_volume_anomaly) on a second issuer with no event; nothing of a real person (the fixtures are the
-- anonymised ones). Run by lib.php as postgres from the repository root (the \copy paths are relative), after the directory sync:
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d <scratch> -f tests/phase3/slice7/seed.sql
\set QUIET on
\set ON_ERROR_STOP on
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
GRANT EXECUTE ON FUNCTION pg_temp.i(text), pg_temp.keep(text, bigint) TO PUBLIC;
SET ROLE shareholder_intelligence_rw;
SELECT set_config('app.member_id', '27', false);
UPDATE si_settings SET escalate_high_to = 27, market_holidays = '{2024-03-29}';

-- the issuers, their securities and counts
-- (rules.php makes the SMOKE issuer first and uploads the SPR fixture through the form; the seed then takes that issuer and security)
INSERT INTO issuers (name, cik, ticker, exchange, transfer_agent, transfer_agent_connector, created_by) SELECT 'SMOKE ISSUER INC', '0000999999', 'SMOK', 'otc', 'Securities Transfer Corporation', 'stc', 27
 WHERE NOT EXISTS (SELECT 1 FROM issuers WHERE cik = '0000999999');
SELECT pg_temp.keep('iss', (SELECT id FROM issuers WHERE cik = '0000999999'));
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) SELECT pg_temp.i('iss'), 'common', 'Common Stock', '07831X206', 'SMOK', true WHERE NOT EXISTS (SELECT 1 FROM securities WHERE cusip = '07831X206');
SELECT pg_temp.keep('sec', (SELECT id FROM securities WHERE cusip = '07831X206'));
INSERT INTO issuers (name, cik, ticker, exchange, created_by) VALUES ('SMOKE SECOND CORP', '0000888888', 'SMOK2', 'nasdaq', 27) RETURNING pg_temp.keep('iss2', id);
INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (pg_temp.i('iss2'), 'common', 'Common Stock', '88888X100', 'SMOK2', true) RETURNING pg_temp.keep('sec2', id);
INSERT INTO share_counts (security_id, as_of, kind, value, source, xbrl_frame, recorded_by) VALUES (pg_temp.i('sec'), '2024-03-25', 'outstanding', 17053900, 'filing', 'CY2024Q1I', NULL), (pg_temp.i('sec'), '2024-03-25', 'float', 12000000, 'manual', NULL, 27),
    (pg_temp.i('sec2'), '2024-03-25', 'outstanding', 20000000, 'filing', 'CY2024Q1I', NULL);

-- THE SPR: the fortnight (participant 0005's crafted jump, the retail boxes shedding ~3%), the month's one-day file of 03-01, and nine one-day files
-- in February with the top five boxes halved — twenty position dates, so the concentration moved
SELECT set_config('app.member_id', '26', false);
CREATE TEMP TABLE t_spr (row_no integer, participant_number text, participant_name text, position_date date, shares numeric);
\copy t_spr FROM 'tests/fixtures/spr_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr_weekly.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'dtc_spr_xlsx', '{"shares_by_date": {"2024-04-04": 1581000}, "participants": 17}', 26) RETURNING pg_temp.keep('spr', id);
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares, raw) SELECT pg_temp.i('spr'), row_no, participant_number, participant_name, position_date, shares, jsonb_build_object('number', participant_number, 'shares', shares::text) FROM t_spr;
SELECT si_spr_load(pg_temp.i('spr'));
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr_march1.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'dtc_spr_xlsx', '{}', 26) RETURNING pg_temp.keep('spr0', id);
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('spr0'), row_no, participant_number, participant_name, '2024-03-01', shares FROM t_spr WHERE position_date = '2024-03-22';
SELECT si_spr_load(pg_temp.i('spr0'));
CREATE TEMP TABLE t_top AS SELECT participant_number FROM t_spr WHERE position_date = '2024-03-22' ORDER BY shares DESC LIMIT 5;
DO $$ DECLARE d date; imp bigint; BEGIN
    FOR d IN SELECT x::date FROM generate_series('2024-02-16'::date, '2024-02-29'::date, interval '1 day') x WHERE extract(isodow FROM x) < 6 AND x::date <> '2024-02-19' LOOP
        INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr_' || d || '.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'dtc_spr_xlsx', '{}', 26) RETURNING id INTO imp;
        INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares)
        SELECT imp, row_no, participant_number, participant_name, d, CASE WHEN participant_number IN (SELECT participant_number FROM t_top) THEN round(shares / 2) ELSE shares END FROM t_spr WHERE position_date = '2024-03-22';
        PERFORM si_spr_load(imp);
    END LOOP;
END $$;

-- NOBO: Schwab's known owners halved between the record dates while its box held (nobo_drain); the broker rows only, no person
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by) VALUES ('nobo', 'nobo_april.xlsx', 'done', pg_temp.i('iss'), pg_temp.i('sec'), 'broadridge_nobo_xlsx', '2024-04-04', true, 26) RETURNING pg_temp.keep('nobo', id);
INSERT INTO nobo_jobs (import_id, security_id, job_number, cusip, record_date, nobo_count_header) VALUES (pg_temp.i('nobo'), pg_temp.i('sec'), 'O08888', '07831X206', '2024-04-04', 120) RETURNING pg_temp.keep('job', id);
INSERT INTO nobo_broker_rows (job_id, row_no, client, participant_id, nobo_count, shares) VALUES (pg_temp.i('job'), 1, 'CHARLES SCHWAB & CO., INC.', si_participant_find('0164'), 120, 300000);
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by) VALUES ('nobo', 'nobo_march.xlsx', 'done', pg_temp.i('iss'), pg_temp.i('sec'), 'broadridge_nobo_xlsx', '2024-03-01', true, 26) RETURNING pg_temp.keep('nobo2', id);
INSERT INTO nobo_jobs (import_id, security_id, job_number, cusip, record_date, nobo_count_header) VALUES (pg_temp.i('nobo2'), pg_temp.i('sec'), 'O07777', '07831X206', '2024-03-01', 240) RETURNING pg_temp.keep('job2', id);
INSERT INTO nobo_broker_rows (job_id, row_no, client, participant_id, nobo_count, shares) VALUES (pg_temp.i('job2'), 1, 'CHARLES SCHWAB & CO., INC.', si_participant_find('0164'), 240, 600000);

-- THE RECONCILIATION: the register (the anonymised fixture) ties Cede to the SPR; a share count typed wrong unties the register side (register_untied)
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, control_totals, created_by) VALUES ('register', 'register_stc.xlsx', 'running', pg_temp.i('iss'), pg_temp.i('sec'), 'stc_position_detail', '2024-04-04', '{"shares": 17053900}', 26) RETURNING pg_temp.keep('reg', id);
CREATE TEMP TABLE t_reg (row_no integer, section text, position_id text, position_type text, holder_ref text, registration_name text, address_line text, city text, state text, country text, zip text, issue_date text, shares numeric, cost_basis text, restriction_text text, percent_owned text, is_employee text, is_officer_director text, is_us_citizen text);
\copy t_reg FROM 'tests/fixtures/register_rows.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO register_rows (import_id, security_id, record_date, row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, issue_date, shares, cost_basis, restriction_text, percent_owned, is_employee, is_officer_director, is_us_citizen)
SELECT pg_temp.i('reg'), pg_temp.i('sec'), '2024-04-04', row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip, NULLIF(issue_date, '')::date, shares, NULLIF(replace(cost_basis, ',', ''), '')::numeric, NULLIF(restriction_text, ''), NULLIF(replace(percent_owned, ',', ''), '')::numeric, is_employee = 'Y', is_officer_director = 'Y', is_us_citizen = 'Y' FROM t_reg;
UPDATE imports SET status = 'done', finished_at = now(), rows_made = 842, derived_totals = '{"shares": 17053900}', tied = true WHERE id = pg_temp.i('reg');
SELECT set_config('app.member_id', '27', false);
INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by, note) VALUES (pg_temp.i('sec'), '2024-04-05', 'outstanding', 17100000, 'manual', 27, 'a typo on purpose');
SELECT si_reconcile(pg_temp.i('sec'), '2024-04-05');
-- the second issuer: its SPR of 04-04 against a DTC letter that says otherwise (spr_untied)
INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, control_totals, created_by) VALUES ('spr', 'spr2.xlsx', 'running', pg_temp.i('iss2'), pg_temp.i('sec2'), 'dtc_spr_xlsx', '{}', 26) RETURNING pg_temp.keep('spr2', id);
INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares) SELECT pg_temp.i('spr2'), row_no, participant_number, participant_name, position_date, shares FROM t_spr WHERE position_date = '2024-04-04';
SELECT si_spr_load(pg_temp.i('spr2'));
INSERT INTO share_counts (security_id, as_of, kind, value, source, recorded_by) VALUES (pg_temp.i('sec2'), '2024-04-04', 'cede', 1500000, 'dtc_letter', 27);
SELECT si_reconcile(pg_temp.i('sec2'), '2024-04-05');

-- THE PUBLIC RECORD: filings (their events), the ELOC confirmed, an activist short report
CREATE TEMP TABLE t_fil (accession text, form text, filed_on date, report_date text, items text, primary_document text, primary_doc_description text, about_us text, filer_cik text);
\copy t_fil FROM 'tests/fixtures/filings_index.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, report_date, items, primary_document, primary_doc_description, about_us)
SELECT pg_temp.i('iss'), filer_cik, accession, form, filed_on, NULLIF(report_date, '')::date, CASE WHEN COALESCE(items, '') = '' THEN '{}'::text[] ELSE string_to_array(items, ';') END, primary_document, primary_doc_description, about_us = '1' FROM t_fil;
INSERT INTO dilution_instruments (issuer_id, security_id, kind, counterparty, filed_on, filing_id, facility_amount, price_terms, floating_price, status, proposed_by, watch_term)
VALUES (pg_temp.i('iss'), pg_temp.i('sec'), 'eloc', 'SMOKE EPSILON CAPITAL LLC', '2024-03-15', (SELECT id FROM filings WHERE form = '8-K'), 10000000, '95% of the lowest VWAP of the five trading days before each purchase', true, 'proposed', 'watch', 'equity purchase agreement') RETURNING pg_temp.keep('eloc', id);
SELECT set_config('app.member_id', '26', false);
UPDATE dilution_instruments SET status = 'active', confirmed_by = 26, confirmed_at = now() WHERE id = pg_temp.i('eloc');
INSERT INTO events (issuer_id, security_id, on_date, kind, title, url, severity, source, created_by) VALUES (pg_temp.i('iss'), pg_temp.i('sec'), '2024-04-03', 'activist_short_report', 'SMOKE Short Research: "SMOK — the equity line nobody read"', 'https://example.invalid/report', 'high', 'person', 26) RETURNING pg_temp.keep('ev_short', id);

-- the ownership forms
CREATE TEMP TABLE t_13f (accession text, sk text, nameofissuer text, titleofclass text, cusip text, figi text, value numeric, sshprnamt numeric, sshprnamttype text, putcall text, discretion text, othermanager text, vsole numeric, vshared numeric, vnone numeric, filer_cik text, filer_name text, period date);
\copy t_13f FROM 'tests/fixtures/13f_infotable.tsv' WITH (FORMAT csv, DELIMITER E'\t', HEADER true)
INSERT INTO institutional_holdings (institution_id, security_id, period_end, accession_number, value_dollars, shares, shares_type, put_call, discretion, voting_sole, voting_shared, voting_none, name_of_issuer, title_of_class, cusip)
SELECT si_institution_upsert(filer_cik, filer_name), pg_temp.i('sec'), period, accession, value, sshprnamt, sshprnamttype, NULLIF(putcall, ''), discretion, vsole, vshared, vnone, nameofissuer, titleofclass, cusip FROM t_13f;
CREATE TEMP TABLE t_bo (filer_cik text, filer_name text, schedule text, is_amendment text, amendment_no text, filed_on date, event_date date, pct numeric, shares numeric, purpose text, filer_type text, accession text);
\copy t_bo FROM 'tests/fixtures/beneficial.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO beneficial_ownership (institution_id, security_id, filing_id, schedule, is_amendment, amendment_no, filed_on, event_date, percent_of_class, shares_beneficially_owned, purpose_text, filer_type)
SELECT si_institution_upsert(filer_cik, filer_name), pg_temp.i('sec'), (SELECT id FROM filings WHERE accession_number = t_bo.accession), schedule, is_amendment = '1', NULLIF(amendment_no, '')::integer, filed_on, event_date, pct, shares, NULLIF(purpose, ''), filer_type FROM t_bo;
CREATE TEMP TABLE t_f4 (accession text, form text, cik text, name text, relationship text, title text, tdate date, code text, shares numeric, price numeric, ad text, after_ numeric, own text, deriv text, row_no integer);
\copy t_f4 FROM 'tests/fixtures/form4.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO insider_transactions (issuer_id, security_id, filing_id, form, reporting_owner_cik, reporting_owner_name, relationship, officer_title, transaction_date, transaction_code, shares, price, acquired_disposed, shares_owned_after, ownership, is_derivative, row_no)
SELECT pg_temp.i('iss'), pg_temp.i('sec'), NULL, form, cik, name, ARRAY[relationship], NULLIF(title, ''), tdate, code, shares, price, ad, after_, own, deriv = '1', row_no FROM t_f4;
INSERT INTO form144_notices (issuer_id, security_id, filing_id, filed_on, seller_name, seller_cik, relationship, shares_to_sell, aggregate_market_value, approx_sale_date, broker, shares_outstanding_stated)
VALUES (pg_temp.i('iss'), pg_temp.i('sec'), (SELECT id FROM filings WHERE form = '144'), '2024-04-01', 'SMITH ROBERT A', '0000099990', 'officer', 100000, 410000, '2024-04-05', 'SMOKE BROKER LLC', 17053900);

-- the short side and prices
CREATE TEMP TABLE t_si (sd date, pub date, cur numeric, prev numeric, chg numeric, adv numeric, dtc numeric, rev text);
\copy t_si FROM 'tests/fixtures/short_interest.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO short_interest (security_id, settlement_date, published_on, short_shares, previous_short_shares, change_shares, avg_daily_volume, days_to_cover, revision_flag, market, source_id)
SELECT pg_temp.i('sec'), sd, pub, cur, prev, chg, adv, dtc, rev = 'Y', 'OTC', (SELECT id FROM sources WHERE connector = 'finra_short_interest') FROM t_si;
INSERT INTO short_interest (security_id, settlement_date, published_on, short_shares, avg_daily_volume, days_to_cover, revision_flag, observed_at) VALUES (pg_temp.i('sec'), '2024-03-28', '2024-04-10', 530000, 47000, 11.28, true, now() + interval '1 second');
CREATE TEMP TABLE t_sv (d text, sym text, sv numeric, sev numeric, tv numeric, mkt text);
\copy t_sv FROM 'tests/fixtures/CNMSshvol.txt' WITH (FORMAT csv, DELIMITER '|', HEADER true)
INSERT INTO daily_short_volume (security_id, trade_date, market, short_volume, short_exempt_volume, total_volume, source_id) SELECT pg_temp.i('sec'), to_date(d, 'YYYYMMDD'), 'CNMS', sv, sev, tv, (SELECT id FROM sources WHERE connector = 'finra_daily_short_volume') FROM t_sv;
CREATE TEMP TABLE t_ftd (sd text, cusip text, sym text, q numeric, descr text, price numeric);
\copy t_ftd FROM 'tests/fixtures/cnsfails.txt' WITH (FORMAT csv, DELIMITER '|', HEADER true)
INSERT INTO fails_to_deliver (security_id, settlement_date, quantity, price, file_half, source_id) SELECT pg_temp.i('sec'), to_date(sd, 'YYYYMMDD'), q, price, 'a', (SELECT id FROM sources WHERE connector = 'sec_ftd') FROM t_ftd;
CREATE TEMP TABLE t_th (d date, l text, f text);
\copy t_th FROM 'tests/fixtures/threshold_days.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO threshold_listings (security_id, list_date, list, flag, source_id) SELECT pg_temp.i('sec'), d, l, f, (SELECT id FROM sources WHERE connector = 'threshold_nasdaq') FROM t_th;
INSERT INTO borrow_observations (security_id, observed_on, source, fee_rate_percent, utilization_percent, note, recorded_by) VALUES (pg_temp.i('sec'), '2024-04-04', 'broker_quote', 35.5, 92, 'indicative from the prime''s desk', 26);
CREATE TEMP TABLE t_px (d date, o numeric, h numeric, l numeric, c numeric, v numeric);
\copy t_px FROM 'tests/fixtures/prices_stooq.csv' WITH (FORMAT csv, HEADER true)
INSERT INTO prices (security_id, trade_date, open, high, low, close, volume) SELECT pg_temp.i('sec'), d, o, h, l, c, v FROM t_px;
INSERT INTO prices (security_id, trade_date, open, high, low, close, volume) SELECT pg_temp.i('sec2'), d, o, h, l, c, v FROM t_px;
RESET ROLE;
\set QUIET off
SELECT 'seeded' AS done, pg_temp.i('iss') AS iss, pg_temp.i('sec') AS sec, pg_temp.i('iss2') AS iss2, pg_temp.i('sec2') AS sec2, pg_temp.i('ev_short') AS ev_short, pg_temp.i('eloc') AS eloc;
