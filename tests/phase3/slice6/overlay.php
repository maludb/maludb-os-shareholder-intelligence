<?php
/**
 * Proof: the short-side screens (short-side.md "Screens", "Proof") — the one chart renders every panel (the close, the volume, short interest
 * on its settlement dates, the fails, the 5-day ratio, the threshold bands, the borrow, an event across both) with its legend, its source lines
 * and FINRA's caveat; the tiles with their dates; the five tables; a borrow observation recorded through the form's checks and shown
 * "indicative", then removed; a Viewer reads everything and records nothing; the issuer wall.
 */
require __DIR__ . '/lib.php';
$sm = seed_short_all();
$sec = $sm['security'];
q("INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity) VALUES (:i, :s, '2024-03-28', 'activist_short_report', 'SMOKE: a short report', 'high')", ['i' => $sm['issuer'], 's' => $sec]);
$ev = (int) one("SELECT id FROM events WHERE title = 'SMOKE: a short report'");
[$jP, ] = sign_on(26);
page($jP, '/?issuer=' . $sm['issuer']);

echo "1. A borrow observation, through the form's checks\n";
$base = ['security' => $sec, 'source' => 'broker_quote'];
$r = post_json($jP, '/short/borrow/save.php', $base + ['observed_on' => '2099-01-01', 'fee_rate_percent' => '35.5']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['observed_on']), 'a date in the future refused on its field');
$r = post_json($jP, '/short/borrow/save.php', $base + ['observed_on' => '2024-04-04']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['fee_rate_percent']), 'no figure at all refused');
$r = post_json($jP, '/short/borrow/save.php', ['security' => $sec, 'source' => 'vendor', 'observed_on' => '2024-04-04', 'fee_rate_percent' => '35.5']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['source']), 'a person may not record a vendor\'s row');
$r = post_json($jP, '/short/borrow/save.php', $base + ['observed_on' => '2024-04-04', 'fee_rate_percent' => '35.5', 'utilization_percent' => '92', 'note' => 'SMOKE: indicative from the prime\'s desk']);
$bid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $bid > 0 && (float) one('SELECT fee_rate_percent FROM borrow_observations WHERE id = :i', ['i' => $bid]) === 35.5, 'recorded: 35.5% at 92% utilisation');
$l = activity('borrow.record');
ok(count($l) === 1 && !str_contains($l[0]['after'], 'SMOKE: indicative') && (float) json_decode($l[0]['after'], true)['fee_rate_percent'] === 35.5, 'borrow.record with the figures, never the note\'s words');

echo "2. The one chart\n";
$h = page($jP, '/short?security=' . $sec . '&from=2024-01-01&to=2024-04-04')['body'];
foreach (['short-chart', 'short-chart-svg', 'short-chart-price', 'short-chart-volume', 'short-chart-si', 'short-chart-fails', 'short-chart-ratio', 'short-chart-threshold', 'short-chart-borrow', 'short-chart-events', 'short-chart-legend', 'short-caveat', 'short-chart-table'] as $id) {
    if (!str_contains($h, 'id="' . $id . '"')) { ok(false, "the panel #$id is on the page"); }
}
ok(true, 'every panel, the legend, the caveat and the table twin are on the page');
$grp = static function (string $id) use ($h): string { return preg_match('~<g id="' . $id . '">(.*?)</g>~s', $h, $m) ? $m[1] : ''; };
ok(substr_count($grp('short-chart-si'), '<circle') === 5, 'short interest: five points, one a settlement date');
ok(substr_count($grp('short-chart-fails'), '<rect') === 20 && substr_count($grp('short-chart-threshold'), '<rect') === 6, 'twenty fail bars, six threshold bands');
ok(substr_count($grp('short-chart-borrow'), '<path') === 1 && str_contains($h, 'id="short-chart-event-' . $ev . '"'), 'the borrow diamond and the short report across both panels');
ok(str_contains($h, 'Short interest, % of float (points; highest 3.47%)') && str_contains($h, 'Fails to deliver (bars; tallest 95,000)') && str_contains($h, 'Borrow fee (diamonds; highest 35.50%)'), 'the legend names each scale');
ok(substr_count($h, 'si-source-line') >= 6 && str_contains($h, 'Source: FINRA short interest, settlement · Mar 28, 2024') && str_contains($h, 'Source: the SEC&#039;s fails-to-deliver, settlement · Apr 4, 2024'), 'a source line with its date under the chart for each series');
ok(str_contains($h, 'Not comparable with short interest'), 'FINRA\'s caveat under the chart');

echo "3. The tiles\n";
$tile = static fn (string $id): string => preg_match('~id="short-tile-' . $id . '-value">(.*?)</div>~s', $h, $m) ? strip_tags($m[1]) : '';
ok($tile('si') === '3.47%' && $tile('dtc') === '11.06', 'short 3.47% of the float, 11.06 days to cover');
ok($tile('threshold') === '6 days' && str_contains($h, 'On the Nasdaq list since Mar 27, 2024'), 'six list days, on the Nasdaq list since Mar 27');
ok($tile('fails') === '4,909' && $tile('borrow') === '35.50%' && str_contains($h, 'text-bg-light">indicative'), 'the latest fails 4,909; the borrow 35.50%, indicative');
ok(preg_match('~^\d+\.\d%$~', $tile('ratio')) === 1, 'the 5-day ratio as a percent (' . $tile('ratio') . ')');

echo "4. The tables\n";
$s = page($jP, '/short/interest?security=' . $sec . '&from=2024-01-01&to=2024-04-30')['body'];
ok(substr_count($s, 'id="si-row-') === 5 && str_contains($s, '3.47%') && str_contains($s, '11.06'), 'short interest: five rows with the share of the float');
$v = page($jP, '/short/volume?security=' . $sec . '&from=2024-01-01&to=2024-04-04')['body'];
ok(str_contains($v, 'alert alert-secondary') && str_contains($v, 'id="short-caveat"') && str_contains($v, 'is not short interest') && substr_count($v, 'id="volume-row-') === 60 && str_contains($v, 'id="volume-chart"'), 'short volume: the caveat in a callout at the top, the line, sixty rows');
ok(str_contains(page($jP, '/short/volume?security=' . $sec . '&market=FORF&from=2024-01-01&to=2024-04-04')['body'], 'id="volume-table-empty"'), 'the OTC file\'s view: none of ours');
$f = page($jP, '/short/fails?security=' . $sec . '&from=2024-01-01&to=2024-04-04')['body'];
ok(substr_count($f, 'id="fails-row-') === 20 && str_contains($f, 'id="fails-chart"') && str_contains($f, 'id="fails-median"') && str_contains($f, 'fw-semibold text-danger">95,000'), 'fails: twenty rows, the bars, the median line, the spike marked');
$t = page($jP, '/short/threshold?security=' . $sec)['body'];
ok(str_contains($t, 'id="threshold-run-0"') && str_contains($t, 'badge text-bg-danger">now') && str_contains($t, 'Nasdaq: 10 days read'), 'threshold: the current run and the days read per list');
$b = page($jP, '/short/borrow?security=' . $sec)['body'];
ok(str_contains($b, 'id="borrow-row-' . $bid . '"') && str_contains($b, 'text-bg-light">indicative') && str_contains($b, 'SMOKE: indicative from the prime&#039;s desk'), 'borrow: the observation, indicative, with its note');

echo "5. A Viewer reads everything and records nothing\n";
[$jV, ] = sign_on(29);
page($jV, '/?issuer=' . $sm['issuer']);
$bad = [];
foreach (['/short', '/short/interest', '/short/volume', '/short/fails', '/short/threshold', '/short/borrow'] as $u) {
    if (page($jV, $u . '?security=' . $sec)['code'] !== 200) { $bad[] = $u; }
}
ok($bad === [], 'the six screens answer a Viewer' . ($bad ? ': ' . implode(', ', $bad) : ''));
ok(!str_contains(page($jV, '/short?security=' . $sec)['body'], 'id="short-borrow-add-btn"') && !str_contains(page($jV, '/short/borrow?security=' . $sec)['body'], 'id="borrow-delete-'), 'no Record or remove button for a Viewer');
ok(page($jV, '/short/borrow/new?security=' . $sec)['code'] === 403 && post_json($jV, '/short/borrow/save.php', $base + ['observed_on' => '2024-04-04', 'fee_rate_percent' => '20'])['code'] === 403
   && post_json($jV, '/short/borrow/delete.php', ['observation' => $bid])['code'] === 403, 'the form, the record and the removal refused (403)');

echo "6. The removal, and the wall\n";
$r = post_json($jP, '/short/borrow/delete.php', ['observation' => $bid]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM borrow_observations') === 0 && count(activity('borrow.delete')) === 1, 'removed; borrow.delete logged');
q("INSERT INTO issuers (name, ticker, exchange) VALUES ('SMOKE WALLED INC', 'WALL', 'nasdaq')");
$wi = (int) one("SELECT id FROM issuers WHERE ticker = 'WALL'");
q("INSERT INTO securities (issuer_id, name, ticker, is_primary) VALUES (:i, 'Common Stock', 'WALL', true)", ['i' => $wi]);
$ws = (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $wi]);
q("INSERT INTO issuer_members (issuer_id, member_id) VALUES (:i, 27)", ['i' => $wi]);
q("INSERT INTO prices (security_id, trade_date, close) VALUES (:s, '2024-04-04', 99.99)", ['s' => $ws]);
$j = json_decode(page($jP, '/short?security=' . $ws . '&from=2024-04-01&to=2024-04-04', ['headers' => ['Accept: application/json']])['body'], true);
ok(($j['data']['security_id'] ?? 0) === $sec && !str_contains(json_encode($j), '99.99'), 'a restricted issuer\'s security asked for by id: the Analyst gets their own issuer\'s page, nothing of the other');
ok(post_json($jP, '/short/borrow/save.php', ['security' => $ws, 'observed_on' => '2024-04-04', 'fee_rate_percent' => '5'])['code'] === 404, '…and cannot record a borrow on it (404)');
finish();
