<?php
declare(strict_types=1);
/** /holders/{id}/edit — a holder's kind, entity type, name, address and notes (screen `holder-edit`; the PII roles: matches.decide). */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_right('matches.decide');
require_human();
$pdo = db();
$h = holder_from_request($pdo, 'id');
log_screen_view($pdo, 'holder-edit');
if (wants_json()) {
    respond_screen(['holder' => present_holder($h), 'kinds' => HOLDER_KINDS, 'entity_types' => HOLDER_ENTITY_TYPES]);
}
render_screen('Edit the holder', view('holders/form.php', ['h' => $h]), ['activeNav' => 'holders', 'screen' => 'holder-edit', 'entity' => 'holder', 'recordId' => (string) $h['holder_id']]);
