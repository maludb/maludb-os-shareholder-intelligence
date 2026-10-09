<?php
declare(strict_types=1);

/**
 * The one search box (screen `search`, sso-shell.md): si_search (db/015) — websearch syntax over holders, institutions, participants,
 * filings, events, signals and issuers, ranked; BOTH WALLS IN THE FUNCTION: a restricted issuer's rows never come back, and a natural
 * person is "Individual holder" with no headline for a Viewer. PHP only groups and pages.
 */

/** The kinds the box searches, in the order the results are grouped, with their words and screens. */
const SEARCH_KINDS = [
    'signal'      => ['Signals', 'feather-flag', '/signals/'],
    'filing'      => ['Filings', 'feather-file-text', '/filings/'],
    'event'       => ['Events', 'feather-calendar', '/events/'],
    'holder'      => ['Holders', 'feather-users', '/holders/'],
    'institution' => ['Institutions', 'feather-briefcase', '/institutions/'],
    'participant' => ['Participants', 'feather-share-2', '/participants/'],
    'issuer'      => ['Issuers', 'feather-layers', '/issuers/'],
];

/** One page of results: ['rows' => [...], 'total' => n, 'page' => p, 'pages' => n]. $kinds empty = every kind; $issuer null = every issuer I may see. */
function search(PDO $pdo, string $q, array $kinds, ?int $issuer, int $page = 1, int $size = 50): array
{
    $q = mb_substr(trim($q), 0, 200);
    if ($q === '') {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    }
    $kinds = array_values(array_intersect($kinds, array_keys(SEARCH_KINDS)));
    $st = $pdo->prepare('SELECT entity_kind, entity_id, title, headline, status, issuer_id, rank, total FROM si_search(:q, CAST(:k AS text[]), :i, :n, :o)');
    $st->execute(['q' => $q, 'k' => $kinds === [] ? null : pg_array_literal($kinds), 'i' => $issuer, 'n' => $size, 'o' => (max(1, $page) - 1) * $size]);
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => $rows, 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int) ceil($total / $size))];
}

/** The screen a result opens. */
function search_result_url(array $r): string
{
    return (SEARCH_KINDS[$r['entity_kind']][2] ?? '/') . (int) $r['entity_id'];
}
