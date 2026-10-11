<?php
declare(strict_types=1);
/**
 * /signals/{id} — one signal (screen `signal-view`): the summary, the basis, the evidence as rows and a chart of its window, the issuer's events around it,
 * what the price did after; Acknowledge, Dismiss with a reason and the explaining event, Escalate; the agent's proposal with Take it; the notes.
 */
require_once dirname(__DIR__, 2) . '/app/features/signals/screen.php';
require_right('signals.read');
$pdo = db();
$sig = signal_from_request($pdo, 'id');
$s = $sig['signal'];
$sid = (int) $s['signal_id'];
log_screen_view($pdo, 'signal-view');
if (wants_json()) {
    $r = $sig['rule'];
    respond_screen(['signal' => present_signal($s), 'rule' => ['key' => $r['key'], 'name' => $r['name'], 'evidence_class' => $r['evidence_class'], 'basis' => $r['basis'], 'description' => $r['description'],
        'params' => $r['effective_params'] ?: new stdClass()], 'evidence' => $sig['evidence'] ?: new stdClass(), 'events' => array_map('present_signal_event', $sig['events']),
        'outcome' => present_signal_outcome($sig['outcome']), 'notes' => array_map('present_note', $sig['notes'])]);
}
$human = (current_member()['member_kind'] ?? '') === 'human';
$notices = ['acknowledged' => ['success', 'Acknowledged: the signal keeps its reason.'], 'dismissed' => ['success', 'Dismissed with its reason.'], 'escalated' => ['success', 'Escalated: the IR Manager is told.'],
            'proposed' => ['success', 'The proposal is on the signal for a person to take.'], 'note_added' => ['success', 'Added the note.']];
render_screen((string) $s['rule_name'], view('signals/view.php', ['sig' => $sig, 's' => $s, 'chart' => signal_chart($pdo, $s, $sig['evidence']), 'may' => has_right('signals.work') && $human,
    'canNote' => has_right('notes.write'), 'canDelete' => has_right('rules.manage'), 'here' => '/signals/' . $sid, 'tz' => member_timezone(), 'back' => back_link(),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'signals', 'screen' => 'signal-view', 'entity' => 'signal', 'recordId' => (string) $sid]);
