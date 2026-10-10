<?php
/**
 * Proof: THE PII WALL on every holder screen, read, search, picker, export gate and log line (holder-identity.md §4, D4), on the anonymised
 * fixtures: a Viewer's holder list, page, changes, ranges, geography, custodians, register and upload pages render "Individual holder" for every
 * natural person and the entity by name, with no address, city, registration or note of a person; the queue, the edit, the picker and the raw
 * rows answer nothing; the search box finds no person for a Viewer and finds them for an Analyst; pii_export_allowed() follows the setting;
 * after everything the activity log carries no fixture name.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
[$jV, ] = sign_on(29);
$fx = fixture_issuer();
$sec = $fx['security'];
foreach ([$jP, $jM, $jV] as $j) { page($j, '/?issuer=' . $fx['issuer']); }
$json = ['headers' => ['Accept: application/json']];
$seed = seed_fixture_holders($jP, $jM, $sec);
$names = fixture_person_names();
$person = (int) one("SELECT id FROM holders WHERE kind = 'person' AND issuer_id = :i ORDER BY last_seen_on DESC, id LIMIT 1", ['i' => $fx['issuer']]);
$personName = (string) one('SELECT upper(display_name) FROM holders WHERE id = :h', ['h' => $person]);
$entity = (int) one("SELECT id FROM holders WHERE kind = 'entity' AND issuer_id = :i ORDER BY id LIMIT 1", ['i' => $fx['issuer']]);
$entityName = (string) one('SELECT display_name FROM holders WHERE id = :h', ['h' => $entity]);
$noNames = static function (string $body) use ($names, $personName): bool {
    $u = strtoupper($body);
    foreach (array_merge($names, [$personName]) as $n) { if (str_contains($u, strtoupper($n))) { return false; } }
    return true;
};
ok(count($names) > 100 && $person > 0 && $entity > 0, count($names) . ' fixture person names to sweep for; a person holder and an entity to read');
ok(post_json($jP, '/notes/add.php', ['kind' => 'holder', 'record' => $person, 'body' => 'SMOKE note about the person'])['code'] === 200, 'an Analyst notes a person holder');

echo "1. A Viewer's holder screens\n";
$d = json_decode(page($jV, '/holders?security=' . $sec, $json)['body'], true)['data']['holders'] ?? [];
$persons = array_filter($d, static fn ($h) => $h['kind'] === 'person');
ok(count($persons) > 10 && array_unique(array_column($persons, 'display_name')) === ['Individual holder'] && array_filter(array_column($persons, 'address_line')) === [] && array_filter(array_column($persons, 'city')) === []
    && !in_array(false, array_column($persons, 'pii_withheld'), true), 'the list in JSON: every person "Individual holder", no address or city, pii_withheld');
ok(in_array($entityName, array_column($d, 'display_name'), true), 'the entity is named');
$hl = page($jV, '/holders?security=' . $sec)['body'];
ok($noNames($hl) && str_contains($hl, 'Individual holder') && str_contains($hl, 'id="holder-list-pii-note"'), 'the list\'s page: no person named, the wall said in words');
$hv = page($jV, '/holders/' . $person);
$hvj = json_decode(page($jV, '/holders/' . $person, $json)['body'], true)['data'] ?? [];
ok($hv['code'] === 200 && $noNames($hv['body']) && !str_contains($hv['body'], 'SMOKE note') && !str_contains($hv['body'], 'id="holder-notes"') && array_filter(array_column($hvj['accounts'] ?? [], 'registration_text')) === [] && ($hvj['notes'] ?? null) === [],
    'a person\'s page: "Individual holder", no registration lines, no notes — in HTML and JSON');
ok(str_contains(page($jP, '/holders/' . $person)['body'], 'SMOKE note about the person') && !$noNames(page($jP, '/holders/' . $person)['body']), 'an Analyst reads the same page with the name, the lines and the note');
foreach (['/holders/changes', '/holders/ranges', '/holders/geography', '/holders/custodians', '/holders/register', '/uploads/' . $seed['nobo'], '/uploads/' . $seed['register']] as $u) {
    $r = page($jV, $u . (str_contains($u, '?') ? '&' : '?') . 'security=' . $sec);
    ok($r['code'] === 200 && $noNames($r['body']), "a Viewer reads $u — and no person in it");
}

echo "2. What answers a Viewer nothing\n";
ok(page($jV, '/holders/unmatched')['code'] === 403 && page($jV, '/holders/' . $person . '/edit')['code'] === 403 && page($jV, '/holders/' . $person . '/merge')['code'] === 403, 'the queue, the edit and the merge: 403');
$pk = page($jV, '/pick/?source=holder&q=a', ['headers' => ['HX-Request: true']]);
ok($pk['code'] === 403 && str_contains($pk['body'], 'Holders are an Analyst') && $noNames($pk['body']), 'the holder picker: "Holders are an Analyst\'s."');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
ok((int) one('SELECT count(*) FROM mcp_nobo_rows') === 0 && (int) one('SELECT count(*) FROM mcp_register_rows') === 0 && (int) one('SELECT count(*) FROM mcp_holder_match_proposals') === 0
    && (int) one("SELECT count(*) FROM mcp_holders WHERE kind = 'person' AND display_name <> 'Individual holder'") === 0 && (int) one("SELECT count(*) FROM mcp_holder_accounts a JOIN holders h ON h.id = a.holder_id WHERE h.kind = 'person' AND a.registration_text IS NOT NULL") === 0,
    'as the Viewer, the raw rows, the proposals answer nothing; the views withhold every person');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
ok((int) one('SELECT count(*) FROM mcp_nobo_rows') === 841, '…the same views answer an Analyst the 841 rows');

echo "3. The search box\n";
$q = explode(' ', $personName)[array_key_last(explode(' ', $personName))];
$sv = page($jV, '/search?q=' . rawurlencode($q), $json)['body'];
$sp = page($jP, '/search?q=' . rawurlencode($q), $json)['body'];
ok(!str_contains(strtoupper($sv), $personName) && str_contains(strtoupper($sp), $personName), 'searching a person\'s surname: nothing of them for a Viewer, the holder for an Analyst');

echo "4. The export gate\n";
$gate = static function (int $member, string $rule): bool {
    q("UPDATE si_settings SET pii_in_exports = :r WHERE id = 1", ['r' => $rule]);
    return (bool) app_php("db()->exec(\"SELECT set_config('app.member_id', '$member', false)\"); require_once " . var_export(dirname(__DIR__, 3) . '/app/features/holders/queries.php', true) . "; echo json_encode(pii_export_allowed(db()));");
};
ok($gate(26, 'analyst') === true && $gate(29, 'analyst') === false && $gate(27, 'admin') === false && $gate(1, 'admin') === true && $gate(1, 'nobody') === false,
    'pii_export_allowed(): analyst admits an Analyst and refuses a Viewer; admin only the SI admin; nobody no one');
q("UPDATE si_settings SET pii_in_exports = 'analyst' WHERE id = 1");

echo "5. The log\n";
$vals = array_map('strtoupper', array_column(q("SELECT DISTINCT v #>> '{}' AS v FROM activity_log, jsonb_path_query(COALESCE(after, '{}') || COALESCE(before, '{}'), 'strict $.**') v WHERE jsonb_typeof(v) = 'string'"), 'v'));
$hits = array_filter($vals, static function (string $v) use ($names): bool { foreach ($names as $n) { if (str_contains($v, $n)) { return true; } } return false; });
ok(count($vals) >= 5 && $hits === [], 'no value in the activity log holds a fixture name (' . number_format((int) one('SELECT count(*) FROM activity_log')) . ' lines swept)');
finish();
