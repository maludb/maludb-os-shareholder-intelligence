<?php
/**
 * Proof: the generic connector (position-reports.md §1.2 "else the person picks a template (`generic_mapped`)"). Every file here is CRAFTED —
 * persons named SMOKE, numbers that are no one's. An administrator saves a template for a register in a layout no connector names; a template
 * that maps a column whose name says tax id, birth date, phone or email is refused, as is one without the kind's required fields or of a kind
 * the generic connector does not read. A file in that layout is refused without a template (the words say to choose one) and read with it: the
 * record date from the file name, tied to the file's own total row, Cede flagged, the matcher run — and THE STORE KEEPS ONLY THE MAPPED COLUMNS
 * (the file's SSN, birth date and email columns are nowhere: not in the kept copy, not in a row's raw cells, not in the log). A total that
 * disagrees unties with both numbers named; a re-parse reads the kept copy to the same rows; a template slipped into the table around the save
 * check is still refused when it reads. A DTC position report as a WORKBOOK in another layout (letters, a named sheet, the date and the CUSIP
 * in cells, a Grand Total) loads its positions; another CUSIP is refused naming both. A NOBO list in another layout (first and last names in
 * two columns, the record date given on the form) loads its rows. The upload form offers the template picker, which lists generic templates only.
 */
require __DIR__ . '/lib.php';
[$jA, ] = sign_on(1);
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$fx = fixture_issuer();
$sec = $fx['security'];
foreach ([$jA, $jP, $jV] as $j) { page($j, '/?issuer=' . $fx['issuer']); }

/** A CSV from rows of cells. */
function csv_of(array $rows): string
{
    $fh = fopen('php://memory', 'r+');
    foreach ($rows as $r) { fputcsv($fh, $r, ',', '"', ''); }
    rewind($fh);
    $s = (string) stream_get_contents($fh);
    fclose($fh);
    return $s;
}

/** A minimal XLSX (inline strings, one or more named sheets) from rows of cells. */
function xlsx_of(array $sheets): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'gx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $names = array_keys($sheets);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . implode('', array_map(static fn ($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names))) . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . implode('', array_map(static fn ($i, $n) => '<sheet name="' . htmlspecialchars($n) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys($names), $names)) . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(static fn ($i) => '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>', array_keys($names))) . '</Relationships>');
    foreach (array_values($sheets) as $i => $rows) {
        $x = '';
        foreach ($rows as $ri => $r) {
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = chr(65 + $ci) . ($ri + 1);
                $x .= is_int($v) ? '<c r="' . $ref . '"><v>' . $v . '</v></c>' : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $v) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $z->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $x . '</sheetData></worksheet>');
    }
    $z->close();
    $b = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $b;
}

$save = static fn (array $f): array => post_json($jA, '/admin/templates/save.php', $f + ['connector' => 'generic_mapped']);
$stored = static fn (int $import): string => (string) file_get_contents(rtrim((string) getenv('SI_STORAGE_DIR'), '/') . '/' . one('SELECT a.storage_path FROM imports i JOIN attachments a ON a.id = i.attachment_id WHERE i.id = :i', ['i' => $import]));
$secrets = ['999-00-1234', '999-00-5678', '01/02/1960', 'smoke.alpha@example.invalid', '555-0100'];

echo "1. A template is checked as it will be read\n";
$regMap = ['columns' => ['holder_ref' => 'Account No', 'registration_name' => ['Reg Line 1', 'Reg Line 2'], 'address_line' => 'Street', 'city' => 'City', 'state' => 'St', 'zip' => 'Postal',
           'account_kind' => 'Acct Type', 'shares' => 'Share Balance'], 'total_row' => 'Total'];
$r = $save(['name' => 'SMOKE bad — tax id', 'kind' => 'register', 'mapping' => json_encode(['columns' => ['registration_name' => 'Name', 'shares' => 'Shares', 'holder_ref' => 'Tax ID']])]);
ok($r['code'] === 422 && str_contains($r['body'], 'never stores'), 'a template mapping a "Tax ID" column is refused: the application never stores it');
$r = $save(['name' => 'SMOKE bad — no shares', 'kind' => 'register', 'mapping' => json_encode(['columns' => ['registration_name' => 'Name']])]);
ok($r['code'] === 422 && str_contains($r['body'], 'must map shares'), 'a register template without shares is refused, naming the field');
$r = $save(['name' => 'SMOKE bad — holders with an email', 'kind' => 'holders_csv', 'mapping' => json_encode(['columns' => ['contact' => 'E-mail']])]);
ok($r['code'] === 422 && str_contains($r['body'], 'never stores'), 'a template of a kind no reader reads yet is kept as written, but never maps an email column');
$r = $save(['name' => 'SMOKE holders for later', 'kind' => 'holders_csv', 'mapping' => json_encode(['columns' => ['registration_name' => 'A', 'shares' => 'B']])]);
ok($r['code'] === 200, 'such a template is saved as written (slice 10\'s contract)');
$r = $save(['name' => 'SMOKE Registrar — holder list (CSV)', 'kind' => 'register', 'mapping' => json_encode($regMap)]);
$tReg = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $tReg > 0 && count(activity('template.save')) >= 1, 'the register template saved' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));

echo "2. A register in another layout\n";
$hdr = ['Account No', 'Reg Line 1', 'Reg Line 2', 'SSN', 'Birth Date', 'Email Address', 'Street', 'City', 'St', 'Postal', 'Acct Type', 'Share Balance'];
$regRows = [
    ['Registrar of SMOKE holders as of the date in the file name'], [],
    $hdr,
    ['A-1001', 'SMOKE ALPHA PERSON', 'JT TEN', '999-00-1234', '01/02/1960', 'smoke.alpha@example.invalid', '1 ELM ST', 'SPRINGFIELD', 'IL', '62701', 'INDIVIDUAL', '1,500'],
    ['A-1002', 'SMOKE BETA HOLDINGS LLC', '', '999-00-5678', '', '', '2 OAK AVE', 'DOVER', 'DE', '19901', 'CORPORATION', '25,000'],
    ['A-1003', 'CEDE & CO', '', '', '', '', '570 WASHINGTON BLVD', 'JERSEY CITY', 'NJ', '07310', 'NOMINEE', '1,581,000'],
    ['Total', '', '', '', '', '', '', '', '', '', '', '1,607,500'],
];
$file = ['name' => 'SMOKE holders 06-30-24.csv', 'bytes' => csv_of($regRows)];
$r = upload($jP, $file, ['security' => $sec, 'kind' => '']);
ok($r['code'] === 422 && str_contains($r['body'], 'import template'), 'without a template the file is refused, and the words say to choose a template');
$before = last_activity_id();
$r = upload($jP, $file, ['security' => $sec, 'kind' => '', 'template' => $tReg]);
$u = (int) ($r['json']['record_id'] ?? 0);
$im = q('SELECT * FROM imports WHERE id = :i', ['i' => $u])[0] ?? [];
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && ($im['connector'] ?? '') === 'generic_mapped' && $im['kind'] === 'register' && $im['record_date'] === '2024-06-30',
    'read by the template: kind register, the record date from the file name, tied to the file\'s own total row' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
ok((int) (json_decode((string) $im['mapping'], true)['template_id'] ?? 0) === $tReg && (json_decode((string) $im['control_totals'], true)['template'] ?? '') === 'SMOKE Registrar — holder list (CSV)', 'the upload remembers the template it was read by');
ok((int) one('SELECT count(*) FROM register_rows WHERE import_id = :i', ['i' => $u]) === 3 && (float) one('SELECT sum(shares) FROM register_rows WHERE import_id = :i', ['i' => $u]) === 1607500.0
    && (int) one('SELECT count(*) FROM register_rows WHERE import_id = :i AND is_cede', ['i' => $u]) === 1, 'three rows, 1,607,500 shares, the Cede line flagged');
ok(one("SELECT registration_name FROM register_rows WHERE import_id = :i AND holder_ref = 'A-1001'", ['i' => $u]) === "SMOKE ALPHA PERSON\nJT TEN"
    && one("SELECT raw ->> 'ownership_type' FROM register_rows WHERE import_id = :i AND holder_ref = 'A-1002'", ['i' => $u]) === 'CORPORATION', 'the registration\'s two lines joined; the account type kept in the row\'s raw cells');
$kept = $stored($u);
$raws = (string) one('SELECT string_agg(raw::text, \'\') FROM register_rows WHERE import_id = :i', ['i' => $u]);
$logs = (string) one('SELECT string_agg(COALESCE(before::text, \'\') || COALESCE(after::text, \'\'), \'\') FROM activity_log WHERE id > :s', ['s' => $before]);
$leak = array_filter($secrets, static fn ($x) => str_contains($kept, $x) || str_contains($raws, $x) || str_contains($logs, $x));
ok(str_starts_with($kept, '#si-generic/1') && !str_contains($kept, 'SSN') && !str_contains($kept, 'Birth') && !str_contains($kept, 'Email') && $leak === [],
    'THE STORE KEEPS ONLY THE MAPPED COLUMNS: no SSN, birth date or email in the kept copy, the rows\' raw cells or the log' . ($leak ? ' — found ' . count($leak) : ''));
ok(str_contains($kept, 'SMOKE ALPHA PERSON') && str_contains($kept, '1,581,000'), 'the kept copy holds what the template maps (the registration, the shares)');
ok((int) (json_decode((string) one("SELECT after::text FROM activity_log WHERE action = 'upload.create' AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $before]), true)['template_id'] ?? 0) === $tReg, 'upload.create logs the template\'s id');
match_all();
ok((int) one('SELECT count(*) FROM register_rows WHERE import_id = :i AND NOT is_cede AND holder_id IS NOT NULL', ['i' => $u]) === 2, 'the matcher makes its holders (Cede never one)');

echo "3. A re-parse reads the kept copy\n";
$r = post_json($jP, '/uploads/reparse.php', ['upload' => $u]);
ok($r['code'] === 200 && one('SELECT tied FROM imports WHERE id = :i', ['i' => $u]) === true && (int) one('SELECT count(*) FROM register_rows WHERE import_id = :i', ['i' => $u]) === 3
    && (float) one('SELECT sum(shares) FROM register_rows WHERE import_id = :i', ['i' => $u]) === 1607500.0, 'the same three rows, tied again' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));

echo "4. A total that disagrees\n";
$bad = $regRows;
$bad[6][11] = '1,700,000';
$r = upload($jP, ['name' => 'SMOKE holders 07-31-24.csv', 'bytes' => csv_of($bad)], ['security' => $sec, 'kind' => '', 'template' => $tReg]);
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === false && str_contains((string) one('SELECT tie_note FROM imports WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)]), '1,700,000')
    && str_contains((string) one('SELECT tie_note FROM imports WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)]), '1,607,500'), 'untied, both numbers named');

echo "5. A template slipped in around the save check is refused when it reads\n";
q("INSERT INTO import_templates (name, kind, connector, mapping) VALUES ('SMOKE slipped', 'register', 'generic_mapped', '{\"columns\": {\"registration_name\": \"Reg Line 1\", \"shares\": \"Share Balance\", \"holder_ref\": \"Email Address\"}}')");
$tSlip = (int) one("SELECT id FROM import_templates WHERE name = 'SMOKE slipped'");
$r = upload($jP, ['name' => 'SMOKE holders 08-30-24.csv', 'bytes' => csv_of($regRows)], ['security' => $sec, 'kind' => '', 'template' => $tSlip]);
ok($r['code'] === 422 && str_contains($r['body'], 'never stores') && (int) one("SELECT count(*) FROM imports WHERE file_name = 'SMOKE holders 08-30-24.csv'") === 0, 'refused, nothing stored');

$tLater = (int) one("SELECT id FROM import_templates WHERE name = 'SMOKE holders for later'");
$r = upload($jP, ['name' => 'SMOKE holders 09-30-24.csv', 'bytes' => csv_of($regRows)], ['security' => $sec, 'kind' => '', 'template' => $tLater]);
ok($r['code'] === 422 && str_contains($r['body'], 'NOBO list or a register'), 'a template of a kind no reader reads is refused at upload, in words');

echo "6. A DTC position report as a workbook in another layout\n";
$r = $save(['name' => 'SMOKE custodian — positions (xlsx)', 'kind' => 'spr', 'mapping' => json_encode(['sheet' => 'Positions', 'header_row' => 3, 'record_date_cell' => 'B1', 'cusip_cell' => 'D1',
    'columns' => ['participant_number' => 'A', 'participant_name' => 'B', 'shares' => 'C'], 'total_row' => 'Grand Total'])]);
$tSpr = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $tSpr > 0, 'a position template by column letters, the date and the CUSIP in cells');
$wb = static fn (string $cusip, string $total): string => xlsx_of(['Notes' => [['made for the proof']], 'Positions' => [
    ['As of', '04/05/2024', 'CUSIP', $cusip], [], ['Participant', 'Name', 'Position'],
    ['164', 'CHS SCHWAB', '600,000'], ['0226', 'NATL FIN', '981,000'], ['Grand Total', '', $total]]]);
$r = upload($jP, ['name' => 'custodian.xlsx', 'bytes' => $wb('07831X206', '1,581,000')], ['security' => $sec, 'kind' => '', 'template' => $tSpr]);
$u = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && one('SELECT kind FROM imports WHERE id = :i', ['i' => $u]) === 'spr'
    && (int) one("SELECT count(*) FROM spr_rows WHERE import_id = :i AND position_date = '2024-04-05' AND participant_number IN ('0164', '0226')", ['i' => $u]) === 2,
    'loaded: two participants as of the cell\'s date (164 is 0164), tied to the Grand Total' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
ok((float) one("SELECT sum(shares) FROM participant_positions WHERE import_id = :i", ['i' => $u]) === 1581000.0, 'the positions: 1,581,000');
$r = upload($jP, ['name' => 'custodian-other.xlsx', 'bytes' => $wb('12345X678', '1,581,000')], ['security' => $sec, 'kind' => '', 'template' => $tSpr]);
ok($r['code'] === 422 && str_contains($r['body'], '12345X678') && str_contains($r['body'], '07831X206'), 'another CUSIP is refused, both named');

echo "7. A NOBO list in another layout\n";
$r = $save(['name' => 'SMOKE NOBO — names (CSV)', 'kind' => 'nobo', 'mapping' => json_encode(['columns' => ['name' => ['First', 'Last'], 'shares' => 'Qty', 'address_line' => 'Addr', 'city' => 'Town', 'state' => 'State', 'zip' => 'Zip', 'custodian' => 'Broker']])]);
$tNobo = (int) ($r['json']['record_id'] ?? 0);
$nobo = csv_of([['First', 'Last', 'Addr', 'Town', 'State', 'Zip', 'Broker', 'Qty', 'Phone'],
    ['SMOKE', 'GAMMA', '3 PINE RD', 'BOSTON', 'MA', '2108', 'CHARLES SCHWAB & CO., INC.', '100', '555-0100'],
    ['SMOKE', 'DELTA', '4 ASH CT', 'AUSTIN', 'TX', '78701', 'NATIONAL FINANCIAL SERVICES LLC', '250.5', '555-0100']]);
$r = upload($jP, ['name' => 'nobo-names.csv', 'bytes' => $nobo], ['security' => $sec, 'kind' => '', 'template' => $tNobo, 'record_date' => '2024-05-01']);
$u = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && one('SELECT record_date FROM imports WHERE id = :i', ['i' => $u]) === '2024-05-01'
    && (int) one('SELECT count(*) FROM nobo_rows WHERE import_id = :i', ['i' => $u]) === 2, 'two NOBO rows as of the date given on the form, tied to their sum' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
ok(one('SELECT line1 FROM nobo_rows WHERE import_id = :i ORDER BY row_no LIMIT 1', ['i' => $u]) === 'SMOKE GAMMA' && one('SELECT zip_sort FROM nobo_rows WHERE import_id = :i ORDER BY row_no LIMIT 1', ['i' => $u]) === '02108'
    && (int) one('SELECT count(*) FROM nobo_rows WHERE import_id = :i AND custodian_participant_id IS NOT NULL', ['i' => $u]) >= 1, 'the names joined on one line, a dropped zip zero restored, the custodian matched to a participant');
ok(!str_contains($stored($u), '555-0100'), 'the unmapped phone column is not kept');

echo "8. The form and the picker\n";
$h = page($jP, '/uploads/new')['body'];
ok(str_contains($h, 'id="upload-form-field-template"'), 'the upload form offers an import template');
$h = page($jA, '/admin/templates/' . $tReg . '/edit')['body'];
ok(str_contains($h, 'id="template-generic-help"') && str_contains($h, '<strong>registration_name</strong>'), 'the template form says what a generic mapping reads, the required fields marked');
$pick = req('GET', '/pick/?source=template', ['jar' => $jP])['body'];
ok(str_contains($pick, 'SMOKE Registrar') && str_contains($pick, 'SMOKE custodian') && !str_contains($pick, 'STC — Security Holder List') && !str_contains($pick, 'SMOKE holders for later'), 'the picker lists the generic templates it can read — not the vendor connectors\' (those are detected), not a kind no reader reads');
$r = req('GET', '/pick/?source=template', ['jar' => $jV]);
ok($r['code'] === 403 || !str_contains($r['body'], 'SMOKE Registrar'), 'a Viewer (no uploads.write) is not offered the templates');
finish();
