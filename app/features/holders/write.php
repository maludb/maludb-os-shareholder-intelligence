<?php
declare(strict_types=1);

/**
 * Holder writes (slice 3): every decision is the database's — si_match_decide (accept, or reject remembered), si_match_new_holder,
 * si_holder_kind_propose / si_holder_kind_decide, si_holders_merge (a person never into an entity, never across issuers) — and every log
 * line carries ids, kinds, scores and the NAMES OF THE FIELDS changed, never a name, an address or a registration line (holder-identity.md §4).
 * Callers have opened the transaction; each function answers what the handler lands on.
 */

require_once __DIR__ . '/queries.php';

/** The ids a holder's log line carries (its issuer). */
function holder_log_ids(PDO $pdo, int $holder): array
{
    return ['issuer_id' => (int) one_value($pdo, 'SELECT issuer_id FROM holders WHERE id = :h', ['h' => $holder])];
}

/** An open proposal the caller may decide (the PII wall's view): its row and its candidate. */
function find_open_proposal(PDO $pdo, int $proposal): ?array
{
    $st = $pdo->prepare("SELECT * FROM mcp_holder_match_proposals WHERE proposal_id = :p AND status = 'proposed'");
    $st->execute(['p' => $proposal]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Accept a proposal: the row joins the candidate (its other proposals closed). Answers the holder. */
function match_accept(PDO $pdo, int $proposal): int
{
    $p = find_open_proposal($pdo, $proposal) ?? throw new DomainException('That proposal is not open.');
    $pdo->prepare('SELECT si_match_decide(:p, true)')->execute(['p' => $proposal]);
    log_activity($pdo, 'match.accept', 'holder_match_proposal', $proposal, ['issuer_id' => (int) $p['issuer_id'], 'import_id' => match_row_import($pdo, (string) $p['row_kind'], (int) $p['row_id']),
        'after' => ['row_kind' => $p['row_kind'], 'row_id' => (int) $p['row_id'], 'holder_id' => (int) $p['holder_id'], 'score' => (float) $p['score']]]);
    return (int) $p['holder_id'];
}

/** Reject a proposal — remembered: the matcher never proposes that pair again. */
function match_reject(PDO $pdo, int $proposal): void
{
    $p = find_open_proposal($pdo, $proposal) ?? throw new DomainException('That proposal is not open.');
    $pdo->prepare('SELECT si_match_decide(:p, false)')->execute(['p' => $proposal]);
    log_activity($pdo, 'match.reject', 'holder_match_proposal', $proposal, ['issuer_id' => (int) $p['issuer_id'], 'import_id' => match_row_import($pdo, (string) $p['row_kind'], (int) $p['row_id']),
        'after' => ['row_kind' => $p['row_kind'], 'row_id' => (int) $p['row_id'], 'holder_id' => (int) $p['holder_id'], 'score' => (float) $p['score']]]);
}

/** The upload a raw row came from. */
function match_row_import(PDO $pdo, string $rowKind, int $row): ?int
{
    $v = one_value($pdo, $rowKind === 'nobo' ? 'SELECT import_id FROM nobo_rows WHERE id = :r' : 'SELECT import_id FROM register_rows WHERE id = :r', ['r' => $row]);
    return $v === null || $v === false ? null : (int) $v;
}

/** A raw row the caller may see, waiting for a holder (the queue's own rule): ['import_id', 'issuer_id'] or null. */
function find_waiting_row(PDO $pdo, string $rowKind, int $row): ?array
{
    $sql = $rowKind === 'nobo'
        ? 'SELECT n.import_id, x.issuer_id, n.holder_id FROM mcp_nobo_rows n JOIN mcp_imports x ON x.import_id = n.import_id WHERE n.nobo_row_id = :r'
        : 'SELECT g.import_id, x.issuer_id, g.holder_id, g.is_cede FROM mcp_register_rows g JOIN mcp_imports x ON x.import_id = g.import_id WHERE g.register_row_id = :r';
    $st = $pdo->prepare($sql);
    $st->execute(['r' => $row]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** "New holder" for a row, whatever the candidates (each open proposal rejected and remembered). Answers the new holder. */
function match_new_holder(PDO $pdo, string $rowKind, int $row): int
{
    $w = find_waiting_row($pdo, $rowKind, $row) ?? throw new DomainException('Not found.');
    if ($w['holder_id'] !== null) {
        throw new DomainException('That row already has its holder.');
    }
    if (!empty($w['is_cede'])) {
        throw new DomainException('Cede & Co is the depository\'s line, not a holder.');
    }
    $hid = one_value($pdo, 'SELECT si_match_new_holder(:k, :r)', ['k' => $rowKind, 'r' => $row]);
    if ($hid === null || $hid === false) {
        throw new DomainException('A holder could not be made from that row: it has no name the matcher reads.');
    }
    log_activity($pdo, 'match.new_holder', 'holder', (int) $hid, ['issuer_id' => (int) $w['issuer_id'], 'import_id' => (int) $w['import_id'], 'after' => ['row_kind' => $rowKind, 'row_id' => $row, 'holder_id' => (int) $hid]]);
    return (int) $hid;
}

/** An Analyst agent's (or a person's) proposal of a match, with its evidence sentence. Answers the proposal. */
function holder_match_propose(PDO $pdo, string $rowKind, int $row, int $holder, string $evidence): int
{
    $w = find_waiting_row($pdo, $rowKind, $row) ?? throw new DomainException('Not found.');
    if ($w['holder_id'] !== null) {
        throw new DomainException('That row already has its holder.');
    }
    $h = find_holder($pdo, $holder) ?? throw new DomainException('Not found.');
    if ((int) $h['issuer_id'] !== (int) $w['issuer_id']) {
        throw new DomainException('A row is matched only to a holder of its own issuer.');
    }
    if (one_value($pdo, "SELECT 1 FROM holder_match_proposals WHERE row_kind = :k AND row_id = :r AND holder_id = :h AND status = 'rejected'", ['k' => $rowKind, 'r' => $row, 'h' => $holder])) {
        throw new DomainException('That pair was rejected before: it is not proposed again.');
    }
    $st = $pdo->prepare("INSERT INTO holder_match_proposals (row_kind, row_id, issuer_id, holder_id, score, evidence, proposed_by) VALUES (:k, :r, :i, :h, 0.5, CAST(:e AS jsonb), :by)
                         ON CONFLICT (row_kind, row_id, holder_id) DO UPDATE SET evidence = EXCLUDED.evidence, proposed_by = EXCLUDED.proposed_by WHERE holder_match_proposals.status = 'proposed' RETURNING id");
    $st->execute(['k' => $rowKind, 'r' => $row, 'i' => (int) $w['issuer_id'], 'h' => $holder, 'e' => json_encode(['agent' => $evidence]), 'by' => current_member_id()]);
    $id = $st->fetchColumn();
    if ($id === false) {
        throw new DomainException('That pair was already decided.');
    }
    log_activity($pdo, 'match.propose', 'holder_match_proposal', (int) $id, ['issuer_id' => (int) $w['issuer_id'], 'import_id' => (int) $w['import_id'],
        'after' => ['row_kind' => $rowKind, 'row_id' => $row, 'holder_id' => $holder, 'by_agent' => (current_member()['member_kind'] ?? '') === 'agent', 'evidence_length' => mb_strlen($evidence)]]);
    return (int) $id;
}

/** A proposal of a holder's kind (an agent's, decided on the holder's page). Answers the proposal. */
function holder_kind_propose(PDO $pdo, int $holder, string $kind, ?string $type, ?string $evidence): int
{
    $id = (int) one_value($pdo, 'SELECT si_holder_kind_propose(:h, :k, :t, :e)', ['h' => $holder, 'k' => $kind, 't' => $type, 'e' => $evidence]);
    log_activity($pdo, 'holder.kind_propose', 'holder', $holder, holder_log_ids($pdo, $holder) + ['after' => ['holder_id' => $holder, 'proposal_id' => $id, 'kind' => $kind, 'entity_type' => $type]]);
    return $id;
}

/**
 * Set a holder's kind (and entity type). A kind an agent proposed (or the worker) and a person now sets is that proposal ACCEPTED; any other open proposal of the holder is closed as rejected.
 * $proposal (the proposals screen passes it) must be open and the holder's, else 'That proposal is not open.'.
 */
function holder_kind_set(PDO $pdo, int $holder, string $kind, ?string $type, ?int $proposal = null): void
{
    if ($proposal !== null && one_value($pdo, "SELECT 1 FROM holder_kind_proposals WHERE id = :p AND holder_id = :h AND status = 'proposed'", ['p' => $proposal, 'h' => $holder]) === null) {
        throw new DomainException('That proposal is not open.');
    }
    $before = $pdo->query('SELECT kind, entity_type FROM holders WHERE id = ' . $holder)->fetch();
    $pdo->prepare('UPDATE holders SET kind = :k, entity_type = :t WHERE id = :h')->execute(['k' => $kind, 't' => $kind === 'entity' ? $type : null, 'h' => $holder]);
    $pdo->prepare("UPDATE holder_kind_proposals SET status = 'accepted', decided_by = :by, decided_at = now() WHERE holder_id = :h AND status = 'proposed' AND kind = :k")->execute(['by' => current_member_id(), 'h' => $holder, 'k' => $kind]);
    $pdo->prepare("UPDATE holder_kind_proposals SET status = 'rejected', decided_by = :by, decided_at = now() WHERE holder_id = :h AND status = 'proposed'")->execute(['by' => current_member_id(), 'h' => $holder]);
    log_activity($pdo, 'holder.kind_set', 'holder', $holder, holder_log_ids($pdo, $holder) + ['before' => ['kind' => $before['kind'], 'entity_type' => $before['entity_type']],
        'after' => ['holder_id' => $holder, 'kind' => $kind, 'entity_type' => $kind === 'entity' ? $type : null, 'proposal_id' => $proposal]]);
}

/** Reject a proposed kind (remembered: the holder is not proposed that again by the worker). The holder is not changed. Logs `holder.kind_reject`. Answers the holder. */
function holder_kind_reject(PDO $pdo, int $proposal): int
{
    $p = $pdo->prepare("SELECT holder_id, kind, entity_type FROM holder_kind_proposals WHERE id = :p AND status = 'proposed'");
    $p->execute(['p' => $proposal]);
    $row = $p->fetch() ?: throw new DomainException('That proposal is not open.');
    $pdo->prepare('SELECT si_holder_kind_decide(:p, false)')->execute(['p' => $proposal]);
    log_activity($pdo, 'holder.kind_reject', 'holder', (int) $row['holder_id'], holder_log_ids($pdo, (int) $row['holder_id']) + ['after' => ['holder_id' => (int) $row['holder_id'], 'proposal_id' => $proposal, 'kind' => $row['kind'], 'entity_type' => $row['entity_type']]]);
    return (int) $row['holder_id'];
}

/** A holder's fields, partly: what is given changes; the log carries the field names only. */
function holder_update(PDO $pdo, int $id, array $f): array
{
    $cols = ['display_name', 'kind', 'entity_type', 'address_line', 'city', 'state', 'country', 'zip', 'notes', 'is_insider', 'institution_id'];
    $f = array_intersect_key($f, array_flip($cols));
    if ($f === []) {
        return [];
    }
    $before = $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM holders WHERE id = ' . $id)->fetch();
    $changed = [];
    foreach ($f as $k => $v) {
        $old = $before[$k];
        if ($k === 'is_insider') { $old = (bool) $old; }
        if ($old !== $v && !($old !== null && $v !== null && (string) $old === (string) $v)) {
            $changed[] = $k;
        }
    }
    if ($changed === []) {
        return [];
    }
    $set = [];
    $args = ['id' => $id];
    foreach ($changed as $k) {
        $set[] = $k . ' = :' . $k;
        $args[$k] = $k === 'is_insider' ? ($f[$k] ? 'true' : 'false') : $f[$k];
    }
    if (in_array('address_line', $changed, true) || in_array('zip', $changed, true)) {
        $set[] = 'address_key = si_address_key(:ak_s, :ak_z)';
        $args['ak_s'] = $f['address_line'] ?? $before['address_line'];
        $args['ak_z'] = $f['zip'] ?? $before['zip'];
    }
    if (in_array('display_name', $changed, true)) {
        $set[] = 'name_key = COALESCE(si_name_key(:nk), name_key)';
        $args['nk'] = $f['display_name'];
    }
    $pdo->prepare('UPDATE holders SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    log_activity($pdo, 'holder.update', 'holder', $id, holder_log_ids($pdo, $id) + ['after' => ['holder_id' => $id, 'fields_changed' => $changed]]);
    return $changed;
}

/** The merge (a manager's): the survivor takes the loser's accounts, positions and rows; the loser tombstoned with merged_into_id. */
function holders_merge(PDO $pdo, int $survivor, int $loser): void
{
    $pdo->prepare('SELECT si_holders_merge(:s, :l)')->execute(['s' => $survivor, 'l' => $loser]);
    $pdo->prepare("UPDATE holders SET notes = concat_ws(E'\\n', notes, CAST(:n AS text)) WHERE id = :s")->execute(['n' => 'Merged from holder ' . $loser . ' on ' . date('Y-m-d') . '.', 's' => $survivor]);
    log_activity($pdo, 'holder.merge', 'holder', $survivor, holder_log_ids($pdo, $survivor) + ['after' => ['survivor' => $survivor, 'loser' => $loser]]);
}

/** A holder to the tombstone, or back. */
function holder_delete(PDO $pdo, int $id, bool $restore): void
{
    if ($restore) {
        $pdo->prepare('UPDATE holders SET deleted_at = NULL, deleted_by = NULL WHERE id = :h AND merged_into_id IS NULL')->execute(['h' => $id]);
    } else {
        $pdo->prepare('UPDATE holders SET deleted_at = now(), deleted_by = :by WHERE id = :h AND deleted_at IS NULL')->execute(['by' => current_member_id(), 'h' => $id]);
    }
    log_activity($pdo, $restore ? 'holder.restore' : 'holder.delete', 'holder', $id, holder_log_ids($pdo, $id) + ['after' => ['holder_id' => $id]]);
}

/** Score an upload's unmatched rows again (matcher_run). */
function matcher_run(PDO $pdo, int $import): array
{
    return upload_match($pdo, $import);
}
