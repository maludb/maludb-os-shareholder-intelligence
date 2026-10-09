<?php
declare(strict_types=1);

/**
 * An XLSX workbook read without a library (Inventory's feed reader, ported: ZipArchive + SimpleXML; LIBXML_NONET, no entity loader): the
 * shared strings, every worksheet by name in workbook order, each as rows of cell values (strings, trimmed; a number as written). Used by the
 * DTCC directory (slice 1), the SPR and NOBO spreadsheets (slices 2–3). A row of no cells is an empty array; the ceiling is $maxRows a sheet.
 *   si_xlsx_sheets(string $bytes, int $maxRows = 200000): array{name => rows[][]}
 */

function si_xlsx_xml(string $xml): SimpleXMLElement
{
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($x === false) {
        throw new RuntimeException('the workbook holds unreadable XML');
    }
    return $x;
}

/** "A" → 0, "AB" → 27. */
function si_xlsx_col(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        if ($ch >= 'A' && $ch <= 'Z') {
            $n = $n * 26 + (ord($ch) - 64);
        }
    }
    return max(0, $n - 1);
}

function si_xlsx_sheets(string $bytes, int $maxRows = 200000): array
{
    if (!str_starts_with($bytes, "PK\x03\x04")) {
        throw new RuntimeException('not an XLSX workbook');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sixlsx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    try {
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('not a readable XLSX (zip)');
        }
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (si_xlsx_xml($ss)->si as $si) {
                $text = isset($si->t) ? (string) $si->t : '';
                if (!isset($si->t)) {
                    foreach ($si->r as $run) { $text .= (string) $run->t; }
                }
                $shared[] = $text;
            }
        }
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            throw new RuntimeException('the XLSX has no workbook');
        }
        $targets = [];
        foreach (si_xlsx_xml($rels)->Relationship as $rel) {
            $t = ltrim((string) $rel['Target'], '/');
            $targets[(string) $rel['Id']] = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
        }
        $wbx = si_xlsx_xml($wb);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($wbx->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $xml = isset($targets[$rid]) ? $zip->getFromName($targets[$rid]) : false;
            if ($xml === false) {
                continue;
            }
            $rows = [];
            foreach (si_xlsx_xml($xml)->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $col = si_xlsx_col(preg_replace('~\d+~', '', (string) $c['r']) ?? '');
                    $t = (string) $c['t'];
                    $cells[$col] = trim(match ($t) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => (string) ($c->is->t ?? ''),
                        'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $c->v,
                    });
                }
                $line = [];
                if ($cells !== []) {
                    for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) { $line[] = $cells[$i] ?? ''; }
                }
                $rows[] = $line;
                if (count($rows) >= $maxRows) {
                    break;
                }
            }
            $out[(string) $sheet['name']] = $rows;
        }
        return $out;
    } finally {
        $zip->close();
        @unlink($tmp);
    }
}
