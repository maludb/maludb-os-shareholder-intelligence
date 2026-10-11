<?php
declare(strict_types=1);
/** /admin/purge?kind= — the tombstones waiting: uploads, holders, events, reports, issuers — what, when deleted and by whom, when it purges; Restore, Purge now for what is past its window (screen `admin-purge`). records.purge. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_right('records.purge');
$pdo = db();
$kind = in_array(request_string('kind'), ['upload', 'holder', 'event', 'report', 'issuer'], true) ? request_string('kind') : '';
$rows = tombstones($pdo, $kind === '' ? null : $kind);
$days = (int) one_value($pdo, 'SELECT tombstone_days FROM si_settings WHERE id = 1');
log_screen_view($pdo, 'admin-purge');
if (wants_json()) {
    respond_screen(['tombstones' => array_map('present_tombstone', $rows), 'tombstone_days' => $days, 'filters' => ['kind' => $kind], 'due' => count(array_filter($rows, static fn (array $r): bool => (bool) $r['due']))]);
}
// Restore is each kind's own action (a report has none): kind => [path, field]
$restore = ['upload' => ['/uploads/restore.php', 'upload'], 'holder' => ['/holders/restore.php', 'holder'], 'event' => ['/events/restore.php', 'event'], 'issuer' => ['/issuers/restore.php', 'issuer']];
$notices = ['purged' => ['success', 'Purged what was past its window.'], 'nothing' => ['info', 'Nothing was past its window.'], 'restored' => ['success', 'Restored.']];
render_screen('Purge', view('admin/purge.php', ['rows' => $rows, 'kind' => $kind, 'days' => $days, 'restore' => $restore, 'tz' => member_timezone(), 'here' => here_url(), 'notice' => si_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-purge', 'entity' => 'tombstone']);
