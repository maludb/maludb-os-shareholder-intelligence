-- 005: the application's SETTINGS (one row) and the KEY rules that read them (design §6.7 "si_settings"; D4, D8, D9, D12, D13, D14, (b), (d)).
--
-- THE ESTATE'S DATA MODEL (design §6.8): si_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1,
-- updated_at) with this application's columns. The key functions are Knowledge db/011's = DocCloud db/005's = Sales CRM db/005's
-- verbatim (si_resolve_key, si_rate_ok, si_key_calls_today, si_key_rotate, si_prune_key_usage) — here because they read the settings.
-- The EDGAR user-agent is business_name + edgar_contact_email (the SEC's "Company Name admin@domain"); si_edgar_user_agent() answers
-- NULL when either is empty and the fetcher refuses to call EDGAR (K35 asks the installer to warn).
BEGIN;

CREATE TABLE si_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,                                                                                                 -- the EDGAR user-agent's first half ("<business> <contact>")
    edgar_contact_email         citext,                                                                                               -- the SEC's requirement: a contact it may write to; EMPTY = every EDGAR pull refused (K35: the installer warns)
    default_exchange            text NOT NULL DEFAULT 'nasdaq' CHECK (default_exchange IN ('nasdaq', 'nyse', 'nyse_american', 'otc', 'cse', 'tsx', 'tsxv', 'other')),
    market_timezone             text NOT NULL DEFAULT 'America/New_York',                                                             -- the market-day clock (the Analyst's duty, the fetch schedule)
    market_holidays             date[] NOT NULL DEFAULT '{}',                                                                         -- the exchange's, seeded for the year by the admin
    edgar_rate_per_second       numeric(4,2) NOT NULL DEFAULT 2 CHECK (edgar_rate_per_second > 0 AND edgar_rate_per_second <= 10),    -- D9: 2 by default, the SEC's 10 at most
    reconciliation_tolerance_shares bigint NOT NULL DEFAULT 0 CHECK (reconciliation_tolerance_shares >= 0),                            -- D12: untied beyond this
    auto_match_threshold        numeric(3,2) NOT NULL DEFAULT 0.95 CHECK (auto_match_threshold BETWEEN 0.50 AND 1.00),               -- D8: accepted and logged as auto at or above
    pii_in_exports              text NOT NULL DEFAULT 'analyst' CHECK (pii_in_exports IN ('analyst', 'admin', 'nobody')),             -- (d): who may export names and addresses
    escalate_high_to            bigint,                                                                                               -- a member notified of every high signal (FK added in db/006 after members? members exist: FK below)
    morning_note_hour           smallint NOT NULL DEFAULT 7 CHECK (morning_note_hour BETWEEN 0 AND 23),                               -- D14: the Analyst's duty (07:00 market time)
    morning_note_enabled        boolean NOT NULL DEFAULT true,
    analyst_proposals           boolean NOT NULL DEFAULT true,                                                                        -- D14: dispositions and matches as proposals
    pack_day                    smallint NOT NULL DEFAULT 5 CHECK (pack_day BETWEEN 1 AND 7),                                         -- D14: the weekly pack's draft day (ISO: 5 = Friday)
    signal_grace_days           integer NOT NULL DEFAULT 10 CHECK (signal_grace_days BETWEEN 0 AND 365),                              -- §6.6: a signal expires at the window's end + grace
    tombstone_days              integer NOT NULL DEFAULT 30 CHECK (tombstone_days BETWEEN 1 AND 3650),                                -- §3.5: a deleted upload or record waits
    retain_raw_rows_days        integer CHECK (retain_raw_rows_days IS NULL OR retain_raw_rows_days >= 30),                           -- NULL = forever (the files' rows are the evidence)
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),                         -- §4
    key_rate_per_day            integer NOT NULL DEFAULT 5000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),
    max_upload_bytes            bigint NOT NULL DEFAULT 52428800 CHECK (max_upload_bytes BETWEEN 1048576 AND 1073741824),             -- §14: 50 MB
    price_source_default        text NOT NULL DEFAULT 'prices_stooq',                                                                 -- D13
    storage_dir                 text,                                                                                                 -- NULL = storage/ under the install
    timezone                    text NOT NULL DEFAULT 'UTC',
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT si_settings_escalate_fk FOREIGN KEY (escalate_high_to) REFERENCES members(id) ON DELETE SET NULL
);
INSERT INTO si_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER si_settings_touch BEFORE UPDATE ON si_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION si_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM si_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION si_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM si_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION si_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM si_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION si_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, si_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, si_setting_int('key_rate_per_day')::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION si_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label and its limits, with last_used_at touched. Nothing for a revoked,
-- expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION si_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION si_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION si_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label and limits; the old one expires after the overlap (24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION si_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := si_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION si_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- The EDGAR user-agent (D9, (b)): "<business name> <contact email>", or NULL when either is missing — and then no EDGAR pull runs.
CREATE OR REPLACE FUNCTION si_edgar_user_agent() RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN NULLIF(btrim(business_name), '') IS NULL OR edgar_contact_email IS NULL OR btrim(edgar_contact_email::text) = '' THEN NULL
                ELSE btrim(business_name) || ' ' || btrim(edgar_contact_email::text) END
      FROM si_settings WHERE id = 1;
$$;

-- A market day: a weekday that is not one of the exchange's holidays (the Analyst's duty, the fetch schedule).
CREATE OR REPLACE FUNCTION si_is_market_day(p_date date) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT extract(isodow FROM p_date) < 6 AND NOT EXISTS (SELECT 1 FROM si_settings s WHERE s.id = 1 AND p_date = ANY (s.market_holidays));
$$;

GRANT SELECT, UPDATE ON si_settings TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_edgar_user_agent(), si_is_market_day(date) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
GRANT EXECUTE ON FUNCTION si_setting_int(text), si_setting_text(text), si_setting_bool(text), si_key_calls_today(bigint) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;
REVOKE ALL ON FUNCTION si_resolve_key(text), si_rate_ok(bigint, timestamptz), si_key_rotate(bigint, text), si_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_resolve_key(text), si_rate_ok(bigint, timestamptz), si_key_rotate(bigint, text), si_prune_key_usage() TO shareholder_intelligence_rw;

COMMIT;
