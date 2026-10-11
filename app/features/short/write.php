<?php
declare(strict_types=1);

/**
 * The short side's writes (short-side.md, signatures fixed): a borrow observation recorded by a person (`borrow.write`) — a broker's
 * indicative quote or a figure typed from elsewhere, until a licensed feed (Extended) writes `vendor` rows — and its removal (an agent's pauses:
 * deletion). Both log with the ids and the figures, never a note's words.
 */

/**
 * Record one observation. $f: security (the mcp_securities row), observed_on, source (broker_quote | manual), fee_rate_percent,
 * utilization_percent, shares_on_loan, shares_available (each a string or null), note. Answers its id; logs `borrow.record`.
 */
function borrow_observation_create(PDO $pdo, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO borrow_observations (security_id, observed_on, source, fee_rate_percent, utilization_percent, shares_on_loan, shares_available, note, recorded_by)
                         VALUES (:s, :d, :src, :fee, :ut, :loan, :av, :note, :by) RETURNING id');
    $st->execute(['s' => (int) $f['security']['security_id'], 'd' => $f['observed_on'], 'src' => $f['source'], 'fee' => $f['fee_rate_percent'], 'ut' => $f['utilization_percent'],
                  'loan' => $f['shares_on_loan'], 'av' => $f['shares_available'], 'note' => $f['note'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    log_activity($pdo, 'borrow.record', 'borrow_observation', $id, ['issuer_id' => (int) $f['security']['issuer_id'], 'security_id' => (int) $f['security']['security_id'],
        'after' => ['observed_on' => $f['observed_on'], 'source' => $f['source'], 'fee_rate_percent' => $f['fee_rate_percent'], 'utilization_percent' => $f['utilization_percent'],
                    'shares_on_loan' => $f['shares_on_loan'], 'shares_available' => $f['shares_available']]]);
    return $id;
}

/** Remove one observation (the caller found it through mcp_borrow_observations); logs `borrow.delete` with what it said. */
function borrow_observation_delete(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('DELETE FROM borrow_observations b USING securities s WHERE b.id = :id AND s.id = b.security_id RETURNING b.security_id, s.issuer_id, b.observed_on, b.source, b.fee_rate_percent, b.utilization_percent');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    log_activity($pdo, 'borrow.delete', 'borrow_observation', $id, ['issuer_id' => (int) $r['issuer_id'], 'security_id' => (int) $r['security_id'],
        'before' => ['observed_on' => $r['observed_on'], 'source' => $r['source'], 'fee_rate_percent' => $r['fee_rate_percent'], 'utilization_percent' => $r['utilization_percent']]]);
}
