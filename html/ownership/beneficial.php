<?php
declare(strict_types=1);
/** /ownership/beneficial?security= — the 5% filers (screen `ownership-beneficial`): the latest 13D or 13G per filer, percent as a bar against 5% and 10%, shares, the event and filed dates, the purpose, the filing. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
[, $security] = ownership_context_or_empty($pdo, 'ownership-beneficial', '5% holders');
$sid = (int) $security['security_id'];
$rows = beneficial($pdo, $sid);
log_screen_view($pdo, 'ownership-beneficial');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'owners' => array_map(static fn ($r) => ['institution_id' => (int) $r['institution_id'], 'name' => $r['name'], 'kind' => $r['kind'], 'schedule' => $r['schedule'],
        'percent_of_class' => $r['percent_of_class'] === null ? null : (float) $r['percent_of_class'], 'shares' => $r['shares'] === null ? null : (float) $r['shares'], 'filed_on' => $r['filed_on'], 'event_date' => $r['event_date'],
        'purpose_text' => $r['purpose_text'], 'filing_id' => $r['filing_id'] === null ? null : (int) $r['filing_id']], $rows)]);
}
render_screen('5% holders', view('ownership/beneficial.php', ['security' => $security, 'rows' => $rows, 'here' => here_url()]), ['activeNav' => 'ownership', 'screen' => 'ownership-beneficial']);
