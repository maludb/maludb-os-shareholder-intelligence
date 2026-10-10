<?php
/**
 * Proof: the fetch layer's policy on slice 1's two connectors (edgar-and-finra-fetchers.md §1, §5) against the stub: no EDGAR request without
 * the contact (the pull refused before any request, the keeper told), the SEC's user-agent sent, robots honoured (a disallowed path never
 * asked), the rate kept, ETag answered 304 from the cache, a 403 backing the source off an hour and telling the keeper, a 5xx climbing the
 * ladder, the market-day calendar, the policy written into the pull, the pull logged, and a second pull changing nothing.
 */
require __DIR__ . '/lib.php';

echo "1. No EDGAR request without the EDGAR contact\n";
q("UPDATE sources SET last_ok_at = now() WHERE connector = 'dtcc_participants'");          // the directory waits for section 6
edgar_contact(false);
stub_reset();
q("INSERT INTO issuers (name, cik, ticker) VALUES ('SMOKE ISSUER INC', '0000999999', 'SMOK')");
$iid = (int) one("SELECT id FROM issuers WHERE cik = '0000999999'");
source_due('edgar_submissions');
$w = worker('fetch', '2026-10-07T14:00:00-04:00');
$pull = q("SELECT p.* FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE s.connector = 'edgar_submissions' ORDER BY p.id DESC LIMIT 1")[0] ?? [];
ok($w['rc'] === 0 && ($pull['status'] ?? '') === 'failed' && str_contains((string) $pull['error'], 'contact email'), 'the scheduled EDGAR pull is refused in words: ' . ($pull['error'] ?? '(none)'));
ok(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/submissions/')) === [] && json_decode((string) $pull['policy'], true)['refused_before_request'] === true, '…before any request reached EDGAR');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 1 AND kind = 'source_failed' AND title ILIKE '%contact email%'") === 1, 'the keeper of the sources (the owner) is told once');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'source.pull_fail'") >= 1, 'source.pull_fail logged');

echo "2. With the contact: the SEC's user-agent, the entity, the policy written, a second pull unchanged\n";
edgar_contact(true);
stub_reset();
source_due('edgar_submissions');
$w = worker('fetch', '2026-10-07T14:00:00-04:00');
$reqs = array_values(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/submissions/')));
ok(count($reqs) === 2 && array_unique(array_column($reqs, 'ua')) === ['Kinetic Seas claude@kineticseas.com'] && array_unique(array_column($reqs, 'status')) === [200],
    'the submissions document and the older page it names (slice 4\'s index), sent as "Kinetic Seas claude@kineticseas.com"');
$i = q('SELECT * FROM issuers WHERE id = :i', ['i' => $iid])[0];
$ent = json_decode((string) $i['edgar_entity'], true);
ok($ent['name'] === 'SMOKE ISSUER INC' && $ent['formerNames'][0]['name'] === 'SMOKE ACQUISITION CORP' && $i['edgar_synced_at'] !== null, 'the entity kept: the name, the former name, the time');
ok($i['state_of_incorporation'] === 'NV' && $i['fiscal_year_end'] === '1231' && $i['sic'] === '3990' && $i['ein'] === null, 'the blanks filled from EDGAR (NV, 1231, 3990) — never an EIN of zeros');
$pull = q("SELECT p.* FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE s.connector = 'edgar_submissions' ORDER BY p.id DESC LIMIT 1")[0];
$pol = json_decode((string) $pull['policy'], true);
ok($pull['status'] === 'ok' && (int) $pull['listings_seen'] === 1 && (int) $pull['http_requests'] >= 1 && $pol['user_agent'] === 'Kinetic Seas claude@kineticseas.com' && (float) $pol['rate_per_second'] === 10.0,
    'the pull: ok, one issuer seen, the user-agent and the rate written into its policy');
q("UPDATE issuers SET sic = '1234' WHERE id = :i", ['i' => $iid]);
stub_reset();
source_due('edgar_submissions');
worker('fetch', '2026-10-07T15:00:00-04:00');
$reqs = array_values(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/submissions/')));
ok(($reqs[0]['status'] ?? 0) === 304 && one('SELECT sic FROM issuers WHERE id = :i', ['i' => $iid]) === '1234', 'the second pull: 304 from the cache (ETag), and a value a person wrote is never overwritten');

echo "3. The calendar: EDGAR's index on market days only\n";
stub_reset();
source_due('edgar_submissions');
$w = worker('fetch', '2026-10-10T14:00:00-04:00');
ok(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/submissions/')) === [] && !str_contains($w['out'], 'edgar_submissions'), 'on a Saturday the scheduled EDGAR pull does not run');
$w = worker('fetch', '2026-10-07T04:00:00-04:00');
ok(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/submissions/')) === [], '…nor at 04:00 market time on a weekday');

echo "4. Refusals: a 403 backs off an hour and tells the keeper; a 5xx climbs the ladder\n";
stub_reset(['force' => ['/submissions/CIK0000999999.json' => 403]]);
source_due('edgar_submissions');
worker('fetch', '2026-10-07T14:00:00-04:00');
$s = q("SELECT * FROM sources WHERE connector = 'edgar_submissions'")[0];
$mins = (strtotime((string) $s['backoff_until']) - time()) / 60;
ok(one("SELECT status FROM source_pulls WHERE source_id = :s ORDER BY id DESC LIMIT 1", ['s' => $s['id']]) === 'blocked' && $mins > 55 && $mins <= 61, sprintf('a 403 makes the pull blocked and backs the source off an hour (%.0f minutes)', $mins));
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 1 AND kind = 'source_blocked'") === 1 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'source.pull_blocked'") >= 1, 'the keeper is told; source.pull_blocked logged');
$w = worker('fetch', '2026-10-07T14:05:00-04:00');
ok(count(array_filter(stub_requests(), static fn ($r) => str_starts_with($r['path'], '/submissions/'))) === 1, 'a backed-off source is not asked again inside its hour');
stub_reset(['force' => ['/submissions/CIK0000999999.json' => 503]]);
source_due('edgar_submissions');
worker('fetch', '2026-10-07T14:00:00-04:00');
$s = q("SELECT * FROM sources WHERE connector = 'edgar_submissions'")[0];
$mins = (strtotime((string) $s['backoff_until']) - time()) / 60;
ok(one("SELECT status FROM source_pulls WHERE source_id = :s ORDER BY id DESC LIMIT 1", ['s' => $s['id']]) === 'failed' && (int) $s['consecutive_failures'] === 1 && $mins > 3 && $mins <= 6, sprintf('a 503 fails the pull: the first rung of the ladder, %.0f minutes', $mins));
q("UPDATE sources SET backoff_until = NULL, last_ok_at = NULL WHERE connector = 'edgar_submissions'");
worker('fetch', '2026-10-07T14:00:00-04:00');
$s = q("SELECT * FROM sources WHERE connector = 'edgar_submissions'")[0];
$mins = (strtotime((string) $s['backoff_until']) - time()) / 60;
ok((int) $s['consecutive_failures'] === 2 && $mins > 13 && $mins <= 16, sprintf('the second failure: 15 minutes (%.0f)', $mins));
stub_reset();
source_due('edgar_submissions');

echo "5. robots.txt and the rate, in the client\n";
$r = app_php('$c = si_http_client(["user_agent" => "Kinetic Seas claude@kineticseas.com", "rate_per_second" => 2]); $a = $c->get("' . STUB . '/private/secret"); $t0 = microtime(true); $c->get("' . STUB . '/files/company_tickers_exchange.json"); $c->get("' . STUB . '/files/company_tickers_exchange.json?x=1"); $c->get("' . STUB . '/files/company_tickers_exchange.json?x=2"); echo json_encode(["skipped" => $a["skipped"], "reason" => $a["reason"], "elapsed" => microtime(true) - $t0]);');
ok(is_array($r) && $r['skipped'] === true && $r['reason'] === 'robots_disallow' && array_filter(stub_requests(), static fn ($q) => str_starts_with($q['path'], '/private/')) === [], 'a path robots.txt disallows is never asked');
ok(is_array($r) && $r['elapsed'] >= 0.95, sprintf('three requests at two a second take at least a second (%.2f s)', is_array($r) ? $r['elapsed'] : -1));
$r = app_php('$c = si_http_client(["user_agent" => "a script", "rate_per_second" => 10]); $a = $c->get("' . STUB . '/files/company_tickers_exchange.json"); echo json_encode(["blocked" => $a["blocked"], "status" => $a["status"]]);');
ok(is_array($r) && $r['blocked'] === true && $r['status'] === 403, 'a user-agent without a contact is refused by the SEC (the stub plays it): blocked');

echo "6. The participant directory\n";
stub_reset();
source_due('dtcc_participants');
$w = worker('fetch', '2026-10-10T11:00:00-04:00');
$pull = q("SELECT p.* FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE s.connector = 'dtcc_participants' ORDER BY p.id DESC LIMIT 1")[0];
ok($pull['status'] === 'ok' && (int) $pull['listings_seen'] === 5 && (int) $pull['listings_new'] === 2 && (int) $pull['listings_changed'] === 1, 'the directory on a Saturday (any day): five rows, two new, one renamed');
$paths = array_column(stub_requests(), 'path');
ok(in_array('/robots.txt', $paths, true) && in_array('/support/dtc-directories', $paths, true) && count(array_filter($paths, static fn ($p) => str_contains($p, 'Alphabetical'))) === 1 && count(array_filter($paths, static fn ($p) => str_contains($p, 'Pledgees'))) === 0,
    'it read robots.txt, the page and the alphabetical listing — never the pledgees');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'participant.directory_sync'") === 1, 'participant.directory_sync logged with the counts');
$a = json_decode((string) one("SELECT after FROM activity_log WHERE action = 'participant.directory_sync'"), true);
ok($a['inserted'] === 2 && $a['renamed'] === 1 && $a['unchanged'] === 2 && $a['file_date'] === '2026-09-30', 'inserted 2, renamed 1, unchanged 2, the file dated 2026-09-30 from its name');
stub_reset();
source_due('dtcc_participants');
worker('fetch', '2026-10-10T11:00:00-04:00');
$pull = q("SELECT p.* FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE s.connector = 'dtcc_participants' ORDER BY p.id DESC LIMIT 1")[0];
ok($pull['status'] === 'ok' && (int) $pull['listings_new'] === 0 && (int) $pull['listings_changed'] === 0 && json_decode((string) $pull['policy'], true)['cached'] >= 1, 'a second pull: 304s from the cache, nothing new, nothing renamed');
finish();
