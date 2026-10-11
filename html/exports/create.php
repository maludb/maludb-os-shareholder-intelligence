<?php
declare(strict_types=1);
/**
 * Action `export_create` (log `export.create`, then `export.build` with the row count and whether names are in it; export.own): **kind** (positions_csv, holders_csv, institutions_csv,
 * short_csv, signals_csv, events_csv or filings_csv), **security**, from, to, record_date, channel. Built at once as the person who asked and kept seven days. A holders export
 * also needs the Analyst's right to holders; whether it carries natural persons' names is the setting pii_in_exports'. record_id = the export.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/write.php';
si_handler_begin();
require_right('export.own');
$pdo = db();
$f = export_fields($pdo);
if ($f['kind'] === 'holders_csv') { require_right('holders.read'); }
$id = si_guard($pdo, static fn (): int => export_create($pdo, $f, (int) current_member_id()));
$x = export_row($pdo, $id);
if ($x['status'] === 'failed') {
    emit_action_status(false, ['error' => (string) $x['log'], 'record_id' => $id]);
    refuse(422, (string) ($x['log'] ?: 'The export could not be built.'));
}
si_done('Built the export (' . (int) $x['item_count'] . ' rows)', $id, si_land('/exports', 'built'), 'exportChanged', ['rows' => (int) $x['item_count'], 'includes_pii' => (bool) $x['includes_pii']]);
