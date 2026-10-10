<?php
declare(strict_types=1);
/** /ownership/13f?security=&period= — a quarter's 13F holders ranked (screen `ownership-13f`): shares, value, voting sole/shared/none, % of outstanding; the summary row and the top-10 share; puts and calls apart. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
[, $security] = ownership_context_or_empty($pdo, 'ownership-13f', '13F holders');
$sid = (int) $security['security_id'];
$t = thirteen_f($pdo, $sid, is_string(request_date('period')) ? request_date('period') : null);
log_screen_view($pdo, 'ownership-13f');
if (wants_json()) {
    $num = static fn ($v) => $v === null ? null : (float) $v;
    respond_screen(['security_id' => $sid, 'period' => $t['period'], 'periods' => $t['periods'],
        'holders' => array_map(static fn ($r) => ['institution_id' => (int) $r['institution_id'], 'name' => $r['name'], 'kind' => $r['kind'], 'shares' => $num($r['shares']), 'value_dollars' => $num($r['value_dollars']),
            'voting_sole' => $num($r['voting_sole']), 'voting_shared' => $num($r['voting_shared']), 'voting_none' => $num($r['voting_none']), 'pct_of_outstanding' => $num($r['pct_of_outstanding']), 'state' => $r['state']], $t['holders']),
        'summary' => $t['summary'] === null ? null : array_map(static fn ($v) => is_numeric($v) ? (float) $v : $v, $t['summary']),
        'options' => array_map(static fn ($r) => ['institution_id' => (int) $r['institution_id'], 'name' => $r['name'], 'put_call' => $r['put_call'], 'shares' => (float) $r['shares']], $t['options'])]);
}
render_screen('13F holders', view('ownership/13f.php', ['security' => $security, 't' => $t, 'here' => here_url()]), ['activeNav' => 'ownership', 'screen' => 'ownership-13f']);
