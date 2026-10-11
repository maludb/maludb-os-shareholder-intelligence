<?php
/**
 * Helpers for slice 9's proofs (docs/build-specs/agents-k7-api.md "Proof"): slice 8's library (sign-on, JSON posts, the worker, an agent's run token, the fake MaluMail's log) and Phase 2's SMOKE store, plus
 * the fake kernel's logs (the chat turns, the texts), a key minted through the real handler, the API's calls, and this slice's seed. The members of bin/dev_directory.json: 1 the owner (admin), 26 Priya (Analyst),
 * 27 Marco (IR Manager), 28 Bea (Analyst by capability), 29 Ann (a Viewer, EXTERNAL), 30 Dana (on Beta's list), 31 Lee (admitted, no role), 40 the Expert, 42 the Surveillance Analyst (agents).
 */
require dirname(__DIR__) . '/slice8/lib.php';

/**
 * The SMOKE store (Phase 2's seed_store: SMOKE Alpha Corp with prices, an SPR, an open high signal, two holders; Beta restricted to Dana) and this slice's rows, once a database: holders of kind unknown
 * (an LLC, a Trust, a plain name), a register row whose registration is Frank's, two filings (an 8-K and a Schedule 13D — the trigger dispatches the Analyst), the 13D's text, and a holder and a
 * signal on Beta.
 */
function seed9(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $ids = seed_store();
    $a = $ids['alpha'];
    $sec = $ids['security'];
    pdo()->exec("SELECT set_config('app.member_id', '27', false)");
    if (one("SELECT id FROM holders WHERE display_name = 'SMOKE Orion Capital LLC'") === false) {
        foreach (['SMOKE Orion Capital LLC', 'SMOKE Harbor Trust', 'SMOKE Beacon Inc.', 'SMOKE Plain Holder'] as $n) {
            q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, :n, si_name_key(:n2), 'unknown')", ['i' => $a, 'n' => $n, 'n2' => $n]);
        }
        $imp = (int) q("INSERT INTO imports (kind, file_name, status, issuer_id, security_id, connector, record_date, tied, created_by, finished_at) VALUES ('register', 'SMOKE-register.xlsx', 'done', :i, :s, 'stc_position_detail', CURRENT_DATE - 5, true, 26, now()) RETURNING id", ['i' => $a, 's' => $sec])[0]['id'];
        q("INSERT INTO register_rows (import_id, security_id, record_date, row_no, registration_name, address_line, city, state, zip, shares) VALUES (:im, :s, CURRENT_DATE - 5, 1, 'SMOKE FRANK PERSON JR', '1 Elm Street', 'Springfield', 'IL', '62701', 1200)", ['im' => $imp, 's' => $sec]);
        q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, primary_document, primary_doc_description) VALUES (:i, '0000999001', '0000999001-24-000101', '8-K', CURRENT_DATE - 6, 'k8.htm', 'Current report')", ['i' => $a]);
        q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, primary_document, primary_doc_description) VALUES (:i, '0000999777', '0000999777-24-000007', 'SC 13D', CURRENT_DATE - 2, 'd13.htm', 'Schedule 13D')", ['i' => $a]);
        $f = (int) one("SELECT id FROM filings WHERE accession_number = '0000999777-24-000007'");
        q("INSERT INTO filing_text (filing_id, extractor, text) VALUES (:f, 'html', 'SMOKE: a Schedule 13D. The filer holds 6.1 percent of the class and intends to engage with the board.')", ['f' => $f]);
        q('UPDATE filings SET fetched_text_at = now(), char_count = 97 WHERE id = :f', ['f' => $f]);
        $b = $ids['beta'];
        q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, 'SMOKE Beta Holder LLC', si_name_key('SMOKE Beta Holder LLC'), 'unknown')", ['i' => $b]);
    }
    $ids['holder_orion'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Orion Capital LLC'");
    $ids['holder_harbor'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Harbor Trust'");
    $ids['holder_beacon'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Beacon Inc.'");
    $ids['holder_plain'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Plain Holder'");
    $ids['holder_frank'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Frank Person'");
    $ids['holder_zeta'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Zeta Holdings LLC'");
    $ids['holder_beta'] = (int) one("SELECT id FROM holders WHERE display_name = 'SMOKE Beta Holder LLC'");
    $ids['register_row'] = (int) one("SELECT id FROM register_rows WHERE registration_name = 'SMOKE FRANK PERSON JR'");
    $ids['filing_13d'] = (int) one("SELECT id FROM filings WHERE accession_number = '0000999777-24-000007'");
    $ids['filing_8k'] = (int) one("SELECT id FROM filings WHERE accession_number = '0000999001-24-000101'");
    $ids['signal'] = (int) one("SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = 'si_over_float' AND s.security_id = :s", ['s' => $sec]);
    $ids['ev_answer'] = (int) one("SELECT id FROM events WHERE title = 'SMOKE: the company answers the report'");
    return $ids;
}

/** An open signal of another rule on the SMOKE security (the trigger dispatches the Analyst for a high one). Answers its id. */
function new_signal(int $security, string $rule, string $severity = 'high'): int
{
    $issuer = (int) one('SELECT issuer_id FROM securities WHERE id = :s', ['s' => $security]);
    pdo()->exec("SELECT set_config('app.member_id', '27', false)");
    return (int) q("INSERT INTO signals (rule_id, issuer_id, security_id, raised_on, window_from, window_to, severity, value, threshold, summary, expires_on, evidence)
                    SELECT r.id, :i, :s, CURRENT_DATE, CURRENT_DATE - 14, CURRENT_DATE, :sev, 1, 1, :sum, CURRENT_DATE + 20, '{}'::jsonb FROM signal_rules r WHERE r.key = :k RETURNING id",
        ['i' => $issuer, 's' => $security, 'sev' => $severity, 'sum' => 'SMOKE: ' . $rule . ' fired', 'k' => $rule])[0]['id'];
}

/** The dispatch of a signal (the newest), or []. */
function dispatch_of_signal(int $signal): array
{
    return q('SELECT * FROM agent_dispatches WHERE signal_id = :s ORDER BY id DESC LIMIT 1', ['s' => $signal])[0] ?? [];
}

/** The fake kernel's chat log since it was emptied: [{agent, acting, utterance, context}]. */
function chat_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.chat';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($f))))) : [];
}
function chat_reset(): void { file_put_contents(need('FAKE_KERNEL_STATE') . '.chat', ''); }
/** The fake kernel's text log: [{member_id, text, reference, accepted, mode}]. */
function sms_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.sms';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($f))))) : [];
}
function sms_reset(): void { file_put_contents(need('FAKE_KERNEL_STATE') . '.sms', ''); }

/** The fields of the first form on a page whose action is $action (and whose markup holds $idPrefix): its hidden inputs. */
function form_of(string $html, string $action, string $idPrefix = ''): ?array
{
    if (!preg_match_all('~<form[^>]*action="' . preg_quote($action, '~') . '"[^>]*>(.*?)</form>~s', $html, $m, PREG_SET_ORDER)) {
        return null;
    }
    foreach ($m as $f) {
        if ($idPrefix !== '' && !str_contains($f[1], $idPrefix)) { continue; }
        preg_match_all('~<input type="hidden" name="([a-z_]+)" value="([^"]*)"~', $f[1], $h, PREG_SET_ORDER);
        $fields = [];
        foreach ($h as [, $k, $v]) { $fields[$k] = html_entity_decode($v, ENT_QUOTES); }
        return $fields;
    }
    return null;
}

/** A key minted through the real handler as a signed-in person (or by an admin for another: $for). Answers ['key' => raw, 'id' => the key's id]. */
function mint_key(string $jar, string $label, ?int $for = null): array
{
    $r = post_json($jar, '/settings/tokens/key-mint.php', ['label' => $label] + ($for === null ? [] : ['member' => $for]));
    return ['key' => (string) ($r['json']['key'] ?? ''), 'id' => (int) ($r['json']['record_id'] ?? 0), 'code' => $r['code'], 'json' => $r['json']];
}

/** One call to the records API with a key. $o: method, json, form, headers, file (a path → multipart). */
function api(string $path, ?string $key, array $o = []): array
{
    $h = $o['headers'] ?? [];
    if ($key !== null) { $h[] = 'Authorization: Bearer ' . $key; }
    if (isset($o['file'])) {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => ($o['form'] ?? []) + ['file' => new CURLFile($o['file'], 'application/octet-stream', basename($o['file']))]]);
        $r = (string) curl_exec($ch);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $out = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
    } else {
        $out = req($o['method'] ?? (isset($o['json']) || isset($o['form']) ? 'POST' : 'GET'), $path, ['headers' => $h] + array_intersect_key($o, ['json' => 1, 'form' => 1]));
    }
    $out['json'] = json_decode($out['body'], true) ?: [];
    return $out;
}

/** A DTC weekly SPR CSV for the SMOKE security (the fixture fortnight's first week, slice 2's layout): [name, bytes]. */
function week_csv(string $cusip): array
{
    $by = [];
    $f = fopen(dirname(__DIR__, 2) . '/fixtures/spr_rows.csv', 'r');
    fgetcsv($f);
    while (($r = fgetcsv($f)) !== false) { $by[$r[1]]['name'] = $r[2]; $by[$r[1]]['d'][$r[3]] = $r[4]; }
    fclose($f);
    $w = ['2024-03-22', '2024-03-25', '2024-03-26', '2024-03-27', '2024-03-28', '2024-03-29'];
    $fmt = static fn (string $d): string => date('m/d/Y', strtotime($d));
    $l = ['', ',,,The Depository Trust Company', ',,,Weekly Subscription Security Position Report', ',,,Daily Closing Balances', ',,,For Week Ending ' . $fmt(end($w)), '', 'Security Description,Cusip', 'SMOKE ALPHA CORP COM,' . $cusip,
          'Participant,Participant,Opening,Monday,Tuesday,Wednesday,Thursday,Friday,Share,Percent', 'Number,Name,' . implode(',', array_map($fmt, $w)) . ',Change,Change'];
    $sum = array_fill(0, 6, 0);
    foreach ($by as $n => $p) {
        $bal = array_map(static fn ($d) => $p['d'][$d] ?? '-9999999999999', $w);
        $l[] = $n . ',"' . $p['name'] . '",' . implode(',', $bal) . ',0,0.0';
        foreach ($bal as $k => $v) { if ($v !== '-9999999999999') { $sum[$k] += (int) $v; } }
    }
    $l[] = 'Total,,' . implode(',', array_map('strval', $sum)) . ',0,0.0';
    return ['SPR_' . date('m-d-Y', strtotime(end($w))) . '_' . $cusip . '.csv', implode("\n", $l) . "\n"];
}

/** Wait for a quiet part of the clock minute (a rate bucket is a calendar minute), so a count of calls never straddles two. */
function steady_minute(int $seconds = 5): void
{
    while ((int) date('s') > 59 - $seconds - 4) { usleep(250000); }
}
