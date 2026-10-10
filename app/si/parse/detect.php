<?php
declare(strict_types=1);

/**
 * The connector of an uploaded file, from its first cells (position-reports.md §1.2). Answers [connector|null, words]. A file we cannot name
 * is refused with words saying what it looks like; a connector a later slice reads is named and refused with which slice.
 */
require_once __DIR__ . '/common.php';

/** connector => [kind, the slice that reads it, the parser file, the function] — the parsers this build has have their file. */
const SI_CONNECTORS = [
    'dtc_spr_csv'                 => ['spr', 2, 'dtc_spr_csv.php', 'si_parse_dtc_spr_csv'],
    'dtc_spr_onetime_csv'         => ['spr', 2, 'dtc_spr_onetime_csv.php', 'si_parse_dtc_spr_onetime_csv'],
    'dtc_spr_xlsx'                => ['spr', 2, 'dtc_spr_xlsx.php', 'si_parse_dtc_spr_xlsx'],
    'broadridge_nobo_csv'         => ['nobo', 3, null, null],
    'broadridge_nobo_xlsx'        => ['nobo', 3, null, null],
    'broadridge_nobo_names_xlsx'  => ['nobo', 3, null, null],
    'broadridge_share_range_xls'  => ['share_range', 3, null, null],
    'ta_extract_csv'              => ['register', 3, null, null],
    'stc_position_detail'         => ['register', 3, null, null],
    'prices_csv'                  => ['prices_csv', 6, null, null],
];

function si_detect_connector(string $bytes, string $filename): array
{
    $isZip = str_starts_with($bytes, "PK\x03\x04");
    $isOle = str_starts_with($bytes, "\xD0\xCF\x11\xE0");
    if ($isOle) {
        return [null, 'An old Excel workbook (.xls): Broadridge\'s Share Range Analysis is read from slice 3; save anything else as .xlsx or CSV.'];
    }
    try {
        $rows = si_file_rows($bytes, 60);                                  // the head names the layout (a large file is never read whole here)
    } catch (Throwable $e) {
        return [null, 'The file could not be read as a spreadsheet or a CSV.'];
    }
    $text = si_rows_text($rows, 40);
    $u = strtoupper($text);
    if (str_contains($u, 'WEEKLY SUBSCRIPTION SECURITY POSITION REPORT') || si_find_row($rows, ['Participant', 'Participant', 'Opening']) !== null) {
        return [$isZip ? 'dtc_spr_xlsx' : 'dtc_spr_csv', 'DTC\'s weekly Security Position Report'];
    }
    if (str_contains($u, 'ONE TIME SECURITY POSITION REPORT')) {
        return [$isZip ? 'dtc_spr_xlsx' : 'dtc_spr_onetime_csv', 'DTC\'s one-time Security Position Report'];
    }
    if (str_contains($u, 'JOB NUMBER') && str_contains($u, 'NUMBER OF NOBOS')) {
        return [$isZip ? 'broadridge_nobo_xlsx' : 'broadridge_nobo_csv', 'Broadridge\'s NOBO list'];
    }
    if (preg_match('~FIRST NAME.*LAST NAME~', $u)) {
        return ['broadridge_nobo_names_xlsx', 'Broadridge\'s NOBO names export'];
    }
    if (str_contains($u, '"CUSIP","TAX-ID","ACCT#"') || str_contains($u, 'CUSIP,TAX-ID,ACCT#')) {
        return ['ta_extract_csv', 'the transfer agent\'s Shareholder Extract'];
    }
    if (str_contains($u, 'SECURITY HOLDER LIST') && str_contains($u, 'POSITION DETAIL')) {
        return ['stc_position_detail', 'Securities Transfer Corporation\'s position detail'];
    }
    if (str_contains($u, 'SPR ANALYSIS') || (str_contains($u, 'FIRM') && !preg_match('~(^|\n)\d{4},~', $text))) {
        return [null, 'This looks like a workbook made from SPRs (a firm per row, no DTC participant numbers). Upload DTC\'s own report — the CSV DTC sends.'];
    }
    return [null, 'This is none of the files read here: DTC\'s Security Position Report (weekly or one-time), Broadridge\'s NOBO list, or the transfer agent\'s register.'];
}

/** Run the connector's parser on the bytes (the slice that reads it must be built). */
function si_parse_file(string $connector, string $bytes): array
{
    $c = SI_CONNECTORS[$connector] ?? null;
    if ($c === null) {
        throw new SiParseError('Unknown connector ' . $connector . '.');
    }
    if ($c[2] === null) {
        throw new SiParseError('This kind of file is read from slice ' . $c[1] . ', which is not built yet.');
    }
    require_once __DIR__ . '/' . $c[2];
    return ($c[3])($bytes) + ['connector' => $connector, 'kind' => $c[0]];
}
