<?php
declare(strict_types=1);
/**
 * / — the home (screen `home`, sso-shell.md): for the current issuer (?issuer= switches it) the ownership tiles with their sources, the open
 * signals, the price with the events overlay, the coming events, the latest uploads and the morning note; several issuers and none chosen →
 * a card each; none visible → the first-run page (a manager's one button, anyone else's sentence). JSON: the same, whitelisted.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$issuer = current_issuer($pdo);
$s = home_summary($pdo, $me, $issuer);
log_screen_view($pdo, 'home');
if (wants_json()) {
    $num = static fn ($v): ?float => $v === null ? null : (float) $v;
    respond_screen([
        'first_run' => $s['first_run'], 'choose' => $s['choose'],
        'issuer' => $issuer === null ? null : ['issuer_id' => (int) $issuer['issuer_id'], 'name' => $issuer['name'], 'ticker' => $issuer['ticker'], 'restricted' => (bool) $issuer['restricted']],
        'issuers' => $s['choose'] ? array_map(static fn (array $i): array => ['issuer_id' => (int) $i['issuer_id'], 'name' => $i['name'], 'ticker' => $i['ticker']], my_issuers($pdo)) : [],
        'summary' => $s['summary'],
        'counts' => array_map(static fn (array $c): array => ['value' => $num($c['value']), 'as_of' => $c['as_of'], 'source' => $c['source']], $s['counts']),
        'signals' => array_map(static fn (array $g): array => ['signal_id' => (int) $g['signal_id'], 'rule' => $g['rule_key'], 'rule_name' => $g['rule_name'], 'evidence_class' => $g['evidence_class'],
            'severity' => $g['severity'], 'raised_on' => $g['raised_on'], 'summary' => $g['summary'], 'proposal_reason' => $g['proposal_reason']], $s['signals']),
        'events' => array_map(static fn (array $e): array => ['event_id' => (int) $e['event_id'], 'on_date' => $e['on_date'], 'kind' => $e['kind'], 'title' => $e['title'], 'severity' => $e['severity']], $s['events']),
        'uploads' => array_map(static fn (array $u): array => ['import_id' => (int) $u['import_id'], 'kind' => $u['kind'], 'record_date' => $u['record_date'] ?? $u['period_to'], 'status' => $u['status'],
            'tied' => $u['tied'], 'overridden' => $u['override_at'] !== null], $s['uploads']),
        'prices' => count($s['overlay']['prices']),
        'note' => $s['note'] === null ? null : ['title' => $s['note']['title'], 'body' => $s['note']['body'], 'created_at' => json_ts($s['note']['created_at'])],
        'may' => $s['may'],
    ]);
}
if ($s['first_run']) {
    $html = view('home/first-run.php', ['s' => $s]);
} elseif ($s['choose']) {
    $html = view('home/choose.php', ['issuers' => my_issuers($pdo)]);
} else {
    $html = view('home/index.php', ['s' => $s, 'tz' => member_timezone()]);
}
render_screen('Home', $html, ['activeNav' => 'home', 'screen' => 'home', 'entity' => 'issuer', 'recordId' => $issuer['issuer_id'] ?? '']);
