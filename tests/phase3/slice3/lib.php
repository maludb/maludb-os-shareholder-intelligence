<?php
/**
 * Helpers for slice 3's proofs (docs/build-specs/holders.md "Proof"): slice 2's library (the reference issuer BMNR, the upload through the
 * form), the fixture issuer (SMOKE ISSUER INC, CUSIP 07831X206 — the anonymised NOBO list and STC register of tests/fixtures/), and the
 * fixture's anonymised names, for the sweep that no log line, no Viewer's answer and no export carries one.
 */
require dirname(__DIR__) . '/slice2/lib.php';
const FIX = __DIR__ . '/../../fixtures/';

/** The fixture issuer and its common stock (made once): ['issuer' => id, 'security' => id]. */
function fixture_issuer(): array
{
    $i = one("SELECT id FROM issuers WHERE ticker = 'SMOK'");
    if ($i === false) {
        q("INSERT INTO issuers (name, ticker, cik, exchange) VALUES ('SMOKE ISSUER INC', 'SMOK', '0000999999', 'nasdaq')");
        $i = one("SELECT id FROM issuers WHERE ticker = 'SMOK'");
        q("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES (:i, 'Common Stock', '07831X206', 'SMOK', true)", ['i' => $i]);
    }
    return ['issuer' => (int) $i, 'security' => (int) one('SELECT id FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $i])];
}

/** Upload a fixture file by name (kind detected unless given). */
function upload_fixture(string $jar, string $name, array $form = []): array
{
    return upload($jar, FIX . $name, $form + ['kind' => '']);
}

/** The fixture's anonymised person names (the NOBO rows' first lines that are persons, and the register's), for the sweeps. */
function fixture_person_names(int $limit = 400): array
{
    $names = [];
    foreach (['nobo_rows.csv' => 'line1', 'register_rows.csv' => 'registration_name'] as $f => $col) {
        $h = fopen(FIX . $f, 'r');
        $hdr = fgetcsv($h, 0, ',', '"', '');
        $i = array_search($col, $hdr, true);
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $n = trim((string) ($r[$i] ?? ''));
            if ($n !== '' && !preg_match('~\b(LLC|INC|CORP|TRUST|TTEE|CO|LP|LTD|FUND|HOLDINGS|CEDE|FMT|CAPITAL|PARTNERS|FBO|CUST)\b~i', $n) && preg_match('~^[A-Z]+ [A-Z] [A-Z]+$~', $n)) {
                $names[$n] = true;
            }
        }
    }
    return array_slice(array_keys($names), 0, $limit);
}

/** The id of the NOBO row of an upload at a row number. */
function nobo_row(int $import, int $rowNo): int
{
    return (int) one('SELECT id FROM nobo_rows WHERE import_id = :i AND row_no = :r', ['i' => $import, 'r' => $rowNo]);
}

/** A weekly SPR in DTC's layout for a CUSIP: $dates (Opening + five days), $rows [[number, name, [6 balances]]] — the Total row their sum. */
function spr_csv3(string $cusip, array $dates, array $rows): string
{
    $fmt = static fn (string $d): string => date('m/d/Y', strtotime($d));
    $l = ['', ',,,The Depository Trust Company', ',,,Weekly Subscription Security Position Report', ',,,Daily Closing Balances', ',,,For Week Ending ' . $fmt(end($dates)), '', 'Security Description,Cusip', 'SMOKE SECURITY +,' . $cusip,
          'Participant,Participant,Opening,Monday,Tuesday,Wednesday,Thursday,Friday,Share,Percent', 'Number,Name,' . implode(',', array_map($fmt, $dates)) . ',Change,Change'];
    $sum = array_fill(0, 6, 0);
    foreach ($rows as [$n, $name, $bal]) {
        $l[] = $n . ',' . $name . ',' . implode(',', $bal) . ',0,0.0';
        foreach ($bal as $k => $v) { $sum[$k] += (int) $v; }
    }
    $l[] = 'Total,,' . implode(',', $sum) . ',0,0.0';
    return implode("\n", $l) . "\n";
}

/** A transfer agent's Shareholder Extract with the never-stored columns filled, for the strip and the controls (persons named SMOKE). */
function extract_csv(array $holders): string
{
    $hdr = ['CUSIP', 'TAX-ID', 'ACCT#', 'TAX-ID 2', 'HOLDER REG 1', 'HOLDER REG 2', 'HOLDER REG 3', 'HOLDER REG 4', 'HOLDER REG 5', 'HOLDER REG 6', 'HOLDER ADDRESS 1', 'HOLDER ADDRESS 2', 'HOLDER ADDRESS 3',
            'CITY', 'STATE', 'ZIP CODE', 'COUNTRY', 'CITIZENSHIP', 'OWNERSHIP TYPE', 'DATE OPENED', 'DATE OF BIRTH', 'CERT', 'BOOK', 'DRS', 'BOOKR', 'PLAN1', 'PLAN2', 'PLAN3', 'PLAN4', 'TOTAL SHARES', 'PHONE NUMBER', 'EMAIL ADDRESS'];
    $out = '"' . implode('","', $hdr) . "\"\n";
    foreach ($holders as $i => $h) {
        $types = $h['types'] + ['CERT' => 0, 'BOOK' => 0, 'DRS' => 0, 'BOOKR' => 0, 'PLAN1' => 0];
        $total = $h['total'] ?? array_sum($types);
        $row = ['07831X206', '="123-45-' . sprintf('%04d', 6000 + $i) . '"', '="' . (100 + $i) . '"', '', $h['name'], $h['reg2'] ?? '', '', '', '', '', $h['street'], '', '', $h['city'] ?? 'RENO', $h['state'] ?? 'NV', $h['zip'] ?? '89501', 'US', 'US',
                $h['type'] ?? 'INDIVIDUAL', '01/02/20', '0' . (1 + $i % 9) . '/1' . ($i % 9) . '/1960', $types['CERT'], $types['BOOK'], $types['DRS'], $types['BOOKR'], $types['PLAN1'], 0, 0, 0, $total, '555-01' . sprintf('%02d', $i), 'smoke' . $i . '@example.invalid'];
        $out .= '"' . implode('","', array_map(static fn ($v) => str_replace('"', '""', (string) $v), $row)) . "\"\n";
    }
    return $out;
}

/** The worker's matcher pass until nothing waits: answers the summed counts and the number of passes. */
function match_all(): array
{
    $sum = ['uploads' => 0, 'auto' => 0, 'proposed' => 0, 'new' => 0, 'failed' => 0, 'passes' => 0, 'errors' => []];
    do {
        $w = worker('matcher', date(DATE_ATOM));
        $r = $w['report']['ran']['matcher'] ?? ['uploads' => 0];
        foreach (['uploads', 'auto', 'proposed', 'new', 'failed'] as $k) { $sum[$k] += (int) ($r[$k] ?? 0); }
        $sum['passes']++;
        $sum['errors'] = array_merge($sum['errors'], $w['report']['errors'] ?? []);
    } while ((int) ($r['uploads'] ?? 0) > 0 && $sum['passes'] < 20);
    return $sum;
}

/** The fixture's NOBO list (accepted by the manager) and STC register, matched: ['nobo' => import, 'register' => import]. */
function seed_fixture_holders(string $jP, string $jM, int $sec): array
{
    $n = (int) (upload_fixture($jP, 'nobo_list.xlsx', ['security' => $sec])['json']['record_id'] ?? 0);
    post_json($jM, '/uploads/override.php', ['upload' => $n, 'note' => 'SMOKE: Broadridge\'s suppressed rows']);
    $r = (int) (upload_fixture($jP, 'register_stc.xlsx', ['security' => $sec])['json']['record_id'] ?? 0);
    match_all();
    return ['nobo' => $n, 'register' => $r];
}
