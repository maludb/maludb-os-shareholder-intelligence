<?php
declare(strict_types=1);
/** GET /uploads/{id}/status — a queued upload's state for the page to poll (Pattern A; no log). Once read, the fragment carries HX-Refresh so the page shows the result. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
require_right('positions.read');
$pdo = db();
$u = upload_from_request($pdo, 'id');
header('Vary: HX-Request');
$matching = upload_matcher_queued($u);
if (wants_json()) {
    respond_screen(['import_id' => (int) $u['import_id'], 'status' => $u['status'], 'state' => upload_state($u)[1], 'matching' => $matching]);
}
if (request_string('part') === 'matcher') {                            // the holders card's poll while the worker matches a long list
    if (!$matching) {
        header('HX-Refresh: true');
    }
    echo '<p class="fs-12 text-muted mb-0" id="upload-matcher-queued"' . ($matching ? ' hx-get="/uploads/' . (int) $u['import_id'] . '/status?part=matcher" hx-trigger="every 3s" hx-swap="outerHTML"' : '') . '><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>'
        . ($matching ? 'Matching the rows to holders — a long list is matched in the background.' : 'Matched.') . '</p>';
    exit;
}
if (!in_array($u['status'], ['queued', 'running'], true)) {
    header('HX-Refresh: true');
}
[$c, $l] = upload_state($u);
echo '<span id="upload-status" class="badge text-bg-' . e($c) . '"' . (in_array($u['status'], ['queued', 'running'], true) ? ' hx-get="/uploads/' . (int) $u['import_id'] . '/status" hx-trigger="every 2s" hx-swap="outerHTML"' : '') . '>' . e($l) . '</span>';
