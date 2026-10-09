<?php
declare(strict_types=1);

/** A participant's class (by a person: class_source becomes manual — the directory never overwrites it again) and notes. */
function participant_update(PDO $pdo, int $id, array $f): void
{
    $set = [];
    $args = ['id' => $id];
    if (array_key_exists('class', $f)) {
        $set[] = 'class_key = :class, class_source = \'manual\'';
        $args['class'] = $f['class'];
    }
    if (array_key_exists('notes', $f)) {
        $set[] = 'notes = :notes';
        $args['notes'] = $f['notes'];
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE participants SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    }
}
