<?php
/** Proof: JSON mode on every slice 4 screen ({data}) and handler ({ok, did, record_id, location}) under a person's action token; a refusal answers 422 as JSON. */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
$sm = smoke_issuer();
page($jP, '/?issuer=' . $sm['issuer']);
edgar_contact(true);
$json = ['headers' => ['Accept: application/json']];
pull('edgar_submissions');
pull('edgar_fulltext');
$f8k = filing_id('0000999999-24-000005');
$inst = (int) one("SELECT id FROM dilution_instruments ORDER BY id LIMIT 1");
$hit = (int) one("SELECT id FROM watch_hits WHERE term = 'equity purchase agreement'");

echo "1. Every screen answers JSON\n";
$bad = [];
foreach (['/filings', '/filings/' . $f8k . '?q=equity', '/filings/search?q=equity', '/filings/watch', '/dilution', '/dilution/' . $inst, '/dilution/new?hit=' . $hit, '/dilution/' . $inst . '/edit'] as $u) {
    $r = page($jP, $u, $json);
    $b = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !isset($b['data'])) { $bad[] = "$u {$r['code']}"; }
}
ok($bad === [], 'all eight slice 4 screens answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));

echo "2. Every handler under a person's action token\n";
$h = ['Accept: application/json', 'X-Action-Token: ' . person_token(26)];
$bad = [];
$check = static function (string $path, array $form) use ($h, &$bad): array {
    $r = req('POST', $path, ['headers' => $h, 'form' => $form]);
    $b = json_decode($r['body'], true) ?: [];
    if ($r['code'] !== 200 || ($b['ok'] ?? false) !== true || !isset($b['did'], $b['location']) || !array_key_exists('record_id', $b)) { $bad[] = "$path {$r['code']} " . substr($r['body'], 0, 120); }
    return $b;
};
$check('/filings/fetch-text.php', ['filing' => $f8k]);
$check('/filings/event-make.php', ['filing' => filing_id('0000999999-24-000003')]);
$check('/filings/watch/confirm.php', ['watch_hit' => $hit, 'kind' => 'eloc']);
$check('/filings/watch/dismiss.php', ['watch_hit' => (int) one("SELECT id FROM watch_hits WHERE status = 'new' ORDER BY id LIMIT 1")]);
$n = $check('/dilution/save.php', ['issuer' => $sm['issuer'], 'kind' => 'pipe', 'counterparty' => 'SMOKE PIPE FUND']);
$check('/dilution/delete.php', ['instrument' => (int) ($n['record_id'] ?? 0)]);
$check('/admin/sources/pull.php', ['source' => (int) one("SELECT id FROM sources WHERE connector = 'edgar_companyfacts'"), 'issuer' => $sm['issuer']]);
ok($bad === [], 'filing_fetch_text, filing_event_make, watch_hit_confirm, watch_hit_dismiss, dilution_save, dilution_delete, fetch_now answer {ok, did, record_id, location}' . ($bad ? ': ' . implode(' | ', $bad) : ''));
$r = req('POST', '/dilution/save.php', ['headers' => $h, 'form' => ['issuer' => $sm['issuer'], 'kind' => 'loan']]);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid', 'a refusal: 422 as JSON');
finish();
