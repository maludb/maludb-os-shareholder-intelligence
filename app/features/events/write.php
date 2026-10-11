<?php
declare(strict_types=1);

/**
 * The events' writes (events-reports.md "Query functions", signatures fixed). An event is a person's or an agent's record of what happened on a day
 * (the worker makes the filing events); a delete is a tombstone a restore lifts (the purge window is db/014's si_tombstones_purge). Logged with ids,
 * the kind, the severity and the date — never the body's words.
 */
require_once dirname(__DIR__) . '/tags/write.php';
require_once __DIR__ . '/queries.php';

/** The fields of an event from a request, validated against the issuer (a security and a filing must be its own); a field left out stays. */
function event_fields(PDO $pdo, int $issuer, ?array $before): array
{
    $errors = [];
    $f = [];
    if (req_has('on_date') || $before === null) {
        $d = request_date('on_date');
        $d === null || $d === false ? $errors['on_date'] = 'The date is required, such as 2026-09-30.' : $f['on_date'] = $d;
    }
    if (req_has('kind') || $before === null) {
        $k = (string) req_val('kind');
        isset(EVENT_KINDS[$k]) ? $f['kind'] = $k : $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(EVENT_KINDS)) . '.';
    }
    if (req_has('title') || $before === null) {
        $t = (string) req_val('title');
        $t === '' || mb_strlen($t) > 300 ? $errors['title'] = $t === '' ? 'Say what happened: a title is required.' : 'A title is up to 300 characters.' : $f['title'] = $t;
    }
    if (req_has('body')) {
        $v = (string) req_val('body');
        mb_strlen($v) > 10000 ? $errors['body'] = 'The body is up to 10,000 characters.' : $f['body'] = $v === '' ? null : $v;
    }
    if (req_has('url')) {
        $u = (string) req_val('url');
        if ($u === '') { $f['url'] = null; }
        elseif (mb_strlen($u) > 1000 || !preg_match('~^https?://[^\s]+$~i', $u)) { $errors['url'] = 'The link is a web address starting with http:// or https://.'; }
        else { $f['url'] = $u; }
    }
    if (req_has('severity')) {
        $s = (string) req_val('severity');
        isset(EVENT_SEVERITIES[$s]) ? $f['severity'] = $s : $errors['severity'] = 'The severity is info, watch or high.';
    }
    if (req_has('security')) {
        $f['security'] = si_ref($pdo, 'security', null, 'SELECT 1 FROM mcp_securities WHERE security_id = :id AND issuer_id = ' . $issuer, 'the security', $errors);
    }
    if (req_has('filing')) {
        $f['filing'] = si_ref($pdo, 'filing', null, 'SELECT 1 FROM mcp_filings WHERE filing_id = :id AND issuer_id = ' . $issuer, 'the filing', $errors);
    }
    $kind = $f['kind'] ?? ($before['kind'] ?? null);
    $filing = array_key_exists('filing', $f) ? $f['filing'] : ($before['filing_id'] ?? null);
    if (!isset($errors['filing']) && $kind === 'filing' && $filing !== null && (array_key_exists('filing', $f) || array_key_exists('kind', $f))) {
        $other = one_value($pdo, "SELECT id FROM events WHERE filing_id = :f AND kind = 'filing' AND deleted_at IS NULL AND id <> :me", ['f' => (int) $filing, 'me' => (int) ($before['event_id'] ?? 0)]);
        if ($other !== null) { $errors['filing'] = 'That filing already has its event.'; }
    }
    if ($errors !== []) {
        si_refuse_fields($errors);
    }
    return $f;
}

/**
 * Insert or update an event from validated fields. A person's events are source person, an agent's agent. The tags (labels) are made the record's
 * tags when given. Logs event.create / event.update. Answers the id.
 */
function event_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $map = ['on_date' => 'on_date', 'kind' => 'kind', 'title' => 'title', 'body' => 'body', 'url' => 'url', 'severity' => 'severity', 'security' => 'security_id', 'filing' => 'filing_id'];
    $vals = [];
    foreach ($map as $k => $col) {
        if (array_key_exists($k, $f)) { $vals[$col] = $f[$k]; }
    }
    $agent = (current_member()['member_kind'] ?? 'human') === 'agent';
    if ($id === null) {
        $vals += ['issuer_id' => (int) $f['issuer'], 'source' => $agent ? 'agent' : 'person', 'created_by' => $by];
        $st = $pdo->prepare('INSERT INTO events (' . implode(', ', array_keys($vals)) . ') VALUES (:' . implode(', :', array_keys($vals)) . ') RETURNING id');
        $st->execute($vals);
        $id = (int) $st->fetchColumn();
        $row = $pdo->query('SELECT kind, severity, on_date, filing_id, security_id FROM events WHERE id = ' . $id)->fetch();
        if (array_key_exists('tags', $f) && $f['tags'] !== null) { tags_set($pdo, 'event', $id, $f['tags'], $by); }
        log_activity($pdo, 'event.create', 'event', $id, ['issuer_id' => (int) $f['issuer'], 'security_id' => $row['security_id'] === null ? null : (int) $row['security_id'],
            'after' => ['event_id' => $id, 'kind' => $row['kind'], 'severity' => $row['severity'], 'on_date' => $row['on_date'], 'filing_id' => $row['filing_id'] === null ? null : (int) $row['filing_id'], 'title' => mb_substr((string) ($f['title'] ?? ''), 0, 80)]]);
        return $id;
    }
    $was = $pdo->query('SELECT kind, severity, on_date, filing_id, security_id, title, url FROM events WHERE id = ' . $id)->fetch();
    if ($vals !== []) {
        $pdo->prepare('UPDATE events SET ' . implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($vals))) . ' WHERE id = :id')->execute($vals + ['id' => $id]);
    }
    $tagChange = ['added' => [], 'removed' => []];
    if (array_key_exists('tags', $f) && $f['tags'] !== null) { $tagChange = tags_set($pdo, 'event', $id, $f['tags'], $by); }
    $now = $pdo->query('SELECT kind, severity, on_date, filing_id, security_id, title, url FROM events WHERE id = ' . $id)->fetch();
    $diff = si_diff($was, $now);
    unset($diff['before']['title'], $diff['after']['title'], $diff['before']['url'], $diff['after']['url']);      // words and addresses stay out of the trail
    log_activity($pdo, 'event.update', 'event', $id, ['issuer_id' => (int) $f['issuer'], 'security_id' => $now['security_id'] === null ? null : (int) $now['security_id'],
        'before' => $diff['before'], 'after' => $diff['after'] + ['event_id' => $id, 'fields_changed' => array_values(array_diff(array_keys($f), ['issuer'])), 'tags_added' => $tagChange['added'], 'tags_removed' => $tagChange['removed']]]);
    return $id;
}

/** Tombstone an event, or lift the tombstone ($restore). A restored filing event whose filing has since been given another event is refused in words. */
function event_delete(PDO $pdo, int $id, bool $restore = false): void
{
    $row = event_base_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($restore) {
        if ($row['deleted_at'] === null) { throw new DomainException('The event is not deleted.'); }
        if ($row['kind'] === 'filing' && $row['filing_id'] !== null
            && one_value($pdo, "SELECT id FROM events WHERE filing_id = :f AND kind = 'filing' AND deleted_at IS NULL AND id <> :me", ['f' => (int) $row['filing_id'], 'me' => $id]) !== null) {
            throw new DomainException('That filing already has a newer event; the deleted one cannot come back beside it.');
        }
        $pdo->prepare('UPDATE events SET deleted_at = NULL, deleted_by = NULL WHERE id = :id')->execute(['id' => $id]);
    } else {
        if ($row['deleted_at'] !== null) { throw new DomainException('The event is deleted already.'); }
        $pdo->prepare('UPDATE events SET deleted_at = now(), deleted_by = :by WHERE id = :id')->execute(['by' => current_member_id(), 'id' => $id]);
    }
    log_activity($pdo, $restore ? 'event.restore' : 'event.delete', 'event', $id, ['issuer_id' => (int) $row['issuer_id'], 'security_id' => $row['security_id'] === null ? null : (int) $row['security_id'],
        'after' => ['event_id' => $id, 'kind' => $row['kind'], 'on_date' => $row['on_date']]]);
}
