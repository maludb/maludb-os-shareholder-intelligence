<?php
declare(strict_types=1);
/** /issuers/{id}/share-counts?security=&kind= — the dated counts of a security, with their sources (screen `issuer-share-counts`). The primary by default. */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_right('issuers.read');
$pdo = db();
$i = issuer_from_request($pdo, 'id');
$securities = find_securities($pdo, (int) $i['id']);
$security = null;
$want = request_integer('security');
foreach ($securities as $s) {
    if (($want !== null && (int) $s['security_id'] === $want) || ($want === null && $s['is_primary'])) { $security = $s; }
}
$security ??= $securities[0] ?? null;
$kind = isset(SHARE_COUNT_KINDS[request_string('kind')]) ? request_string('kind') : null;
$rows = $security === null ? [] : share_counts_list($pdo, (int) $security['security_id'], $kind);
$latest = $security === null ? [] : share_counts_latest($pdo, (int) $security['security_id']);
log_screen_view($pdo, 'issuer-share-counts');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $i['id'], 'security_id' => $security === null ? null : (int) $security['security_id'], 'kind' => $kind,
        'latest' => array_map('present_share_count', array_values($latest)), 'counts' => array_map('present_share_count', $rows), 'kinds' => SHARE_COUNT_KINDS]);
}
$notices = ['count_saved' => ['success', 'Recorded the count.'], 'count_corrected' => ['success', 'Corrected the count.'], 'count_deleted' => ['success', 'Removed the count.']];
render_screen($i['name'] . ' — share counts', view('issuers/share-counts.php', ['i' => $i, 'securities' => $securities, 'security' => $security, 'kind' => $kind, 'rows' => $rows, 'latest' => $latest,
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'tz' => member_timezone(), 'back' => back_link()]), ['activeNav' => 'issuers', 'screen' => 'issuer-share-counts', 'entity' => 'issuer', 'recordId' => (string) $i['id']]);
