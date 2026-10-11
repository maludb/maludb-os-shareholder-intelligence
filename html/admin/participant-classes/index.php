<?php
declare(strict_types=1);
/**
 * /admin/participant-classes?class= — the class vocabulary and which participants sit in each (screen `admin-participant-classes`); reclass any participant through the participant picker, or move one from a class's list
 * (action `participant_class_set`). issuers.manage. A class set here is a person's (class_source manual): the directory's reading of a name never overwrites it.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/participants/queries.php';
require_right('issuers.manage');
$pdo = db();
$classes = classes_overview($pdo);
$keys = array_column($classes, 'class_key');
$class = in_array(request_string('class'), $keys, true) ? request_string('class') : '';
$people = [];
$more = 0;
if ($class !== '') {
    $people = find_participants($pdo, ['class' => $class], null, 200);
    $more = max(0, (int) one_value($pdo, 'SELECT count(*) FROM mcp_participants WHERE class_key = :c', ['c' => $class]) - count($people));
}
log_screen_view($pdo, 'admin-participant-classes');
if (wants_json()) {
    respond_screen(['classes' => array_map('present_class', $classes), 'filters' => ['class' => $class],
        'participants' => array_map(static fn (array $p): array => ['participant_id' => (int) $p['participant_id'], 'number' => $p['number'], 'name' => $p['name'], 'class_key' => $p['class_key'], 'class_source' => $p['class_source']], $people), 'more' => $more]);
}
$notices = ['classed' => ['success', 'Saved the participant\'s class.']];
render_screen('Participant classes', view('admin/classes.php', ['classes' => $classes, 'class' => $class, 'people' => $people, 'more' => $more, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => si_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-participant-classes', 'entity' => 'participant']);
