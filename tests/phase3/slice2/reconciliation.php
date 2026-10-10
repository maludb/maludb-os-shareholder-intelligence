<?php
/**
 * Proof: THE REAL RECONCILIATION through the screens and actions (position-reports.md §6): the SPR of the week ending June 28, 2024 uploaded;
 * before any Cede figure the reconciliation has nothing to compare (said so, never "does not tie"); the transfer agent's Cede & Co line of
 * June 30, 2024 (3,397,800 — the Shareholder Extract's one nominee row) and its outstanding (49,912,607) recorded by an IR Manager; then
 * reconciliation_run ties the SPR's total to the register's Cede line to the share; a DTC letter that disagrees is untied by a named amount; the
 * worker reruns the due ones; the home's tile follows.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
$ref = reference_issuer();
$sec = $ref['security'];
$json = ['headers' => ['Accept: application/json']];
$f = glob(REAL . '*SPR_06-28-2024_09175A107*.csv')[0];
$r = upload($jP, $f, ['security' => $sec]);
ok($r['code'] === 200 && $r['json']['tied'] === true && $r['json']['period'][1] === '2024-06-28', 'the week ending June 28, 2024: tied');
ok((float) one("SELECT si_spr_total(:s, '2024-06-28')", ['s' => $sec]) === 3397800.0, 'its total on Friday: 3,397,800');

echo "1. Nothing to compare yet\n";
$d = json_decode(page($jP, '/reconciliation?security=' . $sec, $json)['body'], true)['data'] ?? [];
ok(($d['latest']['state'] ?? '') === 'incomplete' && (float) $d['latest']['spr_total'] === 3397800.0 && $d['latest']['cede_registered'] === null, 'the run after the upload: the SPR\'s side known, no Cede figure — not enough to compare');
$h = page($jP, '/reconciliation?security=' . $sec)['body'];
ok(str_contains($h, 'not enough to compare') && !str_contains($h, 'does not tie</span>'), '…said so, never "does not tie"');
page($jP, '/?issuer=' . $ref['issuer']);
ok(str_contains(page($jP, '/')['body'], 'Not enough to compare'), 'the home\'s tile says the same');

echo "2. The transfer agent's figures, recorded; the reconciliation ties\n";
ok(post_json($jM, '/issuers/share-counts/save.php', ['security' => $sec, 'as_of' => '2024-06-30', 'kind' => 'cede', 'value' => '3,397,800', 'source' => 'transfer_agent', 'note' => 'the Shareholder Extract of 06-30-24: CEDE & CO (FAST)'])['code'] === 200
    && post_json($jM, '/issuers/share-counts/save.php', ['security' => $sec, 'as_of' => '2024-06-30', 'kind' => 'outstanding', 'value' => '49,912,607', 'source' => 'transfer_agent'])['code'] === 200, 'Cede & Co 3,397,800 and outstanding 49,912,607 as of June 30, from the transfer agent');
$since = last_activity_id();
$r = post_json($jP, '/reconciliation/run.php', ['security' => $sec, 'as_of' => '2024-06-30', 'note' => 'the quarter end']);
ok($r['code'] === 200 && ($r['json']['state'] ?? '') === 'tied' && $r['json']['did'] === 'It ties', 'reconciliation_run as of June 30: it ties');
$d = json_decode(page($jP, '/reconciliation?security=' . $sec, $json)['body'], true)['data']['latest'] ?? [];
ok((float) $d['difference_spr'] === 0.0 && $d['tied_spr'] === true && $d['spr_date'] === '2024-06-28' && $d['cede_source'] === 'share_count' && $d['tied_registered'] === null,
    'Cede & Co = the SPR total on June 28, to the share; the register\'s side waits for the register (slice 3)');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'reconciliation.tie' AND security_id = :s AND id > :x", ['s' => $sec, 'x' => $since]) === 1, 'logged reconciliation.tie with the security');
$h = page($jP, '/reconciliation?security=' . $sec)['body'];
ok(str_contains($h, 'id="recon-spr"') && str_contains($h, '3,397,800') && str_contains($h, '>ties</span>'), 'the page shows the equation with its figures and their sources');
$hh = page($jP, '/')['body'];
ok(str_contains($hh, 'feather-check-circle me-1"></i>Ties'), 'the home\'s tile: Ties');
$d = json_decode(page($jP, '/positions?date=2024-06-28', $json)['body'], true)['data'] ?? [];
ok(($d['reconciliation']['state'] ?? '') === 'tied' && str_contains(page($jP, '/positions?date=2024-06-28')['body'], 'ties to Cede &amp; Co'), 'the position table of June 28 says its total ties to Cede & Co');

echo "3. A figure that disagrees\n";
post_json($jM, '/issuers/share-counts/save.php', ['security' => $sec, 'as_of' => '2024-07-01', 'kind' => 'cede', 'value' => '3,300,000', 'source' => 'dtc_letter']);
$r = post_json($jP, '/reconciliation/run.php', ['security' => $sec, 'as_of' => '2024-07-01']);
$d = json_decode(page($jP, '/reconciliation?security=' . $sec, $json)['body'], true)['data']['latest'] ?? [];
ok(($r['json']['state'] ?? '') === 'untied' && (float) $d['difference_spr'] === -97800.0, 'a DTC letter of 3,300,000 on July 1: untied, off by −97,800, named');
ok(str_contains(page($jP, '/reconciliation?security=' . $sec)['body'], 'id="recon-defect"'), 'the page calls it a data defect before anything else');
ok(post_json($jP, '/reconciliation/run.php', ['security' => $sec, 'as_of' => 'yesterday'])['code'] === 422, 'a run as of "yesterday": 422 — a date');

echo "4. The worker reruns the due ones\n";
post_json($jM, '/issuers/share-counts/save.php', ['security' => $sec, 'as_of' => '2024-07-02', 'kind' => 'float', 'value' => '45,000,000', 'source' => 'manual']);
$w = worker('reconciliation', '2026-10-07T10:00:00-04:00');
ok(($w['report']['ran']['reconciliation']['ran'] ?? 0) === 1, 'a new count makes the security due: the worker runs it');
$w = worker('reconciliation', '2026-10-07T10:05:00-04:00');
ok(($w['report']['ran']['reconciliation']['ran'] ?? -1) === 0, 'nothing changed since: nothing run');
finish();
