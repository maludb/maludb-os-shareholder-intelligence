-- 013: SIGNALS — the rules, their evidence class, the signals they raise, and what a person does with them (design §6.6; D11;
-- docs/build-specs/signals.md).
--
-- A rule is a SQL function si_rule_<key>(security, as_of) → zero or one row (value, threshold, baseline, severity, window, evidence,
-- summary); si_rules_run() runs every enabled rule for every security the worker names after a pull or an upload, keeps ONE open signal
-- per rule per security (a repeat refreshes the window), expires signals past their window + grace, and never sends anything. The
-- EVIDENCE CLASS (established / contested / lore / data_defect) is on the rule and printed on every signal; the summary is a template's
-- sentence, never a model's. A person disposes with a reason and, when it explains the signal, an event. signal_outcomes keeps the price
-- after, so the Auditor can test the lore.
--
-- THE ESTATE'S DATA MODEL (design §6.8): signal_rules and signals are NEW CANONICAL (Inventory's `watches` compared: a threshold on one
-- variant, without a class or a window — a different concept).
BEGIN;

CREATE TABLE signal_rules (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key              text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name             text NOT NULL,
    description      text NOT NULL,
    evidence_class   text NOT NULL CHECK (evidence_class IN ('established', 'contested', 'lore', 'data_defect')),
    basis            text NOT NULL,                                                 -- the paragraph of §0.1 it rests on
    severity_default text NOT NULL DEFAULT 'watch' CHECK (severity_default IN ('info', 'watch', 'high')),
    params           jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(params) = 'object'),   -- the thresholds, with defaults
    window_days      integer NOT NULL DEFAULT 30 CHECK (window_days BETWEEN 1 AND 730),
    applies_to       text[] NOT NULL DEFAULT '{common}',
    enabled          boolean NOT NULL DEFAULT true,
    sort_order       integer NOT NULL DEFAULT 0,
    updated_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER signal_rules_touch BEFORE UPDATE ON signal_rules FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- a per-issuer override of a rule's params or enabled
CREATE TABLE signal_rule_overrides (
    rule_id     bigint NOT NULL REFERENCES signal_rules(id) ON DELETE CASCADE,
    issuer_id   bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    params      jsonb NOT NULL DEFAULT '{}'::jsonb,
    enabled     boolean,
    updated_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (rule_id, issuer_id)
);

CREATE TABLE signals (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    rule_id             bigint NOT NULL REFERENCES signal_rules(id) ON DELETE CASCADE,
    issuer_id           bigint NOT NULL REFERENCES issuers(id) ON DELETE CASCADE,
    security_id         bigint NOT NULL REFERENCES securities(id) ON DELETE CASCADE,
    raised_on           date NOT NULL,
    window_from         date NOT NULL,
    window_to           date NOT NULL,
    severity            text NOT NULL CHECK (severity IN ('info', 'watch', 'high')),
    value               numeric,
    threshold           numeric,
    baseline            numeric,
    evidence            jsonb NOT NULL DEFAULT '{}'::jsonb,
    summary             text NOT NULL,
    status              text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'acknowledged', 'dismissed', 'escalated', 'expired')),
    disposition_reason  text CHECK (disposition_reason IS NULL OR disposition_reason IN ('explained_by_event', 'data_defect', 'known', 'watching', 'false_positive', 'escalated', 'other')),
    disposition_note    text,
    disposed_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    disposed_at         timestamptz,
    event_id            bigint REFERENCES events(id) ON DELETE SET NULL,
    proposal_by         bigint REFERENCES members(id) ON DELETE SET NULL,          -- an agent's proposed disposition
    proposal_reason     text,
    proposal_note       text,
    proposal_event_id   bigint REFERENCES events(id) ON DELETE SET NULL,
    proposal_at         timestamptz,
    repeat_count        integer NOT NULL DEFAULT 0,                                 -- the rule fired again in an overlapping window
    expires_on          date NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (window_to >= window_from),
    CHECK ((status IN ('acknowledged', 'dismissed', 'escalated')) = (disposed_at IS NOT NULL))
);
CREATE UNIQUE INDEX signals_one_open ON signals (rule_id, security_id) WHERE status = 'open';
CREATE INDEX signals_issuer_idx ON signals (issuer_id, status, severity, raised_on DESC);
CREATE INDEX signals_security_idx ON signals (security_id, raised_on DESC);
CREATE TRIGGER signals_touch BEFORE UPDATE ON signals FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The price after a signal (the Auditor's test of the lore): filled by the worker as the days pass.
CREATE TABLE signal_outcomes (
    signal_id      bigint PRIMARY KEY REFERENCES signals(id) ON DELETE CASCADE,
    price_at       numeric(14,4),
    price_5d       numeric(14,4),
    price_20d      numeric(14,4),
    price_60d      numeric(14,4),
    return_5d      numeric(8,4),
    return_20d     numeric(8,4),
    return_60d     numeric(8,4),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

-- the rules' answer shape
DROP TYPE IF EXISTS si_rule_result CASCADE;
CREATE TYPE si_rule_result AS (severity text, value numeric, threshold numeric, baseline numeric, window_from date, window_to date, evidence jsonb, summary text);

-- a rule's effective params for an issuer
CREATE OR REPLACE FUNCTION si_rule_params(p_rule_key text, p_issuer bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT r.params || COALESCE((SELECT o.params FROM signal_rule_overrides o WHERE o.rule_id = r.id AND o.issuer_id = p_issuer), '{}'::jsonb) FROM signal_rules r WHERE r.key = p_rule_key;
$$;
CREATE OR REPLACE FUNCTION si_rule_enabled(p_rule_key text, p_issuer bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT o.enabled FROM signal_rule_overrides o JOIN signal_rules r ON r.id = o.rule_id WHERE r.key = p_rule_key AND o.issuer_id = p_issuer), (SELECT enabled FROM signal_rules WHERE key = p_rule_key), false);
$$;
CREATE OR REPLACE FUNCTION si_fmt(n numeric) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT to_char(n, 'FM999,999,999,999,990.##'); $$;

-- ---------------------------------------------------------------------------------------------
-- THE RULES (§6.6). Each: NULL = silent; a row = a signal. p_as_of is the day the worker runs.
-- ---------------------------------------------------------------------------------------------
-- established: short interest ≥ 10% of float (watch), ≥ 20% (high); or days to cover ≥ 5 (watch), ≥ 10 (high)
CREATE OR REPLACE FUNCTION si_rule_si_over_float(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('si_over_float', (SELECT issuer_id FROM securities WHERE id = p_security)); s record; sev text; r si_rule_result;
BEGIN
    SELECT * INTO s FROM si_short_vs_float(p_security, p_as_of);
    IF s.settlement_date IS NULL OR s.settlement_date < p_as_of - 45 THEN RETURN NULL; END IF;
    IF (s.pct_of_float IS NOT NULL AND s.pct_of_float >= (pr ->> 'float_pct_high')::numeric) OR (s.days_to_cover IS NOT NULL AND s.days_to_cover >= (pr ->> 'dtc_high')::numeric) THEN sev := 'high';
    ELSIF (s.pct_of_float IS NOT NULL AND s.pct_of_float >= (pr ->> 'float_pct_watch')::numeric) OR (s.days_to_cover IS NOT NULL AND s.days_to_cover >= (pr ->> 'dtc_watch')::numeric) THEN sev := 'watch';
    ELSE RETURN NULL; END IF;
    r := (sev, COALESCE(s.pct_of_float, s.days_to_cover), (pr ->> 'float_pct_watch')::numeric, s.float_shares, s.settlement_date, s.settlement_date,
          jsonb_build_object('settlement_date', s.settlement_date, 'short_shares', s.short_shares, 'float', s.float_shares, 'pct_of_float', s.pct_of_float, 'days_to_cover', s.days_to_cover, 'avg_daily_volume', s.avg_daily_volume),
          format('Short interest at %s was %s shares — %s%% of the float, %s days to cover.', s.settlement_date, si_fmt(s.short_shares), COALESCE(s.pct_of_float::text, '?'), COALESCE(s.days_to_cover::text, '?')));
    RETURN r;
END$$;
-- established: short interest up ≥ 25% settlement over settlement with volume not up as much
CREATE OR REPLACE FUNCTION si_rule_si_change(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('si_change', (SELECT issuer_id FROM securities WHERE id = p_security)); cur record; prev record; volchg numeric;
BEGIN
    SELECT * INTO cur FROM si_short_interest_series(p_security, p_as_of - 60, p_as_of) ORDER BY settlement_date DESC LIMIT 1;
    IF cur.settlement_date IS NULL OR cur.settlement_date < p_as_of - 45 THEN RETURN NULL; END IF;
    SELECT * INTO prev FROM si_short_interest_series(p_security, p_as_of - 90, cur.settlement_date - 1) ORDER BY settlement_date DESC LIMIT 1;
    IF prev.settlement_date IS NULL OR prev.short_shares = 0 THEN RETURN NULL; END IF;
    volchg := CASE WHEN prev.avg_daily_volume > 0 THEN (cur.avg_daily_volume - prev.avg_daily_volume) / prev.avg_daily_volume * 100 ELSE 0 END;
    IF (cur.short_shares - prev.short_shares) / prev.short_shares * 100 >= (pr ->> 'pct')::numeric AND COALESCE(volchg, 0) < (cur.short_shares - prev.short_shares) / prev.short_shares * 100 THEN
        RETURN ('watch', round((cur.short_shares - prev.short_shares) / prev.short_shares * 100, 2), (pr ->> 'pct')::numeric, prev.short_shares, prev.settlement_date, cur.settlement_date,
                jsonb_build_object('from', prev.settlement_date, 'to', cur.settlement_date, 'short_from', prev.short_shares, 'short_to', cur.short_shares, 'volume_change_pct', round(COALESCE(volchg, 0), 1)),
                format('Short interest rose %s%% between %s and %s (%s → %s shares) while average volume moved %s%%.', round((cur.short_shares - prev.short_shares) / prev.short_shares * 100, 1), prev.settlement_date, cur.settlement_date, si_fmt(prev.short_shares), si_fmt(cur.short_shares), round(COALESCE(volchg, 0), 1)))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- established: fee ≥ 20% or utilisation ≥ 80% in the latest observation
CREATE OR REPLACE FUNCTION si_rule_borrow_fee(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('borrow_fee', (SELECT issuer_id FROM securities WHERE id = p_security)); b record;
BEGIN
    SELECT * INTO b FROM borrow_observations WHERE security_id = p_security AND observed_on BETWEEN p_as_of - 14 AND p_as_of ORDER BY observed_on DESC, recorded_at DESC LIMIT 1;
    IF b.id IS NULL THEN RETURN NULL; END IF;
    IF COALESCE(b.fee_rate_percent, 0) >= (pr ->> 'fee_pct')::numeric OR COALESCE(b.utilization_percent, 0) >= (pr ->> 'utilization_pct')::numeric THEN
        RETURN ('watch', COALESCE(b.fee_rate_percent, b.utilization_percent), (pr ->> 'fee_pct')::numeric, NULL, b.observed_on, b.observed_on,
                jsonb_build_object('observed_on', b.observed_on, 'source', b.source, 'fee_rate_percent', b.fee_rate_percent, 'utilization_percent', b.utilization_percent),
                format('Borrow on %s (%s): fee %s%%, utilisation %s%%.', b.observed_on, b.source, COALESCE(b.fee_rate_percent::text, '?'), COALESCE(b.utilization_percent::text, '?')))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- contested: fails ≥ 0.5% of outstanding on any day (high), or ≥ 3× the 60-day median (watch)
CREATE OR REPLACE FUNCTION si_rule_ftd_spike(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('ftd_spike', (SELECT issuer_id FROM securities WHERE id = p_security)); f record; w integer := COALESCE((pr ->> 'window_days')::integer, 20);
BEGIN
    SELECT * INTO f FROM si_fails_series(p_security, p_as_of - w, p_as_of) s
     WHERE (s.pct_of_outstanding >= (pr ->> 'pct_outstanding_high')::numeric) OR (s.median_60 > 0 AND s.quantity >= s.median_60 * (pr ->> 'median_multiple')::numeric)
     ORDER BY s.quantity DESC LIMIT 1;
    IF f.settlement_date IS NULL THEN RETURN NULL; END IF;
    RETURN (CASE WHEN f.pct_of_outstanding >= (pr ->> 'pct_outstanding_high')::numeric THEN 'high' ELSE 'watch' END, f.quantity, (pr ->> 'pct_outstanding_high')::numeric, f.median_60, p_as_of - w, p_as_of,
            jsonb_build_object('settlement_date', f.settlement_date, 'quantity', f.quantity, 'pct_of_outstanding', f.pct_of_outstanding, 'median_60', f.median_60),
            format('Fails to deliver of %s shares settled %s — %s%% of outstanding; the 60-day median was %s. A Rule 204 flag, not proof of anything.', si_fmt(f.quantity), f.settlement_date, COALESCE(f.pct_of_outstanding::text, '?'), COALESCE(si_fmt(f.median_60), 'unknown')))::si_rule_result;
END$$;
-- contested: on a threshold list (high); stays 5+ days (high, repeated)
CREATE OR REPLACE FUNCTION si_rule_threshold_list(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE t record;
BEGIN
    SELECT * INTO t FROM si_threshold_status(p_security);
    IF NOT COALESCE(t.on_list, false) OR t.latest_list_date < p_as_of - 7 THEN RETURN NULL; END IF;
    RETURN ('high', t.consecutive_days, 1, NULL, t.since, t.latest_list_date,
            jsonb_build_object('list', t.list, 'since', t.since, 'consecutive_days', t.consecutive_days, 'latest_list_date', t.latest_list_date),
            format('On the %s Reg SHO threshold list since %s (%s consecutive list days).', t.list, t.since, t.consecutive_days))::si_rule_result;
END$$;
-- contested: the 5-day mean off-exchange short-volume ratio ≥ 60% AND rising 10 points over 20 days; never alone high
CREATE OR REPLACE FUNCTION si_rule_short_volume_ratio(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('short_volume_ratio', (SELECT issuer_id FROM securities WHERE id = p_security)); cur record; past record;
BEGIN
    SELECT * INTO cur FROM si_daily_short_volume_series(p_security, 'CNMS', p_as_of - 30, p_as_of) ORDER BY trade_date DESC LIMIT 1;
    IF cur.trade_date IS NULL OR cur.trade_date < p_as_of - 5 THEN RETURN NULL; END IF;
    SELECT * INTO past FROM si_daily_short_volume_series(p_security, 'CNMS', p_as_of - 60, p_as_of - 20) ORDER BY trade_date DESC LIMIT 1;
    IF cur.mean_5 * 100 >= (pr ->> 'ratio_pct')::numeric AND past.mean_5 IS NOT NULL AND (cur.mean_5 - past.mean_5) * 100 >= (pr ->> 'rise_points')::numeric THEN
        RETURN ('watch', round(cur.mean_5 * 100, 1), (pr ->> 'ratio_pct')::numeric, round(past.mean_5 * 100, 1), past.trade_date, cur.trade_date,
                jsonb_build_object('trade_date', cur.trade_date, 'mean_5_pct', round(cur.mean_5 * 100, 1), 'mean_5_pct_20_days_ago', round(past.mean_5 * 100, 1), 'caveat', cur.caveat),
                format('The 5-day mean of FINRA''s off-exchange short-volume ratio is %s%% (it was %s%% twenty days ago). %s', round(cur.mean_5 * 100, 1), round(past.mean_5 * 100, 1), cur.caveat))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- lore: net flow from the retail side to prime + lending + market maker ≥ 2% of the SPR total over 10 position days
CREATE OR REPLACE FUNCTION si_rule_prime_migration(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('prime_migration', (SELECT issuer_id FROM securities WHERE id = p_security)); d_to date; d_from date; retail numeric; other numeric; tot numeric; nd integer := COALESCE((pr ->> 'position_days')::integer, 10);
BEGIN
    SELECT max(position_date) INTO d_to FROM participant_positions WHERE security_id = p_security AND position_date <= p_as_of;
    IF d_to IS NULL OR d_to < p_as_of - 14 THEN RETURN NULL; END IF;
    SELECT position_date INTO d_from FROM (SELECT DISTINCT position_date FROM participant_positions WHERE security_id = p_security AND position_date <= d_to ORDER BY position_date DESC OFFSET nd LIMIT 1) x;
    IF d_from IS NULL THEN RETURN NULL; END IF;
    SELECT sum(net) FILTER (WHERE side = 'retail'), sum(net) FILTER (WHERE side IN ('institutional', 'intermediary')), sum(close_shares) INTO retail, other, tot FROM si_participant_flow(p_security, d_from, d_to);
    IF tot IS NULL OR tot = 0 THEN RETURN NULL; END IF;
    IF retail < 0 AND other > 0 AND LEAST(-retail, other) / tot * 100 >= (pr ->> 'pct_of_total')::numeric THEN
        RETURN ('watch', round(LEAST(-retail, other) / tot * 100, 2), (pr ->> 'pct_of_total')::numeric, tot, d_from, d_to,
                jsonb_build_object('from', d_from, 'to', d_to, 'retail_net', retail, 'other_net', other, 'spr_total', tot, 'class', 'lore'),
                format('Between %s and %s the retail boxes shed %s shares while prime, lending and market-maker boxes gained %s (%s%% of the DTC total). Practitioner lore: no published validation.', d_from, d_to, si_fmt(-retail), si_fmt(other), round(LEAST(-retail, other) / tot * 100, 2)))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- lore / info: one participant moved ≥ 1% of the total in a day, or a new one holds ≥ 0.5%
CREATE OR REPLACE FUNCTION si_rule_participant_jump(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('participant_jump', (SELECT issuer_id FROM securities WHERE id = p_security)); d_to date; tot numeric; c record;
BEGIN
    SELECT max(position_date) INTO d_to FROM participant_positions WHERE security_id = p_security AND position_date <= p_as_of;
    IF d_to IS NULL OR d_to < p_as_of - 14 THEN RETURN NULL; END IF;
    tot := si_spr_total(p_security, d_to);
    IF COALESCE(tot, 0) = 0 THEN RETURN NULL; END IF;
    SELECT * INTO c FROM si_position_changes(p_security, d_to - 7, d_to) x WHERE abs(x.largest_move) / tot * 100 >= (pr ->> 'move_pct')::numeric ORDER BY abs(x.largest_move) DESC LIMIT 1;
    IF c.participant_id IS NOT NULL THEN
        RETURN ('info', round(c.largest_move / tot * 100, 2), (pr ->> 'move_pct')::numeric, tot, c.largest_move_date, c.largest_move_date,
                jsonb_build_object('participant_id', c.participant_id, 'number', c.number, 'name', c.name, 'class', c.class_key, 'move', c.largest_move, 'on', c.largest_move_date, 'spr_total', tot),
                format('%s (%s, %s) moved %s shares on %s — %s%% of the DTC total.', c.name, c.number, c.class_key, si_fmt(c.largest_move), c.largest_move_date, round(c.largest_move / tot * 100, 2)))::si_rule_result;
    END IF;
    SELECT * INTO c FROM si_participants_new_lost(p_security, d_to - 7, d_to) x WHERE x.state = 'new' AND x.shares / tot * 100 >= (pr ->> 'new_pct')::numeric ORDER BY x.shares DESC LIMIT 1;
    IF c.participant_id IS NOT NULL THEN
        RETURN ('info', round(c.shares / tot * 100, 2), (pr ->> 'new_pct')::numeric, tot, d_to - 7, d_to,
                jsonb_build_object('participant_id', c.participant_id, 'number', c.number, 'name', c.name, 'class', c.class_key, 'shares', c.shares, 'spr_total', tot),
                format('%s (%s, %s) is new to the file with %s shares — %s%% of the DTC total.', c.name, c.number, c.class_key, si_fmt(c.shares), round(c.shares / tot * 100, 2)))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- lore: the top-5 share of the SPR total moved ≥ 5 points over 20 position days
CREATE OR REPLACE FUNCTION si_rule_concentration_shift(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('concentration_shift', (SELECT issuer_id FROM securities WHERE id = p_security)); d_to date; d_from date; a record; b record;
BEGIN
    SELECT max(position_date) INTO d_to FROM participant_positions WHERE security_id = p_security AND position_date <= p_as_of;
    IF d_to IS NULL OR d_to < p_as_of - 14 THEN RETURN NULL; END IF;
    SELECT position_date INTO d_from FROM (SELECT DISTINCT position_date FROM participant_positions WHERE security_id = p_security AND position_date <= d_to ORDER BY position_date DESC OFFSET COALESCE((pr ->> 'position_days')::integer, 20) LIMIT 1) x;
    IF d_from IS NULL THEN RETURN NULL; END IF;
    SELECT * INTO a FROM si_concentration(p_security, d_from); SELECT * INTO b FROM si_concentration(p_security, d_to);
    IF a.top5_pct IS NULL OR b.top5_pct IS NULL THEN RETURN NULL; END IF;
    IF abs(b.top5_pct - a.top5_pct) >= (pr ->> 'points')::numeric THEN
        RETURN ('watch', round(b.top5_pct - a.top5_pct, 2), (pr ->> 'points')::numeric, a.top5_pct, d_from, d_to,
                jsonb_build_object('from', d_from, 'to', d_to, 'top5_from', a.top5_pct, 'top5_to', b.top5_pct, 'hhi_from', a.hhi, 'hhi_to', b.hhi, 'class', 'lore'),
                format('The top five participants held %s%% of the DTC total on %s and %s%% on %s. Practitioner lore: no published validation.', a.top5_pct, d_from, b.top5_pct, d_to))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- lore: NOBO shares at a participant fell ≥ 20% between record dates while its SPR position did not
CREATE OR REPLACE FUNCTION si_rule_nobo_drain(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('nobo_drain', (SELECT issuer_id FROM securities WHERE id = p_security)); d2 date; d1 date; r record;
BEGIN
    SELECT j.record_date INTO d2 FROM nobo_jobs j JOIN imports i ON i.id = j.import_id WHERE j.security_id = p_security AND j.record_date <= p_as_of AND i.deleted_at IS NULL ORDER BY j.record_date DESC LIMIT 1;
    IF d2 IS NULL OR d2 < p_as_of - 120 THEN RETURN NULL; END IF;
    SELECT j.record_date INTO d1 FROM nobo_jobs j JOIN imports i ON i.id = j.import_id WHERE j.security_id = p_security AND j.record_date < d2 AND i.deleted_at IS NULL ORDER BY j.record_date DESC LIMIT 1;
    IF d1 IS NULL THEN RETURN NULL; END IF;
    SELECT a.participant_id, a.number, a.name, a.nobo_shares AS n1, b.nobo_shares AS n2, a.spr_shares AS s1, b.spr_shares AS s2 INTO r
      FROM si_obo_remainder(p_security, d1) a JOIN si_obo_remainder(p_security, d2) b ON b.participant_id = a.participant_id
     WHERE a.nobo_shares > 0 AND b.nobo_shares IS NOT NULL AND (a.nobo_shares - b.nobo_shares) / a.nobo_shares * 100 >= (pr ->> 'drop_pct')::numeric
       AND a.spr_shares IS NOT NULL AND b.spr_shares IS NOT NULL AND b.spr_shares >= a.spr_shares * 0.9
     ORDER BY (a.nobo_shares - b.nobo_shares) DESC LIMIT 1;
    IF r.participant_id IS NULL THEN RETURN NULL; END IF;
    RETURN ('watch', round((r.n1 - r.n2) / r.n1 * 100, 1), (pr ->> 'drop_pct')::numeric, r.n1, d1, d2,
            jsonb_build_object('participant_id', r.participant_id, 'number', r.number, 'name', r.name, 'nobo_from', r.n1, 'nobo_to', r.n2, 'spr_from', r.s1, 'spr_to', r.s2, 'class', 'lore'),
            format('NOBO shares at %s (%s) fell from %s to %s between %s and %s while its DTC position held (%s → %s): OBO or lent. Practitioner lore.', r.name, r.number, si_fmt(r.n1), si_fmt(r.n2), d1, d2, si_fmt(r.s1), si_fmt(r.s2)))::si_rule_result;
END$$;
-- data defect: the SPR total differs from Cede's registered position beyond the tolerance
CREATE OR REPLACE FUNCTION si_rule_spr_untied(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r share_reconciliations;
BEGIN
    r := si_reconciliation_latest(p_security);
    IF r.id IS NULL OR r.tied_spr IS NULL OR r.tied_spr OR r.as_of < p_as_of - 60 THEN RETURN NULL; END IF;
    RETURN ('high', r.difference_spr, r.tolerance, r.cede_registered, r.spr_date, r.as_of,
            jsonb_build_object('reconciliation_id', r.id, 'cede_registered', r.cede_registered, 'spr_total', r.spr_total, 'spr_date', r.spr_date, 'difference', r.difference_spr),
            format('The SPR total on %s (%s) differs from Cede & Co.''s registered position (%s) by %s shares. A data defect until explained.', r.spr_date, si_fmt(r.spr_total), si_fmt(r.cede_registered), si_fmt(r.difference_spr)))::si_rule_result;
END$$;
-- data defect: outstanding − the register's components beyond the tolerance
CREATE OR REPLACE FUNCTION si_rule_register_untied(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r share_reconciliations;
BEGIN
    r := si_reconciliation_latest(p_security);
    IF r.id IS NULL OR r.tied_registered IS NULL OR r.tied_registered OR r.as_of < p_as_of - 60 THEN RETURN NULL; END IF;
    RETURN ('high', r.difference_registered, r.tolerance, r.outstanding, r.register_date, r.as_of,
            jsonb_build_object('reconciliation_id', r.id, 'outstanding', r.outstanding, 'cede', r.cede_registered, 'drs_book', r.drs_book, 'certificated', r.certificated, 'plan', r.plan, 'other', r.registered_other, 'difference', r.difference_registered),
            format('Shares outstanding (%s) differ from the register''s components as of %s by %s shares. A data defect until explained.', si_fmt(r.outstanding), r.register_date, si_fmt(r.difference_registered)))::si_rule_result;
END$$;
-- established (activism): a new 13D or 13D/A, or a 13G converting to 13D, in the window
CREATE OR REPLACE FUNCTION si_rule_new_13d(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b record; w integer := COALESCE((si_rule_params('new_13d', (SELECT issuer_id FROM securities WHERE id = p_security)) ->> 'window_days')::integer, 14);
BEGIN
    SELECT b1.*, i.name AS iname INTO b FROM beneficial_ownership b1 JOIN institutions i ON i.id = b1.institution_id
     WHERE b1.security_id = p_security AND b1.schedule = '13D' AND b1.filed_on BETWEEN p_as_of - w AND p_as_of ORDER BY b1.filed_on DESC LIMIT 1;
    IF b.id IS NULL THEN RETURN NULL; END IF;
    RETURN ('high', b.percent_of_class, 5, NULL, b.filed_on, b.filed_on,
            jsonb_build_object('institution_id', b.institution_id, 'name', b.iname, 'filed_on', b.filed_on, 'is_amendment', b.is_amendment, 'percent_of_class', b.percent_of_class, 'shares', b.shares_beneficially_owned, 'filing_id', b.filing_id),
            format('%s filed a Schedule 13D%s on %s: %s%% of the class (%s shares).%s', b.iname, CASE WHEN b.is_amendment THEN '/A' ELSE '' END, b.filed_on, COALESCE(b.percent_of_class::text, '?'), COALESCE(si_fmt(b.shares_beneficially_owned), '?'),
                   CASE WHEN b.purpose_text IS NOT NULL THEN ' Purpose: ' || left(b.purpose_text, 300) ELSE '' END))::si_rule_result;
END$$;
-- info: a filer at ≥ 5% new, or ≥ 10%
CREATE OR REPLACE FUNCTION si_rule_holder_5pct(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b record;
BEGIN
    SELECT b1.*, i.name AS iname INTO b FROM beneficial_ownership b1 JOIN institutions i ON i.id = b1.institution_id
     WHERE b1.security_id = p_security AND b1.filed_on BETWEEN p_as_of - 14 AND p_as_of AND b1.percent_of_class >= 5
       AND NOT EXISTS (SELECT 1 FROM beneficial_ownership x WHERE x.security_id = p_security AND x.institution_id = b1.institution_id AND x.filed_on < b1.filed_on AND x.percent_of_class >= 5)
     ORDER BY b1.percent_of_class DESC LIMIT 1;
    IF b.id IS NULL THEN RETURN NULL; END IF;
    RETURN (CASE WHEN b.percent_of_class >= 10 THEN 'watch' ELSE 'info' END, b.percent_of_class, 5, NULL, b.filed_on, b.filed_on,
            jsonb_build_object('institution_id', b.institution_id, 'name', b.iname, 'schedule', b.schedule, 'percent_of_class', b.percent_of_class, 'filing_id', b.filing_id),
            format('%s reported %s%% of the class on Schedule %s (%s) — a new holder above 5%%.', b.iname, b.percent_of_class, b.schedule, b.filed_on))::si_rule_result;
END$$;
-- established (as information): a top-20 13F holder closed or cut ≥ 50% in the latest quarter
CREATE OR REPLACE FUNCTION si_rule_top_holder_exit(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE per date; c record; prior date;
BEGIN
    SELECT max(period_end) INTO per FROM institutional_holdings WHERE security_id = p_security AND loaded_at >= p_as_of - 60;
    IF per IS NULL THEN RETURN NULL; END IF;
    SELECT max(period_end) INTO prior FROM institutional_holdings WHERE security_id = p_security AND period_end < per;
    IF prior IS NULL THEN RETURN NULL; END IF;
    SELECT x.* INTO c FROM si_13f_changes(p_security, per) x
     WHERE x.state IN ('out', 'down') AND x.prior_shares > 0 AND (COALESCE(x.shares, 0) - x.prior_shares) / x.prior_shares <= -0.5
       AND x.institution_id IN (SELECT y.institution_id FROM si_13f_changes(p_security, per) y WHERE y.prior_shares IS NOT NULL ORDER BY y.prior_shares DESC LIMIT 20)
     ORDER BY x.prior_shares DESC LIMIT 1;
    IF c.institution_id IS NULL THEN RETURN NULL; END IF;
    RETURN ('watch', round((COALESCE(c.shares, 0) - c.prior_shares) / c.prior_shares * 100, 1), -50, c.prior_shares, prior, per,
            jsonb_build_object('institution_id', c.institution_id, 'name', c.name, 'period', per, 'prior_period', prior, 'shares', c.shares, 'prior_shares', c.prior_shares, 'state', c.state),
            format('%s, a top-20 13F holder, went from %s to %s shares between %s and %s (%s).', c.name, si_fmt(c.prior_shares), si_fmt(COALESCE(c.shares, 0)), prior, per, c.state))::si_rule_result;
END$$;
-- established: insider sales ≥ 3 in 30 days, or ≥ 1% of outstanding, or a Form 144 ≥ 0.5%
CREATE OR REPLACE FUNCTION si_rule_insider_sales(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('insider_sales', (SELECT issuer_id FROM securities WHERE id = p_security)); iss bigint := (SELECT issuer_id FROM securities WHERE id = p_security);
        n integer; sh numeric; o numeric := si_share_count(p_security, 'outstanding', p_as_of); f record;
BEGIN
    SELECT count(*), COALESCE(sum(shares), 0) INTO n, sh FROM insider_transactions WHERE issuer_id = iss AND NOT is_derivative AND transaction_code = 'S' AND transaction_date BETWEEN p_as_of - 30 AND p_as_of;
    SELECT * INTO f FROM form144_notices WHERE issuer_id = iss AND filed_on BETWEEN p_as_of - 30 AND p_as_of AND o > 0 AND shares_to_sell / o * 100 >= (pr ->> 'form144_pct')::numeric ORDER BY shares_to_sell DESC LIMIT 1;
    IF n >= (pr ->> 'count')::integer OR (o > 0 AND sh / o * 100 >= (pr ->> 'pct_outstanding')::numeric) OR f.id IS NOT NULL THEN
        RETURN ('watch', CASE WHEN f.id IS NOT NULL THEN f.shares_to_sell ELSE sh END, (pr ->> 'count')::numeric, o, p_as_of - 30, p_as_of,
                jsonb_build_object('sales', n, 'shares_sold', sh, 'outstanding', o, 'form144', CASE WHEN f.id IS NOT NULL THEN jsonb_build_object('seller', f.seller_name, 'shares', f.shares_to_sell, 'filed_on', f.filed_on) END),
                format('Insiders: %s sale(s) of %s shares in 30 days%s.', n, si_fmt(sh), CASE WHEN f.id IS NOT NULL THEN format('; a Form 144 by %s to sell %s shares (%s)', f.seller_name, si_fmt(f.shares_to_sell), f.filed_on) ELSE '' END))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;
-- established + the overlap: an active floating-price instrument AND si_over_float or ftd_spike open
CREATE OR REPLACE FUNCTION si_rule_dilution_overlap(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE iss bigint := (SELECT issuer_id FROM securities WHERE id = p_security); d record; s record;
BEGIN
    SELECT * INTO d FROM dilution_instruments WHERE issuer_id = iss AND status = 'active' AND floating_price ORDER BY filed_on DESC NULLS LAST LIMIT 1;
    IF d.id IS NULL THEN RETURN NULL; END IF;
    SELECT sg.* INTO s FROM signals sg JOIN signal_rules r ON r.id = sg.rule_id WHERE sg.security_id = p_security AND sg.status = 'open' AND r.key IN ('si_over_float', 'ftd_spike') ORDER BY sg.severity DESC LIMIT 1;
    IF s.id IS NULL THEN RETURN NULL; END IF;
    RETURN ('high', s.value, NULL, NULL, s.window_from, p_as_of,
            jsonb_build_object('instrument_id', d.id, 'kind', d.kind, 'counterparty', d.counterparty, 'price_terms', d.price_terms, 'remaining_amount', d.remaining_amount, 'with_signal_id', s.id),
            format('An active floating-price %s (%s; %s) overlaps an open short-side signal: %s', d.kind, COALESCE(d.counterparty, 'counterparty unknown'), COALESCE(d.price_terms, 'terms unrecorded'), s.summary))::si_rule_result;
END$$;
-- established: an activist short report recorded as an event (high for 10 days)
CREATE OR REPLACE FUNCTION si_rule_activist_short_report(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE e record; iss bigint := (SELECT issuer_id FROM securities WHERE id = p_security);
BEGIN
    SELECT * INTO e FROM events WHERE issuer_id = iss AND kind = 'activist_short_report' AND deleted_at IS NULL AND on_date BETWEEN p_as_of - 10 AND p_as_of ORDER BY on_date DESC LIMIT 1;
    IF e.id IS NULL THEN RETURN NULL; END IF;
    RETURN ('high', NULL, NULL, NULL, e.on_date, e.on_date + 10, jsonb_build_object('event_id', e.id, 'title', e.title, 'url', e.url, 'on_date', e.on_date),
            format('An activist short report on %s: %s', e.on_date, e.title))::si_rule_result;
END$$;
-- info: the close down ≥ 15% in 3 days on volume ≥ 3× the 30-day median with no event recorded
CREATE OR REPLACE FUNCTION si_rule_price_volume_anomaly(p_security bigint, p_as_of date) RETURNS si_rule_result
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pr jsonb := si_rule_params('price_volume_anomaly', (SELECT issuer_id FROM securities WHERE id = p_security)); iss bigint := (SELECT issuer_id FROM securities WHERE id = p_security); last date; c3 numeric; c0 numeric; v numeric; med numeric;
BEGIN
    SELECT max(trade_date) INTO last FROM prices WHERE security_id = p_security AND trade_date <= p_as_of;
    IF last IS NULL OR last < p_as_of - 5 THEN RETURN NULL; END IF;
    SELECT close, volume INTO c0, v FROM prices WHERE security_id = p_security AND trade_date = last;
    SELECT close INTO c3 FROM prices WHERE security_id = p_security AND trade_date < last ORDER BY trade_date DESC OFFSET 2 LIMIT 1;
    SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY volume) INTO med FROM prices WHERE security_id = p_security AND trade_date BETWEEN last - 45 AND last - 1;
    IF c3 IS NULL OR c3 = 0 OR med IS NULL OR med = 0 THEN RETURN NULL; END IF;
    IF (c0 - c3) / c3 * 100 <= -(pr ->> 'drop_pct')::numeric AND v >= med * (pr ->> 'volume_multiple')::numeric
       AND NOT EXISTS (SELECT 1 FROM events WHERE issuer_id = iss AND deleted_at IS NULL AND on_date BETWEEN last - 3 AND last) THEN
        RETURN ('info', round((c0 - c3) / c3 * 100, 1), -(pr ->> 'drop_pct')::numeric, med, last - 3, last,
                jsonb_build_object('close_from', c3, 'close_to', c0, 'volume', v, 'median_volume_30', med, 'on', last),
                format('The close fell %s%% in three days to %s on %s, on volume %sx the 30-day median, with no event recorded. What happened?', round((c0 - c3) / c3 * 100, 1), c0, last, round(v / med, 1)))::si_rule_result;
    END IF;
    RETURN NULL;
END$$;

-- the nineteen, seeded
INSERT INTO signal_rules (key, name, description, evidence_class, basis, severity_default, params, window_days, sort_order) VALUES
 ('si_over_float', 'Short interest over float', 'Short interest ≥ 10% of float (watch), ≥ 20% (high); or days to cover ≥ 5 (watch), ≥ 10 (high).', 'established', 'Short interest over float and days to cover predict negative returns (Hong et al., NBER w21166).', 'watch', '{"float_pct_watch": 10, "float_pct_high": 20, "dtc_watch": 5, "dtc_high": 10}', 45, 1),
 ('si_change', 'Short interest jump', 'Short interest up ≥ 25% settlement over settlement while average volume did not rise as much.', 'established', 'A change in short interest not explained by volume (Hong et al.).', 'watch', '{"pct": 25}', 45, 2),
 ('borrow_fee', 'Borrow fee or utilisation', 'The latest borrow observation shows a fee ≥ 20% or utilisation ≥ 80%.', 'established', 'The shorting premium: cost to borrow and utilisation predict returns (Drechsler).', 'watch', '{"fee_pct": 20, "utilization_pct": 80}', 14, 3),
 ('ftd_spike', 'Fails-to-deliver spike', 'Fails ≥ 0.5% of outstanding on a day (high), or ≥ 3× the 60-day median (watch).', 'contested', 'Fotak, Raman & Yadav (JFE 2014): FTD spikes followed, not preceded, failures; the GAO: abuse may occur. A Rule 204 flag.', 'watch', '{"pct_outstanding_high": 0.5, "median_multiple": 3, "window_days": 20}', 20, 4),
 ('threshold_list', 'Reg SHO threshold list', 'The security is on a threshold list; stays five or more days.', 'contested', 'The threshold list is a Rule 203/204 compliance fact; its meaning for price is contested.', 'high', '{}', 7, 5),
 ('short_volume_ratio', 'Off-exchange short-volume ratio', 'The 5-day mean of FINRA''s off-exchange short-volume ratio ≥ 60% and rising 10 points over 20 days.', 'contested', 'FINRA and OTC Markets: daily short volume conflates market-maker shorts, omits the offsetting buys, is off-exchange only and is not comparable with short interest.', 'watch', '{"ratio_pct": 60, "rise_points": 10}', 20, 6),
 ('prime_migration', 'Retail to prime migration', 'Net flow from the retail boxes to prime, lending and market-maker boxes ≥ 2% of the DTC total over 10 position days.', 'lore', 'Practitioner lore the surveillance firms sell (shares moving to lending-rich boxes); no published validation. The class buckets are a setting.', 'watch', '{"pct_of_total": 2, "position_days": 10}', 14, 7),
 ('participant_jump', 'Participant jump', 'One participant moved ≥ 1% of the DTC total in a day, or a participant new to the file holds ≥ 0.5%.', 'lore', 'An observation of the file, not evidence of anything.', 'info', '{"move_pct": 1, "new_pct": 0.5}', 7, 8),
 ('concentration_shift', 'Concentration shift', 'The top-5 share of the DTC total moved ≥ 5 points over 20 position days.', 'lore', 'Practitioner lore; no published validation.', 'watch', '{"points": 5, "position_days": 20}', 28, 9),
 ('nobo_drain', 'NOBO drain', 'NOBO shares at a participant fell ≥ 20% between record dates while its DTC position held.', 'lore', 'A fall in known beneficial owners at a steady participant means OBO or lent shares — lore.', 'watch', '{"drop_pct": 20}', 120, 10),
 ('spr_untied', 'SPR does not tie to Cede', 'The SPR total differs from Cede & Co.''s registered position beyond the tolerance.', 'data_defect', 'Outstanding = Cede + DRS + certificated + plan; Cede = the SPR total (§0.3). A difference is a data question before anything else.', 'high', '{}', 60, 11),
 ('register_untied', 'Register does not tie to outstanding', 'Shares outstanding differ from the register''s components beyond the tolerance.', 'data_defect', 'As spr_untied.', 'high', '{}', 60, 12),
 ('new_13d', 'New Schedule 13D', 'A new 13D or 13D/A naming the security in the window.', 'established', 'A 13D is an activist''s declared intent (item 4); 5 business days to file since 2024.', 'high', '{"window_days": 14}', 14, 13),
 ('holder_5pct', 'New 5% holder', 'A filer reported ≥ 5% for the first time (watch at 10%).', 'established', 'Rule 13d-1; information, not alarm.', 'info', '{}', 14, 14),
 ('top_holder_exit', 'Top holder exit', 'A top-20 13F holder closed or cut its position by half in the latest quarter.', 'established', 'Information from the quarterly 13F data sets (45-day lag).', 'watch', '{}', 60, 15),
 ('insider_sales', 'Insider selling', 'Three or more insider sales in 30 days, or ≥ 1% of outstanding, or a Form 144 ≥ 0.5%.', 'established', 'Forms 4 and 144 are the public record of insider sales; the signal is information.', 'watch', '{"count": 3, "pct_outstanding": 1, "form144_pct": 0.5}', 30, 16),
 ('dilution_overlap', 'Floating-price dilution with a short signal', 'An active floating-price instrument overlaps an open short-interest or fails signal.', 'established', 'Floating-price convertibles ("death spirals"): −34% over a year (Hillion & Vermaelen, JFE 2004); the overlap with shorting is the mechanism alleged, causality unresolved.', 'high', '{}', 30, 17),
 ('activist_short_report', 'Activist short report', 'An activist short report was recorded as an event in the last ten days.', 'established', 'Activist short reports: −11% in three days, −14% in two months; half the targets later delisted (Paugam et al.).', 'high', '{}', 10, 18),
 ('price_volume_anomaly', 'Price and volume anomaly', 'The close fell ≥ 15% in three days on volume ≥ 3× the 30-day median with no event recorded.', 'established', 'An unexplained move is a question, not a diagnosis: "what happened?"', 'info', '{"drop_pct": 15, "volume_multiple": 3}', 5, 19);

-- ---------------------------------------------------------------------------------------------
-- THE RUNNER: every enabled rule for one security; one open signal per rule per security; expiries.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_rules_run(p_security bigint, p_as_of date DEFAULT CURRENT_DATE) RETURNS TABLE (raised integer, refreshed integer, expired integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE rl signal_rules%ROWTYPE; res si_rule_result; iss bigint; cls text; n_r integer := 0; n_f integer := 0; n_e integer := 0; grace integer := (SELECT signal_grace_days FROM si_settings WHERE id = 1); existing bigint;
BEGIN
    SELECT s.issuer_id, s.class INTO iss, cls FROM securities s WHERE s.id = p_security;
    IF iss IS NULL THEN RETURN; END IF;
    FOR rl IN SELECT * FROM signal_rules WHERE cls = ANY (applies_to) ORDER BY sort_order LOOP
        CONTINUE WHEN NOT si_rule_enabled(rl.key, iss);
        EXECUTE format('SELECT si_rule_%I($1, $2)', rl.key) INTO res USING p_security, p_as_of;
        IF res IS NULL OR res.summary IS NULL THEN CONTINUE; END IF;
        SELECT id INTO existing FROM signals WHERE rule_id = rl.id AND security_id = p_security AND status = 'open';
        IF existing IS NOT NULL THEN
            UPDATE signals SET window_to = GREATEST(window_to, res.window_to), severity = CASE WHEN res.severity = 'high' OR severity = 'high' THEN 'high' WHEN res.severity = 'watch' OR severity = 'watch' THEN 'watch' ELSE 'info' END,
                   value = res.value, threshold = res.threshold, baseline = res.baseline, evidence = res.evidence, summary = res.summary, repeat_count = repeat_count + 1,
                   expires_on = GREATEST(expires_on, res.window_to + grace) WHERE id = existing;
            n_f := n_f + 1;
        ELSE
            INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, value, threshold, baseline, evidence, summary, expires_on)
            VALUES (rl.id, iss, p_security, p_as_of, res.window_from, res.window_to, res.severity, res.value, res.threshold, res.baseline, res.evidence, res.summary, res.window_to + grace);
            n_r := n_r + 1;
        END IF;
    END LOOP;
    UPDATE signals SET status = 'expired' WHERE security_id = p_security AND status = 'open' AND expires_on < p_as_of;
    GET DIAGNOSTICS n_e = ROW_COUNT;
    RETURN QUERY SELECT n_r, n_f, n_e;
END$$;

-- A person's disposition (D11): acknowledge, dismiss with a reason and (when it explains) an event, or escalate.
CREATE OR REPLACE FUNCTION si_signal_dispose(p_signal bigint, p_status text, p_reason text, p_note text DEFAULT NULL, p_event bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_status NOT IN ('acknowledged', 'dismissed', 'escalated') THEN RAISE EXCEPTION 'A disposition is acknowledged, dismissed or escalated' USING ERRCODE = 'check_violation'; END IF;
    IF p_reason = 'explained_by_event' AND p_event IS NULL THEN RAISE EXCEPTION 'A signal explained by an event names the event' USING ERRCODE = 'check_violation'; END IF;
    UPDATE signals SET status = p_status, disposition_reason = p_reason, disposition_note = p_note, event_id = p_event, disposed_by = app_current_member_id(), disposed_at = now()
     WHERE id = p_signal AND status IN ('open', 'escalated');
    IF NOT FOUND THEN RAISE EXCEPTION 'The signal is not open' USING ERRCODE = 'check_violation'; END IF;
END$$;
-- An agent's proposal (the Analyst): never a disposition — a person takes it in one click (si_signal_dispose with the proposal's words).
CREATE OR REPLACE FUNCTION si_signal_propose(p_signal bigint, p_reason text, p_note text DEFAULT NULL, p_event bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE signals SET proposal_by = app_current_member_id(), proposal_reason = p_reason, proposal_note = p_note, proposal_event_id = p_event, proposal_at = now() WHERE id = p_signal AND status = 'open';
    IF NOT FOUND THEN RAISE EXCEPTION 'The signal is not open' USING ERRCODE = 'check_violation'; END IF;
END$$;
-- The outcomes (the worker, daily): the price at the signal and 5/20/60 trading days after, as they arrive.
CREATE OR REPLACE FUNCTION si_signal_outcomes_update(p_limit integer DEFAULT 500) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s record; n integer := 0; p0 numeric; p5 numeric; p20 numeric; p60 numeric;
BEGIN
    FOR s IN SELECT sg.id, sg.security_id, sg.raised_on FROM signals sg LEFT JOIN signal_outcomes o ON o.signal_id = sg.id
              WHERE o.signal_id IS NULL OR o.price_60d IS NULL ORDER BY sg.raised_on LIMIT p_limit LOOP
        p0 := si_price_on(s.security_id, s.raised_on);
        SELECT close INTO p5 FROM prices WHERE security_id = s.security_id AND trade_date > s.raised_on ORDER BY trade_date OFFSET 4 LIMIT 1;
        SELECT close INTO p20 FROM prices WHERE security_id = s.security_id AND trade_date > s.raised_on ORDER BY trade_date OFFSET 19 LIMIT 1;
        SELECT close INTO p60 FROM prices WHERE security_id = s.security_id AND trade_date > s.raised_on ORDER BY trade_date OFFSET 59 LIMIT 1;
        INSERT INTO signal_outcomes (signal_id, price_at, price_5d, price_20d, price_60d, return_5d, return_20d, return_60d, updated_at)
        VALUES (s.id, p0, p5, p20, p60, CASE WHEN p0 > 0 AND p5 IS NOT NULL THEN round((p5 - p0) / p0, 4) END, CASE WHEN p0 > 0 AND p20 IS NOT NULL THEN round((p20 - p0) / p0, 4) END, CASE WHEN p0 > 0 AND p60 IS NOT NULL THEN round((p60 - p0) / p0, 4) END, now())
        ON CONFLICT (signal_id) DO UPDATE SET price_at = EXCLUDED.price_at, price_5d = EXCLUDED.price_5d, price_20d = EXCLUDED.price_20d, price_60d = EXCLUDED.price_60d,
            return_5d = EXCLUDED.return_5d, return_20d = EXCLUDED.return_20d, return_60d = EXCLUDED.return_60d, updated_at = now();
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

GRANT SELECT ON signal_rules, signal_rule_overrides, signals, signal_outcomes TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
GRANT INSERT, UPDATE, DELETE ON signal_rules, signal_rule_overrides, signals, signal_outcomes TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_rule_params(text, bigint), si_rule_enabled(text, bigint), si_fmt(numeric) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
DO $$ DECLARE k text; BEGIN
    FOR k IN SELECT key FROM signal_rules LOOP
        EXECUTE format('REVOKE ALL ON FUNCTION si_rule_%I(bigint, date) FROM PUBLIC', k);
        EXECUTE format('GRANT EXECUTE ON FUNCTION si_rule_%I(bigint, date) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro', k);
    END LOOP;
END $$;
REVOKE ALL ON FUNCTION si_rules_run(bigint, date), si_signal_dispose(bigint, text, text, text, bigint), si_signal_propose(bigint, text, text, bigint), si_signal_outcomes_update(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_rules_run(bigint, date), si_signal_dispose(bigint, text, text, text, bigint), si_signal_propose(bigint, text, text, bigint), si_signal_outcomes_update(integer) TO shareholder_intelligence_rw;

COMMIT;
