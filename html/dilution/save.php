<?php
declare(strict_types=1);
/** Action `dilution_save` (log `dilution.save`; events.write; PEOPLE): `issuer`, `kind` and every field of the instrument; `instrument` to change one (a field left out stays). A proposed instrument saved active is confirmed by the saver. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/dilution/fields.php';
si_handler_begin();
require_human();
require_right('events.write');
$pdo = db();
$d = request_integer('instrument') !== null ? instrument_from_request($pdo) : null;
$issuer = $d !== null ? (int) $d['issuer_id'] : (request_integer('issuer') ?? (int) (current_issuer($pdo)['issuer_id'] ?? 0));
if ($d === null && find_issuer($pdo, $issuer) === null) { si_refuse_fields(['issuer' => 'Choose the issuer.']); }
$f = dilution_fields($pdo, $issuer, $d);
$f['issuer'] = $issuer;
$id = si_guard($pdo, static function () use ($pdo, $d, $f): int {
    $pdo->beginTransaction();
    $id = dilution_save($pdo, $d === null ? null : (int) $d['instrument_id'], $f);
    $pdo->commit();
    return $id;
});
si_done($d === null ? 'Recorded the instrument' : 'Saved the instrument', $id, si_land('/dilution/' . $id, 'saved'), 'dilutionChanged');
