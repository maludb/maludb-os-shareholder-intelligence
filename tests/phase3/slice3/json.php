<?php
/** Proof: JSON mode on every slice 3 screen ({data}) and handler ({ok, did, record_id, location}) under a person's action token; a refusal answers 422 as JSON. */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
$fx = fixture_issuer();
$sec = $fx['security'];
foreach ([$jP, $jM] as $j) { page($j, '/?issuer=' . $fx['issuer']); }
$json = ['headers' => ['Accept: application/json']];
$seed = seed_fixture_holders($jP, $jM, $sec);
$h1 = (int) one("SELECT id FROM holders WHERE kind = 'person' ORDER BY id LIMIT 1");
$h2 = (int) one("SELECT id FROM holders WHERE kind = 'person' ORDER BY id LIMIT 1 OFFSET 1");

echo "1. Every screen answers JSON\n";
$bad = [];
foreach (['/holders', '/holders/' . $h1, '/holders/' . $h1 . '/edit', '/holders/' . $h1 . '/merge?into=' . $h2, '/holders/unmatched', '/holders/changes', '/holders/ranges', '/holders/geography', '/holders/custodians', '/holders/register'] as $u) {
    $r = page($jM, $u, $json);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !isset($b['data'])) { $bad[] = "$u {$r['code']}"; }
}
ok($bad === [], 'all ten slice 3 screens answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));

echo "2. Every handler under a person's action token\n";
$hdr = static fn (int $m): array => ['Accept: application/json', 'X-Action-Token: ' . person_token($m)];
$bad = [];
$check = static function (string $path, array $form, int $m) use ($hdr, &$bad): array {
    $r = req('POST', $path, ['headers' => $hdr($m), 'form' => $form]);
    $b = json_decode($r['body'], true) ?: [];
    if ($r['code'] !== 200 || ($b['ok'] ?? false) !== true || !isset($b['did'], $b['location']) || !array_key_exists('record_id', $b)) { $bad[] = "$path {$r['code']} " . substr($r['body'], 0, 120); }
    return $b;
};
$prop = q("SELECT id, row_kind, row_id FROM holder_match_proposals WHERE status = 'proposed' ORDER BY id LIMIT 3");
$check('/holders/save.php', ['holder' => $h1, 'notes' => 'SMOKE json'], 26);
$check('/holders/kind.php', ['holder' => $h1, 'kind' => 'person'], 26);
$check('/holders/kind-propose.php', ['holder' => $h2, 'kind' => 'entity', 'evidence' => 'x'], 26);
if (count($prop) >= 3) {
    $check('/holders/matches/accept.php', ['proposal' => (int) $prop[0]['id']], 26);
    $check('/holders/matches/reject.php', ['proposal' => (int) $prop[1]['id']], 26);
    $check('/holders/matches/new-holder.php', ['row_kind' => $prop[2]['row_kind'], 'row' => (int) $prop[2]['row_id']], 26);
} else { $bad[] = 'fewer than three proposals to decide'; }
$check('/holders/matches/run.php', ['upload' => $seed['register']], 26);
$check('/holders/merge.php', ['survivor' => $h1, 'loser' => $h2], 27);
$h3 = (int) one("SELECT id FROM holders WHERE kind = 'entity' ORDER BY id LIMIT 1");
$check('/holders/delete.php', ['holder' => $h3], 27);
$check('/holders/restore.php', ['holder' => $h3], 27);
ok($bad === [], 'holder_update, holder_kind_set, holder_kind_propose, match_accept, match_reject, match_new_holder, matcher_run, holders_merge, holder_delete, holder_restore answer {ok, did, record_id, location}' . ($bad ? ': ' . implode(' | ', $bad) : ''));
$r = req('POST', '/holders/kind.php', ['headers' => $hdr(26), 'form' => ['holder' => $h1, 'kind' => 'robot']]);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid', 'a refusal (a kind that is not one): 422 as JSON');
finish();
