<?php
declare(strict_types=1);
/**
 * /admin/sources?connector= — the fetchers (screen `admin-sources`): each source with its schedule, the calendar's next slot, the last pull and its status, the failures and the back-off, whether a key is set,
 * paused or not; Pull now (fetch_now), Pause, Resume; the red banner while the EDGAR contact is empty. sources.manage. Slice 10.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_right('sources.manage');
$pdo = db();
$connector = request_string('connector');
$connectors = source_connectors($pdo);
if ($connector !== '' && !isset($connectors[$connector])) {
    $connector = '';
}
$rows = sources_overview($pdo, $connector === '' ? null : $connector);
$ua = one_value($pdo, 'SELECT si_edgar_user_agent()') ?: null;
log_screen_view($pdo, 'admin-sources');
if (wants_json()) {
    respond_screen(['sources' => array_map('present_source_row', $rows), 'connectors' => $connectors, 'filters' => ['connector' => $connector], 'edgar_user_agent_set' => $ua !== null]);
}
$notices = ['paused' => ['success', 'Paused the source.'], 'resumed' => ['success', 'Resumed the source.'], 'pulled' => ['success', 'Pulled the source.']];
render_screen('Sources and settings', view('admin/sources.php', ['rows' => $rows, 'connectors' => $connectors, 'connector' => $connector, 'ua' => $ua, 'tz' => member_timezone(), 'notice' => si_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-sources', 'entity' => 'source']);
