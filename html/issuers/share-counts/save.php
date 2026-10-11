<?php
declare(strict_types=1);
/**
 * Action `share_count_save` (log `share_count.save`; issuers.manage; an agent's pauses — other; undo share_count_delete): record a dated count
 * of a kind for a security from a source (manual, transfer_agent or dtc_letter here; filing, register and spr are written by the fetchers and
 * uploads). The same security, kind, date and source again corrects the value.
 */
require_once dirname(__DIR__, 3) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$sec = find_security($pdo, (int) (request_integer('security') ?? 0));
if ($sec === null) {
    refuse(404, 'Not found.');
}
$errors = [];
$asOf = request_date('as_of');
if ($asOf === null || $asOf === false) {
    $errors['as_of'] = 'The date the count is as of, such as 2026-09-30.';
} elseif ($asOf > (new DateTimeImmutable('now'))->modify('+1 day')->format('Y-m-d')) {
    $errors['as_of'] = 'A count is as of today or earlier.';
}
$kind = strtolower((string) req_val('kind'));
if (!isset(SHARE_COUNT_KINDS[$kind])) {
    $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(SHARE_COUNT_KINDS)) . '.';
}
$raw = str_replace([',', ' ', '$'], '', (string) req_val('value'));
if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
    $errors['value'] = 'The count is a number of shares (dollars for the public float value).';
} elseif ($kind !== 'public_float_value' && floor((float) $raw) != (float) $raw) {
    $errors['value'] = 'A count of shares is a whole number.';
}
$source = strtolower((string) (req_val('origin') ?? req_val('source') ?? 'manual')) ?: 'manual';
if (!in_array($source, ['manual', 'transfer_agent', 'dtc_letter'], true)) {
    $errors['source'] = 'A person records a count as manual, from the transfer agent or from a DTC letter.';
}
$note = req_val('note');
if ($note !== null && mb_strlen($note) > 500) {
    $errors['note'] = 'A note is up to 500 characters.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
[$id, $made] = si_guard($pdo, static function () use ($pdo, $sec, $asOf, $kind, $raw, $source, $note): array {
    $pdo->beginTransaction();
    $r = share_count_save($pdo, ['security' => (int) $sec['security_id'], 'as_of' => $asOf, 'kind' => $kind, 'value' => $raw, 'source' => $source, 'note' => $note === '' ? null : $note], (int) current_member_id());
    log_activity($pdo, 'share_count.save', 'share_count', $r[0], ['issuer_id' => (int) $sec['issuer_id'], 'security_id' => (int) $sec['security_id'],
        'after' => ['kind' => $kind, 'as_of' => $asOf, 'value' => $raw, 'source' => $source, 'corrected' => !$r[1]]]);
    $pdo->commit();
    return $r;
});
si_done(($made ? 'Recorded ' : 'Corrected ') . SHARE_COUNT_KINDS[$kind] . ' ' . number_format((float) $raw, $kind === 'public_float_value' ? 2 : 0) . ' as of ' . $asOf, $id,
    si_land(return_path('/issuers/' . (int) $sec['issuer_id'] . '/share-counts?security=' . (int) $sec['security_id']), $made ? 'count_saved' : 'count_corrected'), 'shareCountChanged');
