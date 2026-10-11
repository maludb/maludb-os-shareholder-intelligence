<?php
declare(strict_types=1);
/**
 * /events?kind=&severity=&from=&to=&q=&page= — the current issuer's timeline (screen `event-list`): grouped by month, each event with its date, the kind chip, the
 * severity dot, the title and its filing linked; the filters narrow it, a person with events.write sees "Record an event".
 */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
require_right('events.read');
$pdo = db();
$issuer = events_issuer_or_empty($pdo, 'event-list', 'Events');
$iid = (int) $issuer['issuer_id'];
$from = request_date('from');
$to = request_date('to');
$f = ['kind' => array_values(array_filter((array) ($_GET['kind'] ?? []), 'is_string')), 'severity' => array_values(array_filter((array) ($_GET['severity'] ?? []), 'is_string')),
      'from' => is_string($from) ? $from : null, 'to' => is_string($to) ? $to : null, 'q' => request_string('q') ?: null];
$f['kind'] = array_values(array_filter($f['kind'], static fn (string $k): bool => isset(EVENT_KINDS[$k])));
$f['severity'] = array_values(array_filter($f['severity'], static fn (string $s): bool => isset(EVENT_SEVERITIES[$s])));
$list = find_events($pdo, $iid, $f, request_integer('page') ?? 1);
log_screen_view($pdo, 'event-list');
if (wants_json()) {
    respond_screen(['issuer_id' => $iid, 'filters' => $f, 'total' => $list['total'], 'page' => $list['page'], 'pages' => $list['pages'], 'events' => array_map('present_event', $list['rows']),
        'upcoming' => array_map('present_upcoming', events_upcoming($pdo, $iid, 30))]);
}
$notices = ['deleted' => ['success', 'Deleted the event. It stays recoverable until the purge window ends.'], 'restored' => ['success', 'Restored the event.']];
$undo = null;
if (($_GET['notice'] ?? '') === 'deleted' && has_right('events.write') && ($u = request_integer('undo')) !== null) {
    $b = event_base_row($pdo, $u);
    $undo = $b !== null && $b['deleted_at'] !== null ? $u : null;               // the tombstone the person just made, taken back in one click
}
render_screen('Events', view('events/index.php', ['issuer' => $issuer, 'f' => $f, 'list' => $list, 'may' => has_right('events.write'), 'here' => here_url(),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'undo' => $undo]), ['activeNav' => 'events', 'screen' => 'event-list']);
