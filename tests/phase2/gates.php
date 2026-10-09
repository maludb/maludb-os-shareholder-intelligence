<?php
/**
 * Proof: rights per role on the shell's screens and the seventeen placeholders (200 vs 403), the placeholders' behaviour (a page naming its
 * slice, 501 for JSON and for a POST, `stub` in the registry), the menu and the tab bar per role, no-session answers, THE ISSUER (the switcher
 * lists only what the wall admits, ?issuer= switches and is logged, a restricted issuer is never chosen by someone off its list, the screen log
 * carries it), the home's JSON (the choice, the tiles' sources, the signals, the uploads), the search box's two walls, the person's own screens
 * and their five actions, the command bar through the kernel's chat endpoint with the issuer in its context, the action-token / run-token gate,
 * the mirror (preferences for everyone, a hand-off that keeps the directory's job title, a revoked grant shutting the door) — sso-shell.md "Proof".
 * Run after the browser proof through tests/phase2/run.sh (the seed is idempotent).
 */
require __DIR__ . '/lib.php';
/** The manifest's screen id of each placeholder (app/features/shell/nav.php NAV_SCREENS). */
const NAV_SCREENS_T = ['signals' => 'signal-list', 'positions' => 'positions', 'holders' => 'holder-list', 'ownership' => 'ownership-13f', 'filings' => 'filing-list',
    'short' => 'short', 'events' => 'event-list', 'reports' => 'report-list', 'uploads' => 'upload-list', 'upload-add' => 'upload-add', 'issuers' => 'issuer-list',
    'issuer-add' => 'issuer-add', 'participants' => 'participant-list', 'proposals' => 'proposals', 'calendar' => 'calendar', 'admin' => 'admin-sources', 'agents' => 'admin-agents'];
$json = ['headers' => ['Accept: application/json']];
$seed = seed_store();

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
// the roles: admin (the owner 1), manager (Marco 27), analyst (Priya 26), bea (an Analyst by capability, 28), viewer (Ann 29).
$all = 'admin manager analyst bea viewer';
$workers = 'admin manager analyst bea';
$matrix = [
    '/' => $all, '/search' => $all, '/activity' => $all, '/notifications' => $all, '/settings/' => $all, '/settings/tokens/' => $all,
    '/signals' => $all, '/positions' => $all, '/holders' => $all, '/ownership/13f' => $all, '/filings' => $all, '/short' => $all, '/events' => $all,
    '/reports' => $all, '/uploads' => $all, '/issuers' => $all, '/participants' => $all, '/calendar' => $all,
    '/proposals' => $workers, '/uploads/new' => $workers,
    '/issuers/new' => 'admin manager', '/admin/agents' => 'admin manager', '/admin/sources' => 'admin',
];
[$jAdmin, ] = sign_on(1);
[$jManager, ] = sign_on(27);
[$jAnalyst, ] = sign_on(26);
[$jBea, ] = sign_on(28);
[$jViewer, ] = sign_on(29);
$who = ['admin' => $jAdmin, 'manager' => $jManager, 'analyst' => $jAnalyst, 'bea' => $jBea, 'viewer' => $jViewer];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 5 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
$r = page($jManager, '/admin/sources');
ok(str_contains($r['body'], 'You may not change the settings.') && str_contains($r['body'], 'id="message-text"'), 'a refusal says what the person may not do, in words ("You may not change the settings.")');
$r = page($jViewer, '/issuers/new');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not manage issuers and securities.'), 'a Viewer adding an issuer: 403 in words');
ok(badge_of($jAdmin) === 'Super-admin' && badge_of($jManager) === 'IR Manager' && badge_of($jAnalyst) === 'Analyst' && badge_of($jBea) === 'Analyst' && badge_of($jViewer) === 'Viewer',
    'the header badge reads the highest role held: Super-admin, IR Manager, Analyst, Analyst (by capability), Viewer');

echo "2. The placeholders — a page naming its slice, 501 for JSON and for a POST\n";
// The shell's seventeen placeholders, less the ones a built slice has replaced (a slice's first step removes its render_nav_stub call).
$stubFiles = array_filter(explode("\n", trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null'))));
$stubNav = [];
foreach ($stubFiles as $f) { if (preg_match("/render_nav_stub\('([a-z-]+)'/", (string) file_get_contents($f), $m)) { $stubNav[$m[1]] = true; } }
ok(count($stubFiles) >= 1 && count($stubFiles) <= 17 && count($stubNav) === count($stubFiles), count($stubFiles) . ' controllers still call render_nav_stub (seventeen in the shell, fewer as slices land)');
$ph = ['signals' => ['/signals', 'slice 7', 'Signals', 'signal-list'], 'positions' => ['/positions', 'slice 2', 'Positions', 'positions'], 'holders' => ['/holders', 'slice 3', 'Holders', 'holder-list'],
       'ownership' => ['/ownership/13f', 'slice 5', 'Ownership', 'ownership-13f'], 'filings' => ['/filings', 'slice 4', 'Filings', 'filing-list'], 'short' => ['/short', 'slice 6', 'Short side', 'short'],
       'events' => ['/events', 'slice 8', 'Events', 'event-list'], 'reports' => ['/reports', 'slice 8', 'Reports', 'report-list'], 'uploads' => ['/uploads', 'slice 2', 'Uploads', 'upload-list'],
       'upload-add' => ['/uploads/new', 'slice 2', 'Upload a file', 'upload-add'], 'issuers' => ['/issuers', 'slice 1', 'Issuers', 'issuer-list'], 'issuer-add' => ['/issuers/new', 'slice 1', 'Add an issuer', 'issuer-add'],
       'participants' => ['/participants', 'slice 1', 'Participants', 'participant-list'], 'proposals' => ['/proposals', 'slice 9', 'Proposals', 'proposals'], 'calendar' => ['/calendar', 'slice 8', 'Calendar', 'calendar'],
       'admin' => ['/admin/sources', 'slice 10', 'Sources and settings', 'admin-sources'], 'agents' => ['/admin/agents', 'slice 9', 'Agents', 'admin-agents']];
$ph = array_intersect_key($ph, $stubNav);
$bad = [];
foreach ($ph as $id => [$url, $slice, $title]) {
    $b = page($jAdmin, $url)['body'];
    if (!(str_contains($b, 'id="' . $id . '-coming"') && str_contains($b, $title . ' is not built yet') && str_contains($b, $slice . ' builds this screen') && str_contains($b, '<title>' . $title . ' · Shareholder Intelligence</title>'))) { $bad[] = $url; }
}
ok($bad === [], 'every placeholder: the shell, the header and one card saying which slice builds it' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$r = page($jAdmin, '/signals', $json);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'a JSON caller on a placeholder: 501 not_built');
$r = req('POST', '/signals', ['jar' => $jAdmin, 'form' => ['csrf_token' => page_csrf($jAdmin)]]);
ok($r['code'] === 501 && str_contains($r['body'], 'This is built by slice 7.'), 'a POST to a placeholder: 501, in words');
$since = last_activity_id();
$r = page($jAdmin, '/ownership/13f', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="ownership-coming"') && !str_contains($r['body'], '<html') && preg_match('/^HX-Title: Ownership/mi', $r['headers']) === 1
    && preg_match('/^X-Screen: ownership-13f\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Nav: ownership\r?$/mi', $r['headers']) === 1,
    'over HTMX a placeholder is the partial with HX-Title, X-Screen (the manifest\'s id) and X-Nav, so the highlight and the command bar follow');
$v = activity('screen.view', $since);
ok($v !== [] && end($v)['screen'] === 'ownership-13f', 'opening a placeholder logs screen.view under the manifest\'s screen id');
$built = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);
$stubIds = array_keys(array_filter($built['screens'], static fn ($s) => !empty($s['stub'])));
$builtIds = array_keys(array_filter($built['screens'], static fn ($s) => !empty($s['built'])));
$builtActions = array_keys(array_filter($built['actions'], static fn ($a) => !empty($a['built'])));
$stubScreens = array_values(array_intersect_key(NAV_SCREENS_T, $stubNav));
ok(array_intersect(['home', 'search', 'notifications', 'activity', 'settings', 'settings-tokens'], $builtIds) === ['home', 'search', 'notifications', 'activity', 'settings', 'settings-tokens']
    && array_diff($stubScreens, $stubIds) === [] && array_intersect($stubIds, $builtIds) === [],
    'the registry marks the shell\'s six screens built and every remaining placeholder a stub (never both), so the voice surface never offers an unbuilt screen');
ok(array_diff(['prefs_save', 'notification_read', 'notifications_read_all', 'token_mint', 'token_revoke'], $builtActions) === [], 'and the shell\'s five actions built');

echo "3. The menu and the tab bar per role, and no session\n";
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
$main = ['home', 'signals', 'positions', 'holders', 'ownership', 'filings', 'short', 'events', 'reports'];
$me = ['my-settings', 'notifications', 'tokens', 'activity'];
ok($menu($jViewer) === array_merge($main, ['uploads', 'issuers', 'participants'], $me) && $groups($jViewer) === ['Data', 'Me'], 'a Viewer sees the nine, Data without Proposals, and Me — no Admin');
ok($menu($jAnalyst) === array_merge($main, ['uploads', 'issuers', 'participants', 'proposals'], $me) && $groups($jAnalyst) === ['Data', 'Me'], 'an Analyst sees Proposals too, no Admin');
ok($menu($jManager) === array_merge($main, ['uploads', 'issuers', 'participants', 'proposals'], $me, ['agents']) && $groups($jManager) === ['Data', 'Me', 'Admin'], 'an IR Manager sees Admin with Agents only');
ok(array_slice($menu($jAdmin), -2) === ['admin', 'agents'], 'the admin sees Sources and settings and Agents');
$tabs = fn (string $j): array => (preg_match_all('/id="tab-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
ok($tabs($jViewer) === ['home', 'signals', 'positions', 'holders', 'my-settings'] && $tabs($jAnalyst) === $tabs($jViewer), 'the phone\'s tab bar is Home · Signals · Positions · Holders · Me for every role');
$r = page(jar(), '/signals');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a screen goes to the launcher with ?app=shareholder_intelligence');
$r = req('GET', '/', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');

echo "4. The issuer — the switcher, the wall, the log\n";
$switch = fn (string $j, string $u = '/'): array => (preg_match_all('/id="issuer-switcher-item-(\d+)"/', page($j, $u)['body'], $m) ? array_map('intval', $m[1]) : []);
[$jP, ] = sign_on(26);
$d = json_decode(page($jP, '/', $json)['body'], true)['data'] ?? [];
ok(($d['choose'] ?? null) === true && $d['issuer'] === null && array_column($d['issuers'], 'issuer_id') === [$seed['alpha'], $seed['gamma']], 'Priya, a new session, two issuers visible: the home asks her to choose (Alpha, Gamma — never Beta)');
ok($switch($jP) === [$seed['alpha'], $seed['gamma']], 'her switcher lists Alpha and Gamma');
[$jD, ] = sign_on(30);
$h = page($jD, '/')['body'];
ok($switch($jD) === [$seed['alpha'], $seed['beta'], $seed['gamma']] && str_contains($h, 'id="issuer-switcher-item-' . $seed['beta'] . '"') && preg_match('/issuer-switcher-item-' . $seed['beta'] . '".*?feather-lock/s', $h) === 1, 'Dana, on Beta\'s list, sees all three, Beta marked restricted');
$since = last_activity_id();
$d = json_decode(page($jP, '/?issuer=' . $seed['beta'], $json)['body'], true)['data'] ?? [];
ok($d['issuer'] === null && activity('issuer.switch', $since) === [], 'Priya asking for Beta by id: not chosen, nothing logged (a restricted issuer is never shown)');
$d = json_decode(page($jP, '/?issuer=SMKB', $json)['body'], true)['data'] ?? [];
ok($d['issuer'] === null, '…nor by its ticker');
$d = json_decode(page($jP, '/?issuer=smka', $json)['body'], true)['data'] ?? [];
$sw = activity('issuer.switch', $since);
ok(($d['issuer']['issuer_id'] ?? 0) === $seed['alpha'] && count($sw) === 1 && (int) $sw[0]['issuer_id'] === $seed['alpha'], 'by its ticker in any case she chooses Alpha; issuer.switch logged once with the issuer');
$d = json_decode(page($jP, '/', $json)['body'], true)['data'] ?? [];
ok(($d['issuer']['issuer_id'] ?? 0) === $seed['alpha'] && count(activity('issuer.switch', $since)) === 1, 'the session remembers it; the next page logs no second switch');
$since = last_activity_id();
page($jP, '/notifications');
$v = activity('screen.view', $since);
ok($v !== [] && (int) end($v)['issuer_id'] === $seed['alpha'], 'every screen view carries the current issuer');
$d = json_decode(page($jP, '/?issuer=0000999003', $json)['body'], true)['data'] ?? [];
ok(($d['issuer']['issuer_id'] ?? 0) === $seed['gamma'], 'an issuer may be named by its CIK too');
$h = page($jP, '/')['body'];
ok(str_contains($h, 'id="home-no-security"'), 'Gamma has no primary security yet: the home says so and links to its securities');

echo "5. The home — the tiles with their sources, the signals, the overlay, the uploads, the note\n";
$d = json_decode(page($jP, '/?issuer=SMKA', $json)['body'], true)['data'] ?? [];
$s = $d['summary'] ?? [];
ok((float) ($s['outstanding'] ?? 0) === 20000000.0 && (float) ($s['short']['pct_of_float'] ?? 0) === 20.0 && (float) ($s['dtc']['total'] ?? 0) === 10000000.0 && ($s['dtc']['position_date'] ?? '') !== '',
    'the summary: 20,000,000 outstanding, short 20% of the float, 10,000,000 at DTC with its position date');
ok(($d['counts']['outstanding']['source'] ?? '') === 'filing' && ($d['counts']['float']['source'] ?? '') === 'transfer_agent', 'each count carries its source and date');
ok(count($d['signals']) === 1 && $d['signals'][0]['evidence_class'] === 'established' && $d['signals'][0]['severity'] === 'high', 'one open signal, high, with its evidence class');
ok(count($d['events']) === 1 && $d['events'][0]['kind'] === 'record_date' && $d['prices'] > 30, 'the next thirty days hold the record date; the overlay has the prices');
ok(count($d['uploads']) === 1 && $d['uploads'][0]['kind'] === 'spr' && $d['uploads'][0]['tied'] === true, 'the latest upload: the SPR, tied');
ok(($d['note']['title'] ?? '') === 'SMOKE: the morning note', 'the Analyst\'s morning note');
$h = page($jP, '/')['body'];
ok(substr_count($h, 'class="fs-11 text-muted si-source-line"') >= 8 && str_contains($h, 'Source: FINRA short interest, settlement'), 'every tile has its source line');
ok(str_contains($h, 'id="home-overlay-chart"') && substr_count($h, 'class="si-event"') === 2 && str_contains($h, '<path d="M'), 'the overlay draws the price line and the two past events');
ok(str_contains($h, 'id="home-overlay-table"') && str_contains($h, 'SMOKE: the company answers the report'), '…with its table twin');
ok(str_contains($h, '>Established</span>') && str_contains($h, 'SMOKE: short interest is 20.0% of the float'), 'the signal card prints its class and its sentence');
[$jV, ] = sign_on(29);
$d = json_decode(page($jV, '/?issuer=SMKA', $json)['body'], true)['data'] ?? [];
ok(($d['may']['upload'] ?? true) === false && count($d['signals']) === 1 && !str_contains(json_encode($d), 'Frank'), 'a Viewer\'s home: the same tiles and signals, no upload, no person');
[$jM, ] = sign_on(27);
$d = json_decode(page($jM, '/?issuer=SMKB', $json)['body'], true)['data'] ?? [];
ok(($d['issuer']['issuer_id'] ?? 0) === $seed['beta'] && ($d['issuer']['restricted'] ?? false) === true, 'an IR Manager reaches the restricted issuer');

echo "6. The search box — both walls\n";
$res = fn (string $j, string $q): array => array_map(static fn ($r) => $r['entity_kind'] . ':' . $r['title'], json_decode(page($j, '/search?scope=all&q=' . rawurlencode($q), $json)['body'], true)['data']['results'] ?? []);
ok($res($jP, 'Frank') === ['holder:SMOKE Frank Person'], 'an Analyst finds a natural-person holder by name');
ok($res($jV, 'Frank') === [], 'a Viewer finds no natural person');
ok($res($jV, 'Zeta') === ['holder:SMOKE Zeta Holdings LLC'], '…but an entity by name');
ok(!in_array('issuer:SMOKE Beta Holdings', $res($jP, 'SMOKE'), true) && in_array('issuer:SMOKE Beta Holdings', $res($jD, 'Beta'), true), 'the restricted issuer is found only by someone on its list');
$since = last_activity_id();
page($jP, '/search?q=' . rawurlencode('short report'));
$k = activity('search.keyword', $since);
$ka = $k !== [] ? json_decode((string) $k[0]['after'], true) : [];
ok($k !== [] && ($ka['q'] ?? '') === 'short report' && isset($ka['count']) && ($ka['scope'] ?? '') === 'issuer' && (int) $k[0]['issuer_id'] === $seed['alpha'], 'search.keyword logged with the query, the count and the issuer');
$d = json_decode(page($jP, '/search?q=report', $json)['body'], true)['data'] ?? [];
ok($d['scope'] === 'issuer' && count(array_filter($d['results'], static fn ($r) => (int) $r['issuer_id'] !== $seed['alpha'] && $r['issuer_id'] !== null)) === 0, 'by default the search stays in the current issuer');

echo "7. The person's own screens and their five actions\n";
$d = json_decode(page($jP, '/settings/', $json)['body'], true)['data'] ?? [];
ok(count($d['kinds']) === 18 && isset($d['notify']['morning_note']) && $d['notify']['signals_min_severity'] === 'watch' && $d['morning_note_hour_default'] === 7 && count($d['issuers']) === 2, '/settings/: eighteen kinds, the morning note, the least severity, the hour\'s default, my issuers');
$r = page($jP, '/settings/tokens/', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['tokens'] ?? null), '/settings/tokens/: the tokens list');
$r = page($jP, '/notifications', $json);
$nl = json_decode($r['body'], true)['data']['notifications'] ?? [];
ok($r['code'] === 200 && ($nl[0]['kind'] ?? '') === 'morning_note' && ($nl[0]['issuer_id'] ?? 0) === $seed['alpha'], '/notifications: the morning note with its issuer');
$r = page($jP, '/activity', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['rows'] ?? null), '/activity: the rows');
$t = page_csrf($jP);
$post = fn (string $u, array $f) => req('POST', $u, ['jar' => $jP, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => $f]);
ok($post('/settings/prefs.php', ['kinds' => ['signal_high', 'nonsense']])['code'] === 422, 'prefs_save refuses an unknown kind: 422');
ok($post('/settings/prefs.php', ['morning_hour' => '25'])['code'] === 422 && $post('/settings/prefs.php', ['signals_min_severity' => 'loud'])['code'] === 422, '…an hour of 25 and an unknown severity');
ok($post('/settings/prefs.php', ['issuer_ids' => [$seed['beta']]])['code'] === 422, '…and an issuer she may not see');
$since = last_activity_id();
$r = $post('/settings/prefs.php', ['morning_note' => 'no', 'morning_hour' => '6', 'signals_min_severity' => 'high', 'kinds' => ['signal_high', 'upload_untied'], 'issuer_ids' => [$seed['alpha']]]);
$row = q('SELECT morning_note, morning_hour, signals_min_severity, kinds::text AS k, issuer_ids::text AS i FROM notification_prefs WHERE member_id = 26')[0];
ok($r['code'] === 200 && (json_decode($r['body'], true)['did'] ?? '') === 'Saved how you are told' && $row['morning_note'] === false && (int) $row['morning_hour'] === 6
    && $row['signals_min_severity'] === 'high' && $row['k'] === '{signal_high,upload_untied}' && $row['i'] === '{' . $seed['alpha'] . '}', 'prefs_save writes the morning note, its hour, the severity, the kinds and the issuers');
$a = activity('settings.prefs', $since);
ok($a !== [] && str_contains((string) $a[0]['after'], 'morning_hour'), 'logged settings.prefs with what changed');
$r = $post('/settings/prefs.php', ['morning_hour' => '']);
ok($r['code'] === 200 && one('SELECT morning_hour FROM notification_prefs WHERE member_id = 26') === null, 'a blank hour returns to the application\'s');
ok($post('/settings/tokens/mint.php', ['label' => 'proof', 'scope' => 'api'])['code'] === 422, 'token_mint refuses the api scope here (a key is slice 9\'s key_mint)');
$r = $post('/settings/tokens/mint.php', ['label' => 'proof', 'scope' => 'mcp']);
$mb = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && str_starts_with((string) ($mb['token'] ?? ''), 'mcp_') && ($mb['record_id'] ?? 0) > 0, 'token_mint answers the token once (JSON) with its record_id');
$tid = (int) ($mb['record_id'] ?? 0);
$r = $post('/settings/tokens/revoke.php', ['token' => $tid]);
ok($r['code'] === 200 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]), 'token_revoke revokes it');
ok($post('/settings/tokens/revoke.php', ['token' => $tid])['code'] === 404, '…and a second revoke is 404');
$nid = (int) q("INSERT INTO notifications (member_id, kind, title, issuer_id) VALUES (26, 'signal_high', 'SMOKE: a high signal', :i) RETURNING id", ['i' => $seed['alpha']])[0]['id'];
$r = $post('/notifications/read.php', ['notification' => $nid]);
ok($r['code'] === 200 && one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $nid]) && activity('notification.read', $since) !== [], 'notification_read marks one read, logged');
ok($post('/notifications/read.php', [])['code'] === 422, 'notification_read with no notification: 422');
q("INSERT INTO notifications (member_id, kind, title) VALUES (26, 'mention', 'SMOKE: a mention'), (27, 'mention', 'SMOKE: Marco''s')");
$r = $post('/notifications/read-all.php', []);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 27 AND read_at IS NULL') >= 1
    && activity('notification.read_all', $since) !== [], 'notifications_read_all marks every one of hers read — none of Marco\'s — logged');

echo "8. The command bar through the kernel's chat endpoint\n";
$since = last_activity_id();
$r = req('POST', '/assistant/ask.php', ['jar' => $jP, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'Who bought the most last week?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-text"') && str_contains($r['body'], 'Hello from the fake expert'), 'the expert\'s reply is rendered above the bar');
$a = activity('assistant.ask', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && ($la['length'] ?? 0) === 30 && !str_contains((string) end($a)['after'], 'bought'), 'assistant.ask logs the length, never the words');
$chatLines = file(need('FAKE_KERNEL_STATE') . '.chat'); $chat = json_decode((string) end($chatLines), true);
ok($chat['agent'] === 'expert' && (int) $chat['acting'] === 26 && (int) ($chat['context']['issuer_id'] ?? 0) === $seed['alpha'] && ($chat['context']['issuer'] ?? '') === 'SMKA',
    'the kernel saw the call for the expert as the acting person, with the current issuer in its context');
ok(req('POST', '/assistant/ask.php', ['jar' => $jP, 'form' => ['utterance' => 'hi']])['code'] === 403, 'no CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jP, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'id="assistant-reply-error"'), 'the kernel\'s 404 (no expert) is shown as an error in words');
kernel_state(function ($s) { unset($s['chat_status']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'Deleting the upload waits for approval.', 'actions' => [['tool' => 'upload_delete', 'status' => 'awaiting_approval']], 'approval_request_id' => 12]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jP, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'delete the last upload', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-approval"') && str_contains($r['body'], 'request #12'), 'an action awaiting approval is shown as such, with the request number');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening your tokens.', 'actions' => [], 'navigate' => '/settings/tokens/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jP, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open my tokens', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/settings/tokens/'), 'a navigate answer is followed (HX-Location)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jP, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
ok(req('POST', '/assistant/ask.php', ['jar' => $jP, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]])['code'] === 422, 'over 2,000 characters: 422');

echo "9. The action-token and run-token gate\n";
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)]]);
ok($r['code'] === 200 && preg_match('/^Set-Cookie:/mi', $r['headers']) === 0, 'a person\'s action token opens a read for one request, with no cookie set');
ok(req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26, -5)]])['code'] === 401, 'an expired action token: 401');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['morning_note' => 'yes']]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true && one('SELECT morning_note FROM notification_prefs WHERE member_id = 26') === true, 'a write under an action token answers JSON (the action token stands in for CSRF) and is written');
$tok = run_token(42, 77);
kernel_state(function ($s) { $s['facts']['77'] = ['valid' => true, 'member_id' => 42, 'run_id' => 77, 'request_id' => 'req-77', 'is_eval' => false]; return $s; });
$r = req('GET', '/activity', ['headers' => as_agent($tok)]);
ok($r['code'] === 200, 'an admitted agent (the Surveillance Analyst, 42) under a run token reads: 200');
$since = last_activity_id();
req('GET', '/', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$v = activity('screen.view', $since);
ok($v !== [] && (int) end($v)['agent_run_id'] === 77 && end($v)['source'] === 'agent', 'its screen view is logged with the run id and source agent');
ok(req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'an agent may not mint a person\'s token: 403 (people only)');
ok(req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, '…nor use the command bar: 403');
ok(req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]])['code'] === 401, 'a run token without its relay signature: 401');
$r = req('GET', '/activity', ['headers' => as_agent(run_token(900, 78))]);
ok($r['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 900') === 0, 'an id with no mirror row is refused and never created');

echo "10. The mirror — preferences for everyone, a hand-off that keeps the directory's fields, a revoked grant\n";
ok((int) one("SELECT count(*) FROM notification_prefs") === (int) one("SELECT count(*) FROM members WHERE status = 'active' AND capability IS NOT NULL"), 'every admitted member has notification preferences after the sync (' . one('SELECT count(*) FROM notification_prefs') . ')');
q("UPDATE members SET job_title = 'Head of Investor Relations', phone = '+15555550126', timezone = 'America/New_York' WHERE id = 26");
sign_on(26);
$m = q('SELECT job_title, phone, timezone FROM members WHERE id = 26')[0];
ok($m['job_title'] === 'Head of Investor Relations' && $m['phone'] === '+15555550126' && $m['timezone'] === 'America/New_York', 'a hand-off whose claims carry no title, phone or time zone keeps the directory\'s (the kit\'s fix, 2026-10-09)');
ok(req('GET', '/', ['jar' => $jBea])['code'] === 200, 'Bea, signed on earlier, is let in while her grant stands');
kernel_state(function ($s) { $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => '2026-01-02T00:00:00.000000Z', 'next' => '2026-01-03T00:00:00.000000Z', 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [],
    'access' => [['member_id' => 28, 'capability' => null, 'roles' => [], 'scopes' => []]]]; return $s; });
exec('php bin/directory_sync.php 2>&1', $syncOut, $rc);
ok($rc === 0 && req('GET', '/', ['jar' => $jBea])['code'] === 302, 'a grant the kernel revoked shuts the door within one sync');
ok(one("SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1") === 'directory' && one('SELECT capability IS NULL FROM members WHERE id = 28'), '…the session ended by the directory and the mirror holds no grant');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
