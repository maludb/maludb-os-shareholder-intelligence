<?php
declare(strict_types=1);

/**
 * The exports' reads (events-reports.md "Query functions", signatures fixed). An export is a CSV a person asked for, built from what THEY see (the walls
 * read as them), kept seven days under the store, downloaded through /exports/{id} after the walls. mcp_exports shows a member their own and, with
 * export.shared, everyone's.
 */
const EXPORT_KINDS = ['positions_csv' => 'DTC positions', 'holders_csv' => 'Holders', 'institutions_csv' => 'Institutional holders (13F)', 'short_csv' => 'Short interest',
    'signals_csv' => 'Signals', 'events_csv' => 'Events', 'filings_csv' => 'Filings'];
/** The most rows one export holds; a longer answer is cut at it and says so in its log. */
const EXPORT_MAX_ROWS = 100000;

/** My exports ($all false) or, for a holder of export.shared, every member's ($all true — the view decides), the newest first; $kind narrows. */
function find_exports(PDO $pdo, int $memberId, bool $all, ?string $kind): array
{
    $w = [];
    $args = [];
    if (!$all) { $w[] = 'x.created_by = :m'; $args['m'] = $memberId; }
    if ($kind !== null && isset(EXPORT_KINDS[$kind])) { $w[] = 'x.kind = :k'; $args['k'] = $kind; }
    $st = $pdo->prepare('SELECT x.*, (SELECT m.display_name FROM members m WHERE m.id = x.created_by) AS created_by_name, (SELECT s.ticker FROM mcp_securities s WHERE s.security_id = x.security_id) AS ticker
                           FROM mcp_exports x' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY x.created_at DESC, x.export_id DESC LIMIT 100');
    $st->execute($args);
    return $st->fetchAll();
}

/** One export the caller may see (their own, or any with export.shared), or null. */
function find_export(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT x.* FROM mcp_exports x WHERE x.export_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The base row of an export (its stored path): only after find_export has let the caller see it. */
function export_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM exports WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
