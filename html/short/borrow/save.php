<?php
declare(strict_types=1);
/**
 * Action `borrow_observation_create` (log `borrow.record`; borrow.write; undo borrow_observation_delete): **security**, **observed_on** (a date,
 * today or earlier), source (broker_quote or manual), fee_rate_percent, utilization_percent, shares_on_loan, shares_available, note — at least one figure.
 */
require_once dirname(__DIR__, 3) . '/app/features/short/screen.php';
si_handler_begin();
require_right('borrow.write');
$pdo = db();
$sec = find_security($pdo, (int) (request_integer('security') ?? 0)) ?? refuse(404, 'Not found.');
$errors = [];
$on = request_date('observed_on');
$today = (new DateTimeImmutable('now', new DateTimeZone(member_timezone())))->format('Y-m-d');
if ($on === null || $on === false) {
    $errors['observed_on'] = 'The date the figure was observed, such as ' . $today . '.';
} elseif ($on > $today) {
    $errors['observed_on'] = 'An observation is of today or earlier.';
}
$source = strtolower((string) (req_val('source') ?? 'broker_quote')) ?: 'broker_quote';
if (!in_array($source, ['broker_quote', 'manual'], true)) {
    $errors['source'] = 'A person records a broker\'s quote (broker_quote) or a figure from elsewhere (manual); a vendor\'s rows come from its feed.';
}
$num = static function (string $name, float $max, string $label, bool $whole) use (&$errors): ?string {
    $v = str_replace([',', ' ', '%'], '', (string) (req_val($name) ?? ''));
    if ($v === '') { return null; }
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max || ($whole && floor((float) $v) != (float) $v)) {
        $errors[$name] = $label;
        return null;
    }
    return $v;
};
$fee = $num('fee_rate_percent', 10000, 'The fee is a yearly rate in percent, 0 to 10,000.', false);
$ut = $num('utilization_percent', 100, 'Utilisation is a percent, 0 to 100.', false);
$loan = $num('shares_on_loan', 1e15, 'Shares on loan are a whole number.', true);
$av = $num('shares_available', 1e15, 'Shares available are a whole number.', true);
$note = (string) (req_val('note') ?? '');
if (mb_strlen($note) > 500) {
    $errors['note'] = 'A note is up to 500 characters.';
}
if ($errors === [] && $fee === null && $ut === null && $loan === null && $av === null) {
    $errors['fee_rate_percent'] = 'Give at least one figure: the fee, the utilisation, the shares on loan or available.';
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$id = si_guard($pdo, static function () use ($pdo, $sec, $on, $source, $fee, $ut, $loan, $av, $note): int {
    $pdo->beginTransaction();
    $id = borrow_observation_create($pdo, ['security' => $sec, 'observed_on' => $on, 'source' => $source, 'fee_rate_percent' => $fee, 'utilization_percent' => $ut,
        'shares_on_loan' => $loan, 'shares_available' => $av, 'note' => $note === '' ? null : $note], (int) current_member_id());
    $pdo->commit();
    return $id;
});
si_done('Recorded the borrow of ' . $on . ($fee !== null ? ' at ' . $fee . '%' : ''), $id, si_land(return_path('/short/borrow?security=' . (int) $sec['security_id']), 'recorded'), 'borrowChanged');
