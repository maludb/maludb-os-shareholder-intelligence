<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md "Proof"). Run them through tests/phase2/run.sh, which builds the
 * scratch database, starts the application (:8901), a fake kernel (:8902) and a fake MaluDB + MaluMail (:8903) and puts the scratch
 * environment in the process environment. Every proof prints "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, SI admin), 26 Priya (Analyst), 27 Marco (IR Manager + Analyst), 28 Bea
 * (no roles sent, capability write = an Analyst), 29 Ann (external, Viewer), 30 Dana (Analyst, Finance), 31 Lee (capability write),
 * 40 the Expert (an agent, admitted), 41 the Watcher (an agent, not admitted), 42 the Surveillance Analyst (an agent).
 */
chdir(dirname(__DIR__, 2));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
const BASE = 'http://127.0.0.1:8901';
const APP = 'shareholder_intelligence';
const LAUNCHER = 'https://app.example.invalid/launcher?app=shareholder_intelligence';
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase2/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 2) . '/bin/dev_directory.json'), true); }
/** si_has_right() as the database answers it for a member. */
function right_of(int $member, string $right): bool { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return (bool) one('SELECT si_has_right(:r)', ['r' => $right]); }

/** One request. $o: jar, headers[], form[], json[], raw. Returns code, headers (string), body, and the Location. */
function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'sijar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]);
}
/** Sign a member on with a fresh jar; returns [jar, response]. */
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/', ['jar' => $jar])['body']); }
function badge_of(string $jar): string { preg_match('/id="header-role-badge">([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
/** A person's action token and an agent's run token with its relay, as the kernel mints them. */
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }

/** The fake kernel's state file: read, change, write. */
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/**
 * The proofs' SMOKE data (idempotent), written as the application role: three issuers — SMOKE Alpha Corp (SMKA: a primary security with
 * share counts, sixty days of prices, a tied SPR upload with three participants' positions, short interest, two past events and one coming,
 * an open high signal, a natural-person holder and an entity holder in the search index), SMOKE Beta Holdings (SMKB, restricted to Dana) and
 * SMOKE Gamma Inc (SMKG, no security yet) — and a morning note to Priya. Returns the ids.
 */
function seed_store(): array
{
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '27', false)");
    $issuer = static function (string $name, string $ticker, string $cik) use ($p): int {
        $id = one('SELECT id FROM issuers WHERE ticker = :t', ['t' => $ticker]);
        if ($id !== false) { return (int) $id; }
        return (int) q("INSERT INTO issuers (name, ticker, cik, exchange) VALUES (:n, :t, :c, 'nasdaq') RETURNING id", ['n' => $name, 't' => $ticker, 'c' => $cik])[0]['id'];
    };
    $a = $issuer('SMOKE Alpha Corp', 'SMKA', '0000999001');
    $b = $issuer('SMOKE Beta Holdings', 'SMKB', '0000999002');
    $g = $issuer('SMOKE Gamma Inc', 'SMKG', '0000999003');
    $p->exec("INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES ($b, 30, 27) ON CONFLICT DO NOTHING");
    $sec = one('SELECT id FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $a]);
    if ($sec === false) {
        $sec = q("INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (:i, 'common', 'Common stock', '99900A101', 'SMKA', true) RETURNING id", ['i' => $a])[0]['id'];
        $p->exec("INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES ($b, 'common', 'Common stock', '99900B101', 'SMKB', true)");
        $p->exec("INSERT INTO share_counts (security_id, as_of, kind, value, source) VALUES ($sec, CURRENT_DATE - 30, 'outstanding', 20000000, 'filing'), ($sec, CURRENT_DATE - 30, 'float', 15000000, 'transfer_agent')");
        $p->exec("INSERT INTO prices (security_id, trade_date, close, volume) SELECT $sec, d::date, round((4 + sin(extract(doy FROM d) / 6.0))::numeric, 2), 100000 + extract(doy FROM d)::int * 100
                    FROM generate_series(CURRENT_DATE - 60, CURRENT_DATE - 1, interval '1 day') d WHERE extract(isodow FROM d) < 6");
        $imp = q("INSERT INTO imports (kind, connector, file_name, issuer_id, security_id, record_date, status, rows_made, tied, tie_note, created_by, finished_at)
                  VALUES ('spr', 'dtc_spr_csv', 'SMOKE-SPR.csv', :i, :s, CURRENT_DATE - 3, 'done', 3, true, 'ties to its Total row', 26, now()) RETURNING id", ['i' => $a, 's' => $sec])[0]['id'];
        $p->exec("INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id)
                  SELECT $sec, x.id, CURRENT_DATE - 3, x.shares, $imp FROM (
                      (SELECT id, 6000000 AS shares FROM participants WHERE class_key = 'retail' ORDER BY number LIMIT 1) UNION ALL
                      (SELECT id, 3000000 FROM participants WHERE class_key = 'prime' ORDER BY number LIMIT 1) UNION ALL
                      (SELECT id, 1000000 FROM participants WHERE class_key = 'market_maker' ORDER BY number LIMIT 1)) x");
        $p->exec("INSERT INTO short_interest (security_id, settlement_date, short_shares, avg_daily_volume, days_to_cover) VALUES ($sec, CURRENT_DATE - 15, 3000000, 150000, 20)");
        $p->exec("INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity, source, created_by) VALUES
                  ($a, $sec, CURRENT_DATE - 20, 'activist_short_report', 'SMOKE: a short report is published', 'high', 'person', 27),
                  ($a, $sec, CURRENT_DATE - 8, 'press', 'SMOKE: the company answers the report', 'info', 'person', 27),
                  ($a, $sec, CURRENT_DATE + 10, 'record_date', 'SMOKE: the record date for the annual meeting', 'watch', 'person', 27)");
        $p->exec("INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, value, threshold, summary, expires_on, evidence)
                  SELECT r.id, $a, $sec, CURRENT_DATE - 1, CURRENT_DATE - 15, CURRENT_DATE - 1, 'high', 20, 20, 'SMOKE: short interest is 20.0% of the float', CURRENT_DATE + 20, '{}'::jsonb
                    FROM signal_rules r WHERE r.key = 'si_over_float'");
        $p->exec("INSERT INTO holders (issuer_id, display_name, name_key, kind, city, state) VALUES
                  ($a, 'SMOKE Frank Person', si_name_key('SMOKE Frank Person'), 'person', 'Springfield', 'IL'),
                  ($a, 'SMOKE Zeta Holdings LLC', si_name_key('SMOKE Zeta Holdings LLC'), 'entity', 'Wilmington', 'DE')");
        one('SELECT si_search_catch_up(1000)');
        $p->exec("INSERT INTO notifications (member_id, kind, title, body, issuer_id) VALUES (26, 'morning_note', 'SMOKE: the morning note', 'One high signal is open on SMKA: short interest is 20% of the float.', $a)");
    }
    return ['alpha' => $a, 'beta' => $b, 'gamma' => $g, 'security' => (int) $sec];
}
