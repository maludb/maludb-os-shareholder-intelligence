<?php
declare(strict_types=1);
/** /filings/watch?status= — the full-text watch's hits as cards (screen `watch-hits`): the term, the filing, the snippet in the filing's own words, the instrument it proposed; Confirm instrument (a full-page form), Dismiss. New by default. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$issuer = filings_issuer_or_empty($pdo, 'watch-hits', 'The watch');
$status = in_array(request_string('status'), ['new', 'confirmed', 'dismissed', 'all'], true) ? request_string('status') : 'new';
$page = max(1, request_integer('page') ?? 1);
$rows = find_watch_hits($pdo, (int) $issuer['issuer_id'], $status === 'all' ? null : $status, $page);
$terms = json_decode((string) one_value($pdo, "SELECT settings FROM sources WHERE connector = 'edgar_fulltext' ORDER BY id LIMIT 1"), true)['terms'] ?? [];
log_screen_view($pdo, 'watch-hits');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $issuer['issuer_id'], 'status' => $status, 'terms' => $terms, 'total' => (int) ($rows[0]['total'] ?? 0), 'hits' => array_map('present_watch_hit', $rows)]);
}
$notices = ['confirmed' => ['success', 'Confirmed: the instrument is on record.'], 'dismissed' => ['success', 'Dismissed.']];
render_screen('The watch', view('filings/watch.php', ['issuer' => $issuer, 'status' => $status, 'rows' => $rows, 'terms' => $terms, 'page' => $page, 'here' => here_url(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'filings', 'screen' => 'watch-hits']);
