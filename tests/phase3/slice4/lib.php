<?php
/**
 * Helpers for slice 4's proofs (docs/build-specs/edgar-filings.md "Proof"): slice 1's library (the stub, the worker, a JSON post), the SMOKE
 * issuer of the EDGAR samples (CIK 0000999999, ticker SMOK, its common stock), a pull of one source by the worker on a market morning, and the
 * filing of an accession.
 */
require dirname(__DIR__) . '/slice1/lib.php';
const MARKET_MORNING = '2026-10-07T10:00:00-04:00';   // a Wednesday, 10:00 in New York

/** The SMOKE issuer of the samples and its common stock (made once): ['issuer' => id, 'security' => id]. */
function smoke_issuer(): array
{
    $i = one("SELECT id FROM issuers WHERE cik = '0000999999'");
    if ($i === false) {
        q("INSERT INTO issuers (name, ticker, cik, exchange) VALUES ('SMOKE ISSUER INC', 'SMOK', '0000999999', 'nasdaq')");
        $i = one("SELECT id FROM issuers WHERE cik = '0000999999'");
        q("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) VALUES (:i, 'Common Stock', '07831X206', 'SMOK', true)", ['i' => $i]);
    }
    return ['issuer' => (int) $i, 'security' => (int) one('SELECT id FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $i])];
}

/** One source pulled by the worker's fetch pass on a market morning (made due first). Answers the worker's report. */
function pull(string $connector, string $at = MARKET_MORNING): array
{
    source_due($connector);
    q("UPDATE sources SET paused_at = now() WHERE connector <> :c AND paused_at IS NULL", ['c' => $connector]);
    $w = worker('fetch', $at);
    q("UPDATE sources SET paused_at = NULL WHERE connector <> :c", ['c' => $connector]);
    return $w;
}

/** The latest pull of a connector. */
function last_pull(string $connector): array
{
    return q("SELECT p.* FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE s.connector = :c ORDER BY p.id DESC LIMIT 1", ['c' => $connector])[0] ?? [];
}

/** A filing's id by its accession. */
function filing_id(string $accession): int
{
    return (int) one('SELECT id FROM filings WHERE accession_number = :a', ['a' => $accession]);
}
