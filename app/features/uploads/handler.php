<?php
declare(strict_types=1);

/** What slice 2's upload and reconciliation handlers start with. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__) . '/reconciliation/queries.php';

/** The largest upload this installation takes, in bytes: the smaller of the setting and SI_MAX_UPLOAD_BYTES. */
function upload_max_bytes(PDO $pdo): int
{
    $setting = (int) (one_value($pdo, 'SELECT max_upload_bytes FROM mcp_settings') ?: 52428800);
    $env = (int) env('SI_MAX_UPLOAD_BYTES', '0');
    return $env > 0 ? min($env, $setting) : $setting;
}

/** The types a file may be (finfo's judgement, never the name's). */
const UPLOAD_MIME = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/zip', 'application/octet-stream',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/x-ole-storage', 'application/CDFV2'];
