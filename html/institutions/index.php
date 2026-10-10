<?php
declare(strict_types=1);
/** /institutions?kind=&q=&activist=&security= — the 13F and 13D/13G filers as cards (screen `institution-list`): kind, CIK, the activist mark, the latest holding in the current security with its quarter. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
[, $security] = positions_context($pdo);
$f = ['kind' => isset(INSTITUTION_KINDS[request_string('kind')]) ? request_string('kind') : '', 'q' => mb_substr(trim(request_string('q')), 0, 80), 'activist' => request_bool('activist')];
$rows = find_institutions($pdo, $f, $security === null || $f['q'] !== '' ? null : (int) $security['security_id']);
log_screen_view($pdo, 'institution-list');
if (wants_json()) {
    respond_screen(['security_id' => $security === null ? null : (int) $security['security_id'], 'filters' => $f, 'institutions' => array_map('present_institution', $rows)]);
}
render_screen('Institutions', view('institutions/index.php', ['security' => $security, 'rows' => $rows, 'f' => $f, 'here' => here_url()]), ['activeNav' => 'ownership', 'screen' => 'institution-list']);
