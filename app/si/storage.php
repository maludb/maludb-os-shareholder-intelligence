<?php
declare(strict_types=1);

/**
 * The upload store (position-reports.md §1.3): every file kept whole under SI_STORAGE_DIR/uploads/<aa>/<sha256> (mode 640, outside the web
 * root; PHP serves a byte only after the walls). A transfer agent's extract is stored WITHOUT its personal columns (the template's
 * drop_columns: tax ids, dates of birth, phones, emails, the withholding and NRA columns) — the original never reaches the disk beyond PHP's
 * own temporary upload; its hash is kept so the same file is recognised again.
 *   si_store_bytes(string $bytes): array{path, sha256, bytes}
 *   si_strip_csv_columns(string $bytes, array $drop): string   — the CSV with those columns removed (header-named, any case)
 *   si_stored_bytes(string $path): string                       — a stored file's bytes, from its path relative to the store
 */

/** The store's root, or a SiStoreError when it is not set or not writable. */
final class SiStoreError extends RuntimeException {}

function si_store_root(): string
{
    $root = rtrim((string) env('SI_STORAGE_DIR', ''), '/');
    if ($root === '') {
        throw new SiStoreError('The upload store is not set (SI_STORAGE_DIR) — the installer sets it; ask the admin.');
    }
    if (!is_dir($root . '/uploads') && !@mkdir($root . '/uploads', 0750, true)) {
        throw new SiStoreError('The upload store cannot be written (' . basename($root) . '/uploads).');
    }
    return $root;
}

function si_store_bytes(string $bytes): array
{
    $sha = hash('sha256', $bytes);
    $rel = 'uploads/' . substr($sha, 0, 2) . '/' . $sha;
    $abs = si_store_root() . '/' . $rel;
    if (!is_file($abs)) {
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0750, true)) {
            throw new SiStoreError('The upload store cannot be written.');
        }
        $tmp = $abs . '.part-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($tmp, $abs)) {
            @unlink($tmp);
            throw new SiStoreError('The file could not be written to the store.');
        }
        @chmod($abs, 0640);
    }
    return ['path' => $rel, 'sha256' => $sha, 'bytes' => strlen($bytes)];
}

function si_stored_bytes(string $rel): string
{
    if (!preg_match('~^uploads/[0-9a-f]{2}/[0-9a-f]{64}$~', $rel)) {
        throw new SiStoreError('Not a stored file.');
    }
    $b = @file_get_contents(si_store_root() . '/' . $rel);
    if ($b === false) {
        throw new SiStoreError('The stored file is missing from the store.');
    }
    return $b;
}

/** The CSV without the columns whose header names $drop (compared upper-case, trimmed); the cells re-quoted as CSV. */
function si_strip_csv_columns(string $bytes, array $drop): string
{
    require_once __DIR__ . '/parse/common.php';
    $rows = si_csv_rows($bytes);
    if ($rows === []) {
        return $bytes;
    }
    $dropU = array_map(static fn ($d): string => strtoupper(trim((string) $d)), $drop);
    $keep = [];
    foreach ($rows[0] as $i => $h) {
        if (!in_array(strtoupper(trim(trim((string) $h), '="')), $dropU, true)) { $keep[] = $i; }
    }
    $fh = fopen('php://memory', 'r+');
    foreach ($rows as $r) {
        fputcsv($fh, array_map(static fn (int $i): string => (string) ($r[$i] ?? ''), $keep), ',', '"', '');
    }
    rewind($fh);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}
