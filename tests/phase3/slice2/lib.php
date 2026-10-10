<?php
/**
 * Helpers for slice 2's proofs (docs/build-specs/spr-positions.md "Proof"): slice 1's library, the reference issuer (Bitmine Immersion, CUSIP
 * 09175A107 — the owner's real SPR files) made as an IR Manager would, and an upload through the form as a person (multipart, JSON answer).
 */
require dirname(__DIR__) . '/slice1/lib.php';
const REAL = __DIR__ . '/../../fixtures/real/';

/** The reference issuer and its common stock (made once): ['issuer' => id, 'security' => id]. */
function reference_issuer(): array
{
    $i = one("SELECT id FROM issuers WHERE ticker = 'BMNR'");
    if ($i === false) {
        q("INSERT INTO issuers (name, ticker, cik, exchange) VALUES ('BITMINE IMMERSION TECHNOLOGIES, INC.', 'BMNR', '0001829311', 'nyse_american')");
        $i = one("SELECT id FROM issuers WHERE ticker = 'BMNR'");
        q("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES (:i, 'Common Stock', '09175A107', 'BMNR', true)", ['i' => $i]);
    }
    return ['issuer' => (int) $i, 'security' => (int) one('SELECT id FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $i])];
}

/** Upload a file (a path, or ['name' => …, 'bytes' => …]) as the person of $jar. Answers ['code', 'json', 'body']. */
function upload(string $jar, array|string $file, array $form = []): array
{
    if (is_array($file)) {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $file['bytes']);
        $cf = new CURLFile($tmp, 'text/csv', $file['name']);
    } else {
        $cf = new CURLFile($file, 'text/csv', basename($file));
    }
    $ch = curl_init(BASE . '/uploads/save.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jar)], CURLOPT_POSTFIELDS => $form + ['kind' => 'spr', 'file' => $cf]]);
    $b = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (isset($tmp)) { @unlink($tmp); }
    return ['code' => $code, 'body' => $b, 'json' => json_decode($b, true) ?: []];
}

/** The owner's real SPR CSVs, in date order (the weekly ones by their week; the one-time first). */
function real_spr_files(): array
{
    $files = array_values(array_filter(glob(REAL . '*SPR*') ?: [], static fn ($f) => str_ends_with(strtolower($f), '.csv')));
    $date = static function (string $f): string {
        $b = basename($f);
        if (preg_match('~(\d{2})-(\d{2})-(\d{2,4})~', $b, $m)) { $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]; return $y . $m[1] . $m[2]; }
        return '0';
    };
    usort($files, static fn ($a, $b) => strcmp($date($a), $date($b)));
    return $files;
}
