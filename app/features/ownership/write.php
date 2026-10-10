<?php
declare(strict_types=1);

/** insider_holder_link (slice 5): a person links an insider (by the reporting owner's CIK) to the register's holder — every row of that insider; the holder marked. */

function insider_holder_link(PDO $pdo, string $cik, int $holder, int $by): int
{
    $iss = one_value($pdo, 'SELECT issuer_id FROM mcp_insider_transactions WHERE reporting_owner_cik = :c LIMIT 1', ['c' => $cik]) ?? throw new DomainException('Not found.');
    $h = $pdo->prepare('SELECT holder_id, issuer_id, kind FROM mcp_holders WHERE holder_id = :h');
    $h->execute(['h' => $holder]);
    $row = $h->fetch() ?: throw new DomainException('Not found.');
    if ((int) $row['issuer_id'] !== (int) $iss) {
        throw new DomainException('The holder is another issuer\'s.');
    }
    if ($row['kind'] === 'entity') {
        throw new DomainException('An insider is a person: an entity holder is not linked to one.');
    }
    $before = array_values(array_unique(array_map('intval', array_filter(array_column($pdo->query("SELECT DISTINCT holder_id FROM insider_transactions WHERE reporting_owner_cik = " . $pdo->quote($cik))->fetchAll(), 'holder_id')))));
    $n = $pdo->prepare('UPDATE insider_transactions SET holder_id = :h WHERE reporting_owner_cik = :c AND issuer_id = :i');
    $n->execute(['h' => $holder, 'c' => $cik, 'i' => (int) $iss]);
    $pdo->prepare('UPDATE holders SET is_insider = true, insider_cik = :c WHERE id = :h')->execute(['c' => $cik, 'h' => $holder]);
    log_activity($pdo, 'insider.holder_link', 'holder', $holder, ['issuer_id' => (int) $iss, 'before' => ['holder_ids' => $before], 'after' => ['reporting_owner_cik' => $cik, 'holder_id' => $holder, 'rows' => $n->rowCount()]]);
    return $holder;
}
