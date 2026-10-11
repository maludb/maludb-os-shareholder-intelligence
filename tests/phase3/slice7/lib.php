<?php
/**
 * Helpers for slice 7's proofs (docs/build-specs/signals-rules.md "Proof"): slice 4's library (sign-on, the worker, q, a JSON post), the crafted
 * fixtures (seed.sql: the SMOKE issuer, CUSIP 07831X206, and a second issuer, as of the rules' day 2024-04-05 — a Friday; Good Friday 2024-03-29 is
 * the holiday run.sh sets), a run of the rules as a person, and the signal of a rule.
 */
require dirname(__DIR__) . '/slice4/lib.php';
const AS_OF = '2024-04-05';
const MORNING = '2024-04-05T07:00:00-04:00';        // 07:00 New York on a market day: the 06:45 run is due
const HOLIDAY = '2024-03-29T07:00:00-04:00';        // Good Friday: no 06:45 run

/** The crafted fixtures (once a database): ['issuer', 'security', 'issuer2', 'security2', 'ev_short', 'eloc']. */
function seed7(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && sudo -n -u postgres psql -v ON_ERROR_STOP=1 -At -F "|" -d ' . escapeshellarg(need('SI_DEV_DB')) . ' -f tests/phase3/slice7/seed.sql 2>&1');
    $line = '';
    foreach (explode("\n", trim($out)) as $l) { if (str_starts_with($l, 'seeded|')) { $line = $l; } }
    if ($line === '') { fwrite(STDERR, "the seed failed:\n" . $out . "\n"); exit(1); }
    $v = explode('|', $line);
    return $ids = ['issuer' => (int) $v[1], 'security' => (int) $v[2], 'issuer2' => (int) $v[3], 'security2' => (int) $v[4], 'ev_short' => (int) $v[5], 'eloc' => (int) $v[6]];
}

/** The rules run on a security as a person (the rules_run action): the JSON answer. */
function run_rules(string $jar, int $security, string $asOf = AS_OF): array
{
    return post_json($jar, '/signals/run.php', ['security' => $security, 'as_of' => $asOf]);
}

/** The open (or escalated) signal of a rule on a security, or 0. */
function signal_of(string $rule, int $security): int
{
    return (int) one("SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = :k AND s.security_id = :s ORDER BY (s.status IN ('open', 'escalated')) DESC, s.id DESC LIMIT 1", ['k' => $rule, 's' => $security]);
}

/** The keys of a security's open signals. */
function open_keys(int $security): array
{
    return array_column(q("SELECT r.key FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.security_id = :s AND s.status = 'open' ORDER BY r.sort_order", ['s' => $security]), 'key');
}

/** The SMOKE issuer the seed takes (made first by rules.php, which uploads the SPR fixture to it through the form). */
function smok_issuer(): array
{
    $i = one("SELECT id FROM issuers WHERE cik = '0000999999'");
    if ($i === false) {
        q("INSERT INTO issuers (name, cik, ticker, exchange, transfer_agent, transfer_agent_connector) VALUES ('SMOKE ISSUER INC', '0000999999', 'SMOK', 'otc', 'Securities Transfer Corporation', 'stc')");
        $i = one("SELECT id FROM issuers WHERE cik = '0000999999'");
        q("INSERT INTO securities (issuer_id, class, name, cusip, ticker, is_primary) VALUES (:i, 'common', 'Common Stock', '07831X206', 'SMOK', true)", ['i' => $i]);
    }
    return ['issuer' => (int) $i, 'security' => (int) one("SELECT id FROM securities WHERE cusip = '07831X206'")];
}

/** Upload a file (a path) through the form as the person of $jar (slice 2's helper): ['code', 'json', 'body']. */
function upload(string $jar, string $file, array $form = []): array
{
    $ch = curl_init(BASE . '/uploads/save.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jar)], CURLOPT_POSTFIELDS => $form + ['file' => new CURLFile($file, 'application/octet-stream', basename($file))]]);
    $b = (string) curl_exec($ch);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $b, 'json' => json_decode($b, true) ?: []];
}

/** The fields of the first form on a page whose action is $action: [action, fields]. */
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

/**
 * The fixture fortnight (tests/fixtures/spr_rows.csv) as DTC's two weekly CSVs (slice 2's layout): the week of 2024-03-29 (opening 03-22) and the week
 * of 2024-04-05 (opening 03-29, Friday absent — DTC's -9999999999999). Answers [[name, bytes], …].
 */
function fortnight_csvs(string $cusip = '07831X206'): array
{
    $by = [];
    $f = fopen(dirname(__DIR__, 2) . '/fixtures/spr_rows.csv', 'r');
    fgetcsv($f);
    while (($r = fgetcsv($f)) !== false) { $by[$r[1]]['name'] = $r[2]; $by[$r[1]]['d'][$r[3]] = $r[4]; }
    fclose($f);
    $weeks = [['2024-03-22', '2024-03-25', '2024-03-26', '2024-03-27', '2024-03-28', '2024-03-29'], ['2024-03-29', '2024-04-01', '2024-04-02', '2024-04-03', '2024-04-04', '2024-04-05']];
    $out = [];
    foreach ($weeks as $w) {
        $fmt = static fn (string $d): string => date('m/d/Y', strtotime($d));
        $l = ['', ',,,The Depository Trust Company', ',,,Weekly Subscription Security Position Report', ',,,Daily Closing Balances', ',,,For Week Ending ' . $fmt(end($w)), '', 'Security Description,Cusip', 'SMOKE ISSUER INC COM,' . $cusip,
              'Participant,Participant,Opening,Monday,Tuesday,Wednesday,Thursday,Friday,Share,Percent', 'Number,Name,' . implode(',', array_map($fmt, $w)) . ',Change,Change'];
        $sum = array_fill(0, 6, 0);
        foreach ($by as $n => $p) {
            $bal = array_map(static fn ($d) => $p['d'][$d] ?? '-9999999999999', $w);
            $l[] = $n . ',"' . $p['name'] . '",' . implode(',', $bal) . ',0,0.0';
            foreach ($bal as $k => $v) { if ($v !== '-9999999999999') { $sum[$k] += (int) $v; } }
        }
        $tot = array_map(static fn ($k) => $w[$k] === '2024-04-05' ? '-9999999999999' : (string) $sum[$k], array_keys($w));
        $l[] = 'Total,,' . implode(',', $tot) . ',0,0.0';
        $out[] = ['SPR_' . date('m-d-Y', strtotime(end($w))) . '_' . $cusip . '.csv', implode("\n", $l) . "\n"];
    }
    return $out;
}

/** Upload bytes as a named file. */
function upload_bytes(string $jar, string $name, string $bytes, array $form = []): array
{
    $tmp = sys_get_temp_dir() . '/' . $name;
    file_put_contents($tmp, $bytes);
    $r = upload($jar, $tmp, $form);
    @unlink($tmp);
    return $r;
}
