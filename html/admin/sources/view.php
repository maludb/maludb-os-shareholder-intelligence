<?php
declare(strict_types=1);
/**
 * /admin/sources/{id} — one source (screen `admin-source-view`): its settings as a definition list, its credential's label and last four (never the secret), its schedule with the calendar's next slot,
 * the last 50 pulls (status, counts, requests, bytes, the error, and the policy followed: the user-agent, the rate, robots, the cache), the schedule and pause forms. sources.manage.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_right('sources.manage');
$pdo = db();
$s = find_source($pdo, (int) (request_integer('id') ?? 0)) ?? refuse(404, 'Source not found.');
$base = find_source_base($pdo, (int) $s['source_id']);
$pulls = source_pulls($pdo, (int) $s['source_id'], 50);
$next = source_next_slot($pdo, ['connector' => $s['connector'], 'settings' => $s['settings'], 'schedule_minutes' => $s['schedule_minutes'], 'last_ok_at' => $s['last_ok_at'], 'backoff_until' => $s['backoff_until'], 'paused_at' => $s['paused_at'], 'active' => $s['active']]);
$ua = one_value($pdo, 'SELECT si_edgar_user_agent()') ?: null;
log_screen_view($pdo, 'admin-source-view');
if (wants_json()) {
    respond_screen(['source' => present_source_detail($s, $pulls, $next), 'user_agent_override' => ($base['user_agent'] ?? '') !== '' ? $base['user_agent'] : null, 'edgar_user_agent_set' => $ua !== null]);
}
$notices = ['saved' => ['success', 'Saved the source.'], 'scheduled' => ['success', 'Saved the schedule.'], 'paused' => ['success', 'Paused the source.'], 'resumed' => ['success', 'Resumed the source.'], 'pulled' => ['success', 'Pulled the source.']];
render_screen($s['name'], view('admin/source.php', ['s' => $s, 'base' => $base, 'pulls' => $pulls, 'next' => $next, 'ua' => $ua, 'tz' => member_timezone(), 'notice' => si_notice($_GET['notice'] ?? null, $notices), 'back' => back_link()]),
    ['activeNav' => 'admin', 'screen' => 'admin-source-view', 'entity' => 'source', 'recordId' => (string) $s['source_id']]);
