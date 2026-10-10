<?php
declare(strict_types=1);
/** /uploads?kind=&security=&status= — the current issuer's uploads with their tie states (screen `upload-list`). */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
require_any_right('positions.read|uploads.write');
$pdo = db();
$issuer = current_issuer($pdo);
$filters = ['kind' => isset(UPLOAD_KINDS[request_string('kind')]) ? request_string('kind') : '', 'security' => request_integer('security'), 'status' => request_string('status')];
$page = max(1, request_integer('page') ?? 1);
$rows = $issuer === null ? [] : find_uploads($pdo, (int) $issuer['issuer_id'], $filters, $page);
log_screen_view($pdo, 'upload-list');
if (wants_json()) {
    respond_screen(['issuer_id' => $issuer['issuer_id'] ?? null, 'filters' => $filters, 'uploads' => array_map('present_upload', $rows), 'total' => (int) ($rows[0]['total'] ?? 0)]);
}
$notices = ['deleted' => ['success', 'Moved to the tombstone.']];
render_screen('Uploads', $issuer === null ? view('positions/partials/no-issuer.php', ['id' => 'upload-list', 'title' => 'Uploads'])
    : view('uploads/index.php', ['issuer' => $issuer, 'rows' => $rows, 'filters' => array_filter($filters, static fn ($v) => $v !== null && $v !== ''), 'total' => (int) ($rows[0]['total'] ?? 0), 'page' => $page,
        'here' => here_url(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'tz' => member_timezone()]), ['activeNav' => 'uploads', 'screen' => 'upload-list']);
