<?php
declare(strict_types=1);

/**
 * What the three ownership connectors share (slice 5): EDGAR lists a schedule 13D/G, a Form 3/4/5 and a Form 144 under its SUBJECT company, so they
 * are read from the issuer's own filing index (slice 4) — the filings of their forms not yet structured, oldest first — each one's RAW XML (the
 * index names the rendered page, "xslF345X05/form4.xml"; the raw document is "form4.xml") fetched under the EDGAR policy and parsed by
 * app/si/fetch/xml.php (an entity declaration refused). A filing read or refused is marked structured (structured_at) so it is not read again;
 * a refusal keeps its words in the filing's error.
 */

require_once __DIR__ . '/filing_text.php';
require_once __DIR__ . '/xml.php';

/** The raw document an index entry names (the xsl rendering's folder dropped). */
function si_raw_document(string $doc): string
{
    return (string) preg_replace('~^xsl[^/]+/~i', '', $doc);
}

/** The issuer's filings of these forms not yet structured (one issuer, or every issuer the source serves), oldest first. */
function si_ownership_due(PDO $pdo, array $source, array $ctx, array $forms, ?int $limit = null): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $limit ??= max(1, min(500, (int) ($settings['per_pull'] ?? 40)));           // a backlog (a new issuer's years of Forms 4) clears over a few pulls
    $only = array_map('intval', (array) ($settings['issuers'] ?? []));
    $sql = "SELECT f.*, i.cik AS issuer_cik FROM filings f JOIN issuers i ON i.id = f.issuer_id WHERE i.deleted_at IS NULL AND f.structured_at IS NULL AND f.primary_document IS NOT NULL
               AND f.form = ANY (CAST(:forms AS text[]))";
    $args = ['forms' => '{' . implode(',', array_map(static fn ($f) => '"' . $f . '"', $forms)) . '}'];
    if (($ctx['issuer'] ?? null) !== null) { $sql .= ' AND f.issuer_id = :one'; $args['one'] = (int) $ctx['issuer']; }
    elseif ($only !== []) { $sql .= ' AND f.issuer_id = ANY (CAST(:only AS bigint[]))'; $args['only'] = '{' . implode(',', $only) . '}'; }
    $st = $pdo->prepare($sql . ' ORDER BY f.filed_on, f.id LIMIT ' . $limit);
    $st->execute($args);
    return $st->fetchAll();
}

/** A filing's raw document: ['ok', 'body', 'blocked', 'error']. */
function si_ownership_document(PDO $pdo, SiHttp $client, array $f): array
{
    $doc = si_raw_document((string) $f['primary_document']);
    $r = $client->get(si_archives_url($pdo, (string) $f['filer_cik'], (string) $f['accession_number'], $doc), ['accept' => 'application/xml, text/xml, text/html']);
    if ($r['blocked']) {
        return ['ok' => false, 'blocked' => true, 'body' => '', 'error' => 'EDGAR refused the document (' . $r['status'] . ').'];
    }
    if (!$r['ok']) {
        return ['ok' => false, 'blocked' => false, 'body' => '', 'error' => 'EDGAR answered ' . $r['status'] . ' for ' . $doc . '.'];
    }
    return ['ok' => true, 'blocked' => false, 'body' => $r['body'], 'error' => null, 'name' => $doc];
}

/**
 * True when the document is about the issuer (or names no subject): EDGAR lists a filing under every company it concerns, so the issuer's
 * index carries the schedules and Forms 4 the issuer itself FILED about another company (Bitmine's 13D on Eightco). The subject is the
 * document's issuerCik / issuerCIK.
 */
function si_ownership_about(SimpleXMLElement $x, string $issuerCik): bool
{
    $c = si_x($x, 'issuerCik') ?? si_x($x, 'issuerCIK');
    return $c === null || !preg_match('~^\d{1,10}$~', $c) || str_pad($c, 10, '0', STR_PAD_LEFT) === $issuerCik;
}

/** The words for a document about another company (kept on the filing; nothing is loaded). */
const SI_NOT_ABOUT_US = 'About another company: the issuer filed it, its subject is not the issuer.';

/** Mark a filing read (or refused, with its words). */
function si_ownership_mark(PDO $pdo, int $filing, ?string $error): void
{
    $pdo->prepare('UPDATE filings SET structured_at = now(), error = CASE WHEN CAST(:e AS text) IS NULL THEN error ELSE CAST(:e AS text) END WHERE id = :i')->execute(['e' => $error === null ? null : mb_substr($error, 0, 300), 'i' => $filing]);
}

/** The first text of an element by local name anywhere below $x (namespaces are stripped by si_xml_load). */
function si_x(SimpleXMLElement $x, string $name, ?SimpleXMLElement $under = null): ?string
{
    $hit = ($under ?? $x)->xpath('.//' . $name);
    if ($hit === false || $hit === []) {
        return null;
    }
    $e = $hit[0];
    $v = isset($e->value) ? (string) $e->value : (string) $e;
    $v = trim(preg_replace('~\s+~', ' ', $v) ?? $v);
    return $v === '' ? null : $v;
}

/** A number from a cell (commas, a sign); null when not one. */
function si_num(?string $v): ?string
{
    $v = str_replace([',', '$', ' '], '', (string) $v);
    return is_numeric($v) ? $v : null;
}

/** A date from MM/DD/YYYY, YYYY-MM-DD or DD-MON-YYYY (the 13F sets); null otherwise. */
function si_date_any(?string $v): ?string
{
    $v = trim((string) $v);
    if (preg_match('~^(\d{2})-([A-Z]{3})-(\d{4})$~i', $v, $m)) {
        $t = strtotime($m[1] . ' ' . $m[2] . ' ' . $m[3]);
        return $t === false ? null : date('Y-m-d', $t);
    }
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $v, $m)) {
        return checkdate((int) $m[1], (int) $m[2], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
    }
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }
    return null;
}
