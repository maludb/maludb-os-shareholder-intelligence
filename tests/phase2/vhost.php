<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-shareholder_intelligence.conf). Meaningful under SI_APP=apache tests/phase2/run.sh, where a
 * real Apache serves the template rendered as the installer renders it; under php -S the same checks run against tests/dev_router.php, which
 * mirrors the rewrites. The receivers at their extension-less names, the shell's screens with and without a slash, the placeholders, the
 * canonical URLs the slices build (404 until then), the records API (404 until slice 9 but for health), /pick/, the MCP proxies (under Apache),
 * the manifest and the assets with their types, nothing outside html/ served.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/settings/')['code'] === 200 && page($j, '/settings')['code'] === 200, '/settings/ and /settings both reach settings/index.php');
ok(page($j, '/search')['code'] === 200 && page($j, '/notifications')['code'] === 200 && page($j, '/activity')['code'] === 200, '/search, /notifications and /activity resolve to their own files');
foreach (['signals', 'positions', 'holders', 'filings', 'short', 'events', 'reports', 'uploads', 'issuers', 'participants', 'proposals', 'calendar'] as $d) {
    ok(page($j, "/$d")['code'] === 200 && page($j, "/$d/")['code'] === 200, "/$d and /$d/ both reach $d/index.php (the placeholder)");
}
ok(page($j, '/ownership/13f')['code'] === 200, '/ownership/13f reaches ownership/13f.php');
ok(page($j, '/issuers/new')['code'] === 200 && page($j, '/uploads/new')['code'] === 200, '/issuers/new and /uploads/new reach their form.php (the placeholders)');
$refused = static fn (array $r): bool => $r['code'] === 404 && str_contains($r['body'], 'Refused · Shareholder Intelligence');      // the controller's own 404, not the router's
ok($refused(page($j, '/issuers/99999/edit')) && $refused(page($j, '/issuers/99999')) && $refused(page($j, '/issuers/99999/securities')) && $refused(page($j, '/participants/99999')),
    '/issuers/{id}/edit, /issuers/{id}, /issuers/{id}/securities and /participants/{id} reach their controllers (slice 1): a missing record is the application\'s own 404');
ok(page($j, '/admin/agents')['code'] === 200 && page($j, '/admin/sources')['code'] === 403, '/admin/agents and /admin/sources resolve (an IR Manager: Agents yes, Sources no — by the right)');
ok(page($j, '/signals/1')['code'] === 404 && page($j, '/holders/1')['code'] === 404 && page($j, '/filings/1')['code'] === 404,
    '/signals/{id}, /holders/{id} and /filings/{id} are rewritten to view.php, which their slices build: 404 until then');
ok(page($j, '/signals/history')['code'] === 404 && page($j, '/events/12')['code'] === 404, 'a record\'s page and a screen beside a list are 404 until their slices');
ok(req('GET', '/exports/1')['code'] === 404, '/exports/{id} is rewritten to exports/download.php: 404 until slice 8');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$h = req('GET', '/api/v1/health');
$hb = json_decode($h['body'], true) ?: [];
ok($h['code'] === 200 && ($hb['application'] ?? '') === 'shareholder_intelligence' && ($hb['database'] ?? '') === 'ok', '/api/v1/health answers');
ok(($hb['edgar_user_agent'] ?? '') === 'missing' && ($hb['storage'] ?? '') === 'unset', 'health says the EDGAR user-agent is missing and the store unset (a scratch install)');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/issuers')['code'] === 404 && req('GET', '/api/v1/short-interest')['code'] === 404 && req('POST', '/api/v1/uploads')['code'] === 404 && req('GET', '/api/v1/holders/1')['code'] === 404,
    'the records API (/api/v1/issuers, /short-interest, /uploads, /holders/{id}) is rewritten to files slice 9 builds: 404 until then');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
$p = req('GET', '/pick/?source=issuer');
ok($p['code'] === 302 && $p['location'] === LAUNCHER, '/pick/ without a session goes to the launcher');
if (getenv('SI_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:8907/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'shareholder_intelligence', 'the internal vhost (the kernel\'s actions server\'s door) answers health on its loopback port');
    $conf = (string) file_get_contents(getenv('SI_DEV_STATE') . '/apache/site.conf');
    ok(str_contains($conf, 'LimitRequestBody 67108864') && str_contains($conf, 'upload_max_filesize 50M') && str_contains($conf, 'post_max_size 60M') && !str_contains($conf, '/d/index.php'),
        'the rendered vhost carries LimitRequestBody 64 MB, the PHP upload limits deploy says, and no DocCloud door');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && json_decode($m['body'], true)['name'] === 'Shareholder Intelligence', '/manifest.webmanifest is served as JSON and names Shareholder Intelligence');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/app/si/svg.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php',
          '/tests/fixtures/real/', '/deploy/ROOT_STEPS.sh', '/storage/uploads/aa/x'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
