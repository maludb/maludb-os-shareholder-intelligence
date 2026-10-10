<?php
declare(strict_types=1);
/** /uploads/new?kind=&security= — upload a file (screen `upload-add`): the current issuer's securities; the kind detected from the file unless chosen. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
require_right('uploads.write');
require_human();
$pdo = db();
$issuer = current_issuer($pdo);
$securities = $issuer === null ? [] : find_securities($pdo, (int) $issuer['issuer_id']);
$security = null;
foreach ($securities as $s) {
    if ((request_integer('security') !== null && (int) $s['security_id'] === request_integer('security')) || (request_integer('security') === null && $s['is_primary'])) { $security = $s; }
}
$security ??= $securities[0] ?? null;
$kind = isset(UPLOAD_KINDS[request_string('kind')]) ? request_string('kind') : '';
log_screen_view($pdo, 'upload-add');
if (wants_json()) {
    respond_screen(['issuer_id' => $issuer['issuer_id'] ?? null, 'kinds' => UPLOAD_KINDS, 'built_kinds' => ['spr', 'nobo', 'register', 'share_range'], 'securities' => array_map('present_security', $securities), 'max_bytes' => upload_max_bytes($pdo)]);
}
render_screen('Upload a file', $security === null ? view('positions/partials/no-issuer.php', ['id' => 'upload-form', 'title' => 'Upload a file', 'issuer' => $issuer])
    : view('uploads/form.php', ['issuer' => $issuer, 'securities' => $securities, 'security' => $security, 'kind' => $kind, 'max' => upload_max_bytes($pdo)]), ['activeNav' => 'uploads', 'screen' => 'upload-add']);
