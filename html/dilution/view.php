<?php
declare(strict_types=1);
/** /dilution/{id} — one instrument (screen `dilution-view`): the filing, the terms, what remains, the notes, the signals that name it (dilution_overlap). */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$d = instrument_from_request($pdo, 'id');
$notes = find_notes($pdo, 'dilution_instrument', (int) $d['instrument_id']);
$st = $pdo->prepare("SELECT signal_id, rule_name, severity, status, raised_on, summary FROM mcp_signals WHERE issuer_id = :i AND evidence ->> 'instrument_id' = CAST(:d AS text) ORDER BY raised_on DESC, signal_id DESC LIMIT 20");
$st->execute(['i' => (int) $d['issuer_id'], 'd' => (int) $d['instrument_id']]);
$signals = $st->fetchAll();
log_screen_view($pdo, 'dilution-view');
if (wants_json()) {
    respond_screen(['instrument' => present_instrument($d), 'notes' => array_map('present_note', $notes),
        'signals' => array_map(static fn ($g) => ['signal_id' => (int) $g['signal_id'], 'rule_name' => $g['rule_name'], 'severity' => $g['severity'], 'status' => $g['status'], 'raised_on' => $g['raised_on']], $signals)]);
}
$notices = ['saved' => ['success', 'Saved.'], 'confirmed' => ['success', 'Confirmed: the instrument is on record.'], 'note_added' => ['success', 'Added the note.']];
render_screen(DILUTION_KINDS[$d['kind']] ?? 'Instrument', view('dilution/view.php', ['d' => $d, 'notes' => $notes, 'signals' => $signals, 'tz' => member_timezone(), 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'filings', 'screen' => 'dilution-view', 'entity' => 'dilution_instrument', 'recordId' => (string) $d['instrument_id']]);
