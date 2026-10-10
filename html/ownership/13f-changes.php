<?php
declare(strict_types=1);
/** /ownership/13f/changes?security=&period=&state= — the quarter against the prior (screen `ownership-13f-changes`): new, out, up, down, with the shares from → to. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
[, $security] = ownership_context_or_empty($pdo, 'ownership-13f-changes', '13F changes');
$sid = (int) $security['security_id'];
$state = in_array(request_string('state'), ['new', 'out', 'up', 'down', 'same'], true) ? request_string('state') : null;
$c = thirteen_f_changes($pdo, $sid, is_string(request_date('period')) ? request_date('period') : null, $state);
log_screen_view($pdo, 'ownership-13f-changes');
if (wants_json()) {
    $num = static fn ($v) => $v === null ? null : (float) $v;
    respond_screen(['security_id' => $sid, 'period' => $c['period'], 'prior' => $c['prior'], 'state' => $state, 'counts' => $c['counts'],
        'rows' => array_map(static fn ($r) => ['institution_id' => (int) $r['institution_id'], 'name' => $r['name'], 'state' => $r['state'], 'prior_shares' => $num($r['prior_shares']), 'shares' => $num($r['shares']), 'net' => (float) $r['net']], $c['rows'])]);
}
render_screen('13F changes', view('ownership/13f-changes.php', ['security' => $security, 'c' => $c, 'state' => $state, 'here' => here_url()]), ['activeNav' => 'ownership', 'screen' => 'ownership-13f-changes']);
