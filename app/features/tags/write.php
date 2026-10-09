<?php
declare(strict_types=1);

/**
 * Tags on any record kind slice 1 and later name (record_tags: holder, institution, participant, event, issuer, signal). The record must be one
 * the caller may see (si_can_see_record — both walls, db/020). A label new to the installation makes the tag (si_tag_ensure).
 */
const TAG_KINDS = ['holder', 'institution', 'participant', 'event', 'issuer', 'signal'];

/** Apply a tag (by label or id) to a record. Answers [tag_id, label, added]. */
function tag_apply(PDO $pdo, string $kind, int $record, string $tag, int $by): array
{
    $tid = ctype_digit($tag) && (int) one_value($pdo, 'SELECT count(*) FROM tags WHERE id = :id', ['id' => (int) $tag]) === 1
        ? (int) $tag : (int) one_value($pdo, 'SELECT si_tag_ensure(:l, :by)', ['l' => $tag, 'by' => $by]);
    $st = $pdo->prepare('INSERT INTO record_tags (record_type, record_id, tag_id, added_by) VALUES (:k, :r, :t, :by) ON CONFLICT DO NOTHING RETURNING tag_id');
    $st->execute(['k' => $kind, 'r' => $record, 't' => $tid, 'by' => $by]);
    return [$tid, (string) one_value($pdo, 'SELECT label FROM tags WHERE id = :id', ['id' => $tid]), $st->fetchColumn() !== false];
}

/** Remove a tag (by label or id) from a record. Answers [tag_id|null, removed]. */
function tag_remove(PDO $pdo, string $kind, int $record, string $tag): array
{
    $tid = one_value($pdo, 'SELECT id FROM tags WHERE ' . (ctype_digit($tag) ? 'id = :t' : 'lower(label) = lower(:t)'), ['t' => ctype_digit($tag) ? (int) $tag : $tag]);
    if ($tid === null || $tid === false) {
        return [null, false];
    }
    $st = $pdo->prepare('DELETE FROM record_tags WHERE record_type = :k AND record_id = :r AND tag_id = :t');
    $st->execute(['k' => $kind, 'r' => $record, 't' => (int) $tid]);
    return [(int) $tid, $st->rowCount() > 0];
}

/** Make the record's tags exactly $labels (the participant form's field). Answers ['added' => [labels], 'removed' => [labels]]. */
function tags_set(PDO $pdo, string $kind, int $record, array $labels, int $by): array
{
    $now = array_map('strtolower', pg_text_array((string) one_value($pdo, 'SELECT si_tags_of(:k, :r)', ['k' => $kind, 'r' => $record])));
    $want = array_values(array_unique(array_filter(array_map(static fn ($l): string => trim(preg_replace('~\s+~', ' ', (string) $l) ?? ''), $labels), static fn ($l) => $l !== '')));
    $added = [];
    $removed = [];
    foreach ($want as $l) {
        if (!in_array(strtolower($l), $now, true)) { tag_apply($pdo, $kind, $record, $l, $by); $added[] = $l; }
    }
    $wantLower = array_map('strtolower', $want);
    foreach ($now as $l) {
        if (!in_array($l, $wantLower, true)) { tag_remove($pdo, $kind, $record, $l); $removed[] = $l; }
    }
    return ['added' => $added, 'removed' => $removed];
}

/** The record a tag or note request names, refused as 404 when the caller may not see it. Answers [kind, id, issuer_id|null]. */
function record_from_request(PDO $pdo, array $kinds): array
{
    $kind = strtolower((string) req_val('kind'));
    $id = request_integer('record');
    if (!in_array($kind, $kinds, true)) {
        si_refuse_fields(['kind' => 'The kind is one of ' . implode(', ', $kinds) . '.']);
    }
    if ($id === null || !db_bool($pdo, 'SELECT si_can_see_record(:k, :id)', ['k' => $kind, 'id' => $id])) {
        refuse(404, 'Not found.');
    }
    $iss = one_value($pdo, 'SELECT si_record_issuer(:k, :id)', ['k' => $kind, 'id' => $id]);
    return [$kind, $id, $iss === null || $iss === false ? null : (int) $iss];
}
