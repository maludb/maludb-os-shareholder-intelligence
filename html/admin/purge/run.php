<?php
declare(strict_types=1);
/**
 * Action `records_purge` (log `records.purge` {kind, count}; records.purge; confirm; an agent's pauses — deletion): kind (upload, holder, event, report, issuer or all), record (one record of that kind). Deletes for good ONLY what has waited
 * past the tombstone window (Settings), with the kept file nothing else keeps; what is still inside its window stays. record_id = the count purged. Never a holder's name in the log.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
si_handler_begin();
require_right('records.purge');
$pdo = db();
$errors = [];
$kind = strtolower((string) (req_val('kind') ?? 'all'));
if ($kind === '') { $kind = 'all'; }
if (!in_array($kind, ['upload', 'holder', 'event', 'report', 'issuer', 'all'], true)) { $errors['kind'] = 'Purge an upload, a holder, an event, a report, an issuer, or all.'; }
$record = request_integer('record');
if (req_has('record') && (string) req_val('record') !== '' && $record === null) { $errors['record'] = 'Name the record by its number.'; }
if ($record !== null && $kind === 'all') { $errors['record'] = 'Name the kind of the record.'; }
if ($errors !== []) {
    si_refuse_fields($errors);
}
$r = si_guard($pdo, static function () use ($pdo, $kind, $record): array {
    $pdo->beginTransaction();
    $r = records_purge($pdo, $kind, $record);
    $pdo->commit();
    return $r;
});
purge_remove_files($r['paths']);
if ($record !== null && $r['total'] === 0) {
    refuse(422, 'That record is not past its window (or is not in the tombstone): nothing was purged.');
}
si_done($r['total'] === 0 ? 'Nothing was past its window' : 'Purged ' . $r['total'] . ' record' . ($r['total'] === 1 ? '' : 's') . ' for good (' . implode(', ', array_map(static fn (string $k, int $n): string => $n . ' ' . $k, array_keys($r['by_kind']), $r['by_kind'])) . ')',
    $r['total'], si_land(return_path('/admin/purge'), $r['total'] === 0 ? 'nothing' : 'purged'), 'tombstoneChanged', ['count' => $r['total'], 'by_kind' => $r['by_kind']]);
