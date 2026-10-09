<?php
declare(strict_types=1);
/** /participants?class=&q= — the DTC participants as cards by class (screen `participant-list`), each with its latest position in the current issuer's primary security. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/participants/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/issuers/present.php';
require_right('positions.read');
$pdo = db();
$classes = participant_classes($pdo);
$class = isset($classes[request_string('class')]) ? request_string('class') : '';
$q = mb_substr(trim(request_string('q')), 0, 80);
$security = current_security($pdo);
$rows = find_participants($pdo, ['class' => $class, 'q' => $q], $security === null ? null : (int) $security['security_id']);
log_screen_view($pdo, 'participant-list');
if (wants_json()) {
    respond_screen(['class' => $class ?: null, 'q' => $q, 'security_id' => $security === null ? null : (int) $security['security_id'], 'participants' => array_map('present_participant', $rows),
        'classes' => array_map(static fn (array $c): array => ['name' => $c[0], 'side' => $c[1]], $classes)]);
}
render_screen('DTC participants', view('participants/index.php', ['rows' => $rows, 'classes' => $classes, 'class' => $class, 'q' => $q, 'security' => $security, 'here' => here_url(), 'notice' => null]),
    ['activeNav' => 'participants', 'screen' => 'participant-list']);
