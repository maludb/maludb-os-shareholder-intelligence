<?php
declare(strict_types=1);

/**
 * The admin's reads (admin-settings.md "Query functions", signatures fixed). Built ahead of slice 10 for the credential door (2026-10-11, the owner:
 * Alpha Vantage's key added later): find_source. Slice 10 adds sources_overview, source_pulls and the rest here.
 */

/** One source as the admin sees it (mcp_sources) with its credential's label and last four (mcp_source_credentials — never the secret), or null. */
function find_source(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_sources WHERE source_id = :id');
    $st->execute(['id' => $id]);
    $s = $st->fetch();
    if ($s === false) {
        return null;
    }
    $st = $pdo->prepare('SELECT c.credential_id, c.kind, c.label, c.last4, c.rotated_at, c.created_at FROM mcp_source_credentials c WHERE c.source_id = :id ORDER BY c.credential_id DESC LIMIT 1');
    $st->execute(['id' => $id]);
    $s['credential'] = $st->fetch() ?: null;
    return $s;
}
