<?php
declare(strict_types=1);
/** /dilution/{id} — one instrument (screen `dilution-view`): the filing, the terms, what remains, the notes; the signals naming it come with slice 7. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$d = instrument_from_request($pdo, 'id');
$notes = find_notes($pdo, 'dilution_instrument', (int) $d['instrument_id']);
log_screen_view($pdo, 'dilution-view');
if (wants_json()) {
    respond_screen(['instrument' => present_instrument($d), 'notes' => array_map('present_note', $notes)]);
}
$notices = ['saved' => ['success', 'Saved.'], 'confirmed' => ['success', 'Confirmed: the instrument is on record.'], 'note_added' => ['success', 'Added the note.']];
render_screen(DILUTION_KINDS[$d['kind']] ?? 'Instrument', view('dilution/view.php', ['d' => $d, 'notes' => $notes, 'tz' => member_timezone(), 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'filings', 'screen' => 'dilution-view', 'entity' => 'dilution_instrument', 'recordId' => (string) $d['instrument_id']]);
