<?php
declare(strict_types=1);

/** The filings' JSON presenters (slice 4): whitelists over the mcp_* rows — never the text but through filing_read's window. */

function present_filing(array $f): array
{
    return ['filing_id' => (int) $f['filing_id'], 'issuer_id' => (int) $f['issuer_id'], 'form' => $f['form'], 'filed_on' => $f['filed_on'], 'report_date' => $f['report_date'],
            'accession_number' => $f['accession_number'], 'filer_cik' => $f['filer_cik'], 'items' => pg_text_array((string) $f['items']), 'description' => $f['primary_doc_description'],
            'url' => $f['url'], 'about_us' => (bool) $f['about_us'], 'has_text' => (bool) $f['has_text'], 'char_count' => (int) $f['char_count'], 'structured' => $f['structured_at'] !== null,
            'watched' => (bool) $f['watched'], 'event_id' => isset($f['event_id']) && $f['event_id'] !== null ? (int) $f['event_id'] : null, 'error' => $f['error']];
}

function present_watch_hit(array $w): array
{
    return ['watch_hit_id' => (int) $w['watch_hit_id'], 'filing_id' => $w['filing_id'] === null ? null : (int) $w['filing_id'], 'term' => $w['term'], 'snippet' => $w['snippet'], 'found_at' => json_ts($w['found_at']),
            'status' => $w['status'], 'form' => $w['form'] ?? null, 'filed_on' => $w['filed_on'] ?? null, 'about_us' => isset($w['about_us']) ? (bool) $w['about_us'] : null,
            'instrument_id' => isset($w['instrument_id']) && $w['instrument_id'] !== null ? (int) $w['instrument_id'] : null, 'instrument_kind' => $w['instrument_kind'] ?? null];
}

/** A form's chip. */
function form_chip(string $form): string
{
    $g = filing_form_group($form);
    $c = match ($g) { '13D' => 'text-bg-danger', '8-K', 'S-1', 'S-3', '424B' => 'text-bg-warning', '10-K', '10-Q' => 'text-bg-primary', '4', '144', '13G' => 'text-bg-info', default => 'text-bg-light' };
    return '<span class="badge ' . $c . '">' . e($form) . '</span>';
}
