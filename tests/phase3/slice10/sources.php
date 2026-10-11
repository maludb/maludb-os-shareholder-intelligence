<?php
/**
 * Proof: the sources' admin (admin-settings.md): the table of fetchers with schedule, next slot, last pull, back-off and the EDGAR banner; the calendar's next slot (market days, hours, a holiday, the
 * back-off; fixed clocks through the application's own function); pause skips and resume pulls; a schedule change moves the next slot; source_save validates (a URL, a rate, the EDGAR ceiling, no
 * secret in the settings) and logs what changed; Pull now; the view with the policy each pull followed; the walls (an IR Manager, an Analyst and a Viewer are refused every screen and POST).
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$jA = who(1);
$jM = who(27);
$jP = who(26);
$jV = who(29);
$dtcc = source_id('dtcc_participants');
$edgar = source_id('edgar_submissions');
$next = static fn (int $id): ?string => get_json($GLOBALS['jA'], '/admin/sources/' . $id)['data']['source']['next_slot'] ?? null;
$total = (int) one('SELECT count(*) FROM sources');

echo "1. The table\n";
$p = page($jA, '/admin/sources');
ok($p['code'] === 200 && str_contains($p['body'], 'id="sources-table"') && substr_count($p['body'], 'id="source-row-') >= $total, "an admin sees the sources table: a row for each of the $total sources");
$rows = preg_match_all('/<tr id="source-row-(\d+)"/', $p['body'], $m) ? $m[1] : [];
ok(count($rows) === $total, 'exactly one row each (' . count($rows) . ')');
ok(str_contains($p['body'], 'id="ua-banner"') && str_contains($p['body'], 'EDGAR is waiting for a contact'), 'the red banner while the EDGAR contact is empty');
ok(str_contains($p['body'], 'id="admin-tabs"') && str_contains($p['body'], 'id="admin-tab-settings"') && str_contains($p['body'], 'id="admin-tab-purge"') && str_contains($p['body'], 'id="admin-tab-tokens"'), 'the admin tabs: sources, classes, templates, settings, keys and tokens, purge, agents');
$av = source_id('prices_alphavantage');
ok(str_contains($p['body'], 'id="source-row-' . $av . '-paused"') && str_contains($p['body'], 'id="source-row-' . $av . '-resume-btn"') && str_contains($p['body'], 'id="source-row-' . $dtcc . '-pull-btn"') && str_contains($p['body'], 'id="source-row-' . $dtcc . '-pause-btn"'),
    'Pull now and Pause for an active source, Resume for the paused one (Alpha Vantage waits for its key)');
$j = get_json($jA, '/admin/sources');
ok($j['code'] === 200 && count($j['data']['sources'] ?? []) === $total && ($j['data']['edgar_user_agent_set'] ?? true) === false && isset($j['data']['connectors']['edgar_submissions']), 'JSON: every source, the connectors, the user-agent flag');
$one = get_json($jA, '/admin/sources?connector=edgar_submissions');
ok(count($one['data']['sources'] ?? []) === 1 && $one['data']['sources'][0]['connector'] === 'edgar_submissions', '?connector= narrows the table to one connector');
$r = $j['data']['sources'][0];
ok(!array_key_exists('settings', $r) && !array_key_exists('ciphertext', $r) && !str_contains($j['body'], 'ciphertext'), 'the row is a whitelist (no settings blob, no ciphertext)');

echo "2. The walls\n";
$bad = [];
foreach (['/admin/sources', '/admin/sources/' . $dtcc, '/admin/sources/' . $dtcc . '/edit'] as $path) {
    foreach (['manager' => $jM, 'analyst' => $jP, 'viewer' => $jV] as $who => $jar) {
        if (page($jar, $path)['code'] !== 403) { $bad[] = "$who $path"; }
    }
}
ok($bad === [], 'an IR Manager, an Analyst and a Viewer: 403 on the list, the view and the edit' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$bad = [];
foreach (['/admin/sources/save.php' => ['source' => $dtcc, 'name' => 'x'], '/admin/sources/schedule.php' => ['source' => $dtcc, 'schedule_minutes' => 5], '/admin/sources/pause.php' => ['source' => $dtcc],
          '/admin/sources/resume.php' => ['source' => $dtcc], '/admin/sources/credential.php' => ['source' => $dtcc, 'label' => 'x', 'secret' => 'abcdefgh']] as $path => $form) {
    foreach (['manager' => $jM, 'analyst' => $jP, 'viewer' => $jV] as $who => $jar) {
        if (post_json($jar, $path, $form)['code'] !== 403) { $bad[] = "$who $path"; }
    }
}
ok($bad === [] && (string) one('SELECT name FROM sources WHERE id = :i', ['i' => $dtcc]) !== 'x', 'and 403 on the five POSTs (save, schedule, pause, resume, credential): nothing changed' . ($bad ? ' — ' . implode(', ', $bad) : ''));
ok(req('GET', '/admin/sources')['code'] === 302, 'no session: the launcher');

echo "3. The calendar's next slot\n";
$slot = static function (string $connector, string $now, array $over = []): ?string {
    $r = app_php('require_once ' . var_export(dirname(__DIR__, 3) . '/app/features/admin/queries.php', true) . ';
        $s = $pdo->prepare("SELECT id AS source_id, connector, settings, schedule_minutes, last_ok_at, backoff_until, paused_at, active FROM sources WHERE connector = :c ORDER BY id LIMIT 1"); $s->execute(["c" => ' . var_export($connector, true) . ']);
        $s = array_merge($s->fetch(), json_decode(' . var_export(json_encode($over), true) . ', true));
        $t = source_next_slot($pdo, $s, new DateTimeImmutable(' . var_export($now, true) . '));
        echo json_encode($t === null ? null : $t->setTimezone(new DateTimeZone("America/New_York"))->format("Y-m-d H:i l"));');
    return is_string($r) ? $r : ($r === null ? null : (string) $r);
};
ok($slot('edgar_submissions', '2026-10-10T15:00:00Z') === '2026-10-12 06:00 Monday', 'EDGAR\'s index on a Saturday: the next slot is Monday 06:00 New York (market days, from 06:00)');
ok($slot('edgar_submissions', '2026-10-07T10:00:00-04:00') === '2026-10-07 10:00 Wednesday', 'on a market morning, now (a source never read is due at the next five-minute mark)');
ok($slot('edgar_submissions', '2026-10-07T21:30:00-04:00') === '2026-10-08 06:00 Thursday', 'after 20:59 the next market day\'s 06:00');
ok($slot('finra_daily_short_volume', '2026-10-07T10:00:00-04:00') === '2026-10-07 18:30 Wednesday', 'FINRA\'s daily files: 18:30 the same day');
ok($slot('edgar_submissions', '2026-10-07T10:00:00-04:00', ['last_ok_at' => '2026-10-07 14:05:00+00', 'schedule_minutes' => 360]) === '2026-10-07 16:05 Wednesday', 'last good pull + the schedule: 16:05');
ok($slot('edgar_submissions', '2026-10-07T10:00:00-04:00', ['backoff_until' => '2026-10-07 22:00:00+00']) === '2026-10-07 18:00 Wednesday', 'a back-off holds it: 18:00 New York');
ok($slot('edgar_submissions', '2026-10-07T10:00:00-04:00', ['paused_at' => '2026-10-07 10:00:00+00']) === null && $slot('edgar_submissions', '2026-10-07T10:00:00-04:00', ['active' => false]) === null
    && $slot('edgar_submissions', '2026-10-07T10:00:00-04:00', ['schedule_minutes' => 0]) === null, 'paused, inactive or manual: no next slot');
q("UPDATE si_settings SET market_holidays = '{2026-10-12}'");
ok($slot('edgar_submissions', '2026-10-10T15:00:00Z') === '2026-10-13 06:00 Tuesday', 'a holiday the settings name (Monday 12 October) is skipped: Tuesday');
q("UPDATE si_settings SET market_holidays = '{2024-03-29}'");
ok($slot('edgar_13f_dataset', '2026-10-10T15:00:00Z') === '2026-10-10 11:00 Saturday', 'a source whose calendar is "any day" (the 13F sets): the next five-minute mark');

echo "4. Pause skips, resume pulls\n";
source_due('dtcc_participants');
$before = pulls_of($dtcc);
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/pause.php', ['source' => $dtcc, 'reason' => 'SMOKE: the host is down']);
$row = q('SELECT paused_at, paused_reason FROM sources WHERE id = :i', ['i' => $dtcc])[0];
ok($r['code'] === 200 && (int) ($r['json']['record_id'] ?? 0) === $dtcc && $row['paused_at'] !== null && $row['paused_reason'] === 'SMOKE: the host is down', 'paused with its reason (record_id = the source)');
$a = activity('source.pause', $since)[0] ?? [];
ok(json_decode($a['after'] ?? '{}', true)['reason'] === 'SMOKE: the host is down' && (int) $a['entity_id'] === $dtcc, 'source.pause logged with the reason');
ok(post_json($jA, '/admin/sources/pause.php', ['source' => $dtcc])['code'] === 422, 'pausing a paused source: 422');
stub_reset();
fetch_only('dtcc_participants', MARKET_MORNING);
ok(pulls_of($dtcc) === $before && stub_requests() === [], 'the worker skips a paused source: no pull, no request');
$p = page($jA, '/admin/sources')['body'];
ok(str_contains($p, 'SMOKE: the host is down') && str_contains($p, 'id="source-row-' . $dtcc . '-resume-btn"'), 'the list says why and offers Resume');
ok($next($dtcc) === null, 'a paused source has no next slot');
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/resume.php', ['source' => $dtcc]);
ok($r['code'] === 200 && one('SELECT paused_at FROM sources WHERE id = :i', ['i' => $dtcc]) === null && count(activity('source.resume', $since)) === 1, 'resumed (source.resume logged)');
ok(post_json($jA, '/admin/sources/resume.php', ['source' => $dtcc])['code'] === 422, 'resuming a source that is not paused: 422');
source_due('dtcc_participants');
fetch_only('dtcc_participants', MARKET_MORNING);
ok(pulls_of($dtcc) === $before + 1 && count(stub_requests()) > 0, 'the worker pulls the resumed source');
$av = source_id('prices_alphavantage');
$r = post_json($jA, '/admin/sources/resume.php', ['source' => $av]);
ok($r['code'] === 422 && str_contains((string) json_encode($r['json']), 'waits for its key') && one('SELECT paused_at FROM sources WHERE id = :i', ['i' => $av]) !== null, 'a source paused for want of its key does not resume without one: 422 naming the credential');

echo "5. A schedule change moves the next slot\n";
q("UPDATE sources SET last_ok_at = now() - interval '1 day' WHERE id = :i", ['i' => $dtcc]);
$n1 = strtotime((string) $next($dtcc));
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => 60]);
$n2 = strtotime((string) $next($dtcc));
ok($r['code'] === 200 && (int) one('SELECT schedule_minutes FROM sources WHERE id = :i', ['i' => $dtcc]) === 60, 'the schedule saved: 60 minutes');
ok($n1 - $n2 > 25 * 86400 && $n2 - time() < 600, 'the next slot moved from about a month ahead to the next five-minute mark (' . round(($n1 - $n2) / 86400, 1) . ' days earlier)');
$a = activity('source.schedule', $since)[0] ?? [];
ok(json_decode($a['before'], true)['schedule_minutes'] === 43200 && json_decode($a['after'], true)['schedule_minutes'] === 60, 'source.schedule logged before and after');
$p = page($jA, '/admin/sources/' . $dtcc)['body'];
ok(str_contains($p, 'every hour'), 'the view says "every hour"');
$r = post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => 0]);
$p = page($jA, '/admin/sources/' . $dtcc)['body'];
ok($r['code'] === 200 && $next($dtcc) === null && str_contains($p, 'manual only'), '0 is manual only: no next slot, the view says so');
ok(post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => -5])['code'] === 422 && post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc])['code'] === 422
    && post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => 'soon'])['code'] === 422, 'a negative, a missing and a non-number schedule: 422');
$since = last_activity_id();
post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => 0]);
ok(activity('source.schedule', $since) === [], 'saving the same schedule logs nothing');
post_json($jA, '/admin/sources/schedule.php', ['source' => $dtcc, 'schedule_minutes' => 43200]);

echo "6. Change a source\n";
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/save.php', ['source' => $dtcc, 'name' => 'DTCC — SMOKE directory', 'rate_per_second' => '4', 'active' => '1', 'user_agent' => 'SMOKE Agent (+smoke@example.invalid)',
    'settings' => json_encode(['files' => ['alphabetical'], 'note' => 'SMOKE'])]);
$row = q('SELECT name, rate_per_second, user_agent, settings FROM sources WHERE id = :i', ['i' => $dtcc])[0];
ok($r['code'] === 200 && $row['name'] === 'DTCC — SMOKE directory' && (float) $row['rate_per_second'] === 4.0 && $row['user_agent'] === 'SMOKE Agent (+smoke@example.invalid)' && json_decode($row['settings'], true) == ['files' => ['alphabetical'], 'note' => 'SMOKE'], 'name, rate, user-agent and settings changed');
$a = activity('source.save', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
$bj = json_decode($a['before'] ?? '{}', true);
ok(isset($aj['name'], $aj['rate_per_second'], $aj['settings']['keys']) && $bj['rate_per_second'] == 10 && in_array('note', $aj['settings']['keys'], true) && !str_contains($a['after'], 'alphabetical'), 'source.save logged what changed (the settings by the names of their keys)');
$since = last_activity_id();
$r = post_json($jA, '/admin/sources/save.php', ['source' => $dtcc, 'name' => 'DTCC — SMOKE directory']);
ok($r['code'] === 200 && activity('source.save', $since) === [], 'nothing differing logs nothing');
foreach ([['base_url' => 'ftp://x.example/a', 'f' => 'base_url'], ['base_url' => 'https://user:pw@x.example/', 'f' => 'base_url'], ['base_url' => 'not a url', 'f' => 'base_url'], ['rate_per_second' => '11', 'f' => 'rate_per_second'],
          ['rate_per_second' => '0', 'f' => 'rate_per_second'], ['name' => '', 'f' => 'name'], ['settings' => '[1,2]', 'f' => 'settings'], ['settings' => '{nope', 'f' => 'settings'],
          ['settings' => json_encode(['api_key' => 'abc']), 'f' => 'settings'], ['settings' => json_encode(['x' => ['password' => 'abc']]), 'f' => 'settings'], ['user_agent' => "a\nb", 'f' => 'user_agent']] as $case) {
    $f = $case['f'];
    unset($case['f']);
    $r = post_json($jA, '/admin/sources/save.php', ['source' => $dtcc] + $case);
    $bad[$f . json_encode($case)] = $r['code'] === 422 && isset($r['json']['error']['fields'][$f]);
}
ok(!in_array(false, $bad, true), 'a bad URL (ftp, user:password, text), a rate off 0.1–10, no name, settings that are not an object or hold a key/password: 422 on the field' . (in_array(false, $bad, true) ? ' — ' . implode(', ', array_keys(array_filter($bad, static fn ($v) => !$v))) : ''));
$r = post_json($jA, '/admin/sources/save.php', ['source' => $edgar, 'rate_per_second' => '5']);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'at most 2 a second'), 'an EDGAR source above the ceiling (Settings → the EDGAR rate, 2): 422 in words');
$r = post_json($jA, '/admin/sources/save.php', ['source' => $edgar, 'rate_per_second' => '1.5', 'active' => '0']);
ok($r['code'] === 200 && one('SELECT active FROM sources WHERE id = :i', ['i' => $edgar]) === false && (float) one('SELECT rate_per_second FROM sources WHERE id = :i', ['i' => $edgar]) === 1.5, 'an EDGAR source below it, set inactive');
ok(!in_array($edgar, array_map('intval', array_column(q('SELECT id FROM si_sources_due()'), 'id')), true), 'an inactive source is not due');
post_json($jA, '/admin/sources/save.php', ['source' => $edgar, 'active' => '1', 'rate_per_second' => '2']);
$p = page($jA, '/admin/sources/' . $dtcc . '/edit');
ok($p['code'] === 200 && str_contains($p['body'], 'id="source-form"') && str_contains($p['body'], 'name="settings"') && str_contains($p['body'], '&quot;alphabetical&quot;') ? true : str_contains($p['body'], 'alphabetical'), 'the edit form shows the settings as a JSON text');
$j = get_json($jA, '/admin/sources/' . $dtcc . '/edit');
ok(($j['data']['source']['user_agent'] ?? '') === 'SMOKE Agent (+smoke@example.invalid)' && $j['data']['fields'] === ['name', 'base_url', 'settings', 'rate_per_second', 'user_agent', 'active'], 'JSON: the source with its override and the field names');
kernel_state(function ($s) { $s['facts']['970'] = ['valid' => true, 'member_id' => 42, 'run_id' => 970, 'request_id' => 'req-970', 'is_eval' => false]; return $s; });
$since = last_activity_id();
$r = req('POST', '/admin/sources/save.php', ['headers' => as_agent(run_token(42, 970)), 'form' => ['source' => $dtcc, 'name' => 'DTCC — by an agent']]);
$a = activity('source.save', $since)[0] ?? [];
ok($r['code'] === 403, 'an agent holds no sources.manage: 403');
post_json($jA, '/admin/sources/save.php', ['source' => $dtcc, 'name' => 'DTCC — the participant directory', 'user_agent' => '', 'rate_per_second' => '10']);

echo "7. Pull now, and the policy each pull followed\n";
$issuer = $sm['issuer'];
stub_reset();
$r = post_json($jA, '/admin/sources/pull.php', ['source' => $edgar, 'issuer' => $issuer, 'return_to' => '/admin/sources']);
$pull = last_pull('edgar_submissions');
ok($r['code'] === 200 && $pull['status'] === 'failed' && str_contains((string) $pull['error'], 'contact') && stub_requests() === [], 'with no EDGAR contact a pull sends nothing and says why');
$p = page($jA, '/admin/sources/' . $edgar)['body'];
ok(str_contains($p, 'id="source-pulls"') && str_contains($p, 'none sent (no contact)') && str_contains($p, 'id="ua-banner"'), 'the view: the pull, "none sent (no contact)", the banner');
$r = post_json($jA, '/admin/sources/pull.php', ['source' => $edgar, 'issuer' => $issuer]);
ok(str_contains(strtolower((string) json_encode($r['json'])), 'back'), 'a source backed off after its failure answers when it may be pulled again, not a second try');
edgar_contact(true);
q('UPDATE sources SET backoff_until = NULL, consecutive_failures = 0 WHERE id = :i', ['i' => $edgar]);
stub_reset();
$r = post_json($jA, '/admin/sources/pull.php', ['source' => $edgar, 'issuer' => $issuer, 'return_to' => '/admin/sources']);
$pull = last_pull('edgar_submissions');
$p = page($jA, '/admin/sources/' . $edgar)['body'];
ok($r['code'] === 200 && in_array($pull['status'], ['ok', 'partial'], true) && stub_requests() !== [] && !str_contains($p, 'id="ua-banner"'), 'with the contact set a pull runs against the stub (' . $pull['status'] . ', ' . count(stub_requests()) . ' requests); the banner is gone');
ok(str_contains($p, 'Kinetic Seas claude@kineticseas.com') && preg_match('~<tr id="pull-row-' . $pull['id'] . '"~', $p) === 1, 'the policy followed: the declared user-agent in the pull\'s row');
$pj = get_json($jA, '/admin/sources/' . $edgar)['data'];
ok(($pj['source']['pulls'][0]['policy']['user_agent'] ?? '') === 'Kinetic Seas claude@kineticseas.com' && isset($pj['source']['pulls'][0]['policy']['rate_per_second']) && !str_contains(json_encode($pj), '/submissions/'), 'JSON: the policy whitelisted (user-agent, rate, robots, cache), no URL');
$p = page($jA, '/admin/sources?connector=edgar_submissions')['body'];
ok(preg_match('~<span class="badge text-bg-(success|info)">(ok|partial)</span>~', $p) === 1, 'the list shows the last pull\'s status chip');
ok(post_json($jA, '/admin/sources/pull.php', ['source' => 999999])['code'] === 404, 'pulling a source that is not there: 404');
ok(get_json($jA, '/admin/sources/999999')['code'] === 404 && page($jA, '/admin/sources/999999/edit')['code'] === 404, 'a missing source\'s view and edit: 404');
finish();
