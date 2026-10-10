<?php
declare(strict_types=1);
/** /holders?security=&channel=&record_date=&kind=&custodian=&q=&tag=&page= — the holders of the current issuer as cards (screen `holder-list`): names for the PII roles, "Individual holder" for a Viewer (the view). */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_any_right('holders.read_aggregate|holders.read');
$pdo = db();
[$issuer, $security] = positions_context($pdo);
if ($security === null) {
    log_screen_view($pdo, 'holder-list');
    if (wants_json()) { respond_screen(['security_id' => null, 'holders' => []]); }
    render_screen('Holders', view('positions/partials/no-issuer.php', ['id' => 'holder-list', 'title' => 'Holders', 'issuer' => $issuer]), ['activeNav' => 'holders', 'screen' => 'holder-list']);
    return;
}
$sid = (int) $security['security_id'];
$dates = array_values(array_unique(array_merge(holder_record_dates($pdo, $sid, 'nobo'), holder_record_dates($pdo, $sid, 'register'))));
rsort($dates);
$rd = request_date('record_date');
$filters = ['q' => mb_substr(trim(request_string('q')), 0, 80), 'channel' => isset(HOLDER_CHANNELS[request_string('channel')]) ? request_string('channel') : '',
            'record_date' => is_string($rd) ? $rd : '', 'kind' => isset(HOLDER_KINDS[request_string('kind')]) ? request_string('kind') : '', 'custodian' => request_integer('custodian'), 'tag' => mb_substr(request_string('tag'), 0, 60)];
$page = max(1, request_integer('page') ?? 1);
$rows = find_holders($pdo, (int) $security['issuer_id'], ['q' => $filters['q'], 'channel' => $filters['channel'] ?: null, 'record_date' => $filters['record_date'] ?: null, 'kind' => $filters['kind'] ?: null,
    'custodian' => $filters['custodian'], 'tag' => $filters['tag']], $page);
$total = (int) ($rows[0]['total'] ?? 0);
log_screen_view($pdo, 'holder-list');
if (wants_json()) {
    respond_screen(['security_id' => $sid, 'filters' => $filters, 'record_dates' => $dates, 'total' => $total, 'page' => $page, 'holders' => array_map('present_holder', $rows)]);
}
render_screen('Holders', view('holders/index.php', ['security' => $security, 'rows' => $rows, 'filters' => $filters, 'dates' => $dates, 'page' => $page, 'total' => $total, 'here' => here_url(),
    'notice' => si_notice($_GET['notice'] ?? null, ['merged' => ['success', 'Merged.'], 'deleted' => ['success', 'Moved to the tombstone.']])]), ['activeNav' => 'holders', 'screen' => 'holder-list']);
