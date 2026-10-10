<?php
/** Proof: JSON mode on every slice 5 screen ({data}) and handler ({ok, did, record_id, location}) under a person's action token; a refusal answers 422 as JSON. */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
$sm = seed_ownership();
page($jP, '/?issuer=' . $sm['issuer']);
$json = ['headers' => ['Accept: application/json']];
q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, 'ROBERT A SMITH', si_name_key('ROBERT A SMITH'), 'person')", ['i' => $sm['issuer']]);
foreach (['edgar_13f_dataset', 'edgar_13dg', 'edgar_ownership', 'edgar_144'] as $c) { pull($c); }
$inst = (int) one("SELECT id FROM institutions WHERE cik = '0001111111'");
$cik = (string) one('SELECT reporting_owner_cik FROM insider_transactions LIMIT 1');
$holder = (int) one("SELECT id FROM holders WHERE display_name = 'ROBERT A SMITH'");

echo "1. Every screen answers JSON\n";
$bad = [];
foreach (['/institutions', '/institutions/' . $inst, '/institutions/' . $inst . '/edit', '/ownership/13f', '/ownership/13f/changes', '/ownership/beneficial', '/ownership/insiders', '/ownership/144'] as $u) {
    $r = page($jP, $u, $json);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !isset($b['data'])) { $bad[] = "$u {$r['code']}"; }
}
ok($bad === [], 'all eight slice 5 screens answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));

echo "2. Every handler under a person's action token\n";
$h = ['Accept: application/json', 'X-Action-Token: ' . person_token(26)];
$bad = [];
foreach ([['/institutions/save.php', ['institution' => $inst, 'notes' => 'SMOKE: a note']], ['/ownership/insiders/link.php', ['reporting_owner_cik' => $cik, 'holder' => $holder]]] as [$path, $form]) {
    $r = req('POST', $path, ['headers' => $h, 'form' => $form]);
    $b = json_decode($r['body'], true) ?: [];
    if ($r['code'] !== 200 || ($b['ok'] ?? false) !== true || !isset($b['did'], $b['location']) || !array_key_exists('record_id', $b)) { $bad[] = "$path {$r['code']} " . substr($r['body'], 0, 120); }
}
ok($bad === [], 'institution_update and insider_holder_link answer {ok, did, record_id, location}' . ($bad ? ': ' . implode(' | ', $bad) : ''));
$r = req('POST', '/ownership/insiders/link.php', ['headers' => $h, 'form' => ['reporting_owner_cik' => 'x', 'holder' => $holder]]);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid', 'a refusal: 422 as JSON');
finish();
