-- 015: THE ONE SEARCH BOX — holders (PII roles only), institutions, participants, filings, events and signals in one index, every
-- result judged by the issuer wall and the PII wall (design §6.8, §7).
--
-- THE ESTATE'S DATA MODEL: `search_index` is Spaces db/013 CANONICAL, verbatim, with entity_kind widened and issuer_id / is_pii appended
-- so the walls are per-row expressions over the index row itself. tsv = A the name/title · B the identifiers (CUSIP, CIK, number, form,
-- tags) · C the body (a filing's first 20,000 characters, an event's body, a signal's summary); `simple` + unaccent; websearch; trigram.
BEGIN;

CREATE TABLE search_index (
    entity_kind   text NOT NULL CHECK (entity_kind IN ('holder', 'institution', 'participant', 'filing', 'event', 'signal', 'issuer')),
    entity_uuid   uuid,
    entity_id     bigint,
    page_id       uuid,
    space_id      bigint,
    channel_id    bigint,
    author_member_id bigint,
    title         text,
    body          text,
    tsv           tsvector,
    has_link      boolean NOT NULL DEFAULT false,
    has_file      boolean NOT NULL DEFAULT false,
    occurred_at   timestamptz NOT NULL,
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((entity_uuid IS NULL) <> (entity_id IS NULL)),
    -- appended (design §6.8): the walls' facts
    issuer_id     bigint,                                                          -- NULL = business-wide (a participant, an institution)
    is_pii        boolean NOT NULL DEFAULT false,                                   -- a natural person's row: PII roles only
    status        text,
    deleted       boolean NOT NULL DEFAULT false
);
CREATE UNIQUE INDEX search_index_entity_uq ON search_index (entity_kind, entity_id);
CREATE INDEX search_index_tsv ON search_index USING gin (tsv);
CREATE INDEX search_index_time ON search_index (occurred_at DESC);
CREATE INDEX search_index_title_trgm ON search_index USING gin (title gin_trgm_ops);
CREATE INDEX search_index_issuer ON search_index (issuer_id);

CREATE OR REPLACE FUNCTION si_tsv(p_a text, p_b text, p_c text) RETURNS tsvector
    LANGUAGE sql IMMUTABLE AS $$
    SELECT setweight(to_tsvector('simple', unaccent(COALESCE(p_a, ''))), 'A')
        || setweight(to_tsvector('simple', unaccent(COALESCE(p_b, ''))), 'B')
        || setweight(to_tsvector('simple', unaccent(left(COALESCE(p_c, ''), 20000))), 'C');
$$;

CREATE OR REPLACE FUNCTION si_index_record(p_kind text, p_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    IF p_kind = 'holder' THEN
        SELECT h.display_name AS title, concat_ws(' ', h.city, h.state, h.zip, h.kind, h.entity_type, array_to_string(si_tags_of('holder', h.id), ' ')) AS b, h.notes AS c,
               h.issuer_id, h.kind = 'person' AS pii, h.kind AS status, h.deleted_at IS NOT NULL OR h.merged_into_id IS NOT NULL AS deleted, h.updated_at AS at INTO r FROM holders h WHERE h.id = p_id;
    ELSIF p_kind = 'institution' THEN
        SELECT i.name AS title, concat_ws(' ', i.cik, i.kind, array_to_string(i.aliases, ' '), array_to_string(si_tags_of('institution', i.id), ' ')) AS b, i.notes AS c,
               NULL::bigint AS issuer_id, false AS pii, i.kind AS status, false AS deleted, i.updated_at AS at INTO r FROM institutions i WHERE i.id = p_id;
    ELSIF p_kind = 'participant' THEN
        SELECT p.name AS title, concat_ws(' ', p.number, p.class_key, array_to_string(p.aliases, ' '), array_to_string(si_tags_of('participant', p.id), ' ')) AS b, p.notes AS c,
               NULL::bigint AS issuer_id, false AS pii, p.class_key AS status, NOT p.active AS deleted, p.updated_at AS at INTO r FROM participants p WHERE p.id = p_id;
    ELSIF p_kind = 'filing' THEN
        SELECT concat_ws(' ', f.form, f.filed_on::text, f.primary_doc_description) AS title, concat_ws(' ', f.accession_number, f.filer_cik, array_to_string(f.items, ' ')) AS b, (SELECT left(t.text, 20000) FROM filing_text t WHERE t.filing_id = f.id) AS c,
               f.issuer_id, false AS pii, f.form AS status, false AS deleted, COALESCE(f.accepted_at, f.filed_on::timestamptz) AS at INTO r FROM filings f WHERE f.id = p_id;
    ELSIF p_kind = 'event' THEN
        SELECT e.title, concat_ws(' ', e.kind, e.severity, e.on_date::text, array_to_string(si_tags_of('event', e.id), ' ')) AS b, e.body AS c,
               e.issuer_id, false AS pii, e.kind AS status, e.deleted_at IS NOT NULL AS deleted, e.updated_at AS at INTO r FROM events e WHERE e.id = p_id;
    ELSIF p_kind = 'signal' THEN
        SELECT (SELECT name FROM signal_rules WHERE id = s.rule_id) AS title, concat_ws(' ', s.severity, s.status, s.raised_on::text, (SELECT evidence_class FROM signal_rules WHERE id = s.rule_id)) AS b, s.summary AS c,
               s.issuer_id, false AS pii, s.status, false AS deleted, s.updated_at AS at INTO r FROM signals s WHERE s.id = p_id;
    ELSIF p_kind = 'issuer' THEN
        SELECT i.name AS title, concat_ws(' ', i.ticker, i.cik, i.exchange, (SELECT string_agg(cusip, ' ') FROM securities WHERE issuer_id = i.id)) AS b, i.notes AS c,
               i.id AS issuer_id, false AS pii, i.exchange AS status, i.deleted_at IS NOT NULL AS deleted, i.updated_at AS at INTO r FROM issuers i WHERE i.id = p_id;
    ELSE RETURN; END IF;
    IF r IS NULL OR r.title IS NULL THEN DELETE FROM search_index WHERE entity_kind = p_kind AND entity_id = p_id; RETURN; END IF;
    INSERT INTO search_index (entity_kind, entity_id, title, body, tsv, occurred_at, issuer_id, is_pii, status, deleted)
    VALUES (p_kind, p_id, r.title, left(COALESCE(r.c, ''), 20000), si_tsv(r.title, r.b, r.c), r.at, r.issuer_id, r.pii, r.status, r.deleted)
    ON CONFLICT (entity_kind, entity_id) DO UPDATE SET title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv, occurred_at = EXCLUDED.occurred_at, issuer_id = EXCLUDED.issuer_id,
        is_pii = EXCLUDED.is_pii, status = EXCLUDED.status, deleted = EXCLUDED.deleted, updated_at = now();
END$$;

CREATE OR REPLACE FUNCTION si_index_trigger() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN DELETE FROM search_index WHERE entity_kind = TG_ARGV[0] AND entity_id = OLD.id; RETURN NULL; END IF;
    PERFORM si_index_record(TG_ARGV[0], NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER holders_index      AFTER INSERT OR UPDATE OR DELETE ON holders      FOR EACH ROW EXECUTE FUNCTION si_index_trigger('holder');
CREATE TRIGGER institutions_index AFTER INSERT OR UPDATE OR DELETE ON institutions FOR EACH ROW EXECUTE FUNCTION si_index_trigger('institution');
CREATE TRIGGER participants_index AFTER INSERT OR UPDATE OR DELETE ON participants FOR EACH ROW EXECUTE FUNCTION si_index_trigger('participant');
CREATE TRIGGER filings_index      AFTER INSERT OR UPDATE OR DELETE ON filings      FOR EACH ROW EXECUTE FUNCTION si_index_trigger('filing');
CREATE TRIGGER events_index       AFTER INSERT OR UPDATE OR DELETE ON events       FOR EACH ROW EXECUTE FUNCTION si_index_trigger('event');
CREATE TRIGGER signals_index      AFTER INSERT OR UPDATE OR DELETE ON signals      FOR EACH ROW EXECUTE FUNCTION si_index_trigger('signal');
CREATE TRIGGER issuers_index      AFTER INSERT OR UPDATE OR DELETE ON issuers      FOR EACH ROW EXECUTE FUNCTION si_index_trigger('issuer');
CREATE OR REPLACE FUNCTION si_filing_text_index() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN PERFORM si_index_record('filing', COALESCE(NEW.filing_id, OLD.filing_id)); RETURN NULL; END$$;
CREATE TRIGGER filing_text_index AFTER INSERT OR UPDATE OR DELETE ON filing_text FOR EACH ROW EXECUTE FUNCTION si_filing_text_index();
CREATE OR REPLACE FUNCTION si_record_tags_index() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF COALESCE(NEW.record_type, OLD.record_type) IN ('holder', 'institution', 'participant', 'event') THEN PERFORM si_index_record(COALESCE(NEW.record_type, OLD.record_type), COALESCE(NEW.record_id, OLD.record_id)); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_index AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION si_record_tags_index();
-- the seeded participants, indexed now
SELECT si_index_record('participant', id) FROM participants;

CREATE OR REPLACE FUNCTION si_search_catch_up(p_limit integer DEFAULT 500) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN
        SELECT 'holder' AS k, id FROM holders h WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'holder' AND s.entity_id = h.id AND s.updated_at >= h.updated_at)
        UNION ALL SELECT 'institution', id FROM institutions i WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'institution' AND s.entity_id = i.id AND s.updated_at >= i.updated_at)
        UNION ALL SELECT 'participant', id FROM participants p WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'participant' AND s.entity_id = p.id AND s.updated_at >= p.updated_at)
        UNION ALL SELECT 'event', id FROM events e WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'event' AND s.entity_id = e.id AND s.updated_at >= e.updated_at)
        UNION ALL SELECT 'signal', id FROM signals g WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'signal' AND s.entity_id = g.id AND s.updated_at >= g.updated_at)
        UNION ALL SELECT 'filing', id FROM filings f WHERE NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'filing' AND s.entity_id = f.id)
        LIMIT p_limit
    LOOP PERFORM si_index_record(r.k, r.id); n := n + 1; END LOOP;
    RETURN n;
END$$;

-- THE SEARCH: websearch syntax, both walls per row, a trigram fallback on the title, ranked, with a highlight and the total.
CREATE OR REPLACE FUNCTION si_search(p_q text, p_kinds text[] DEFAULT NULL, p_issuer bigint DEFAULT NULL, p_limit integer DEFAULT 50, p_offset integer DEFAULT 0)
    RETURNS TABLE (entity_kind text, entity_id bigint, title text, headline text, status text, issuer_id bigint, rank real, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q tsquery; qq text := btrim(COALESCE(p_q, '')); pii boolean := si_sees_holder_pii();
BEGIN
    IF qq = '' OR NOT app_is_active_member() THEN RETURN; END IF;
    q := websearch_to_tsquery('simple', unaccent(qq));
    RETURN QUERY
    SELECT s.entity_kind, s.entity_id, CASE WHEN s.is_pii AND NOT pii THEN 'Individual holder' ELSE s.title END,
           CASE WHEN s.is_pii AND NOT pii THEN NULL ELSE ts_headline('simple', COALESCE(s.title || ' — ' || left(s.body, 400), s.title), q, 'MaxFragments=1, MaxWords=24, MinWords=8') END,
           s.status, s.issuer_id, GREATEST(ts_rank_cd(s.tsv, q), word_similarity(qq, s.title))::real AS rank, count(*) OVER () AS total
      FROM search_index s
     WHERE NOT s.deleted
       AND (p_kinds IS NULL OR s.entity_kind = ANY (p_kinds))
       AND (p_issuer IS NULL OR s.issuer_id = p_issuer)
       AND (s.tsv @@ q OR qq <% s.title)
       AND (s.issuer_id IS NULL OR s.issuer_id IN (SELECT si_my_issuers()))
       AND (NOT s.is_pii OR pii)
     ORDER BY rank DESC, s.occurred_at DESC
     LIMIT LEAST(COALESCE(p_limit, 50), 100) OFFSET GREATEST(COALESCE(p_offset, 0), 0);
END$$;

GRANT SELECT ON search_index TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
GRANT INSERT, UPDATE, DELETE ON search_index TO shareholder_intelligence_rw;
GRANT EXECUTE ON FUNCTION si_tsv(text, text, text), si_search(text, text[], bigint, integer, integer) TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
REVOKE ALL ON FUNCTION si_index_record(text, bigint), si_search_catch_up(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION si_index_record(text, bigint), si_search_catch_up(integer) TO shareholder_intelligence_rw;

COMMIT;
