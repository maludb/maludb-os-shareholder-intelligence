<?php
declare(strict_types=1);
/**
 * /reports/{id} — one pack (screen `report-view`): the narrative, the sections rendered below it (a draft from today's data; an approved pack as it was
 * rendered at approval), Edit, Render, Approve, Download, Send to…, Delete according to the right and the status.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_any_right('reports.read|reports.run');
$pdo = db();
$r = report_from_request($pdo, 'id');
$rid = (int) $r['report_id'];
$row = report_row($pdo, $rid);
$stored = $r['status'] !== 'draft' ? pack_stored_sections($row['rendered_path'] ?? null) : null;
$sections = $stored ?? pack_sections_html($pdo, $r);
$notes = find_notes($pdo, 'report', $rid);
log_screen_view($pdo, 'report-view');
if (wants_json()) {
    respond_screen(['report' => present_report($r), 'sections_as' => $stored !== null ? 'rendered at approval' : 'rendered now', 'notes' => array_map('present_note', $notes)]);
}
$notices = ['drafted' => ['success', 'Drafted the pack. Read it, edit the narrative, render it, then ask an IR Manager to approve it.'], 'saved' => ['success', 'Saved the pack.'], 'rendered' => ['success', 'Rendered the pack.'],
            'approved' => ['success', 'Approved: the narrative is frozen and the pack is kept as rendered.'], 'sent' => ['success', 'Sent the pack to the members you chose.'], 'note_added' => ['success', 'Added the note.']];
$human = (current_member()['member_kind'] ?? '') === 'human';
$n = $_GET['notice'] ?? '';
$notice = $notices[$n] ?? null;
if ($n === 'sent' && ($c = request_integer('count')) !== null) { $notice = ['success', 'Sent the pack to ' . $c . ' member' . ($c === 1 ? '' : 's') . (request_integer('failed') ? '; ' . request_integer('failed') . ' could not be reached.' : '.')]; }
render_screen((string) $r['title'], view('reports/view.php', ['r' => $r, 'sections' => $sections, 'storedAt' => $stored !== null, 'notes' => $notes, 'canRun' => has_right('reports.run') && $human, 'canApprove' => has_right('reports.approve') && $human,
    'hasHtml' => $r['rendered'], 'hasPdf' => $r['has_pdf'], 'tz' => member_timezone(), 'back' => back_link(), 'notice' => $notice]),
    ['activeNav' => 'reports', 'screen' => 'report-view', 'entity' => 'report', 'recordId' => (string) $rid]);
