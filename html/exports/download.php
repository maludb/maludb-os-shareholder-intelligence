<?php
declare(strict_types=1);
/**
 * /exports/{id} — an export's CSV, sent after the walls (the store is never under the web root): the person who asked, or a holder of export.shared; an export with
 * names in it only to someone who may see names. Logs export.download {kind, rows, includes_pii}. An expired file is a 410 in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_login();
$pdo = db();
$id = request_integer('id');
$x = $id === null ? null : find_export($pdo, $id);
if ($x === null) { refuse(404, 'Not found.'); }
if ($x['includes_pii'] && !si_sees_pii()) { refuse(403, 'This export has natural persons\' names in it; it is an Analyst\'s.'); }
if ($x['status'] !== 'done') { refuse(422, $x['status'] === 'failed' ? 'This export could not be built.' : 'This export is not ready yet.'); }
$row = export_row($pdo, (int) $x['export_id']);
$bytes = null;
if ($row['storage_path'] !== null && strtotime((string) $row['expires_at']) > time()) {
    try { $bytes = si_stored_named((string) $row['storage_path']); } catch (SiStoreError) { $bytes = null; }
}
if ($bytes === null) { refuse(410, 'This export has expired and its file was removed; ask for it again.'); }
$pdo->prepare('UPDATE exports SET downloaded_at = now() WHERE id = :id')->execute(['id' => $id]);
log_activity($pdo, 'export.download', 'export', $id, ['issuer_id' => $x['issuer_id'] === null ? null : (int) $x['issuer_id'], 'security_id' => $x['security_id'] === null ? null : (int) $x['security_id'],
    'after' => ['export_id' => $id, 'kind' => $x['kind'], 'rows' => (int) $x['item_count'], 'includes_pii' => (bool) $x['includes_pii']]]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $x['kind'] . '-' . $id . '.csv"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
