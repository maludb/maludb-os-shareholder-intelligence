<?php
declare(strict_types=1);
/**
 * Action `upload_create` (log `upload.create`; uploads.write; PEOPLE — a file does not travel over the Actions MCP; the API's POST /api/v1/uploads
 * is a person's key): `kind` (spr, nobo, register or share_range; detected from the file when omitted), `security`, `file`, `connector` (detected
 * when omitted), `record_date` (a NOBO list's or a register's, when the file does not say it — the agent's extract, Broadridge's names export). The file's layout is detected, its CUSIP checked against the security's, the same file refused twice; it is kept
 * whole in the store and, at 5 MB or less, parsed and loaded in this request — larger files are queued for the worker. Lands on the upload.
 */
require_once dirname(__DIR__, 2) . '/app/features/uploads/handler.php';
si_handler_begin();
require_human();
require_right('uploads.write');
$pdo = db();
$errors = [];
$kind = strtolower((string) (req_val('kind') ?? ''));
if ($kind !== '' && !isset(UPLOAD_KINDS[$kind])) {
    $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(UPLOAD_KINDS)) . '.';
}
$sec = null;
$secId = request_integer('security') ?? (int) (current_security($pdo)['security_id'] ?? 0);
if ($secId > 0) {
    $sec = find_security($pdo, $secId);
}
if ($sec === null) {
    $errors['security'] = 'Choose the security the file is for.';
}
$connector = (string) (req_val('connector') ?? '');
if ($connector !== '' && !isset(SI_CONNECTORS[$connector])) {
    $errors['connector'] = 'Unknown connector: leave it blank to detect it from the file.';
}
$recordDate = request_date('record_date');
if ($recordDate === false) {
    $errors['record_date'] = 'A record date such as 2026-09-30.';
}
$file = $_FILES['file'] ?? null;
$max = upload_max_bytes($pdo);
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $errors['file'] = 'Choose the file to upload.';
} elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > $max) {
    $errors['file'] = 'The file is larger than ' . round($max / 1048576) . ' MB, the most this installation takes.';
} elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
    $errors['file'] = 'The file did not arrive whole; send it again.';
} elseif ((int) $file['size'] === 0) {
    $errors['file'] = 'The file is empty.';
} else {
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    if (!in_array($mime, UPLOAD_MIME, true)) {
        $errors['file'] = 'A spreadsheet or a CSV, please (this file is ' . $mime . ').';
    }
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$bytes = (string) file_get_contents((string) $file['tmp_name']);
@unlink((string) $file['tmp_name']);
$r = si_guard($pdo, static function () use ($pdo, $kind, $sec, $connector, $recordDate, $bytes, $file, $mime): array {
    try {
        return upload_create($pdo, ['kind' => $kind, 'security' => $sec, 'connector' => $connector ?: null, 'record_date' => $recordDate, 'bytes' => $bytes,
            'filename' => basename((string) $file['name']), 'mime' => $mime, 'by' => (int) current_member_id()]);
    } catch (SiStoreError $e) {
        throw new DomainException($e->getMessage());
    }
});
$res = $r['result'];
$did = $r['queued'] ? 'Uploaded ' . $file['name'] . ': it is being read'
    : ($res['status'] === 'failed' ? 'Uploaded ' . $file['name'] . ', but it could not be read: ' . $res['tie_note']
    : ($res['tied'] && ($res['record_date'] ?? null) !== null ? 'Uploaded ' . $file['name'] . ': ' . $res['rows'] . ' rows as of ' . $res['record_date'] . ', tied to the file\'s own statements' . (!empty($res['matching']) ? '; the rows are being matched to holders' : '')
    : ($res['tied'] ? 'Uploaded ' . $file['name'] . ': ' . $res['positions'] . ' positions from ' . $res['period'][0] . ' to ' . $res['period'][1] . ', tied to the file\'s own totals'
    : 'Uploaded ' . $file['name'] . ', but it does not tie: ' . $res['tie_note'])));
si_done($did, $r['import_id'], si_land('/uploads/' . $r['import_id'], $r['queued'] ? 'queued' : ($res['status'] === 'failed' ? 'failed' : ($res['tied'] ? 'tied' : 'untied'))), 'uploadChanged',
    ['tied' => $res['tied'] ?? null, 'queued' => $r['queued'], 'positions' => $res['positions'] ?? 0, 'period' => $res['period'] ?? null, 'matching' => $res['matching'] ?? false, 'matcher' => $res['matcher'] ?? null]);
