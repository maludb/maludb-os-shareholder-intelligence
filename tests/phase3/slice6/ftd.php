<?php
/**
 * Proof: the SEC's fails-to-deliver (short-side.md "Proof") — refused before any request without the SEC's contact; the fails page read for
 * its half-month zips, each zip checked against its own trailer and the tracked CUSIP's rows kept (the series the Phase 0 proof wrote: the
 * 95,000 spike over 0.5% of outstanding, the 60-day median); a second pull reading only the page; a file whose trailer disagrees refused in
 * words with nothing written; `ftd.load` per zip.
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$sec = $sm['security'];

echo "1. The SEC's rule: no contact, no request\n";
stub_reset();
pull6('sec_ftd');
$p = last_pull('sec_ftd');
ok($p['status'] === 'failed' && str_contains((string) $p['error'], 'contact email') && stub_requests() === [], 'without the contact email the pull fails before any request (the stub saw none)');

echo "2. The page, the zips, the trailer\n";
edgar_contact(true);
stub_reset();
pull6('sec_ftd');
$p = last_pull('sec_ftd');
$paths = array_column(stub_requests(), 'path');
$zips = array_values(array_filter($paths, static fn ($x) => str_ends_with($x, '.zip')));
ok($p['status'] === 'ok' && count($zips) === 3 && $zips[0] === '/files/data/fails-deliver-data/cnsfails202403a.zip', "ok: the three half-months the page lists, oldest first ({$p['status']}: " . implode(', ', array_map('basename', $zips)) . ')');
ok((int) one('SELECT count(*) FROM fails_to_deliver WHERE security_id = :s', ['s' => $sec]) === 20 && (int) one('SELECT count(*) FROM fails_to_deliver') === 20, 'twenty rows, all the tracked CUSIP\'s (another security\'s line in each file not kept)');
$f = q("SELECT * FROM si_fails_series(:s, '2024-03-01', '2024-04-05') ORDER BY quantity DESC LIMIT 1", ['s' => $sec])[0];
ok((float) $f['quantity'] === 95000.0 && $f['settlement_date'] === '2024-03-28' && (float) $f['pct_of_outstanding'] > 0.5 && (float) $f['median_60'] > 0 && (float) $f['quantity'] > 3 * (float) $f['median_60'],
   'the spike: 95,000 on Mar 28, ' . $f['pct_of_outstanding'] . '% of outstanding, over three times its 60-day median');
ok(q("SELECT file_half, price FROM fails_to_deliver WHERE security_id = :s AND settlement_date = '2024-03-07'", ['s' => $sec])[0] == ['file_half' => 'a', 'price' => '4.1000'], 'the half and the price kept');
$l = activity('ftd.load');
ok(count($l) === 3 && json_decode($l[0]['after'], true)['half'] === '202403a' && isset(json_decode($l[0]['after'], true)['rows']), 'ftd.load per zip, with its half and its rows');
$set = json_decode((string) one("SELECT settings FROM sources WHERE connector = 'sec_ftd'"), true);
ok(count($set['loaded'] ?? []) === 3, 'the zips read are remembered on the source');

echo "3. A second pull reads the page only\n";
stub_reset();
pull6('sec_ftd');
ok(array_column(stub_requests(), 'path') === ['/robots.txt', '/data-research/sec-markets-data/fails-deliver-data'] && last_pull('sec_ftd')['status'] === 'ok', 'robots and the page, no zip again');

echo "4. A file that does not tie to its trailer is refused\n";
source_settings('sec_ftd', ['loaded' => []]);
q('DELETE FROM fails_to_deliver');
stub_reset(['variant' => ['ftd_bad_trailer']]);
pull6('sec_ftd');
$p = last_pull('sec_ftd');
ok($p['status'] === 'failed' && preg_match('~cnsfails202403a\.zip\'s trailer says \d+ records, the file has \d+: not loaded~', (string) $p['error']) === 1, 'failed in words: "' . $p['error'] . '"');
ok((int) one('SELECT count(*) FROM fails_to_deliver') === 0, 'nothing written');
stub_reset();
source_due('sec_ftd');
pull6('sec_ftd');
ok(last_pull('sec_ftd')['status'] === 'ok' && (int) one('SELECT count(*) FROM fails_to_deliver') === 20, 'the corrected files read on the next pull');
finish();
