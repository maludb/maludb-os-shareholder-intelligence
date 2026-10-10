-- 022: slice 5 — the ownership forms (docs/build-specs/ownership-forms.md "Built").
--
-- si_insider_mark reads EDGAR's reporting-owner name as EDGAR writes it — "SMITH ROBERT A", the family name FIRST and no comma — beside
-- "Smith, Robert" and the name as given: the trigger proposed no register link for a single real Form 4 (the register says "ROBERT A SMITH").
-- The link is a proposal: a person confirms or corrects it (insider_holder_link).
BEGIN;

CREATE OR REPLACE FUNCTION si_insider_mark() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n text := btrim(regexp_replace(upper(COALESCE(NEW.reporting_owner_name, '')), '\s+', ' ', 'g'));
        keys text[];
BEGIN
    IF NEW.holder_id IS NULL AND n <> '' THEN
        keys := ARRAY[si_name_key(n),                                                        -- as given
                      si_name_key(regexp_replace(n, '^([^,]+),\s*(.+)$', '\2 \1')),          -- "SMITH, ROBERT A"
                      si_name_key(regexp_replace(n, '^(\S+)\s+(.+)$', '\2 \1'))];            -- "SMITH ROBERT A" (EDGAR's)
        SELECT id INTO NEW.holder_id FROM holders WHERE issuer_id = NEW.issuer_id AND deleted_at IS NULL AND merged_into_id IS NULL AND kind <> 'entity'
           AND name_key = ANY (keys) ORDER BY last_seen_on DESC NULLS LAST LIMIT 1;
    END IF;
    IF NEW.holder_id IS NOT NULL THEN UPDATE holders SET is_insider = true, insider_cik = COALESCE(insider_cik, NEW.reporting_owner_cik) WHERE id = NEW.holder_id; END IF;
    RETURN NEW;
END$$;

COMMIT;
