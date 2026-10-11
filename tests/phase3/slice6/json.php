<?php
/** Proof: JSON mode on every slice 6 screen ({data}, FINRA's caveat in the short side's and the volume's) and handler ({ok, did, record_id, location}) under a person's action token; a refusal answers 422 as JSON. */
require __DIR__ . '/lib.php';
$sm = seed_short_all();
$sec = $sm['security'];
[$jP, ] = sign_on(26);
page($jP, '/?issuer=' . $sm['issuer']);
$json = ['headers' => ['Accept: application/json']];

echo "1. Every screen answers JSON\n";
$bad = [];
$data = [];
foreach (['/short', '/short/interest', '/short/volume', '/short/fails', '/short/threshold', '/short/borrow', '/short/borrow/new'] as $u) {
    $r = page($jP, $u . '?security=' . $sec . '&from=2024-01-01&to=2024-04-04', $json);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !isset($b['data'])) { $bad[] = "$u {$r['code']}"; }
    $data[$u] = $b['data'] ?? [];
}
ok($bad === [], 'all seven slice 6 screens answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));
ok(($data['/short']['caveat'] ?? '') !== '' && ($data['/short/volume']['caveat'] ?? '') !== '' && count($data['/short/volume']['series'] ?? []) === 60, 'FINRA\'s caveat in the short side\'s and the volume\'s answers, sixty days of volume');
ok(count($data['/short']['points'] ?? []) === 60 && ($data['/short']['threshold']['consecutive_days'] ?? 0) === 6 && ($data['/short']['short_vs_float']['pct_of_float'] ?? 0) === 3.47, 'the overlay\'s sixty points, the threshold status, short as a share of the float');
ok(count($data['/short/interest']['series'] ?? []) === 5 && count($data['/short/fails']['series'] ?? []) === 20 && count($data['/short/threshold']['runs'] ?? []) === 1, 'the series: five settlements, twenty fails, one threshold run');

echo "2. Every handler under a person's action token\n";
$h = ['Accept: application/json', 'X-Action-Token: ' . person_token(26)];
$r = req('POST', '/short/borrow/save.php', ['headers' => $h, 'form' => ['security' => $sec, 'observed_on' => '2024-04-04', 'source' => 'manual', 'fee_rate_percent' => '12.5']]);
$b = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($b['ok'] ?? false) === true && isset($b['did'], $b['location']) && (int) ($b['record_id'] ?? 0) > 0, 'borrow_observation_create answers {ok, did, record_id, location}');
$r = req('POST', '/short/borrow/delete.php', ['headers' => $h, 'form' => ['observation' => (int) ($b['record_id'] ?? 0)]]);
$d = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($d['ok'] ?? false) === true && isset($d['did'], $d['location']) && array_key_exists('record_id', $d), 'borrow_observation_delete answers {ok, did, record_id, location}');
$r = req('POST', '/short/borrow/save.php', ['headers' => $h, 'form' => ['security' => $sec, 'observed_on' => 'yesterday']]);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid', 'a refusal: 422 as JSON');
finish();
