<?php
declare(strict_types=1);

/**
 * A filing's primary document fetched and kept as text (edgar-and-finra-fetchers.md §2 "filing text"): under the EDGAR source's policy (the
 * user-agent, the rate, robots, the cache), the document at the archives (settings.archives_url of the edgar_submissions source, default
 * https://www.sec.gov/Archives/edgar/data/), read by its kind — HTML stripped with its paragraphs kept, an XML primary document as its
 * elements' text, a .txt as it is, a PDF through pdftotext when the host has it — up to 20 MB of text. filing_text upserted; filings'
 * fetched_text_at, text_sha256, char_count set; an error kept on the filing. Answers ['ok', 'chars', 'error'].
 */

require_once __DIR__ . '/html_to_text.php';
require_once __DIR__ . '/xml.php';

const SI_FILING_TEXT_MAX = 20 * 1024 * 1024;

/** The archives' base (the stub's in a proof). */
function si_archives_base(PDO $pdo): string
{
    $s = json_decode((string) one_value($pdo, "SELECT settings FROM sources WHERE connector = 'edgar_submissions' ORDER BY id LIMIT 1"), true) ?: [];
    return rtrim((string) ($s['archives_url'] ?? 'https://www.sec.gov/Archives/edgar/data/'), '/') . '/';
}

/** A document of a filing on the archives. */
function si_archives_url(PDO $pdo, string $cik, string $accession, string $document): string
{
    return si_archives_base($pdo) . ltrim($cik, '0') . '/' . str_replace('-', '', $accession) . '/' . ltrim($document, '/');
}

/** A document's bytes as text by its name. */
function si_document_text(string $name, string $bytes): array
{
    $ext = strtolower(pathinfo(parse_url($name, PHP_URL_PATH) ?: $name, PATHINFO_EXTENSION));
    if ($ext === 'xml') {
        return [si_xml_text($bytes), 'xml'];
    }
    if ($ext === 'pdf' || str_starts_with($bytes, '%PDF')) {
        $bin = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            throw new RuntimeException('A PDF filing needs pdftotext on the host to be read.');
        }
        $in = tempnam(sys_get_temp_dir(), 'sipdf');
        file_put_contents($in, $bytes);
        $out = (string) shell_exec(escapeshellarg($bin) . ' -layout -q ' . escapeshellarg($in) . ' - 2>/dev/null');
        @unlink($in);
        return [si_text_tidy($out), 'pdf'];
    }
    if ($ext === 'txt') {
        return [si_html_to_text($bytes), 'txt'];
    }
    return [si_html_to_text($bytes), 'html'];
}

function si_filing_text_fetch(PDO $pdo, SiHttp $client, int $filingId): array
{
    $f = $pdo->query('SELECT id, filer_cik, accession_number, primary_document FROM filings WHERE id = ' . $filingId)->fetch();
    if ($f === false) {
        return ['ok' => false, 'chars' => 0, 'error' => 'Not found.'];
    }
    if ($f['primary_document'] === null || $f['primary_document'] === '') {
        $pdo->prepare('UPDATE filings SET error = :e WHERE id = :i')->execute(['e' => 'The index names no primary document.', 'i' => $filingId]);
        return ['ok' => false, 'chars' => 0, 'error' => 'The index names no primary document.'];
    }
    $url = si_archives_url($pdo, (string) $f['filer_cik'], (string) $f['accession_number'], (string) $f['primary_document']);
    $r = $client->get($url, ['accept' => 'text/html, application/xml, text/plain, application/pdf;q=0.8', 'max_bytes' => 60 * 1024 * 1024]);
    if ($r['blocked'] || $r['skipped'] || !$r['ok']) {
        $e = $r['blocked'] ? 'EDGAR refused the document (' . $r['status'] . ').' : ($r['skipped'] ? 'robots.txt disallows the document.' : 'EDGAR answered ' . $r['status'] . ' for the document.');
        $pdo->prepare('UPDATE filings SET error = :e WHERE id = :i')->execute(['e' => $e, 'i' => $filingId]);
        return ['ok' => false, 'chars' => 0, 'error' => $e, 'blocked' => $r['blocked']];
    }
    try {
        [$text, $kind] = si_document_text((string) $f['primary_document'], $r['body']);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE filings SET error = :e WHERE id = :i')->execute(['e' => mb_substr($e->getMessage(), 0, 300), 'i' => $filingId]);
        return ['ok' => false, 'chars' => 0, 'error' => $e->getMessage()];
    }
    $text = mb_strcut($text, 0, SI_FILING_TEXT_MAX, 'UTF-8');
    $pdo->prepare('INSERT INTO filing_text (filing_id, text, extractor) VALUES (:i, :t, :k) ON CONFLICT (filing_id) DO UPDATE SET text = EXCLUDED.text, extractor = EXCLUDED.extractor, extracted_at = now()')
        ->execute(['i' => $filingId, 't' => $text, 'k' => $kind]);
    $pdo->prepare('UPDATE filings SET fetched_text_at = now(), text_sha256 = :h, char_count = :c, error = NULL WHERE id = :i')->execute(['h' => hash('sha256', $text), 'c' => mb_strlen($text), 'i' => $filingId]);
    return ['ok' => true, 'chars' => mb_strlen($text), 'error' => null, 'kind' => $kind];
}

/** The sentence or so around a term in a text (a watch hit's snippet): up to 300 characters, the term kept whole. */
function si_text_snippet(string $text, string $term, int $around = 140): ?string
{
    $p = mb_stripos($text, $term);
    if ($p === false) {
        return null;
    }
    $from = max(0, $p - $around);
    $s = mb_substr($text, $from, mb_strlen($term) + 2 * $around);
    $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
    return ($from > 0 ? '…' : '') . trim($s) . ($from + mb_strlen($s) < mb_strlen($text) ? '…' : '');
}
