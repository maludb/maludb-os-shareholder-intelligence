<?php
declare(strict_types=1);
/** /institutions/{id}/edit — an institution's kind, activist mark, website, notes (screen `institution-edit`; events.write). */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('events.write');
$pdo = db();
$i = find_institution($pdo, (int) (request_integer('id') ?? 0), null) ?? refuse(404, 'Not found.');
log_screen_view($pdo, 'institution-edit');
if (wants_json()) { respond_screen(['institution' => present_institution($i), 'kinds' => INSTITUTION_KINDS]); }
render_screen('Edit the institution', view('institutions/form.php', ['i' => $i]), ['activeNav' => 'ownership', 'screen' => 'institution-edit', 'entity' => 'institution', 'recordId' => (string) $i['institution_id']]);
