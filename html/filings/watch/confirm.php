<?php
declare(strict_types=1);
/** Action `watch_hit_confirm` (log `watch.confirm`; events.write; PEOPLE): `watch_hit`, `kind`, `counterparty`, `facility_amount`, `price_terms`, `floating_price` (yes/no), and any field of dilution_save — the hit becomes an active instrument (the proposal updated, or one made). */
require_once dirname(__DIR__, 3) . '/app/features/filings/screen.php';
require_once dirname(__DIR__, 3) . '/app/features/dilution/fields.php';
si_handler_begin();
require_human();
require_right('events.write');
$pdo = db();
$hit = request_integer('watch_hit');
$w = $hit === null ? null : find_watch_hit($pdo, $hit);
if ($w === null) { refuse(404, 'Not found.'); }
$f = dilution_fields($pdo, (int) $w['issuer_id'], null);
unset($f['status']);
$id = si_guard($pdo, static function () use ($pdo, $hit, $f): int {
    $pdo->beginTransaction();
    $id = watch_hit_confirm($pdo, (int) $hit, $f, (int) current_member_id());
    $pdo->commit();
    return $id;
});
si_done('Confirmed the instrument', $id, si_land('/dilution/' . $id, 'confirmed'), 'watchChanged');
