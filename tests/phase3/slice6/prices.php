<?php
/**
 * Proof: prices (short-side.md "Proof"; D13) — stooq paused by db/023 for its robots and its proof-of-work page; read, it fills sixty days, a
 * second read changes nothing; its robots.txt disallowing every agent → blocked with no request for the CSV; its proof-of-work page → blocked
 * as a bot wall; Alpha Vantage and Polygon with a key sealed under the installation's key (none: failed in words; the key never in a pull, a
 * log or an error); a price CSV uploaded through slice 2's pipeline (detected, upserted, untied when a line does not read).
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$sec = $sm['security'];

echo "1. stooq\n";
$src = q("SELECT paused_at, paused_reason FROM sources WHERE connector = 'prices_stooq'")[0];
ok($src['paused_at'] !== null && str_contains((string) $src['paused_reason'], 'robots.txt disallows every agent but Google') && str_contains((string) $src['paused_reason'], 'proof-of-work'), 'db/023 paused stooq with the reason found live');
ok((int) one("SELECT count(*) FROM sources WHERE connector IN ('prices_alphavantage', 'prices_polygon') AND paused_at IS NOT NULL AND paused_reason LIKE 'Needs a%key%'") === 2 && (int) one("SELECT count(*) FROM sources WHERE connector = 'prices_manual'") === 1, 'Alpha Vantage and Polygon seeded paused until a key; the upload\'s source seeded');
q("UPDATE sources SET paused_at = NULL, paused_reason = NULL WHERE connector = 'prices_stooq'");
stub_reset();
pull6('prices_stooq');
ok(last_pull('prices_stooq')['status'] === 'ok' && (int) one('SELECT count(*) FROM prices WHERE security_id = :s', ['s' => $sec]) === 60, 'read from the stub: sixty days');
ok((string) one("SELECT si_price_on(:s, '2024-04-06')", ['s' => $sec]) === (string) one("SELECT close FROM prices WHERE security_id = :s AND trade_date = '2024-04-04'", ['s' => $sec]), 'si_price_on takes the last close at or before a date');
$pl = activity('price.load');
ok(count($pl) === 1 && (int) json_decode($pl[0]['after'], true)['days'] === 60, 'price.load: sixty days');
pull6('prices_stooq');
ok((int) last_pull('prices_stooq')['listings_new'] === 0 && (int) last_pull('prices_stooq')['listings_changed'] === 0, 'a second read changes nothing');

echo "2. Blocked means blocked\n";
stub_reset(['robots' => "User-agent: Googlebot\nAllow: /\n\nUser-agent: *\nDisallow: /\n"]);
pull6('prices_stooq');
$p = last_pull('prices_stooq');
ok($p['status'] === 'blocked' && str_contains((string) $p['error'], 'robots.txt disallows') && array_filter(array_column(stub_requests(), 'path'), static fn ($x) => $x === '/q/d/l/') === [], 'stooq\'s robots disallowing every agent: blocked, the CSV never asked');
ok(one("SELECT backoff_until > now() + interval '50 minutes' FROM sources WHERE connector = 'prices_stooq'") === true, 'the source backs off an hour');
stub_reset(['variant' => ['stooq_wall']]);
source_due('prices_stooq');
pull6('prices_stooq');
$p = last_pull('prices_stooq');
ok($p['status'] === 'blocked' && str_contains((string) $p['error'], 'bot_wall:js_challenge'), 'its proof-of-work page: blocked as a bot wall (' . $p['error'] . ')');

echo "3. Alpha Vantage and Polygon, with a key\n";
q('DELETE FROM prices');
q("UPDATE sources SET paused_at = NULL, paused_reason = NULL WHERE connector = 'prices_alphavantage'");
stub_reset();
pull6('prices_alphavantage');
$p = last_pull('prices_alphavantage');
ok($p['status'] === 'failed' && $p['error'] === 'Alpha Vantage needs a key: the admin sets it on the source.' && stub_requests() === [], 'no key: failed in words before any request');
set_credential('prices_alphavantage', 'SMOKE-AV-KEY');
stub_reset();
source_due('prices_alphavantage');
pull6('prices_alphavantage');
$p = last_pull('prices_alphavantage');
$q = array_values(array_filter(stub_requests(), static fn ($r) => $r['path'] === '/query'));
ok($p['status'] === 'ok' && (int) one('SELECT count(*) FROM prices') === 60 && count($q) === 2, 'with the key: full refused as premium, compact read — sixty days (two requests)');
q('DELETE FROM prices');
set_credential('prices_polygon', 'SMOKE-PG-KEY');
stub_reset();
pull6('prices_polygon');
ok(last_pull('prices_polygon')['status'] === 'ok' && (int) one('SELECT count(*) FROM prices') === 60 && (string) one("SELECT close FROM prices WHERE trade_date = '2024-01-11'") === '3.9500', 'Polygon with its bearer key: sixty days, the bars dated in New York');
$leak = (int) one("SELECT count(*) FROM source_pulls WHERE policy::text LIKE '%SMOKE-%-KEY%' OR error LIKE '%SMOKE-%-KEY%'") + (int) one("SELECT count(*) FROM activity_log WHERE after::text LIKE '%SMOKE-%-KEY%' OR before::text LIKE '%SMOKE-%-KEY%'");
ok($leak === 0 && !str_contains((string) @file_get_contents(need('SI_DEV_STATE') . '/app.log'), 'SMOKE-AV-KEY'), 'neither key in a pull\'s policy or error, the trail or the server log');

echo "4. A price file uploaded\n";
q('DELETE FROM prices');
[$jP, ] = sign_on(26);
page($jP, '/?issuer=' . $sm['issuer']);
$csv = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/prices_stooq.csv');
$u = upload($jP, ['name' => 'smok-prices.csv', 'bytes' => $csv], ['kind' => '', 'security' => $sec]);
$imp = q('SELECT * FROM imports ORDER BY id DESC LIMIT 1')[0] ?? [];
ok($u['code'] === 200 && ($imp['connector'] ?? '') === 'prices_csv' && $imp['kind'] === 'prices_csv' && $imp['tied'] === true && (int) $imp['rows_made'] === 60, 'detected as a price file and read: tied, sixty bars (' . $u['code'] . ' ' . substr($u['body'], 0, 120) . ')');
ok((int) one('SELECT count(*) FROM prices p JOIN sources s ON s.id = p.source_id WHERE s.connector = \'prices_manual\'') === 60 && $imp['period_from'] === '2024-01-11' && $imp['record_date'] === '2024-04-04', 'sixty prices under the upload\'s source, Jan 11 to Apr 4');
$bad = "Date,Open,High,Low,Close,Volume\n2024-04-05,4.1,4.2,4.0,4.15,50000\nApril 8,4.1,4.2,4.0,x,1\n";
$u = upload($jP, ['name' => 'more.csv', 'bytes' => $bad], ['kind' => 'prices_csv', 'security' => $sec]);
$imp = q('SELECT * FROM imports ORDER BY id DESC LIMIT 1')[0] ?? [];
ok($imp['tied'] === false && str_contains((string) $imp['tie_note'], '1 line did not read as a bar') && (int) one('SELECT count(*) FROM prices') === 61, 'a line that does not read: untied in words, the good bar kept (' . $imp['tie_note'] . ')');
finish();
