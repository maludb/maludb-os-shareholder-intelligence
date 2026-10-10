<?php
declare(strict_types=1);

/** What every filings and dilution screen and handler starts with (slice 4). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/dilution/queries.php';
require_once dirname(__DIR__) . '/dilution/write.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__) . '/notes/write.php';

/** The current issuer for a filings screen, or the empty page. */
function filings_issuer_or_empty(PDO $pdo, string $screen, string $title, string $nav = 'filings'): array
{
    $issuer = current_issuer($pdo);
    if ($issuer === null) {
        log_screen_view($pdo, $screen);
        if (wants_json()) { respond_screen(['issuer_id' => null]); }
        render_screen($title, view('positions/partials/no-issuer.php', ['id' => $screen, 'title' => $title, 'issuer' => null]), ['activeNav' => $nav, 'screen' => $screen]);
        exit;
    }
    return $issuer;
}

/** A filing the request names, or 404. */
function filing_from_request(PDO $pdo, string $field = 'filing'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $f = $id === null ? null : find_filing($pdo, $id);
    if ($f === null) {
        refuse(404, 'Not found.');
    }
    return $f;
}

/** An instrument the request names, or 404. */
function instrument_from_request(PDO $pdo, string $field = 'instrument'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $d = $id === null ? null : find_dilution_one($pdo, $id);
    if ($d === null) {
        refuse(404, 'Not found.');
    }
    return $d;
}

/** The filings screens' tabs. */
function filings_tabs(string $active): string
{
    $tabs = [['list', '/filings', 'Filings'], ['search', '/filings/search', 'Search the text'], ['watch', '/filings/watch', 'The watch'], ['dilution', '/dilution', 'Dilution']];
    $o = '<div class="d-flex flex-wrap gap-1 mb-3" id="filings-tabs" role="tablist">';
    foreach ($tabs as [$k, $u, $l]) {
        $o .= hx_link($u, e($l), 'btn btn-touch btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-light'), 'id="filings-tab-' . $k . '"');
    }
    return $o . '</div>';
}
