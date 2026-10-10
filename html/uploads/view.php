<?php
declare(strict_types=1);
/** /uploads/{id}?rows= — one upload (screen `upload-view`): its state, the file's totals against its rows, the rows, the override, delete, restore. */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
require_any_right('positions.read|uploads.write');
$pdo = db();
$u = upload_from_request($pdo, 'id');
$rowsPage = max(1, request_integer('rows') ?? 1);
$rows = $u['kind'] === 'spr' ? upload_rows($pdo, (int) $u['import_id'], false, $rowsPage) : [];
$totals = upload_totals($u);
$facts = $u['kind'] === 'spr' ? null : upload_holder_facts($pdo, $u);
log_screen_view($pdo, 'upload-view');
if (wants_json()) {
    respond_screen(['upload' => present_upload($u), 'totals' => $totals['by_date'], 'absent_dates' => $totals['absent'], 'override' => $u['override_at'] === null ? null : ['by' => $u['override_by_name'], 'at' => json_ts($u['override_at']), 'note' => $u['override_note']],
        'holders' => $facts === null ? null : ['control' => $totals['control'], 'derived' => $totals['derived'], 'waiting' => $facts['waiting'],
            'clients' => array_map(static fn (array $b): array => ['client' => $b['client'], 'participant_id' => $b['participant_id'] === null ? null : (int) $b['participant_id'], 'number' => $b['number'],
                'nobo_count' => $b['nobo_count'] === null ? null : (int) $b['nobo_count'], 'shares' => (float) $b['shares']], $facts['clients']),
            'failed_rows' => array_map(static fn (array $r): array => ['row_no' => (int) $r['row_no'], 'error' => $r['error']], $facts['failed']),
            'ranges' => array_map(static fn (array $r): array => ['population' => $r['population'], 'range_label' => $r['range_label'], 'accounts' => (int) $r['accounts'], 'shares' => (float) $r['shares']], $facts['ranges'])],
        'rows' => array_map(static fn (array $r): array => ['row_no' => (int) $r['row_no'], 'participant_number' => $r['participant_number'], 'participant_name' => $r['participant_name'], 'position_date' => $r['position_date'],
            'shares' => $r['shares'] === null ? null : (float) $r['shares'], 'error' => $r['error']], $rows), 'rows_total' => (int) ($rows[0]['total'] ?? 0)]);
}
$notices = ['tied' => ['success', 'Read and tied: every date adds up to the file\'s own total.'], 'untied' => ['danger', 'Read, but it does not tie — see the difference below. An IR Manager may accept it with a note.'],
    'queued' => ['secondary', 'The file is large: it is being read and this page will show the result.'], 'matching' => ['secondary', 'The rows are being matched to holders.'], 'matched' => ['success', 'Matched again.'], 'failed' => ['danger', 'The file was kept, but it could not be read.'],
    'overridden' => ['warning', 'Accepted with its difference on record.'], 'deleted' => ['success', 'Moved to the tombstone.'], 'restored' => ['success', 'Restored: its positions are back.']];
render_screen(UPLOAD_KINDS[$u['kind']] ?? 'Upload', view('uploads/view.php', ['u' => $u, 'totals' => $totals, 'facts' => $facts, 'rows' => $rows, 'rowsPage' => $rowsPage, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null,
    'tz' => member_timezone(), 'back' => back_link()]), ['activeNav' => 'uploads', 'screen' => 'upload-view', 'entity' => 'import', 'recordId' => (string) $u['import_id']]);
