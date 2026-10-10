<?php
/**
 * Proof: Broadridge's Share Range Analysis (an old Excel workbook; no person in it): the fixture of 2023-12-15 loads both populations, tied to each
 * sheet's TOTALS (962 accounts / 2,645,039 shares; 274 OBO / 1,095,203); its six-character CUSIP checked against the security's; the ranges
 * screen shows Broadridge's ranges with the OBO share of accounts beside the list's, to a Viewer in full; a second analysis of the date refused;
 * a PDF refused in words.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$ref = reference_issuer();
$fx = fixture_issuer();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $ref['issuer']); }
$json = ['headers' => ['Accept: application/json']];

echo "1. The workbook\n";
$r = upload_fixture($jP, 'share_range_20231215.xls', ['security' => $fx['security']]);
ok($r['code'] === 422 && str_contains($r['body'], 'CUSIP 09175A…') && str_contains($r['body'], '07831X206'), 'against another security: 422 — its six characters (09175A…) are not the security\'s');
$r = upload_fixture($jP, 'share_range_20231215.xls', ['security' => $ref['security']]);
$u = (int) ($r['json']['record_id'] ?? 0);
$im = q('SELECT * FROM imports WHERE id = :i', ['i' => $u])[0] ?? [];
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && ($im['kind'] ?? '') === 'share_range' && $im['connector'] === 'broadridge_share_range_xls' && $im['record_date'] === '2023-12-15',
    'detected as Broadridge\'s Share Range Analysis, its record date from its footer (2023-12-15), tied' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
$t = q('SELECT * FROM si_obo_totals(:s)', ['s' => $ref['security']])[0] ?? [];
ok((int) $t['all_accounts'] === 962 && (float) $t['all_shares'] === 2645039.0 && (int) $t['obo_accounts'] === 274 && (float) $t['obo_shares'] === 1095203.0 && (float) $t['obo_pct_of_accounts'] === 28.48,
    'the populations: 962 accounts and 2,645,039 shares; 274 OBO accounts (28.48%) with 1,095,203 shares');
ok(one('SELECT job_number FROM share_range_reports WHERE import_id = :i', ['i' => $u]) === 'N96192' && (int) one('SELECT count(*) FROM share_range_rows x JOIN share_range_reports r ON r.id = x.report_id WHERE r.import_id = :i', ['i' => $u]) === 46,
    'the job N96192 and 23 ranges a population');
ok(str_contains(page($jP, '/uploads/' . $u)['body'], 'id="upload-ranges-table"') && str_contains(page($jV, '/uploads/' . $u)['body'], 'id="upload-ranges-table"'), 'its upload page shows the ranges as read, to a Viewer too');

echo "2. The ranges screen\n";
$d = json_decode(page($jV, '/holders/ranges?security=' . $ref['security'], $json)['body'], true)['data'] ?? [];
$b = $d['broadridge'] ?? [];
ok(count($b) === 23 && array_sum(array_column($b, 'obo_accounts')) === 274 && array_sum(array_column($b, 'nobo_accounts')) === 688 && $d['record_date'] === '2023-12-15', 'a Viewer reads Broadridge\'s ranges: 274 OBO, 688 NOBO (all − OBO)');
$h = page($jV, '/holders/ranges?security=' . $ref['security'])['body'];
ok(str_contains($h, 'id="ranges-obo-share"') && str_contains($h, '274</span> of 962') && str_contains($h, '28.5%'), 'the OBO share of accounts in words: 274 of 962 (28.5%)');

echo "3. Refusals\n";
$r = upload_fixture($jP, 'share_range_20231215.xls', ['security' => $ref['security']]);
ok($r['code'] === 422 && str_contains($r['body'], 'already uploaded'), 'the same workbook again: 422');
$r = upload($jP, ['name' => 'OBO Share Range.pdf', 'bytes' => "%PDF-1.4\n%smoke\n"], ['security' => $ref['security'], 'kind' => '']);
ok($r['code'] === 422, 'a PDF: 422 (' . substr((string) ($r['json']['error']['message'] ?? $r['body']), 0, 80) . ')');
finish();
