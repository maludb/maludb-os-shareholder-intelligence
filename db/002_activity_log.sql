-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "shareholder_intelligence" first). A RECORD'S HISTORY IS THIS TABLE (design §6.8): there is no
-- audit table; `before`/`after` carry what the trail shows: ids, counts, dates, shares, severities, rule keys, a status, 120
-- characters of a search query at most. A HOLDER'S NAME, ADDRESS OR REGISTRATION LINES, A FILING'S TEXT, A CREDENTIAL, A TOKEN OR
-- A KEY IS NEVER HERE (design §6.8); a key is named by its label.
--
-- THE ESTATE'S DATA MODEL (design §6.8): the contract table (memory.md / Consultant Tracking db/002) with Shareholder Intelligence's
-- audit keys APPENDED: issuer_id (the wall's key — every event about an issuer's securities, uploads, holders, filings, signals and
-- reports carries it, so an issuer's trail is one index), security_id, import_id (the upload the rows came from), signal_id;
-- token_id as Knowledge db/002 appended it (the key an API call ran under). No FK: history outlives a record. The siblings' own
-- appended keys (Sales CRM's deal_id, DocCloud's library_id …) are their domain and are not copied.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a standing-department grant)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.8): Shareholder Intelligence's audit keys (no FK — history outlives a record)
    issuer_id       bigint,                        -- the issuer the event concerns (filled from the child when known: a security's, an upload's, a holder's, a signal's)
    security_id     bigint,                        -- the security (a position file, a share count, a short-interest row, a signal)
    import_id       bigint,                        -- the upload (its parse, its tie, the rows it made, its deletion)
    signal_id       bigint,                        -- the signal (raised, acknowledged, dismissed, escalated, proposed)
    token_id        bigint                         -- the key (mcp_access_tokens.id) a token-API event ran under; its LABEL is in `after`, never the key
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_issuer_idx   ON activity_log (issuer_id, occurred_at DESC) WHERE issuer_id IS NOT NULL;
CREATE INDEX activity_log_security_idx ON activity_log (security_id, occurred_at DESC) WHERE security_id IS NOT NULL;
CREATE INDEX activity_log_import_idx   ON activity_log (import_id, occurred_at DESC) WHERE import_id IS NOT NULL;
CREATE INDEX activity_log_signal_idx   ON activity_log (signal_id, occurred_at DESC) WHERE signal_id IS NOT NULL;
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO shareholder_intelligence_rw;
REVOKE ALL ON activity_log FROM shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO shareholder_intelligence_rw;

COMMIT;
