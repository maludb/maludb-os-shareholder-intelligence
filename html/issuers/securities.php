<?php
declare(strict_types=1);
/** /issuers/{id}/securities?security= — an issuer's securities with the add / edit form on the page (screen `issuer-securities`). */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_right('issuers.read');
$pdo = db();
$i = issuer_from_request($pdo, 'id');
$rows = find_securities($pdo, (int) $i['id']);
$editing = null;
if (($sid = request_integer('security')) !== null && has_right('issuers.manage')) {
    foreach ($rows as $s) {
        if ((int) $s['security_id'] === $sid) { $editing = $s; }
    }
}
log_screen_view($pdo, 'issuer-securities');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $i['id'], 'securities' => array_map('present_security', $rows), 'classes' => SECURITY_CLASSES]);
}
$notices = ['security_added' => ['success', 'Added the security.'], 'security_saved' => ['success', 'Saved the security.'], 'security_deleted' => ['success', 'Removed the security.']];
render_screen($i['name'] . ' — securities', view('issuers/securities.php', ['i' => $i, 'rows' => $rows, 'editing' => $editing, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => back_link()]),
    ['activeNav' => 'issuers', 'screen' => 'issuer-securities', 'entity' => 'issuer', 'recordId' => (string) $i['id']]);
