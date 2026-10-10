<?php
declare(strict_types=1);
/** /participants/{id}?security=&from=&to= — one participant (screen `participant-view`): its series in the security (the current issuer's primary by default, 90 days), facts, notes, tags. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/participants/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/issuers/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/issuers/present.php';
require_right('positions.read');
$pdo = db();
$p = find_participant($pdo, (int) (request_integer('id') ?? 0));
if ($p === null) {
    refuse(404, 'Not found.');
}
$security = request_integer('security') !== null ? find_security($pdo, (int) request_integer('security')) : current_security($pdo);
$to = request_date('to') ?: date('Y-m-d');
$from = request_date('from') ?: date('Y-m-d', strtotime($to . ' -90 days'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
$points = $security === null ? [] : participant_series_points($pdo, (int) $p['participant_id'], (int) $security['security_id'], $from, $to);
$presence = $security === null ? ['days' => 0, 'first' => null, 'last' => null, 'pct_of_total' => null] : participant_presence($pdo, (int) $p['participant_id'], (int) $security['security_id']);
$notes = find_notes($pdo, 'participant', (int) $p['participant_id']);
require_once dirname(__DIR__, 2) . '/app/features/holders/queries.php';
$nobo = $security === null ? [] : participant_nobo_summary($pdo, (int) $p['participant_id'], (int) $security['security_id']);
log_screen_view($pdo, 'participant-view');
if (wants_json()) {
    respond_screen(['participant' => present_participant($p + ['position_date' => null, 'shares' => null]), 'security_id' => $security === null ? null : (int) $security['security_id'], 'from' => $from, 'to' => $to,
        'series' => array_map(static fn (array $r): array => ['position_date' => $r['position_date'], 'shares' => (float) $r['shares'], 'change' => $r['change'] === null ? null : (float) $r['change']], $points),
        'presence' => $presence, 'notes' => array_map('present_note', $notes),
        'nobo' => array_map(static fn (array $r): array => ['record_date' => $r['record_date'], 'nobo_count' => $r['nobo_count'] === null ? null : (int) $r['nobo_count'], 'nobo_shares' => $r['nobo_shares'] === null ? null : (float) $r['nobo_shares'],
            'spr_shares' => $r['spr_shares'] === null ? null : (float) $r['spr_shares'], 'spr_date' => $r['spr_date'], 'obo_remainder' => $r['obo_remainder'] === null ? null : (float) $r['obo_remainder']], $nobo)]);
}
$notices = ['updated' => ['success', 'Saved.'], 'note_added' => ['success', 'Added the note.']];
render_screen($p['number'] . ' ' . $p['name'], view('participants/view.php', ['p' => $p, 'security' => $security, 'from' => $from, 'to' => $to, 'points' => $points, 'presence' => $presence, 'notes' => $notes, 'nobo' => $nobo,
    'tz' => member_timezone(), 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'here' => here_url()]), ['activeNav' => 'participants', 'screen' => 'participant-view', 'entity' => 'participant', 'recordId' => (string) $p['participant_id']]);
