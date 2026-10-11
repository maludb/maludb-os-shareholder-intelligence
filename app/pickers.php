<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [Shareholder Intelligence rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * `search` reads the same view the feature's screens read (the walls in SQL), never a second rule; `label` names the chosen record.
 *
 * The Phase 2 shell has `member` and `issuer` (sso-shell.md); slice 1 adds security, participant and tag; slice 3 holder — the PII roles only
 * (a Viewer is told "Holders are an Analyst's": `refusal`) — and institution; slice 4 filing and instrument; slice 5 reporting_owner; slice 7
 * signal, rule and event (the explaining event, scoped to a signal's window). Later: report (8).
 * A short enumeration (a kind, a status, a severity, a class) stays a <select>.
 */

/** A page of rows for a picker: $sql selects (id, label, detail, badge) with :q bound to an ILIKE pattern. */
function picker_page(PDO $pdo, string $sql, string $q, int $page, array $args = [], int $size = 20): array
{
    $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $count = $pdo->prepare('SELECT count(*) FROM (' . $sql . ') x');
    $count->execute(['q' => $pattern] + $args);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $size));
    $page = min(max(1, $page), $pages);
    $st = $pdo->prepare($sql . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size));
    $st->execute(['q' => $pattern] + $args);
    $rows = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'detail' => $r['detail'] ?? null,
        'badge' => $r['badge'] ?? null, 'color' => ($r['badge'] ?? null) === 'restricted' ? 'warning' : 'secondary'], $st->fetchAll());
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $size, 'pages' => $pages];
}

/** A picker's label lookup: the one column of one row, or null. */
function picker_one_label(PDO $pdo, string $sql, int $id): ?string
{
    $v = one_value($pdo, $sql, ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}

return [
    'member' => [
        'title' => 'People and agents', 'noun' => 'person', 'roles' => ['issuers.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT member_id AS id, display_name AS label, NULLIF(concat_ws(' · ', job_title, CASE WHEN member_kind = 'agent' THEN 'agent' END), '') AS detail, NULL AS badge
               FROM mcp_members WHERE display_name ILIKE :q OR coalesce(job_title, '') ILIKE :q ORDER BY member_kind, display_name", $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :id', $id),
    ],
    'issuer' => [
        'title' => 'Issuers', 'noun' => 'issuer', 'roles' => ['issuers.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT issuer_id AS id, name AS label, NULLIF(concat_ws(' · ', ticker, exchange, CASE WHEN cik IS NOT NULL THEN 'CIK ' || ltrim(cik, '0') END), '') AS detail,
                    CASE WHEN restricted THEN 'restricted' END AS badge
               FROM mcp_issuers WHERE name ILIKE :q OR coalesce(ticker, '') ILIKE :q OR coalesce(cik, '') ILIKE :q ORDER BY name", $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, 'SELECT name FROM mcp_issuers WHERE issuer_id = :id', $id),
    ],
    // slice 1: a security (of an issuer the caller may see; `issuer` narrows it), a DTC participant (by number, name or an old name), a tag
    'security' => [
        'title' => 'Securities', 'noun' => 'security', 'roles' => ['issuers.read'], 'params' => ['issuer'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT security_id AS id, issuer_name || ' — ' || name AS label, NULLIF(concat_ws(' · ', ticker, cusip), '') AS detail, CASE WHEN is_primary THEN 'primary' END AS badge
               FROM mcp_securities WHERE (CAST(:iss AS bigint) IS NULL OR issuer_id = CAST(:iss AS bigint))
                AND (name ILIKE :q OR issuer_name ILIKE :q OR coalesce(cusip, '') ILIKE :q OR coalesce(ticker, '') ILIKE :q) ORDER BY issuer_name, is_primary DESC, name", $q, $page, ['iss' => $p['issuer'] ?? null]),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT issuer_name || ' — ' || name FROM mcp_securities WHERE security_id = :id", $id),
    ],
    'participant' => [
        'title' => 'DTC participants', 'noun' => 'participant', 'roles' => ['positions.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT participant_id AS id, number || ' ' || name AS label, (SELECT name FROM participant_classes WHERE key = class_key) AS detail, NULL AS badge
               FROM mcp_participants WHERE number ILIKE :q OR name ILIKE :q OR EXISTS (SELECT 1 FROM unnest(aliases) a WHERE a ILIKE :q) ORDER BY number", $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT number || ' ' || name FROM mcp_participants WHERE participant_id = :id", $id),
    ],
    'tag' => [
        'title' => 'Tags', 'noun' => 'tag', 'roles' => ['tags.apply'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT tag_id AS id, label, usage_count || ' in use' AS detail, NULL AS badge FROM mcp_tags WHERE label ILIKE :q OR slug ILIKE :q ORDER BY usage_count DESC, label", $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, 'SELECT label FROM mcp_tags WHERE tag_id = :id', $id),
    ],
    // slice 3: a holder (mcp_holders — the PII wall: the roles admit only holders.read; `issuer` narrows it) and an institution (13F / 13D filers, slice 5 fills them)
    'holder' => [
        'title' => 'Holders', 'noun' => 'holder', 'roles' => ['holders.read'], 'params' => ['issuer'], 'refusal' => 'Holders are an Analyst\'s.',
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT holder_id AS id, display_name AS label, NULLIF(concat_ws(' · ', concat_ws(' ', city, state), CASE WHEN last_shares IS NOT NULL THEN to_char(last_shares, 'FM999,999,999,999') || ' shares' END), '') AS detail,
                    CASE kind WHEN 'entity' THEN 'entity' WHEN 'custodian_account' THEN 'custodian' END AS badge
               FROM mcp_holders WHERE (CAST(:iss AS bigint) IS NULL OR issuer_id = CAST(:iss AS bigint)) AND (display_name ILIKE :q OR coalesce(city, '') ILIKE :q OR coalesce(zip, '') ILIKE :q)
              ORDER BY last_shares DESC NULLS LAST, display_name", $q, $page, ['iss' => $p['issuer'] ?? null]),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, 'SELECT display_name FROM mcp_holders WHERE holder_id = :id', $id),
    ],
    'institution' => [
        'title' => 'Institutions', 'noun' => 'institution', 'roles' => ['institutions.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT institution_id AS id, name AS label, NULLIF(concat_ws(' · ', kind, CASE WHEN cik IS NOT NULL THEN 'CIK ' || ltrim(cik, '0') END), '') AS detail, CASE WHEN is_activist THEN 'activist' END AS badge
               FROM mcp_institutions WHERE name ILIKE :q OR coalesce(cik, '') ILIKE :q OR EXISTS (SELECT 1 FROM unnest(aliases) a WHERE a ILIKE :q) ORDER BY name", $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, 'SELECT name FROM mcp_institutions WHERE institution_id = :id', $id),
    ],
    // slice 4: a filing of an issuer (`issuer` narrows it) and a dilution instrument
    'filing' => [
        'title' => 'Filings', 'noun' => 'filing', 'roles' => ['filings.read'], 'params' => ['issuer'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT filing_id AS id, form || ' of ' || to_char(filed_on, 'Mon FMDD, YYYY') AS label, NULLIF(concat_ws(' · ', primary_doc_description, accession_number), '') AS detail, CASE WHEN about_us THEN 'about us' END AS badge
               FROM mcp_filings WHERE (CAST(:iss AS bigint) IS NULL OR issuer_id = CAST(:iss AS bigint)) AND (form ILIKE :q OR coalesce(primary_doc_description, '') ILIKE :q OR accession_number ILIKE :q)
              ORDER BY filed_on DESC, filing_id DESC", $q, $page, ['iss' => $p['issuer'] ?? null]),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT form || ' of ' || to_char(filed_on, 'Mon FMDD, YYYY') FROM mcp_filings WHERE filing_id = :id", $id),
    ],
    'instrument' => [
        'title' => 'Dilution instruments', 'noun' => 'instrument', 'roles' => ['filings.read'], 'params' => ['issuer'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT instrument_id AS id, initcap(replace(kind, '_', ' ')) || COALESCE(' — ' || counterparty, '') AS label, NULLIF(concat_ws(' · ', status, to_char(filed_on, 'Mon FMDD, YYYY')), '') AS detail,
                    CASE WHEN floating_price THEN 'floating' END AS badge
               FROM mcp_dilution_instruments WHERE (CAST(:iss AS bigint) IS NULL OR issuer_id = CAST(:iss AS bigint)) AND (kind ILIKE :q OR coalesce(counterparty, '') ILIKE :q) ORDER BY filed_on DESC NULLS LAST", $q, $page, ['iss' => $p['issuer'] ?? null]),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT initcap(replace(kind, '_', ' ')) || COALESCE(' — ' || counterparty, '') FROM mcp_dilution_instruments WHERE instrument_id = :id", $id),
    ],
    // slice 5: a reporting owner (an insider of Forms 3/4/5, by CIK — the value is the CIK's number)
    'reporting_owner' => [
        'title' => 'Insiders', 'noun' => 'insider', 'roles' => ['institutions.read'], 'params' => ['issuer'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT CAST(reporting_owner_cik AS bigint) AS id, max(reporting_owner_name) AS label, 'CIK ' || ltrim(reporting_owner_cik, '0') || ' · ' || count(*) || ' rows' AS detail, NULL AS badge
               FROM mcp_insider_transactions WHERE (CAST(:iss AS bigint) IS NULL OR issuer_id = CAST(:iss AS bigint)) AND (reporting_owner_name ILIKE :q OR reporting_owner_cik ILIKE :q)
              GROUP BY reporting_owner_cik ORDER BY max(transaction_date) DESC NULLS LAST", $q, $page, ['iss' => $p['issuer'] ?? null]),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT max(reporting_owner_name) FROM mcp_insider_transactions WHERE reporting_owner_cik = lpad(CAST(:id AS text), 10, '0')", $id),
    ],
    // slice 7: a signal (of an issuer — `issuer` narrows it), a rule (the nineteen by key or name), an event (the explaining event: with `signal`, the
    // issuer's events around that signal's window — the dismissal's and the proposal's candidates; else `issuer`'s events, the newest first)
    'signal' => [
        'title' => 'Signals', 'noun' => 'signal', 'roles' => ['signals.read'], 'params' => ['issuer'],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT s.signal_id AS id, s.rule_name || ' — ' || to_char(s.raised_on, 'Mon FMDD, YYYY') AS label, left(s.summary, 120) AS detail, s.status AS badge
               FROM mcp_signals s WHERE (CAST(:iss AS bigint) IS NULL OR s.issuer_id = CAST(:iss AS bigint)) AND (s.rule_name ILIKE :q OR s.rule_key ILIKE :q OR s.summary ILIKE :q)
              ORDER BY CASE WHEN s.status IN ('open', 'escalated') THEN 0 ELSE 1 END, s.raised_on DESC, s.signal_id DESC", $q, $page, ['iss' => $p['issuer'] ?? null]),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT rule_name || ' — ' || to_char(raised_on, 'Mon FMDD, YYYY') FROM mcp_signals WHERE signal_id = :id", $id),
    ],
    'rule' => [
        'title' => 'Signal rules', 'noun' => 'rule', 'roles' => ['signals.read'], 'params' => [],
        'search' => static fn (PDO $pdo, string $q, array $p, int $page): array => picker_page($pdo,
            "SELECT rule_id AS id, name AS label, key || ' · ' || replace(evidence_class, '_', ' ') AS detail, CASE WHEN NOT enabled THEN 'off' END AS badge
               FROM mcp_signal_rules WHERE name ILIKE :q OR key ILIKE :q OR description ILIKE :q ORDER BY sort_order", $q, $page),
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, 'SELECT name FROM mcp_signal_rules WHERE rule_id = :id', $id),
    ],
    'event' => [
        'title' => 'Events', 'noun' => 'event', 'roles' => ['events.read'], 'params' => ['signal', 'issuer'],
        'search' => static function (PDO $pdo, string $q, array $p, int $page): array {
            $from = null; $to = null; $iss = $p['issuer'] ?? null; $sec = null;
            if (($p['signal'] ?? null) !== null) {
                $st = $pdo->prepare('SELECT issuer_id, security_id, window_from, window_to, raised_on FROM mcp_signals WHERE signal_id = :id');
                $st->execute(['id' => (int) $p['signal']]);
                $s = $st->fetch();
                if ($s === false) {
                    return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => 20, 'pages' => 1];
                }
                $iss = (int) $s['issuer_id']; $sec = (int) $s['security_id'];
                $to = (new DateTimeImmutable(max((string) $s['window_to'], (string) $s['raised_on'])))->modify('+7 days')->format('Y-m-d');
                $from = (new DateTimeImmutable((string) $s['window_from']))->modify('-14 days')->format('Y-m-d');
            }
            return picker_page($pdo,
                "SELECT event_id AS id, title AS label, to_char(on_date, 'Mon FMDD, YYYY') || ' · ' || replace(kind, '_', ' ') AS detail, CASE WHEN severity = 'high' THEN 'high' END AS badge
                   FROM mcp_events WHERE (CAST(:iss AS bigint) IS NULL OR issuer_id = CAST(:iss AS bigint)) AND (CAST(:sec AS bigint) IS NULL OR security_id IS NULL OR security_id = CAST(:sec2 AS bigint))
                    AND (CAST(:f AS date) IS NULL OR on_date >= CAST(:f2 AS date)) AND (CAST(:t AS date) IS NULL OR on_date <= CAST(:t2 AS date)) AND (title ILIKE :q OR kind ILIKE :q)
                  ORDER BY on_date DESC, event_id DESC", $q, $page, ['iss' => $iss, 'sec' => $sec, 'sec2' => $sec, 'f' => $from, 'f2' => $from, 't' => $to, 't2' => $to]);
        },
        'label' => static fn (PDO $pdo, int $id): ?string => picker_one_label($pdo, "SELECT title || ' (' || to_char(on_date, 'Mon FMDD, YYYY') || ')' FROM mcp_events WHERE event_id = :id", $id),
    ],
];
