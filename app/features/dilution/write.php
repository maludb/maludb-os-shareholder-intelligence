<?php
declare(strict_types=1);

/** Dilution writes (slice 4): an instrument saved (a person's) or deleted (an agent's pauses). Logged with ids, the kind and the status. */

require_once __DIR__ . '/queries.php';

/** Insert or update an instrument from validated fields; $proposedBy on a new one. Answers its id. */
function dilution_store(PDO $pdo, ?int $id, array $f, string $proposedBy = 'person', ?string $term = null): int
{
    $cols = ['kind', 'security_id', 'counterparty', 'filed_on', 'filing_id', 'facility_amount', 'currency', 'remaining_amount', 'price_terms', 'floating_price', 'shares_issuable_max', 'expires_on', 'status', 'notes'];
    $map = ['security' => 'security_id', 'filing' => 'filing_id'];
    $vals = [];
    foreach ($f as $k => $v) {
        $c = $map[$k] ?? $k;
        if (in_array($c, $cols, true)) {
            $vals[$c] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
        }
    }
    if ($id === null) {
        $vals += ['issuer_id' => (int) $f['issuer'], 'proposed_by' => $proposedBy, 'watch_term' => $term];
        $st = $pdo->prepare('INSERT INTO dilution_instruments (' . implode(', ', array_keys($vals)) . ') VALUES (:' . implode(', :', array_keys($vals)) . ') RETURNING id');
        $st->execute($vals);
        return (int) $st->fetchColumn();
    }
    if ($vals !== []) {
        $pdo->prepare('UPDATE dilution_instruments SET ' . implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($vals))) . ' WHERE id = :id')->execute($vals + ['id' => $id]);
    }
    return $id;
}

function dilution_save(PDO $pdo, ?int $id, array $f): int
{
    $before = $id === null ? null : $pdo->query('SELECT status, kind FROM dilution_instruments WHERE id = ' . $id)->fetch();
    $d = dilution_store($pdo, $id, $f);
    if ($id !== null && ($f['status'] ?? null) === 'active' && ($before['status'] ?? '') === 'proposed') {
        $pdo->prepare('UPDATE dilution_instruments SET confirmed_by = :by, confirmed_at = now() WHERE id = :d')->execute(['by' => current_member_id(), 'd' => $d]);
    }
    $iss = (int) one_value($pdo, 'SELECT issuer_id FROM dilution_instruments WHERE id = :d', ['d' => $d]);
    log_activity($pdo, 'dilution.save', 'dilution_instrument', $d, ['issuer_id' => $iss, 'before' => $before ?: null,
        'after' => ['instrument_id' => $d, 'kind' => $f['kind'] ?? ($before['kind'] ?? null), 'status' => $f['status'] ?? ($before['status'] ?? null), 'fields_changed' => array_keys($f)]]);
    return $d;
}

function dilution_delete(PDO $pdo, int $id): void
{
    $iss = (int) one_value($pdo, 'SELECT issuer_id FROM dilution_instruments WHERE id = :d', ['d' => $id]);
    $pdo->prepare('DELETE FROM dilution_instruments WHERE id = :d')->execute(['d' => $id]);
    log_activity($pdo, 'dilution.delete', 'dilution_instrument', $id, ['issuer_id' => $iss, 'after' => ['instrument_id' => $id]]);
}
