<?php
declare(strict_types=1);
/** /participants/{id}/edit — a participant's class, notes and tags (screen `participant-edit`; issuers.manage). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/participants/queries.php';
require_right('issuers.manage');
$pdo = db();
$p = find_participant($pdo, (int) (request_integer('id') ?? 0));
if ($p === null) {
    refuse(404, 'Not found.');
}
$classes = participant_classes($pdo);
log_screen_view($pdo, 'participant-edit');
if (wants_json()) {
    respond_screen(['participant' => present_participant($p + ['position_date' => null, 'shares' => null]), 'classes' => array_map(static fn (array $c): array => ['name' => $c[0], 'side' => $c[1], 'description' => $c[2]], $classes)]);
}
render_screen('Edit ' . $p['number'], view('participants/form.php', ['p' => $p, 'classes' => $classes]), ['activeNav' => 'participants', 'screen' => 'participant-edit', 'entity' => 'participant', 'recordId' => (string) $p['participant_id']]);
