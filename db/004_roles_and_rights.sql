-- 004: Shareholder Intelligence's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- si_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (Analyst): the
-- installer's --grant-standing-departments gives a department the role keyed member/user/write.
--
-- Rights are application-wide capabilities; WHO SEES WHICH ISSUER is the wall of db/006 (open unless the issuer carries a
-- restriction list; D3) and WHO SEES A NATURAL PERSON'S NAME is the PII wall (holders.read — Analyst and above; D4). Four roles:
-- Viewer, Analyst (user), IR Manager, SI admin (is_admin).
--
-- THE ESTATE'S DATA MODEL (design §6.8): the shape is Consultant Tracking db/004 = DocCloud db/004 = Sales CRM db/004 (crm_ → si_);
-- the roles and rights are this application's; si_member_roles(member) is Inventory db/004's convenience.
BEGIN;

CREATE TABLE si_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE si_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX si_roles_one_admin ON si_roles (is_admin) WHERE is_admin;
CREATE TABLE si_role_rights (
    role_key  text NOT NULL REFERENCES si_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES si_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO si_rights (right_key, description, sort_order) VALUES
 ('issuers.read',           'See the issuers and securities they may reach, the share counts and the reconciliation', 1),
 ('positions.read',         'See the DTC participants, their positions and movements, the flow by class, the concentration', 2),
 ('institutions.read',      'See the 13F holders, the 13D/13G filers and the insiders as filed (the public record)', 3),
 ('filings.read',           'See the filings, their text and the dilution instruments', 4),
 ('short.read',             'See short interest, daily short volume, fails, the threshold lists and borrow observations', 5),
 ('signals.read',           'See the signals, their evidence and their class', 6),
 ('events.read',            'See the events and the calendar', 7),
 ('reports.read',           'See the approved packs', 8),
 ('holders.read_aggregate', 'See holders in aggregate: counts, share ranges, geography, custodians — and entities by name; never a natural person', 9),
 ('holders.read',           'See every holder: a natural person''s name, address and registration lines (the PII wall, D4)', 10),
 ('uploads.write',          'Upload an SPR, a NOBO list, a register, a price file; re-run the matcher on one', 11),
 ('matches.decide',         'Accept or reject a holder match proposal; set a holder''s kind; make a new holder from a row', 12),
 ('events.write',           'Record, edit and tombstone events and dilution instruments', 13),
 ('borrow.write',           'Record a borrow observation (fee, utilisation)', 14),
 ('signals.work',           'Acknowledge, dismiss with a reason or escalate a signal', 15),
 ('notes.write',            'Add a note to any record they may see', 16),
 ('tags.apply',             'Apply tags to holders, institutions, participants and events', 17),
 ('reports.run',            'Draft and render a pack; run every report', 18),
 ('export.own',             'Export what they see as CSV; a holders export is logged with its row count', 19),
 ('tokens.own',             'Mint and revoke their own keys', 20),
 ('issuers.manage',         'Create and edit issuers and securities, record share counts, set the transfer agent and the CIK, override an untied upload', 21),
 ('rules.manage',           'Enable, disable and set the thresholds of the signal rules, per issuer too', 22),
 ('restrictions.manage',    'Restrict an issuer to named members, or lift the restriction', 23),
 ('holders.merge',          'Merge two holders (pauses for an agent)', 24),
 ('uploads.delete',         'Delete an upload to the tombstone, with its rows; restore it', 25),
 ('reports.approve',        'Approve a pack and send it to members (a send pauses for an agent)', 26),
 ('agents.settings',        'See every agent''s dispatches and the Analyst''s proposals and settings; link to the kernel for hires and grants', 27),
 ('export.shared',          'Export every record of a kind', 28),
 ('settings.manage',        'Run Shareholder Intelligence: the application settings (EDGAR user-agent, exchange, holidays, tolerances, the morning note, the pack''s day)', 29),
 ('sources.manage',         'Keep the sources: the fetchers, their schedule, pause and resume, pull now', 30),
 ('credentials.manage',     'Set and rotate a source''s credential (never shown back)', 31),
 ('tokens.manage',          'Mint and revoke keys for anyone', 32),
 ('api.manage',             'Set the API''s rates and CORS', 33),
 ('records.purge',          'Purge tombstoned uploads and records before their time', 34),
 ('costs.read',             'See the agents'' usage by month', 35);

INSERT INTO si_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('viewer',  'Viewer',     'The board, the CFO''s staff, an outside advisor, an advisory firm''s client: dashboards, charts, filings, signals and packs of the issuers they may reach; holders in aggregate and entities by name — never a natural person''s name or address.', 'read',  false, 1),
 ('user',    'Analyst',    'The IR officer or the advisory firm''s analyst: uploads the files, decides holder matches, records events and borrow observations, works the signals, runs the reports, sees every holder.', 'write', false, 2),
 ('manager', 'IR Manager', 'Owns the issuers and securities, the share counts, the signal rules, the restrictions, merges, upload deletions and the pack''s approval.', 'write', false, 3),
 ('admin',   'SI admin',   'Every right: the settings, the sources and their credentials, the EDGAR user-agent, keys and the API, the agents'' settings, purge, costs. A super-admin holds this role.', 'admin', true, 4);

INSERT INTO si_role_rights (role_key, right_key)
SELECT 'viewer', r FROM unnest(ARRAY['issuers.read','positions.read','institutions.read','filings.read','short.read','signals.read','events.read','reports.read','holders.read_aggregate']) r
UNION ALL SELECT 'user', r FROM unnest(ARRAY['issuers.read','positions.read','institutions.read','filings.read','short.read','signals.read','events.read','reports.read','holders.read_aggregate',
    'holders.read','uploads.write','matches.decide','events.write','borrow.write','signals.work','notes.write','tags.apply','reports.run','export.own','tokens.own']) r
UNION ALL SELECT 'manager', r FROM unnest(ARRAY['issuers.read','positions.read','institutions.read','filings.read','short.read','signals.read','events.read','reports.read','holders.read_aggregate',
    'holders.read','uploads.write','matches.decide','events.write','borrow.write','signals.work','notes.write','tags.apply','reports.run','export.own','tokens.own',
    'issuers.manage','rules.manage','restrictions.manage','holders.merge','uploads.delete','reports.approve','agents.settings','export.shared']) r
UNION ALL SELECT 'admin', right_key FROM si_rights;

-- The roles a member holds, resolved: the kernel's list; a super-admin always holds admin; a member the kernel has not yet sent
-- roles for is read from the capability (admin = admin, read = viewer, anything else = user). Empty for nobody.
CREATE OR REPLACE FUNCTION si_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             ELSE ARRAY[CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'read' THEN 'viewer' ELSE 'user' END]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- The rule: a right the member's roles give. The member must be active and admitted.
CREATE OR REPLACE FUNCTION si_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM si_role_rights rr
         WHERE rr.right_key = p_right AND rr.role_key = ANY (si_member_roles(app_current_member_id())));
END$$;

-- Runs Shareholder Intelligence: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION si_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND si_has_right('settings.manage'));
END$$;

-- A viewer: admitted and holding nothing above Viewer — reads in aggregate, changes nothing.
CREATE OR REPLACE FUNCTION si_member_is_viewer(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL)
       AND NOT (si_member_roles(p_member_id) && ARRAY['user', 'manager', 'admin']);
END$$;

CREATE OR REPLACE FUNCTION si_is_viewer() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN si_member_is_viewer(app_current_member_id());
END$$;

-- A manager: holds issuers.manage (an IR Manager or the admin) — sees every issuer, restricted or not, and changes the measure.
CREATE OR REPLACE FUNCTION si_is_manager() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN si_is_admin() OR si_has_right('issuers.manage');
END$$;

-- THE PII WALL (D4): a natural person's name, address and registration lines are seen by a holder of holders.read — Analyst and
-- above — never by a Viewer, a Viewer's key, a share or a Viewer-level API read.
CREATE OR REPLACE FUNCTION si_sees_holder_pii() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN si_has_right('holders.read');
END$$;

-- Someone who belongs in the business here: admitted (any role; an agent granted a role too).
CREATE OR REPLACE FUNCTION si_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM si_role_rights rr
                   JOIN si_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM si_roles r;

GRANT SELECT ON si_rights, si_roles, si_role_rights, mcp_app_roles TO shareholder_intelligence_rw, shareholder_intelligence_records_ro;
GRANT EXECUTE ON FUNCTION si_member_roles(bigint), si_has_right(text), si_is_admin(), si_is_viewer(), si_member_is_viewer(bigint), si_is_manager(), si_sees_holder_pii(), si_is_member_here()
    TO shareholder_intelligence_rw, shareholder_intelligence_records_ro, shareholder_intelligence_activity_ro;

COMMIT;
