<?php
declare(strict_types=1);

/**
 * The writes of slice 1's issuer actions. Each runs inside the handler's transaction and leaves the logging to it; the database's checks
 * (a CIK's ten digits, one primary security, a CUSIP's nine characters, the unique CIK and CUSIP) are the last word — si_guard() turns
 * them into sentences.
 */

/** Make or change an issuer. $f holds only the fields to write (a field left out stays). Returns the id. */
function issuer_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $cols = ['name', 'cik', 'ticker', 'exchange', 'state_of_incorporation', 'fiscal_year_end', 'sic', 'transfer_agent', 'transfer_agent_connector', 'timezone', 'market_holidays', 'notes'];
    $f = array_intersect_key($f, array_flip($cols));
    $args = [];
    foreach ($f as $k => $v) {
        $args[$k] = $k === 'market_holidays' ? '{' . implode(',', $v) . '}' : $v;
    }
    $cast = static fn (string $k): string => $k === 'market_holidays' ? 'CAST(:' . $k . ' AS date[])' : ':' . $k;
    if ($id === null) {
        $keys = array_keys($args);
        $st = $pdo->prepare('INSERT INTO issuers (' . implode(', ', array_merge($keys, ['created_by'])) . ') VALUES (' . implode(', ', array_merge(array_map($cast, $keys), [':by'])) . ') RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($args !== []) {
        $set = implode(', ', array_map(static fn (string $k): string => $k . ' = ' . $cast($k), array_keys($args)));
        $pdo->prepare('UPDATE issuers SET ' . $set . ' WHERE id = :id')->execute($args + ['id' => $id]);
    }
    return $id;
}

/** Tombstone an issuer (everything under it is hidden with it), or restore it. */
function issuer_delete(PDO $pdo, int $id, bool $restore, int $by): void
{
    if ($restore) {
        $pdo->prepare('UPDATE issuers SET deleted_at = NULL, deleted_by = NULL WHERE id = :id AND deleted_at IS NOT NULL')->execute(['id' => $id]);
    } else {
        $pdo->prepare('UPDATE issuers SET deleted_at = now(), deleted_by = :by WHERE id = :id AND deleted_at IS NULL')->execute(['id' => $id, 'by' => $by]);
    }
}

/** Make or change a security. When it becomes primary, the issuer's other primary steps down first (one primary per issuer). */
function security_save(PDO $pdo, ?int $id, int $issuer, array $f): int
{
    $cols = ['class', 'name', 'cusip', 'isin', 'ticker', 'figi', 'par_value', 'is_primary', 'listed_on', 'delisted_on'];
    $f = array_intersect_key($f, array_flip($cols));
    if (!empty($f['is_primary'])) {
        $pdo->prepare('UPDATE securities SET is_primary = false WHERE issuer_id = :i AND is_primary AND id IS DISTINCT FROM :id')->execute(['i' => $issuer, 'id' => $id]);
    }
    $args = array_map(static fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, $f);
    if ($id === null) {
        $keys = array_keys($args);
        $st = $pdo->prepare('INSERT INTO securities (issuer_id, ' . implode(', ', $keys) . ') VALUES (:issuer, ' . implode(', ', array_map(static fn ($k) => ':' . $k, $keys)) . ') RETURNING id');
        $st->execute($args + ['issuer' => $issuer]);
        return (int) $st->fetchColumn();
    }
    if ($args !== []) {
        $pdo->prepare('UPDATE securities SET ' . implode(', ', array_map(static fn ($k) => $k . ' = :' . $k, array_keys($args))) . ' WHERE id = :id')->execute($args + ['id' => $id]);
    }
    return $id;
}

function security_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM securities WHERE id = :id')->execute(['id' => $id]);
}

/** Record a dated count; the same security, kind, date and source changes the value (a correction). Returns [id, made]. */
function share_count_save(PDO $pdo, array $f, int $by): array
{
    $st = $pdo->prepare('INSERT INTO share_counts (security_id, as_of, kind, value, source, note, recorded_by) VALUES (:s, :d, :k, :v, :src, :note, :by)
                         ON CONFLICT (security_id, kind, as_of, source) DO UPDATE SET value = EXCLUDED.value, note = EXCLUDED.note, recorded_by = EXCLUDED.recorded_by, recorded_at = now()
                         RETURNING id, (xmax = 0) AS made');
    $st->execute(['s' => $f['security'], 'd' => $f['as_of'], 'k' => $f['kind'], 'v' => $f['value'], 'src' => $f['source'], 'note' => $f['note'], 'by' => $by]);
    $r = $st->fetch();
    return [(int) $r['id'], (bool) $r['made']];
}

function share_count_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM share_counts WHERE id = :id')->execute(['id' => $id]);
}

/** Replace the restriction list with $members (empty lifts it). Answers ['added' => [ids], 'removed' => [ids]]. */
function restriction_save(PDO $pdo, int $issuer, array $members, int $by): array
{
    $before = array_map('intval', $pdo->query('SELECT member_id FROM issuer_members WHERE issuer_id = ' . $issuer)->fetchAll(PDO::FETCH_COLUMN));
    $members = array_values(array_unique(array_map('intval', $members)));
    $added = array_values(array_diff($members, $before));
    $removed = array_values(array_diff($before, $members));
    if ($removed !== []) {
        $pdo->prepare('DELETE FROM issuer_members WHERE issuer_id = :i AND member_id = ANY (CAST(:m AS bigint[]))')->execute(['i' => $issuer, 'm' => '{' . implode(',', $removed) . '}']);
    }
    $ins = $pdo->prepare('INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (:i, :m, :by) ON CONFLICT DO NOTHING');
    foreach ($added as $m) {
        $ins->execute(['i' => $issuer, 'm' => $m, 'by' => $by]);
    }
    return ['added' => $added, 'removed' => $removed];
}
