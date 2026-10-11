<?php
declare(strict_types=1);
/** /events/{id} — one event (screen `event-view`): its date, kind and severity, the body, its filing, the signals it explains, the tags, the notes and the trail. */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
require_right('events.read');
$pdo = db();
$e = event_from_request($pdo, 'id');
$eid = (int) $e['event_id'];
$explains = event_explains($pdo, $eid);
$notes = find_notes($pdo, 'event', $eid);
log_screen_view($pdo, 'event-view');
if (wants_json()) {
    respond_screen(['event' => present_event($e), 'signals' => array_map(static fn (array $s): array => ['signal_id' => (int) $s['signal_id'], 'rule_name' => $s['rule_name'], 'severity' => $s['severity'],
        'status' => $s['status'], 'raised_on' => $s['raised_on']], $explains), 'notes' => array_map('present_note', $notes)]);
}
$notices = ['saved' => ['success', 'Saved the event.'], 'created' => ['success', 'Recorded the event.'], 'note_added' => ['success', 'Added the note.'], 'restored' => ['success', 'Restored the event.']];
render_screen((string) $e['title'], view('events/view.php', ['e' => $e, 'explains' => $explains, 'notes' => $notes, 'may' => has_right('events.write') && (current_member()['member_kind'] ?? '') === 'human',
    'trail' => find_record_activity($pdo, 'event', $eid, 20), 'tags' => is_array($e['tags']) ? $e['tags'] : pg_text_array((string) $e['tags']), 'tz' => member_timezone(), 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'events', 'screen' => 'event-view', 'entity' => 'event', 'recordId' => (string) $eid]);
