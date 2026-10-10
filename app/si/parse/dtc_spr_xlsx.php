<?php
declare(strict_types=1);

/**
 * Connector `dtc_spr_xlsx` — DTC's Security Position Report saved as a workbook: the same layouts as the CSVs, cell for cell, on the first
 * sheet (a date may be an Excel serial day). A workbook MADE FROM SPRs (a firm per row, no participant numbers — the owner's analysis) is not
 * DTC's file and is refused by the detector before it reaches here.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/dtc_spr_csv.php';
require_once __DIR__ . '/dtc_spr_onetime_csv.php';

function si_parse_dtc_spr_xlsx(string $bytes): array
{
    $rows = si_file_rows($bytes);
    return stripos(si_rows_text($rows, 12), 'one time security position report') !== false ? si_parse_dtc_spr_onetime_rows($rows) : si_parse_dtc_spr_grid($rows);
}
