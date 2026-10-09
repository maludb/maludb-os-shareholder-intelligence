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
 * The Phase 2 shell has `member` and `issuer` (sso-shell.md); slice 1 adds security, participant and tag. Later: template (2); holder — an
 * Analyst's only — and institution (3, 5); filing, instrument (4); reporting_owner (5); signal, rule, event (7); report (8).
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
];
