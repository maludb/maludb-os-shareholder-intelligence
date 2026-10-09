<?php
declare(strict_types=1);
/** /issuers?q= — the issuers the caller may see, as cards (screen `issuer-list`); a manager also sees the tombstone with Restore. */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_right('issuers.read');
$pdo = db();
$q = mb_substr(trim(request_string('q')), 0, 120);
$rows = find_issuers($pdo, ['q' => $q]);
$deleted = has_right('issuers.manage') ? find_deleted_issuers($pdo) : [];
log_screen_view($pdo, 'issuer-list');
if (wants_json()) {
    respond_screen(['q' => $q, 'issuers' => array_map('present_issuer_card', $rows),
        'tombstone' => array_map(static fn (array $d): array => ['issuer_id' => (int) $d['id'], 'name' => $d['name'], 'ticker' => $d['ticker'], 'deleted_at' => json_ts($d['deleted_at'])], $deleted)]);
}
$notices = ['deleted' => ['success', 'Moved to the tombstone. Restore it below.']];
render_screen('Issuers', view('issuers/index.php', ['rows' => $rows, 'q' => $q, 'deleted' => $deleted, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'here' => here_url()]),
    ['activeNav' => 'issuers', 'screen' => 'issuer-list']);
