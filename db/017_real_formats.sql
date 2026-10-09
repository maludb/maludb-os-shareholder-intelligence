-- 017: what the owner's REAL FILES taught (2026-10-09, after Phase 0's proof on the reference files; design §0.2, §6.2, §16).
--
-- The owner put 186 files in tests/fixtures/real/: DTC's weekly SPR as a CSV and, for the first weeks, DTC's ONE-TIME report ("Position as of",
-- participant number without leading zeros, name, quantity, no Total row); the weekly SPR (a title block, a two-row header with an OPENING
-- column — the previous Friday — then Monday–Friday, a Share Change and a Percent Change, and a TOTAL row per date: the control
-- total the file carries itself); Broadridge's NOBO job file as a CSV (the reference layout, shares as zero-padded decimals
-- "000461260.00000") and Broadridge's newer "names" export (first/middle/last name, last active date, shares, a parsed address);
-- Broadridge's SHARE RANGE ANALYSIS workbooks (two sheets: the pre-suppression holder count by range, and the OBO COUNT by range —
-- the one place an issuer sees its objecting owners' distribution); the transfer agent's SHAREHOLDER EXTRACT (one row per holder
-- with the shares by position type in columns — CERT, BOOK, DRS, BOOKR, PLAN1–4, TOTAL — and tax ids, dates of birth, phones and
-- emails the application NEVER stores). Additive: new connectors, a widened `imports.kind`, and the share-range tables.
BEGIN;

ALTER TABLE imports DROP CONSTRAINT imports_connector_check;
ALTER TABLE imports ADD CONSTRAINT imports_connector_check CHECK (connector IS NULL OR connector IN (
    'dtc_spr_xlsx', 'dtc_spr_csv', 'dtc_spr_onetime_csv', 'dtc_spr_ccf', 'broadridge_nobo_xlsx', 'broadridge_nobo_csv', 'broadridge_nobo_names_xlsx', 'broadridge_share_range_xls',
    'stc_position_detail', 'computershare_holder_list', 'ta_extract_csv', 'generic_mapped', 'prices_csv'));
ALTER TABLE imports DROP CONSTRAINT imports_kind_check;
ALTER TABLE imports ADD CONSTRAINT imports_kind_check CHECK (kind IN ('markdown_zip', 'notion_zip', 'csv', 'markdown', 'spr', 'nobo', 'register', 'prices_csv', 'holders_csv', 'voted_unvoted', 'share_range'));
ALTER TABLE import_templates DROP CONSTRAINT import_templates_kind_check;
ALTER TABLE import_templates ADD CONSTRAINT import_templates_kind_check CHECK (kind IN ('spr', 'nobo', 'register', 'prices_csv', 'holders_csv', 'share_range'));

-- The SPR's OPENING column: the previous Friday's close, carried by the file. si_spr_load keeps every date the rows carry; an opening
-- date that overlaps the previous file's Friday replaces it (a corrected figure) — the bridge is the file's own.
COMMENT ON TABLE spr_rows IS 'One row per participant per position date, unpivoted from DTC''s weekly CSV: the Opening column (the previous Friday) and Monday–Friday. The file''s Total row per date is the control total (imports.control_totals.shares_by_date).';

-- BROADRIDGE'S SHARE RANGE ANALYSIS: accounts and shares by share range, for every holder (pre-suppression) and for the OBOs alone.
CREATE TABLE share_range_reports (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    import_id      bigint NOT NULL UNIQUE REFERENCES imports(id) ON DELETE CASCADE,
    security_id    bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    record_date    date NOT NULL,
    job_number     text,
    source_kind    text NOT NULL DEFAULT 'broadridge_xls' CHECK (source_kind IN ('broadridge_xls', 'broadridge_pdf', 'manual')),
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX share_range_reports_security_idx ON share_range_reports (security_id, record_date DESC);
CREATE TABLE share_range_rows (
    report_id      bigint NOT NULL REFERENCES share_range_reports(id) ON DELETE CASCADE,
    population     text NOT NULL CHECK (population IN ('all', 'obo', 'nobo')),      -- the sheet: "Share Range - Holder Count" = all (pre-suppression), "OBO Count" = obo; nobo derived = all − obo
    range_label    text NOT NULL,                                                   -- as the sheet labels it ("100 - 249")
    range_from     numeric(20,0) NOT NULL,
    range_to       numeric(20,0),                                                   -- NULL = and above
    accounts       integer NOT NULL CHECK (accounts >= 0),
    shares         numeric(20,2) NOT NULL CHECK (shares >= 0),
    PRIMARY KEY (report_id, population, range_from)
);
-- The latest report's ranges with the NOBO population derived (all − obo), for the Viewer's ranges screen and the morning note.
CREATE OR REPLACE FUNCTION si_share_ranges(p_security bigint, p_record_date date DEFAULT NULL)
    RETURNS TABLE (record_date date, range_label text, range_from numeric, all_accounts integer, all_shares numeric, obo_accounts integer, obo_shares numeric, nobo_accounts integer, nobo_shares numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH r AS (SELECT * FROM share_range_reports WHERE security_id = p_security AND (p_record_date IS NULL OR record_date <= p_record_date) ORDER BY record_date DESC LIMIT 1),
         a AS (SELECT * FROM share_range_rows x, r WHERE x.report_id = r.id AND x.population = 'all'),
         o AS (SELECT * FROM share_range_rows x, r WHERE x.report_id = r.id AND x.population = 'obo')
    SELECT r.record_date, a.range_label, a.range_from, a.accounts, a.shares, COALESCE(o.accounts, 0), COALESCE(o.shares, 0), a.accounts - COALESCE(o.accounts, 0), a.shares - COALESCE(o.shares, 0)
      FROM r JOIN a ON true LEFT JOIN o ON o.range_from = a.range_from ORDER BY a.range_from;
$$;
-- The OBO population's totals over time: the count and shares an issuer cannot otherwise know.
CREATE OR REPLACE FUNCTION si_obo_totals(p_security bigint)
    RETURNS TABLE (record_date date, all_accounts bigint, all_shares numeric, obo_accounts bigint, obo_shares numeric, obo_pct_of_accounts numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT r.record_date, sum(x.accounts) FILTER (WHERE x.population = 'all'), sum(x.shares) FILTER (WHERE x.population = 'all'),
           sum(x.accounts) FILTER (WHERE x.population = 'obo'), sum(x.shares) FILTER (WHERE x.population = 'obo'),
           round(sum(x.accounts) FILTER (WHERE x.population = 'obo')::numeric / NULLIF(sum(x.accounts) FILTER (WHERE x.population = 'all'), 0) * 100, 2)
      FROM share_range_reports r JOIN share_range_rows x ON x.report_id = r.id WHERE r.security_id = p_security GROUP BY r.record_date ORDER BY r.record_date;
$$;
CREATE OR REPLACE VIEW mcp_share_range_reports WITH (security_barrier = true) AS
SELECT r.id AS share_range_report_id, r.import_id, r.security_id, r.record_date, r.job_number, r.source_kind FROM share_range_reports r WHERE r.security_id IN (SELECT id FROM securities WHERE issuer_id IN (SELECT si_my_issuers()));
CREATE OR REPLACE VIEW mcp_share_range_rows WITH (security_barrier = true) AS
SELECT x.report_id AS share_range_report_id, x.population, x.range_label, x.range_from, x.range_to, x.accounts, x.shares FROM share_range_rows x WHERE x.report_id IN (SELECT share_range_report_id FROM mcp_share_range_reports);

-- The transfer agent's extract: one row per holder, the shares by position type in columns. The parser emits ONE register_rows row per
-- non-zero type column (cert, book, drs, bookr → restricted — the insiders' restricted book shares, the bulk of a small cap's outstanding —, plan1–4 → plan) so the reconciliation's categories read as before; `position_type`
-- carries the column's name; the agent's account number is holder_ref; OWNERSHIP TYPE becomes the account kind. TAX-ID, TAX-ID 2, DATE OF
-- BIRTH, PHONE NUMBER, EMAIL ADDRESS, WITHHOLDING STATUS, NRA columns are DROPPED before any row is stored — not in raw, not anywhere.
INSERT INTO import_templates (name, kind, connector, mapping) VALUES
 ('Transfer agent — Shareholder Extract (CSV)', 'register', 'ta_extract_csv', '{"header_row": 1, "quoted_excel_cells": true, "columns": {"holder_ref": "ACCT#", "registration_name": ["HOLDER REG 1", "HOLDER REG 2", "HOLDER REG 3", "HOLDER REG 4", "HOLDER REG 5", "HOLDER REG 6"], "address_line": ["HOLDER ADDRESS 1", "HOLDER ADDRESS 2", "HOLDER ADDRESS 3"], "city": "CITY", "state": "STATE", "zip": "ZIP CODE", "country": "COUNTRY", "account_kind": "OWNERSHIP TYPE", "issue_date": "DATE OPENED"}, "shares_by_type": {"CERT": "cert", "BOOK": "book", "DRS": "drs", "BOOKR": "restricted", "PLAN1": "plan", "PLAN2": "plan", "PLAN3": "plan", "PLAN4": "plan"}, "total_column": "TOTAL SHARES", "drop_columns": ["TAX-ID", "TAX-ID 2", "DATE OF BIRTH", "PHONE NUMBER", "EMAIL ADDRESS", "WITHHOLDING STATUS", "TAX CERTIFICATE DATE", "SUBJECT TO NRA", "NRA RATE", "CHAPTER 3", "CHAPTER 4", "CITIZENSHIP", "LAST CONTACT"], "cede_marker": "CEDE & CO"}'),
 ('DTC — SPR weekly (CSV)', 'spr', 'dtc_spr_csv', '{"title_rows_until": "Participant,Participant", "header_rows": 2, "columns": {"participant_number": 1, "participant_name": 2, "opening": 3, "monday": 4, "tuesday": 5, "wednesday": 6, "thursday": 7, "friday": 8, "share_change": 9, "percent_change": 10}, "dates_row": 2, "total_row_marker": "Total", "cusip_from": "Security Description,Cusip", "no_balance_sentinel": ["-9999999999999", "Holiday"]}'),
 ('DTC — One Time Security Position Report (CSV)', 'spr', 'dtc_spr_onetime_csv', '{"title_rows_until": "Number,", "as_of_from": "Position as of:", "columns": {"participant_number": 1, "participant_name": 3, "shares": 5}, "zero_pad_number": 4, "control": "the sum of the rows (no Total row)"}'),
 ('Broadridge — NOBO list (CSV)', 'nobo', 'broadridge_nobo_csv', '{"header": {"job_number": "A2", "cusip": "B2", "issuer_name": "C2", "record_date": "D2", "sort_order": "E2", "nobo_count": "F2"}, "shares_section_from_row": 5, "shares_format": "zero_padded_decimal", "columns": {"shares": "A", "line1": "B", "line2": "C", "line3": "D", "line4": "E", "line5": "F", "line6": "G", "line7": "H", "zip_sort": "I", "cusip": "J"}, "clients_section_marker": "CUSIP", "clients_columns": {"cusip": "A", "client": "B", "nobo_count": "C", "shares": "D"}}'),
 ('Broadridge — NOBO names export (xlsx)', 'nobo', 'broadridge_nobo_names_xlsx', '{"header_row": 3, "columns": {"first_name": "First name", "middle_name": "Middle name", "last_name": "Last name", "last_active_date": "Last active date", "shares": "Shares", "address": "Address", "city": "City", "state": "State", "country": "Country", "zip": "ZIP code"}, "note": "the address columns shift on some rows in the owner''s sample; the parser re-joins them by the zip and the state"}'),
 ('Broadridge — Share Range Analysis (xls)', 'share_range', 'broadridge_share_range_xls', '{"sheets": {"all": "Share Range - Holder Count", "obo": "OBO Count"}, "header_row": 2, "columns": {"range_label": "A", "accounts": "B", "shares": "C"}, "record_date_from_filename": "_(\\\\d{8})\\\\.xls$"}');

GRANT SELECT, INSERT, UPDATE, DELETE ON share_range_reports, share_range_rows TO shareholder_intelligence_rw;
GRANT SELECT ON mcp_share_range_reports, mcp_share_range_rows TO shareholder_intelligence_records_ro, shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_share_ranges(bigint, date), si_obo_totals(bigint) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;

COMMIT;
