<?php
/**
 * Proof: the record picker as the Phase 2 shell carries it (design-system record-picker.md "Building it into a new application"): the modal
 * once and the script once on a full page, the endpoint's refusals as fragments (404 for a source the registry lacks, the launcher for no
 * session, JSON 401), the shell's two sources — `member` and `issuer` — searching through the walls (a restricted issuer only for someone on
 * its list, marked), labels, nothing in the activity log or the server's log. The slices prove their own sources when they add them.
 * Run through tests/phase2/run.sh (needs the servers it starts).
 */
require __DIR__ . '/lib.php';
$root = dirname(__DIR__, 2);

echo "1. The shell carries the modal and the script, once\n";
[$jAdmin, ] = sign_on(1);
[$jGuest, ] = sign_on(29);
[$jDana, ] = sign_on(30);
$seed = seed_store();
foreach (['admin' => $jAdmin, 'viewer' => $jGuest] as $who => $j) {
    $b = page($j, '/')['body'];
    ok(substr_count($b, 'id="record-picker"') === 1 && substr_count($b, 'id="record-picker-results"') === 1 && substr_count($b, 'id="record-picker-search"') === 1, "$who: a full page has the #record-picker modal once, with its search box and results");
    ok(substr_count($b, '/assets/js/record-picker.js') === 1 && strpos($b, 'common-init.min.js') < strpos($b, 'record-picker.js'), "$who: record-picker.js is loaded once, after common-init.min.js");
    ok(strpos($b, '</main>') < strpos($b, 'id="record-picker"') && !str_contains(substr($b, 0, strpos($b, '</main>')), 'id="record-picker"'), "$who: the modal sits after </main>, outside #page-content, so a swap keeps it");
}
$h = page($jAdmin, '/settings/', ['headers' => ['HX-Request: true']])['body'];
ok(!str_contains($h, 'id="record-picker"') && !str_contains($h, 'record-picker.js'), 'an HTMX swap (the partial) carries neither the modal nor the script — they live in the shell');
$js = req('GET', '/assets/js/record-picker.js');
ok($js['code'] === 200 && preg_match('/^Content-Type: (text|application)\/(x-)?javascript/mi', $js['headers']) === 1, 'record-picker.js is served as JavaScript');
$tmp = tempnam(sys_get_temp_dir(), 'rp') . '.js';
file_put_contents($tmp, $js['body']);
exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
unlink($tmp);
ok($rc === 0, 'the script parses (node --check)' . ($rc ? ': ' . implode(' ', $out) : ''));
$css = req('GET', '/assets/css/app-overrides.css')['body'];
ok(str_contains($css, 'RECORD PICKER') && str_contains($css, '#record-picker .modal-dialog') && str_contains($css, '.record-picker-field .record-picker-open'), 'app-overrides.css has the RECORD PICKER section');

echo "2. The endpoint — a source the registry lacks answers 404 as a fragment\n";
foreach (['/pick/?source=anything', '/pick/', '/pick/?source=' . str_repeat('x', 300), '/pick/?source=../../etc/passwd'] as $u) {
    $r = page($jAdmin, $u);
    ok($r['code'] === 404 && str_contains($r['body'], 'id="record-picker-error"') && str_contains($r['body'], 'That list does not exist.') && !str_contains($r['body'], '<html'), "signed in, GET $u: 404 with the fragment, not a page");
}
$r = page($jGuest, '/pick/?source=anything&q=' . str_repeat('y', 200));
ok($r['code'] === 404 && str_contains($r['body'], 'That list does not exist.'), 'a Viewer gets the same 404 fragment (the source is looked up before any right)');
$r = page($jAdmin, '/pick/?source=anything', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 404 && preg_match('/^Vary: .*HX-Request/mi', $r['headers']) === 1, 'over HTMX the same 404 fragment, with Vary: HX-Request');
$r = page(jar(), '/pick/?source=anything');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a redirect to the launcher with ?app=shareholder_intelligence');
$r = page(jar(), '/pick/?source=anything', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'no session, JSON: 401');
$r = req('POST', '/pick/', ['jar' => $jAdmin, 'form' => ['source' => 'anything']]);
ok($r['code'] === 404, 'a search is a GET; a POST with a source the registry lacks is the same 404 (nothing is chosen or written)');

echo "3. The shell's two sources, through the walls\n";
$sources = require $root . '/app/pickers.php';
ok(array_keys($sources) === ['member', 'issuer'], 'app/pickers.php has the shell\'s two sources: member and issuer');
$rows = fn (string $j, string $u): array => (preg_match_all('/id="record-picker-row-(\d+)"/', page($j, $u)['body'], $m) ? array_map('intval', $m[1]) : []);
[$jP, ] = sign_on(26);
ok($rows($jP, '/pick/?source=issuer') === [$seed['alpha'], $seed['gamma']], 'Priya picks from Alpha and Gamma — never the restricted Beta');
$h = page($jDana, '/pick/?source=issuer&q=beta')['body'];
ok($rows($jDana, '/pick/?source=issuer&q=beta') === [$seed['beta']] && str_contains($h, '>restricted</span>'), 'Dana, on Beta\'s list, finds it by name, marked restricted');
ok($rows($jP, '/pick/?source=issuer&q=SMKA') === [$seed['alpha']] && $rows($jP, '/pick/?source=issuer&q=999003') === [$seed['gamma']], 'an issuer is found by ticker or CIK');
ok($rows($jGuest, '/pick/?source=issuer') === [$seed['alpha'], $seed['gamma']], 'a Viewer may pick an issuer (issuers.read)');
$m = $rows($jP, '/pick/?source=member&q=SMOKE');
ok(in_array(27, $m, true) && in_array(42, $m, true) && !in_array(41, $m, true), 'the member source lists admitted people and agents (Marco, the Surveillance Analyst), never the unadmitted Watcher');
$h = page($jP, '/pick/?source=member&q=Surveillance')['body'];
ok(str_contains($h, 'SMOKE Surveillance Analyst') && str_contains($h, 'agent'), 'an agent is marked as one');
$h = page($jP, '/pick/?source=issuer&q=' . rawurlencode('no such thing'))['body'];
ok(str_contains($h, 'id="record-picker-empty"') && str_contains($h, 'No issuer matches'), 'no match: the empty fragment says so');
$lbl = static function (int $member, string $src, int $id) use ($root): string {
    $f = tempnam(sys_get_temp_dir(), 'lbl') . '.php';
    file_put_contents($f, '<?php require ' . var_export($root . '/app/bootstrap.php', true) . '; db()->exec("SELECT set_config(\'app.member_id\', \'' . $member . '\', false)"); echo picker_label(db(), ' . var_export($src, true) . ', ' . $id . ') ?? "(none)";');
    $out = trim((string) shell_exec('php ' . escapeshellarg($f) . ' 2>&1'));
    unlink($f);
    return $out;
};
ok($lbl(26, 'issuer', $seed['alpha']) === 'SMOKE Alpha Corp' && $lbl(26, 'issuer', $seed['beta']) === '(none)' && $lbl(30, 'issuer', $seed['beta']) === 'SMOKE Beta Holdings', 'a label is the issuer\'s name, and none for an issuer the person may not see');

echo "4. The log\n";
$before = last_activity_id();
page($jAdmin, '/pick/?source=anything');
page($jP, '/pick/?source=issuer&q=SMOKE');
ok(last_activity_id() === $before, 'a search is not an action: nothing is written to the activity log');
$log = '';
foreach ([getenv('SI_DEV_STATE') . '/app.log', getenv('SI_DEV_STATE') . '/apache/apache.err'] as $f) { if (is_file($f)) { $log .= file_get_contents($f); } }
ok(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)|Uncaught/i', $log), 'no PHP warning, notice or uncaught error in the server log');
finish();
