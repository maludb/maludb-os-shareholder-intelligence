<?php
declare(strict_types=1);
/**
 * /reports/{id}/file?format=html|pdf — a pack's stored rendering, sent after the walls (the store is never under the web root). The HTML is a stand-alone
 * document served with a policy that lets it run nothing; the PDF is a download. A pack not rendered yet, or a PDF the renderer never made, is a 404 in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
require_any_right('reports.read|reports.run');
$pdo = db();
$r = report_from_request($pdo, 'id');
$row = report_row($pdo, (int) $r['report_id']) ?? refuse(404, 'Not found.');
$format = request_string('format', 'html') === 'pdf' ? 'pdf' : 'html';
$rel = $format === 'pdf' ? $row['pdf_path'] : $row['rendered_path'];
if ($rel === null) { refuse(404, $format === 'pdf' ? 'This pack has no PDF: the renderer is not installed here, or it has not been rendered.' : 'This pack has not been rendered yet.'); }
try {
    $bytes = si_stored_named((string) $rel);
} catch (SiStoreError) {
    $bytes = null;
}
if ($bytes === null) { refuse(404, 'The rendered file is gone from the store; render the pack again.'); }
$name = preg_replace('~[^A-Za-z0-9._-]+~', '-', (string) $r['title']) . '.' . $format;
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($format === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
} else {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
    header('Content-Disposition: inline; filename="' . $name . '"');
}
header('Content-Length: ' . strlen($bytes));
echo $bytes;
