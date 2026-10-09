<?php
declare(strict_types=1);
/**
 * Action `participant_update` (log `participant.update`; issuers.manage): a participant's class (a person's class is `manual`: the directory never
 * overwrites it), its notes and its tags (`tags`: the whole list, comma-separated or tags[]). A field left out stays.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/participants/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/participants/write.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$p = find_participant($pdo, (int) (request_integer('participant') ?? request_integer('id') ?? 0));
if ($p === null) {
    refuse(404, 'Not found.');
}
$errors = [];
$f = [];
if (req_has('class')) {
    $c = strtolower((string) req_val('class'));
    if (!isset(participant_classes($pdo)[$c])) {
        $errors['class'] = 'The class is one of ' . implode(', ', array_keys(participant_classes($pdo))) . '.';
    } elseif ($c !== $p['class_key'] || $p['class_source'] !== 'manual') {
        $f['class'] = $c;
    }
}
if (req_has('notes')) {
    $n = (string) req_val('notes');
    if (mb_strlen($n) > 2000) { $errors['notes'] = 'Notes are up to 2,000 characters.'; } elseif (($n === '' ? null : $n) !== $p['notes']) { $f['notes'] = $n === '' ? null : $n; }
}
$tags = request_list('tags');
if ($tags !== null && count(array_filter($tags, static fn ($t) => mb_strlen($t) > 60)) > 0) {
    $errors['tags'] = 'A tag is up to 60 characters.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$t = si_guard($pdo, static function () use ($pdo, $p, $f, $tags): array {
    $pdo->beginTransaction();
    participant_update($pdo, (int) $p['participant_id'], $f);
    $t = $tags === null ? ['added' => [], 'removed' => []] : tags_set($pdo, 'participant', (int) $p['participant_id'], $tags, (int) current_member_id());
    $after = [];
    if (isset($f['class'])) { $after['class'] = $f['class']; }
    if (array_key_exists('notes', $f)) { $after['notes'] = '(changed)'; }
    if ($t['added'] !== [] || $t['removed'] !== []) { $after['tags'] = $t; }
    if ($after !== []) {
        log_activity($pdo, 'participant.update', 'participant', (int) $p['participant_id'], ['before' => isset($f['class']) ? ['class' => $p['class_key'], 'class_source' => $p['class_source']] : null, 'after' => $after]);
    }
    $pdo->commit();
    return $after;
});
si_done($t === [] ? 'Nothing to change' : 'Saved ' . $p['number'] . ' ' . $p['name'], (int) $p['participant_id'], si_land(return_path('/participants/' . (int) $p['participant_id']), $t === [] ? null : 'updated'), 'participantChanged');
