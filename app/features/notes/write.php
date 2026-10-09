<?php
declare(strict_types=1);

/** Notes on any record kind (notes: issuer, holder, institution, participant, event, signal, report, dilution_instrument, filing). */
const NOTE_KINDS = ['issuer', 'holder', 'institution', 'participant', 'event', 'signal', 'report', 'dilution_instrument', 'filing'];

function note_add(PDO $pdo, string $kind, int $record, string $body, int $by): int
{
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:k, :r, :m, :b) RETURNING id');
    $st->execute(['k' => $kind, 'r' => $record, 'm' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}
