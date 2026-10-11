<?php
declare(strict_types=1);
/**
 * /calendar?at=YYYY-MM&kinds= — the month grid of what happened and what is coming (screen `calendar`): past events as chips by kind, the record dates, earnings and
 * expiries to come, FINRA's expected publication days and the 13F deadlines muted; a day opens its events. Server-rendered; no script is needed.
 */
require_once dirname(__DIR__, 2) . '/app/features/events/screen.php';
require_right('events.read');
$pdo = db();
$issuer = events_issuer_or_empty($pdo, 'calendar', 'Calendar');
$iid = (int) $issuer['issuer_id'];
$kinds = array_values(array_filter(array_map('trim', explode(',', is_array($_GET['kinds'] ?? null) ? implode(',', $_GET['kinds']) : (string) ($_GET['kinds'] ?? ''))), static fn (string $k): bool => isset(EVENT_KINDS[$k])));
$cal = calendar_month($pdo, $iid, request_string('at'), $kinds);
log_screen_view($pdo, 'calendar');
if (wants_json()) {
    $items = [];
    foreach ($cal['items'] as $date => $list) { $items[$date] = array_map('present_upcoming', array_map(static fn (array $i): array => $i + ['on_date' => $date], $list)); }
    respond_screen(['issuer_id' => $iid, 'month' => $cal['month'], 'label' => $cal['label'], 'prev' => $cal['prev'], 'next' => $cal['next'], 'today' => $cal['today'], 'kinds' => $cal['kinds'],
        'days' => $items ?: new stdClass()]);
}
render_screen('Calendar', view('calendar/month.php', ['issuer' => $issuer, 'cal' => $cal, 'may' => has_right('events.write'), 'here' => here_url()]), ['activeNav' => 'events', 'screen' => 'calendar']);
