<?php
declare(strict_types=1);

/** institution_update (slice 5): the kind (manual from then on), the activist mark, the website, the notes; the tags through tags_set. Logged with the field names. */

require_once __DIR__ . '/queries.php';

function institution_update(PDO $pdo, int $id, array $f): array
{
    $cols = ['kind', 'is_activist', 'website', 'notes'];
    $before = $pdo->query('SELECT kind, is_activist, website, notes FROM institutions WHERE id = ' . $id)->fetch();
    $set = [];
    $args = ['id' => $id];
    $changed = [];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $f)) { continue; }
        $v = $f[$c];
        $old = $c === 'is_activist' ? (bool) $before[$c] : $before[$c];
        if ($old === $v) { continue; }
        $changed[] = $c;
        $set[] = "$c = :$c";
        $args[$c] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
    }
    if (in_array('kind', $changed, true)) {
        $set[] = "kind_source = 'manual'";
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE institutions SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    }
    log_activity($pdo, 'institution.update', 'institution', $id, ['after' => ['institution_id' => $id, 'fields_changed' => $changed]]);
    return $changed;
}
