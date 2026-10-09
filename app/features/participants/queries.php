<?php
declare(strict_types=1);

/**
 * The DTC participants (slice 1): the directory as cards by class, one participant with its series for the current security. A participant is
 * estate-wide (no issuer wall); its POSITIONS are an issuer's, read only for a security the caller may see (si_can_see_security).
 */

/** The classes, in their order: key => [name, side, description]. */
function participant_classes(PDO $pdo): array
{
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach ($pdo->query('SELECT class_key, name, side, description FROM mcp_participant_classes ORDER BY sort_order') as $r) {
            $c[$r['class_key']] = [$r['name'], $r['side'], $r['description']];
        }
    }
    return $c;
}

/** The participants: filters class, q (number, name or an alias), active; with the latest position in $security when given. */
function find_participants(PDO $pdo, array $filters = [], ?int $security = null, int $limit = 300): array
{
    $where = [];
    $args = [];
    if (($filters['class'] ?? '') !== '') {
        $where[] = 'p.class_key = :class';
        $args['class'] = (string) $filters['class'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(p.number = :qn OR p.name ILIKE :q OR EXISTS (SELECT 1 FROM unnest(p.aliases) a WHERE a ILIKE :q))';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
        $args['qn'] = str_pad(preg_replace('~\D~', '', (string) $filters['q']) ?: 'x', 4, '0', STR_PAD_LEFT);
    }
    if (!empty($filters['active_only'])) {
        $where[] = 'p.active';
    }
    $pos = 'NULL::date AS position_date, NULL::numeric AS shares';
    if ($security !== null && db_bool($pdo, 'SELECT si_can_see_security(:s)', ['s' => $security])) {
        $pos = '(SELECT max(pp.position_date) FROM participant_positions pp WHERE pp.security_id = :sec AND pp.participant_id = p.participant_id) AS position_date,
                (SELECT pp.shares FROM participant_positions pp WHERE pp.security_id = :sec AND pp.participant_id = p.participant_id ORDER BY pp.position_date DESC LIMIT 1) AS shares';
        $args['sec'] = $security;
    }
    $st = $pdo->prepare('SELECT p.participant_id, p.number, p.name, p.aliases, p.class_key, p.side, p.class_source, p.directory_kind, p.last_seen_in_directory, p.active, p.tags, ' . $pos . '
                           FROM mcp_participants p' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY p.number LIMIT ' . max(1, min(2000, $limit)));
    $st->execute($args);
    return $st->fetchAll();
}

/** One participant, or null; with its notes. */
function find_participant(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_participants WHERE participant_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The participant's daily series in a security over a window: [{position_date, shares, change}] — [] for a security behind the wall. */
function participant_series_points(PDO $pdo, int $participant, int $security, string $from, string $to): array
{
    if (!db_bool($pdo, 'SELECT si_can_see_security(:s)', ['s' => $security])) {
        return [];
    }
    $st = $pdo->prepare('SELECT position_date, shares, change FROM si_participant_series(:s, :p, CAST(:f AS date), CAST(:t AS date))');
    $st->execute(['s' => $security, 'p' => $participant, 'f' => $from, 't' => $to]);
    return $st->fetchAll();
}

/** The dates a participant appears in a security's SPRs, and its first: ['days' => n, 'first' => date|null, 'last' => date|null, 'total_on_last' => n]. */
function participant_presence(PDO $pdo, int $participant, int $security): array
{
    if (!db_bool($pdo, 'SELECT si_can_see_security(:s)', ['s' => $security])) {
        return ['days' => 0, 'first' => null, 'last' => null, 'pct_of_total' => null];
    }
    $r = $pdo->prepare('SELECT count(*) AS days, min(position_date) AS first, max(position_date) AS last FROM participant_positions WHERE security_id = :s AND participant_id = :p');
    $r->execute(['s' => $security, 'p' => $participant]);
    $out = $r->fetch();
    $out['pct_of_total'] = null;
    if ($out['last'] !== null) {
        $out['pct_of_total'] = one_value($pdo, 'SELECT round(100.0 * (SELECT shares FROM participant_positions WHERE security_id = :s AND participant_id = :p AND position_date = :d) / NULLIF(si_spr_total(:s, CAST(:d AS date)), 0), 2)',
            ['s' => $security, 'p' => $participant, 'd' => $out['last']]);
    }
    return $out;
}

function present_participant(array $p): array
{
    return ['participant_id' => (int) $p['participant_id'], 'number' => $p['number'], 'name' => $p['name'], 'aliases' => pg_text_array((string) $p['aliases']), 'class_key' => $p['class_key'],
            'side' => $p['side'], 'class_source' => $p['class_source'], 'directory_kind' => $p['directory_kind'], 'last_seen_in_directory' => $p['last_seen_in_directory'], 'active' => (bool) $p['active'],
            'tags' => pg_text_array((string) ($p['tags'] ?? '{}')),
            'latest' => ($p['position_date'] ?? null) === null ? null : ['position_date' => $p['position_date'], 'shares' => (float) $p['shares']]];
}

/** The colour of a class chip (the dataviz categorical palette in the classes' order, position-reports.md §5). */
function class_colour(string $class): string
{
    return match ($class) { 'retail' => '#3454d1', 'clearing_retail' => '#41b2c4', 'prime' => '#8b5cf6', 'market_maker' => '#f59e0b', 'lending' => '#ef4444', 'transfer_agent' => '#64748b',
        'bank_custodian' => '#14b8a6', default => '#94a3b8' };
}

/** A class chip, in words with its colour. */
function class_chip(PDO $pdo, string $class): string
{
    $name = participant_classes($pdo)[$class][0] ?? $class;
    return '<span class="badge si-class-chip" style="background:' . e(class_colour($class)) . '">' . e($name) . '</span>';
}
