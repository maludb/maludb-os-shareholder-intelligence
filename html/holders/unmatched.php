<?php
declare(strict_types=1);
/** /holders/unmatched?upload=&kind=&page= — the queue (screen `holders-unmatched`; the PII roles): a raw registration on the left, up to three candidates with score and evidence on the right; Accept, Reject, New holder, Set kind. */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_right('holders.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($issuer === null) {
    log_screen_view($pdo, 'holders-unmatched');
    if (wants_json()) { respond_screen(['issuer_id' => null, 'rows' => []]); }
    render_screen('Unmatched rows', view('positions/partials/no-issuer.php', ['id' => 'holders-unmatched', 'title' => 'Unmatched rows', 'issuer' => null]), ['activeNav' => 'holders', 'screen' => 'holders-unmatched']);
    return;
}
$upload = request_integer('upload');
$kind = in_array(request_string('kind'), ['nobo', 'register'], true) ? request_string('kind') : null;
$page = max(1, request_integer('page') ?? 1);
$rows = unmatched_queue($pdo, (int) $issuer['issuer_id'], $upload, $page, $kind);
$total = (int) ($rows[0]['total'] ?? 0);
log_screen_view($pdo, 'holders-unmatched');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $issuer['issuer_id'], 'upload' => $upload, 'kind' => $kind, 'total' => $total, 'page' => $page, 'rows' => array_map('present_queue_row', $rows)]);
}
$notices = ['accepted' => ['success', 'Matched.'], 'rejected' => ['success', 'Rejected: that pair will not be proposed again.'], 'new_holder' => ['success', 'Made a new holder.'], 'kind_set' => ['success', 'Set the kind.'], 'proposed' => ['success', 'Proposed.']];
render_screen('Unmatched rows', view('holders/unmatched.php', ['security' => $security, 'rows' => $rows, 'total' => $total, 'page' => $page, 'upload' => $upload, 'kind' => $kind, 'here' => here_url(),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'holders', 'screen' => 'holders-unmatched']);
