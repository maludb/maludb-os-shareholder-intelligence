<?php
/**
 * Proof: the tombstones and the purge (admin-settings.md): the list with who/when/purge-after; Restore; Purge now only past the window; the purge of all kinds; a kept file removed with an upload;
 * the worker's tombstones_purge and key_usage_prune passes; the log carries {kind, count} and no name; the walls; everyone's keys listed and one revoked (an MCP token and a key); participants reclassed;
 * templates saved and deleted (the extract's template stays; never-stored columns cannot be dropped).
 */
require __DIR__ . '/lib.php';
$ids = seed_store();
$a = $ids['alpha'];
$jA = who(1); $jM = who(27); $jP = who(26); $jV = who(29);
$old = static fn (string $t, int $id, int $days) => q("UPDATE $t SET deleted_at = now() - make_interval(days => :d) WHERE id = :i", ['d' => $days, 'i' => $id]);
$mkevent = static function (string $title, int $days) use ($a): int {
    $id = (int) q("INSERT INTO events (issuer_id, on_date, kind, title, source) VALUES (:i, CURRENT_DATE, 'note', :t, 'person') RETURNING id", ['i' => $a, 't' => $title])[0]['id'];
    q("UPDATE events SET deleted_at = now() - make_interval(days => :d) WHERE id = :i", ['d' => $days, 'i' => $id]);
    return $id;
};
$due = $mkevent('SMOKE purge due', 40);
$young = $mkevent('SMOKE purge young', 5);
$due2 = $mkevent('SMOKE purge due two', 60);

echo "1. The list\n";
$p = page($jA, '/admin/purge');
ok($p['code'] === 200 && str_contains($p['body'], 'id="purge-table"') && str_contains($p['body'], 'id="purge-row-event-' . $due . '"') && str_contains($p['body'], 'id="purge-row-event-' . $young . '"'), 'the tombstones table lists the events waiting');
ok(str_contains($p['body'], 'id="purge-row-event-' . $due . '-purge-btn"') && !str_contains($p['body'], 'id="purge-row-event-' . $young . '-purge-btn"') && str_contains($p['body'], 'id="purge-row-event-' . $young . '-restore-btn"'), 'Purge now only for what is past its window; Restore for all');
$j = get_json($jA, '/admin/purge');
ok($j['data']['due'] === 2 && $j['data']['tombstone_days'] === 30 && count($j['data']['tombstones']) >= 3, 'JSON: counts, the window, the rows');
foreach (['manager' => $jM, 'analyst' => $jP, 'viewer' => $jV] as $w => $jar) {
    $bad[$w] = page($jar, '/admin/purge')['code'] === 403 && post_json($jar, '/admin/purge/run.php', ['kind' => 'all'])['code'] === 403;
}
ok(!in_array(false, $bad, true) && (int) one('SELECT count(*) FROM events WHERE id = :i', ['i' => $due]) === 1, 'IR Manager, Analyst, Viewer: 403, nothing purged');

echo "2. Restore, purge\n";
$r = post_json($jA, '/events/restore.php', ['event' => $young, 'return_to' => '/admin/purge']);
ok($r['code'] === 200 && one('SELECT deleted_at FROM events WHERE id = :i', ['i' => $young]) === null && str_contains($r['json']['location'], '/admin/purge'), 'Restore (the event\'s own action) lands back on the purge screen');
$r = post_json($jA, '/admin/purge/run.php', ['kind' => 'event', 'record' => $mkevent('SMOKE y2', 3)]);
ok($r['code'] === 422 && (int) one("SELECT count(*) FROM events WHERE title = 'SMOKE y2'") === 1, 'Purge now of a record still inside its window: 422, nothing deleted');
$since = last_activity_id();
$r = post_json($jA, '/admin/purge/run.php', ['kind' => 'event', 'record' => $due]);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === 1 && one('SELECT 1 FROM events WHERE id = :i', ['i' => $due]) === false && one('SELECT 1 FROM events WHERE id = :i', ['i' => $due2]) !== false, 'one record purged for good; the other due one untouched');
$l = activity('records.purge', $since)[0] ?? [];
ok(json_decode($l['after'], true) == ['kind' => 'event', 'count' => 1] && !str_contains($l['after'], 'SMOKE'), 'records.purge logged {kind, count}, no title');

echo "3. An upload's file goes with it\n";
$imp = (int) q("INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, created_by, finished_at) VALUES ('prices_csv', 'SMOKE-p.csv', 'done', :i, :s, 'prices_csv', 26, now()) RETURNING id", ['i' => $a, 's' => $ids['security']])[0]['id'];
app_php('require_once ' . var_export(dirname(__DIR__, 3) . '/app/si/storage.php', true) . '; $s = si_store_bytes("SMOKE purge file"); echo json_encode($s);');
$sha = hash('sha256', 'SMOKE purge file');
$path = 'uploads/' . substr($sha, 0, 2) . '/' . $sha;
q("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('import', :r, 'p.csv', 'text/csv', 16, :h, :p, 26)", ['r' => $imp, 'h' => $sha, 'p' => $path]);
$file = need('SI_DEV_STATE') . '/storage/' . $path;
q("UPDATE imports SET deleted_at = now() - interval '50 days' WHERE id = :i", ['i' => $imp]);
ok(is_file($file), 'the upload\'s kept file is in the store');
$r = post_json($jA, '/admin/purge/run.php', ['kind' => 'all']);
clearstatcache();
ok($r['code'] === 200 && one('SELECT 1 FROM imports WHERE id = :i', ['i' => $imp]) === false && one('SELECT 1 FROM events WHERE id = :i', ['i' => $due2]) === false && !is_file($file) && $r['json']['count'] === 2, 'purge all: the upload, its attachment row and its file, and the other due event (2); nothing else' . ' — ' . json_encode(['code' => $r['code'], 'imp' => one('SELECT 1 FROM imports WHERE id = :i', ['i' => $imp]), 'due2' => one('SELECT 1 FROM events WHERE id = :i', ['i' => $due2]), 'file' => is_file($file), 'count' => $r['json']['count'] ?? null]));
ok((int) one('SELECT count(*) FROM events WHERE title = :t', ['t' => 'SMOKE purge young']) === 1, 'what was restored stays');
$r = post_json($jA, '/admin/purge/run.php', ['kind' => 'all']);
ok($r['code'] === 200 && $r['json']['count'] === 0, 'nothing due: says so');
ok(post_json($jA, '/admin/purge/run.php', ['kind' => 'mars'])['code'] === 422 && post_json($jA, '/admin/purge/run.php', ['kind' => 'all', 'record' => 5])['code'] === 422, 'a bad kind, a record without a kind: 422');

echo "4. The worker\n";
$e1 = $mkevent('SMOKE worker due', 45);
$e2 = $mkevent('SMOKE worker young', 2);
$since = last_activity_id();
$w = worker('tombstones_purge,key_usage_prune', '2026-10-11T10:00:00Z');
ok($w['rc'] === 0 && ($w['report']['ran']['tombstones_purge']['purged'] ?? -1) === 1 && one('SELECT 1 FROM events WHERE id = :i', ['i' => $e1]) === false && one('SELECT 1 FROM events WHERE id = :i', ['i' => $e2]) !== false, 'the tombstones_purge pass purges what is due and only that');
$pl = activity('records.purge', $since)[0] ?? [];
ok($pl !== [] && $pl['source'] === 'cron', 'logged by cron');
$tok = (int) q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (26, 'SMOKE usage', 'h-usage', 'api') RETURNING id")[0]['id'];
q("INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (:t, 'minute', now() - interval '3 days', 4), (:t, 'minute', now(), 1), (:t, 'day', now() - interval '40 days', 9)", ['t' => $tok]);
$w = worker('key_usage_prune', '2026-10-11T10:00:00Z');
ok(($w['report']['ran']['key_usage_prune']['pruned'] ?? -1) === 2 && (int) one('SELECT count(*) FROM key_usage WHERE token_id = :t', ['t' => $tok]) === 1, 'key_usage_prune removes old minute and day buckets (2), keeps today\'s');

echo "5. Everyone's keys and tokens\n";
$k = mint_token_row(26, 'SMOKE Priya key', 'api');
$m = mint_token_row(27, 'SMOKE Marco mcp', 'mcp');
$p = page($jA, '/admin/tokens');
ok($p['code'] === 200 && str_contains($p['body'], 'id="token-row-' . $k . '"') && str_contains($p['body'], 'id="token-row-' . $m . '"') && str_contains($p['body'], 'Priya'), 'the admin lists everyone\'s keys and MCP tokens by person');
$jj = get_json($jA, '/admin/tokens?member=26');
ok(count(array_filter($jj['data']['tokens'], static fn ($t) => $t['member_id'] !== 26)) === 0 && count($jj['data']['tokens']) >= 2 && !str_contains($jj['body'], 'token_hash'), '?member= narrows to one person; no hash');
foreach (['manager' => $jM, 'analyst' => $jP, 'viewer' => $jV] as $w2 => $jar) {
    $bad2[$w2] = page($jar, '/admin/tokens')['code'] === 403 && post_json($jar, '/admin/tokens/revoke.php', ['token' => $k])['code'] === 403;
}
ok(!in_array(false, $bad2, true) && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $k]) === null, 'IR Manager, Analyst, Viewer: 403, nothing revoked');
kernel_state(function ($s) { $s['facts']['973'] = ['valid' => true, 'member_id' => 42, 'run_id' => 973, 'request_id' => 'r973', 'is_eval' => false]; return $s; });
ok(req('POST', '/admin/tokens/revoke.php', ['headers' => as_agent(run_token(42, 973)), 'form' => ['token' => $k]])['code'] === 403, 'an agent: 403 (people only)');
$since = last_activity_id();
$r = post_json($jA, '/admin/tokens/revoke.php', ['token' => $k]);
$l = activity('token.revoke_any', $since)[0] ?? [];
ok($r['code'] === 200 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $k]) !== null && json_decode($l['after'], true) == ['token_id' => $k, 'label' => 'SMOKE Priya key', 'scope' => 'api', 'member_id' => 26], 'a key revoked at once; logged with label and person, no token');
ok(post_json($jA, '/admin/tokens/revoke.php', ['token' => $k])['code'] === 422 && post_json($jA, '/admin/tokens/revoke.php', ['token' => 9999999])['code'] === 404, 'revoking twice: 422; unknown: 404');
$r = post_json($jA, '/admin/tokens/revoke.php', ['token' => $m]);
ok($r['code'] === 200 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $m]) !== null, 'an MCP token too');
$mk = post_json(who(26), '/settings/tokens/key-mint.php', ['label' => 'SMOKE live key']);
$key = (string) ($mk['json']['key'] ?? '');
$kid = (int) ($mk['json']['record_id'] ?? 0);
$call = static fn (): int => req('GET', '/api/v1/issuers', ['headers' => ['Authorization: Bearer ' . $key]])['code'];
ok($key !== '' && $call() === 200, 'a live key works');
post_json($jA, '/admin/tokens/revoke.php', ['token' => $kid]);
ok($call() === 401, 'and after the admin revokes it: 401');

echo "6. Participant classes\n";
$part = (int) one("SELECT id FROM participants WHERE class_key = 'retail' ORDER BY id LIMIT 1");
$p = page($jA, '/admin/participant-classes?class=retail')['body'];
ok(str_contains($p, 'id="class-form"') && str_contains($p, 'id="participant-row-' . $part . '"') && str_contains($p, 'id="class-row-prime"'), 'the classes with counts, the class\'s participants, the picker form');
$since = last_activity_id();
$r = post_json($jA, '/admin/participant-classes/set.php', ['participant' => $part, 'class' => 'prime']);
$row = q('SELECT class_key, class_source FROM participants WHERE id = :i', ['i' => $part])[0];
ok($r['code'] === 200 && $row['class_key'] === 'prime' && $row['class_source'] === 'manual' && count(activity('participant.class_set', $since)) === 1, 'reclassed: class prime, source manual, participant.class_set logged');
q("UPDATE participants SET name = name WHERE id = :i", ['i' => $part]);
ok(post_json($jA, '/admin/participant-classes/set.php', ['participant' => $part, 'class' => 'mars'])['code'] === 422 && post_json($jA, '/admin/participant-classes/set.php', ['participant' => 999999, 'class' => 'prime'])['code'] === 404, 'a class off the list: 422; a participant not there: 404');
$j = get_json($jA, '/admin/participant-classes');
ok(count($j['data']['classes']) === 8 && array_sum(array_column($j['data']['classes'], 'participants')) === (int) one('SELECT count(*) FROM participants'), 'JSON: the eight classes with their counts');
ok(post_json($jM, '/admin/participant-classes/set.php', ['participant' => $part, 'class' => 'retail'])['code'] === 200 && post_json($jP, '/admin/participant-classes/set.php', ['participant' => $part, 'class' => 'lending'])['code'] === 403, 'an IR Manager (issuers.manage) may; an Analyst may not');

echo "7. Templates\n";
$p = page($jA, '/admin/templates');
ok($p['code'] === 200 && str_contains($p['body'], 'id="templates-kind-register"') && str_contains($p['body'], 'id="templates-kind-spr"'), 'the templates by kind');
$ta = (int) one("SELECT id FROM import_templates WHERE connector = 'ta_extract_csv'");
ok(!str_contains($p['body'], 'id="template-row-' . $ta . '-delete-btn"'), 'the extract\'s template has no Delete');
$since = last_activity_id();
$map = ['header_row' => 1, 'columns' => ['registration_name' => 'A', 'shares' => 'B']];
$r = post_json($jA, '/admin/templates/save.php', ['name' => 'SMOKE mapping', 'kind' => 'holders_csv', 'connector' => 'generic_mapped', 'mapping' => json_encode($map)]);
$t = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $t > 0 && json_decode((string) one('SELECT mapping FROM import_templates WHERE id = :i', ['i' => $t]), true) == $map && count(activity('template.save', $since)) === 1, 'a template created; template.save logged');
$e = page($jA, '/admin/templates/' . $t . '/edit')['body'];
ok(str_contains($e, 'id="template-sample-table"') && str_contains($e, 'registration_name'), 'the editor shows what the mapping reads beside it');
$map['columns']['holder_ref'] = 'C';
$r = post_json($jA, '/admin/templates/save.php', ['template' => $t, 'mapping' => json_encode($map)]);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $t && isset(json_decode((string) one('SELECT mapping FROM import_templates WHERE id = :i', ['i' => $t]), true)['columns']['holder_ref']), 'edited in place (a field left out stays)');
$bad = [];
foreach ([['mapping' => '{nope'], ['mapping' => '[1]'], ['kind' => 'mars'], ['name' => ''], ['connector' => 'Bad Name']] as $c) {
    $bad[] = post_json($jA, '/admin/templates/save.php', ['template' => $t] + $c)['code'] === 422;
}
ok(!in_array(false, $bad, true), 'bad JSON, a non-object, a kind off the list, no name, a bad connector: 422');
$ex = json_decode((string) one('SELECT mapping FROM import_templates WHERE id = :i', ['i' => $ta]), true);
$ex['drop_columns'] = ['TAX-ID'];
$r = post_json($jA, '/admin/templates/save.php', ['template' => $ta, 'mapping' => json_encode($ex)]);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'never stores'), 'the extract\'s drop_columns cannot lose a never-stored column');
$ex['drop_columns'] = array_merge(json_decode((string) one('SELECT mapping -> \'drop_columns\' FROM import_templates WHERE id = :i', ['i' => $ta]), true), ['CUSTOM COLUMN']);
ok(post_json($jA, '/admin/templates/save.php', ['template' => $ta, 'mapping' => json_encode($ex)])['code'] === 200, 'but may grow');
$csv = "ACCT#,HOLDER REG 1,TOTAL SHARES,DATE OF BIRTH,CUSTOM COLUMN,PHONE NUMBER\n=\"1\",SMOKE X,5,01/01/1970,SECRET,555\n";
$kept = app_php('require_once ' . var_export(dirname(__DIR__, 3) . '/app/si/storage.php', true) . '; require_once ' . var_export(dirname(__DIR__, 3) . '/app/features/uploads/load.php', true) . '; echo json_encode(upload_bytes_to_store($pdo, "ta_extract_csv", ' . var_export($csv, true) . '));');
ok(is_string($kept) && !str_contains($kept, '1970') && !str_contains($kept, 'SECRET') && !str_contains($kept, '555') && str_contains($kept, 'SMOKE X'), 'the extract is stored without the personal columns (and the one the admin added)');
ok(post_json($jA, '/admin/templates/delete.php', ['template' => $ta])['code'] === 422, 'deleting the extract\'s template: 422');
$since = last_activity_id();
$r = post_json($jA, '/admin/templates/delete.php', ['template' => $t]);
ok($r['code'] === 200 && one('SELECT 1 FROM import_templates WHERE id = :i', ['i' => $t]) === false && count(activity('template.delete', $since)) === 1, 'a template deleted, logged');
kernel_state(function ($s) { $s['facts']['974'] = ['valid' => true, 'member_id' => 42, 'run_id' => 974, 'request_id' => 'r974', 'is_eval' => false]; return $s; });
$r = req('POST', '/admin/templates/delete.php', ['headers' => as_agent(run_token(42, 974)), 'form' => ['template' => $ta]]);
ok($r['code'] === 403, 'an agent holds no settings.manage: 403');
foreach (['manager' => $jM, 'analyst' => $jP] as $w3 => $jar) { $b3[$w3] = page($jar, '/admin/templates')['code'] === 403; }
ok(!in_array(false, $b3, true), 'IR Manager and Analyst: 403 on the templates');
finish();
