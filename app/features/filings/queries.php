<?php
declare(strict_types=1);

/**
 * Filings (slice 4): the issuer's index and others' filings about it, their text, the search, the watch's hits. Every read is through the
 * mcp_* views (the issuer wall) or si_filings_search (the wall inside). A filing's text over 1 MB is read by window.
 */

const FILINGS_PAGE = 50;
const FILING_TEXT_WINDOW = 100000;
/** The form filters as chips: label => the forms. */
const FILING_FORM_GROUPS = ['8-K' => ['8-K', '8-K/A'], '10-K' => ['10-K', '10-K/A'], '10-Q' => ['10-Q', '10-Q/A'], 'S-1' => ['S-1', 'S-1/A'], 'S-3' => ['S-3', 'S-3/A'],
    '424B' => ['424B1', '424B2', '424B3', '424B4', '424B5', '424B7'], '13D' => ['SC 13D', 'SC 13D/A'], '13G' => ['SC 13G', 'SC 13G/A'], '4' => ['3', '4', '5', '3/A', '4/A', '5/A'],
    '144' => ['144', '144/A'], 'DEF 14A' => ['DEF 14A', 'DEFA14A', 'PRE 14A']];

/** The chip a form goes under (or "other"). */
function filing_form_group(string $form): string
{
    foreach (FILING_FORM_GROUPS as $g => $forms) {
        if (in_array($form, $forms, true)) {
            return (string) $g;
        }
    }
    return 'other';
}

/** An issuer's filings, newest first: filters form (a chip), from, to, about_us (yes), q (the form, the description, the accession). */
function find_filings(PDO $pdo, int $issuer, array $filters, int $page): array
{
    $where = ['f.issuer_id = :i'];
    $args = ['i' => $issuer];
    $g = $filters['form'] ?? '';
    if (isset(FILING_FORM_GROUPS[$g])) {
        $where[] = 'f.form = ANY (CAST(:forms AS text[]))';
        $args['forms'] = '{' . implode(',', array_map(static fn ($x) => '"' . $x . '"', FILING_FORM_GROUPS[$g])) . '}';
    } elseif ($g === 'other') {
        $where[] = 'NOT (f.form = ANY (CAST(:forms AS text[])))';
        $args['forms'] = '{' . implode(',', array_map(static fn ($x) => '"' . $x . '"', array_merge(...array_values(FILING_FORM_GROUPS)))) . '}';
    }
    if (($filters['from'] ?? null) !== null) { $where[] = 'f.filed_on >= :from'; $args['from'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 'f.filed_on <= :to'; $args['to'] = $filters['to']; }
    if (!empty($filters['about_us'])) { $where[] = 'f.about_us'; }
    if (($filters['q'] ?? '') !== '') {
        $where[] = "(f.form ILIKE :q OR coalesce(f.primary_doc_description, '') ILIKE :q OR f.accession_number ILIKE :q)";
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare('SELECT f.*, (SELECT event_id FROM mcp_events e WHERE e.filing_id = f.filing_id ORDER BY e.event_id LIMIT 1) AS event_id, count(*) OVER () AS total
                           FROM mcp_filings f WHERE ' . implode(' AND ', $where) . ' ORDER BY f.filed_on DESC, f.filing_id DESC LIMIT ' . FILINGS_PAGE . ' OFFSET ' . (max(1, $page) - 1) * FILINGS_PAGE);
    $st->execute($args);
    return $st->fetchAll();
}

function find_filing(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT f.*, (SELECT name FROM mcp_issuers WHERE issuer_id = f.issuer_id) AS issuer_name, (SELECT cik FROM mcp_issuers WHERE issuer_id = f.issuer_id) AS issuer_cik FROM mcp_filings f WHERE f.filing_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * A window of a filing's text: from $offset, $length characters (the screen 100,000, a tool 6,000), or — with $around — the passages around
 * each occurrence of a term (up to 20). Answers ['char_count', 'offset', 'text', 'passages' => [[offset, text]], 'hits' => count].
 */
function filing_text_window(PDO $pdo, int $id, int $offset, int $length, ?string $around): array
{
    $text = (string) (one_value($pdo, 'SELECT text FROM mcp_filing_text WHERE filing_id = :i', ['i' => $id]) ?? '');
    $n = mb_strlen($text);
    $offset = max(0, min($offset, max(0, $n - 1)));
    $out = ['char_count' => $n, 'offset' => $offset, 'text' => mb_substr($text, $offset, max(1, $length)), 'passages' => [], 'hits' => 0];
    if ($around !== null && trim($around) !== '') {
        $p = 0;
        $needle = mb_strtolower(trim($around));
        $lower = mb_strtolower($text);
        while (($p = mb_strpos($lower, $needle, $p)) !== false) {
            $out['hits']++;
            if (count($out['passages']) < 20) {
                $from = max(0, $p - 300);
                $out['passages'][] = ['offset' => $from, 'text' => mb_substr($text, $from, 600 + mb_strlen($needle))];
            }
            $p += max(1, mb_strlen($needle));
        }
    }
    return $out;
}

/** The words across the issuer's filings' text (si_filings_search, the wall inside), a form chip narrowing it. */
function filings_search(PDO $pdo, int $issuer, string $q, ?string $form, int $page): array
{
    if (trim($q) === '') {
        return [];
    }
    $st = $pdo->prepare('SELECT * FROM si_filings_search(:i, :q, 100)');
    $st->execute(['i' => $issuer, 'q' => mb_substr($q, 0, 200)]);
    $rows = $st->fetchAll();
    if ($form !== null && isset(FILING_FORM_GROUPS[$form])) {
        $rows = array_values(array_filter($rows, static fn ($r) => in_array($r['form'], FILING_FORM_GROUPS[$form], true)));
    }
    return array_slice($rows, (max(1, $page) - 1) * 20, 20);
}

/** ts_headline's <b>…</b> made safe: the text escaped, the markers a <mark>. */
function search_headline_html(string $headline): string
{
    return str_replace(['&lt;b&gt;', '&lt;/b&gt;'], ['<mark>', '</mark>'], e($headline));
}

/** The watch's hits, newest first, by status (new by default). */
function find_watch_hits(PDO $pdo, int $issuer, ?string $status, int $page): array
{
    $st = $pdo->prepare('SELECT w.*, f.form, f.filed_on, f.about_us, f.primary_doc_description, f.accession_number, f.filer_cik,
                                (SELECT instrument_id FROM mcp_dilution_instruments d WHERE d.filing_id = w.filing_id AND d.watch_term = w.term ORDER BY d.instrument_id LIMIT 1) AS instrument_id,
                                (SELECT kind FROM mcp_dilution_instruments d WHERE d.filing_id = w.filing_id AND d.watch_term = w.term ORDER BY d.instrument_id LIMIT 1) AS instrument_kind,
                                count(*) OVER () AS total
                           FROM mcp_watch_hits w LEFT JOIN mcp_filings f ON f.filing_id = w.filing_id
                          WHERE w.issuer_id = :i' . ($status !== null ? ' AND w.status = :s' : '') . ' ORDER BY w.found_at DESC, w.watch_hit_id DESC LIMIT 30 OFFSET ' . (max(1, $page) - 1) * 30);
    $st->execute(['i' => $issuer] + ($status !== null ? ['s' => $status] : []));
    return $st->fetchAll();
}

function find_watch_hit(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT w.*, f.form, f.filed_on, f.primary_doc_description FROM mcp_watch_hits w LEFT JOIN mcp_filings f ON f.filing_id = w.filing_id WHERE w.watch_hit_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The event a filing made, and its structured rows (slice 5's tables, when present). */
function filing_context(PDO $pdo, int $id): array
{
    $ev = $pdo->query('SELECT * FROM mcp_events WHERE filing_id = ' . $id . ' ORDER BY event_id LIMIT 1')->fetch() ?: null;
    $structured = [];
    foreach (['beneficial' => 'mcp_beneficial_ownership', 'insider' => 'mcp_insider_transactions', 'form144' => 'mcp_form144_notices'] as $k => $view) {
        try {
            $rows = $pdo->query("SELECT * FROM $view WHERE filing_id = $id LIMIT 50")->fetchAll();
            if ($rows !== []) { $structured[$k] = $rows; }
        } catch (Throwable) {
        }
    }
    $hits = $pdo->query('SELECT * FROM mcp_watch_hits WHERE filing_id = ' . $id . ' ORDER BY watch_hit_id')->fetchAll();
    return ['event' => $ev, 'structured' => $structured, 'hits' => $hits];
}
