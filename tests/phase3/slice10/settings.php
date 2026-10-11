<?php
/**
 * Proof: the settings (admin-settings.md): the form by groups with the red banner while the EDGAR contact is empty; saving the business name and contact removes the banner, makes the user-agent
 * and an EDGAR pull then runs (and before it, sends nothing); every field validated in words; the changed keys logged (never the contact's address); lowering the EDGAR rate lowers the sources above it;
 * the holidays reach the market-day calendar; the escalation member; a Viewer, an Analyst and an IR Manager refused; an agent refused; the JSON.
 */
require __DIR__ . '/lib.php';
$sm = seed_short();
$jA = who(1);
$jM = who(27);
$jP = who(26);
$jV = who(29);
$edgar = source_id('edgar_submissions');
$set = static fn (array $f): array => post_json($GLOBALS['jA'], '/admin/settings/save.php', $f);
$row = static fn (): array => q('SELECT * FROM si_settings WHERE id = 1')[0];

echo "1. The form, the walls, the banner\n";
$p = page($jA, '/admin/settings');
ok($p['code'] === 200 && str_contains($p['body'], 'id="settings-form"') && str_contains($p['body'], 'id="ua-banner"'), 'an admin sees the form and the red banner (the contact is empty)');
$groups = ['settings-edgar', 'settings-market', 'settings-fetch', 'settings-rules', 'settings-people', 'settings-keys'];
ok(array_filter($groups, static fn ($g) => !str_contains($p['body'], 'id="' . $g . '"')) === [], 'grouped: the business and EDGAR contact; the exchange, time zones and holidays; the fetch rates; the tolerances; names in exports and the escalation member; keys, uploads and retention');
$fields = ['business_name', 'edgar_contact_email', 'default_exchange', 'market_timezone', 'timezone', 'market_holidays', 'edgar_rate_per_second', 'price_source_default', 'reconciliation_tolerance_shares', 'auto_match_threshold', 'signal_grace_days',
           'pii_in_exports', 'escalate_high_to', 'key_rate_per_minute', 'key_rate_per_day', 'max_upload_mb', 'tombstone_days', 'retain_raw_rows_days'];
ok(array_filter($fields, static fn ($f) => !str_contains($p['body'], 'name="' . $f . '"')) === [], 'every setting has its field (' . count($fields) . ')');
ok(str_contains($p['body'], 'id="settings-form-escalate-open"') || str_contains($p['body'], 'record-picker'), 'the escalation member is chosen through the record picker');
ok(str_contains($p['body'], 'value="Kinetic Seas"') && preg_match('~id="settings-form-max-upload-mb"[^>]*value="50"~', $p['body']) === 1, 'the form shows the stored values (the business name; 50 MB)');
$bad = [];
foreach (['manager' => $jM, 'analyst' => $jP, 'viewer' => $jV] as $who => $jar) {
    if (page($jar, '/admin/settings')['code'] !== 403 || post_json($jar, '/admin/settings/save.php', ['business_name' => 'X'])['code'] !== 403) { $bad[] = $who; }
}
ok($bad === [] && $row()['business_name'] === 'Kinetic Seas', 'an IR Manager, an Analyst and a Viewer: 403 on the screen and the action, nothing changed');
kernel_state(function ($s) { $s['facts']['972'] = ['valid' => true, 'member_id' => 42, 'run_id' => 972, 'request_id' => 'req-972', 'is_eval' => false]; return $s; });
$r = req('POST', '/admin/settings/save.php', ['headers' => as_agent(run_token(42, 972)), 'form' => ['business_name' => 'Agent Co']]);
ok($r['code'] === 403 && $row()['business_name'] === 'Kinetic Seas', 'an agent: 403 (a person\'s action)');
$h = json_decode(req('GET', '/api/v1/health')['body'], true) ?: [];
ok(($h['edgar_user_agent'] ?? '') === 'missing', 'health says the EDGAR user-agent is missing');

echo "2. The contact: the banner goes, EDGAR runs\n";
stub_reset();
$w = fetch_only('edgar_submissions', MARKET_MORNING);
$pull = last_pull('edgar_submissions');
ok($pull !== [] && $pull['status'] === 'failed' && str_contains((string) $pull['error'], 'contact') && stub_requests() === [], 'with no contact the scheduled pull fails before any request, saying why');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'source_failed' AND title = 'EDGAR is waiting for the contact email'") >= 1, 'and the keepers of the sources are told once');
$since = last_activity_id();
$r = $set(['business_name' => 'Kinetic Seas', 'edgar_contact_email' => 'claude@kineticseas.com']);
ok($r['code'] === 200 && (int) ($r['json']['record_id'] ?? 0) === 1 && $row()['edgar_contact_email'] === 'claude@kineticseas.com', 'the contact saved (record_id 1, the settings)');
$a = activity('settings.save', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
ok(array_keys($aj) === ['edgar_contact_email'] && $aj['edgar_contact_email'] === '(changed)' && !str_contains((string) $a['after'] . (string) $a['before'], 'kineticseas'), 'settings.save logged the changed key, not the address');
$p = page($jA, '/admin/settings')['body'];
ok(!str_contains($p, 'id="ua-banner"') && str_contains($p, 'Kinetic Seas claude@kineticseas.com'), 'the banner is gone and the user-agent is shown');
ok(!str_contains(page($jA, '/admin/sources')['body'], 'id="ua-banner"'), 'gone from the sources too');
$h = json_decode(req('GET', '/api/v1/health')['body'], true) ?: [];
ok(($h['edgar_user_agent'] ?? '') === 'set', 'health says set');
q('UPDATE sources SET backoff_until = NULL, consecutive_failures = 0 WHERE id = :i', ['i' => $edgar]);
stub_reset();
source_due('edgar_submissions');
fetch_only('edgar_submissions', MARKET_MORNING);
$pull = last_pull('edgar_submissions');
$reqs = stub_requests();
ok(in_array($pull['status'], ['ok', 'partial'], true) && count($reqs) > 0 && $reqs[0]['ua'] === 'Kinetic Seas claude@kineticseas.com', 'an EDGAR pull now runs, declaring "Kinetic Seas claude@kineticseas.com" (' . count($reqs) . ' requests)');
$since = last_activity_id();
$r = $set(['business_name' => 'Kinetic Seas', 'edgar_contact_email' => 'claude@kineticseas.com']);
ok($r['code'] === 200 && activity('settings.save', $since) === [] && str_contains($r['json']['did'] ?? '', 'Nothing changed'), 'saving the same values changes and logs nothing');

echo "3. Every field is checked, in words\n";
$cases = ['business_name' => str_repeat('b', 121), 'edgar_contact_email' => 'not-an-email', 'default_exchange' => 'mars', 'market_timezone' => 'Mars/Olympus', 'timezone' => 'nowhere', 'market_holidays' => '2024-02-30',
          'edgar_rate_per_second' => '11', 'reconciliation_tolerance_shares' => '-1', 'auto_match_threshold' => '0.2', 'pii_in_exports' => 'everyone', 'escalate_high_to' => '42', 'signal_grace_days' => '400',
          'tombstone_days' => '0', 'retain_raw_rows_days' => '10', 'key_rate_per_minute' => '0', 'key_rate_per_day' => '99999999999', 'max_upload_mb' => '5000', 'price_source_default' => 'prices_nasdaq'];
$bad = [];
foreach ($cases as $f => $v) {
    $r = $set([$f => $v]);
    $key = $f === 'max_upload_mb' ? 'max_upload_mb' : $f;
    if ($r['code'] !== 422 || !isset($r['json']['error']['fields'][$key])) { $bad[] = $f . ' ' . $r['code']; }
}
ok($bad === [], count($cases) . ' bad values (a long name, a bad email, an unknown exchange and zone, a bad date, an EDGAR rate of 11, a negative tolerance, a threshold of 0.2, an agent as the escalation member, …): 422 on the field' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$r = $set(['edgar_rate_per_second' => '11', 'tombstone_days' => '0']);
ok($r['code'] === 422 && count($r['json']['error']['fields'] ?? []) === 2, 'two bad fields: both named in one refusal');
ok($row()['tombstone_days'] === 30 && (float) $row()['edgar_rate_per_second'] === 2.0, 'and nothing was saved');

echo "4. Saving the rest\n";
$since = last_activity_id();
$r = $set(['default_exchange' => 'nyse', 'market_timezone' => 'America/Chicago', 'timezone' => 'America/Chicago', 'market_holidays' => "2024-04-08\n2024-04-09, 2024-04-08", 'reconciliation_tolerance_shares' => '500', 'auto_match_threshold' => '0.9',
    'pii_in_exports' => 'admin', 'escalate_high_to' => '27', 'signal_grace_days' => '5', 'tombstone_days' => '45', 'retain_raw_rows_days' => '365', 'key_rate_per_minute' => '30', 'key_rate_per_day' => '1000', 'max_upload_mb' => '20',
    'price_source_default' => 'prices_polygon']);
$s = $row();
ok($r['code'] === 200 && $s['default_exchange'] === 'nyse' && $s['market_timezone'] === 'America/Chicago' && $s['timezone'] === 'America/Chicago' && $s['market_holidays'] === '{2024-04-08,2024-04-09}'
    && (int) $s['reconciliation_tolerance_shares'] === 500 && (float) $s['auto_match_threshold'] === 0.9 && $s['pii_in_exports'] === 'admin' && (int) $s['escalate_high_to'] === 27 && (int) $s['signal_grace_days'] === 5
    && (int) $s['tombstone_days'] === 45 && (int) $s['retain_raw_rows_days'] === 365 && (int) $s['key_rate_per_minute'] === 30 && (int) $s['key_rate_per_day'] === 1000 && (int) $s['max_upload_bytes'] === 20971520
    && $s['price_source_default'] === 'prices_polygon', 'every field saved (the holidays de-duplicated and sorted; 20 MB is 20,971,520 bytes)');
$a = activity('settings.save', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
$bj = json_decode($a['before'] ?? '{}', true);
ok(count($aj) === 15 && $aj['tombstone_days'] === 45 && $bj['tombstone_days'] === 30 && $aj['market_holidays'] === ['2024-04-08', '2024-04-09'], 'one settings.save logged the fifteen changed keys, before and after');
ok(one('SELECT si_is_market_day(CAST(:d AS date))', ['d' => '2024-04-08']) === false && one('SELECT si_is_market_day(CAST(:d AS date))', ['d' => '2024-04-10']) === true, 'the holidays reach the market-day calendar: 8 April is not a market day, 10 April is');
pdo()->exec("SELECT set_config('app.member_id', '1', false)");
ok((int) one('SELECT key_rate_per_minute FROM mcp_settings') === 30 && (int) one('SELECT tombstone_days FROM mcp_settings') === 45, 'and the read view the agents use');
$r = $set(['escalate_high_to' => '', 'retain_raw_rows_days' => '']);
$s = $row();
ok($r['code'] === 200 && $s['escalate_high_to'] === null && $s['retain_raw_rows_days'] === null, 'a blank escalation member and a blank retention are "none" and "forever"');
$r = $set(['max_upload_bytes' => '2097152']);
ok($r['code'] === 200 && (int) $row()['max_upload_bytes'] === 2097152, 'the upload ceiling may be given in bytes (an agent\'s way)');

echo "5. Lowering the EDGAR rate\n";
q("UPDATE sources SET rate_per_second = 2 WHERE connector IN ('edgar_submissions', 'edgar_fulltext')");
q("UPDATE sources SET rate_per_second = 1 WHERE connector = 'edgar_13f_dataset'");
$since = last_activity_id();
$r = $set(['edgar_rate_per_second' => '1.5']);
ok($r['code'] === 200 && (float) one("SELECT rate_per_second FROM sources WHERE connector = 'edgar_submissions'") === 1.5 && (float) one("SELECT rate_per_second FROM sources WHERE connector = 'edgar_13f_dataset'") === 1.0
    && (float) one("SELECT rate_per_second FROM sources WHERE connector = 'dtcc_participants'") === 10.0 && (int) ($r['json']['sources_lowered'] ?? 0) >= 5, 'the EDGAR sources above 1.5 a second were lowered to it (' . ($r['json']['sources_lowered'] ?? 0) . '); the 13F set at 1 and DTCC are untouched');
$a = activity('settings.save', $since)[0] ?? [];
ok(json_decode($a['after'], true)['edgar_sources_lowered'] >= 5, 'settings.save says how many');
$set(['edgar_rate_per_second' => '2']);
ok((float) one("SELECT rate_per_second FROM sources WHERE connector = 'edgar_submissions'") === 1.5, 'raising the ceiling raises nothing');

echo "6. JSON\n";
$j = get_json($jA, '/admin/settings');
$d = $j['data']['settings'] ?? [];
ok($j['code'] === 200 && $d['business_name'] === 'Kinetic Seas' && $d['edgar_user_agent'] === 'Kinetic Seas claude@kineticseas.com' && $d['edgar_user_agent_set'] === true && $d['tombstone_days'] === 45 && $d['market_holidays'] === ['2024-04-08', '2024-04-09']
    && is_float($d['auto_match_threshold']) && array_key_exists('escalate_high_to', $d) && isset($j['data']['options']['exchanges']['nyse']), 'JSON: the settings with the user-agent they make, and the options');
ok(!array_key_exists('storage_dir', $d) && !str_contains($j['body'], 'SI_SECRETS_KEY'), 'a whitelist: no storage path, no key');
ok(get_json($jM, '/admin/settings')['code'] === 403, 'an IR Manager: 403 in JSON too');
finish();
