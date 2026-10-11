<?php
declare(strict_types=1);

/**
 * The pack's rendering (events-reports.md "Divergences" 1): the HTML is built at render time from the sections' own queries — the SAME functions the screens use —
 * and the narrative is the only text stored. Each section is a partial (app/views/reports/partials/section-<key>.php) that draws what pack_section_data()
 * hands it. A pack holds aggregates, entities and the public record, never a natural person's name (the holders sections are counts and ranges), so one
 * rendered by an Analyst reads the same to a Viewer. A PDF is made only when SI_WKHTMLTOPDF names an executable; otherwise the HTML is the pack.
 */
require_once dirname(__DIR__) . '/positions/queries.php';
require_once dirname(__DIR__) . '/holders/queries.php';
require_once dirname(__DIR__) . '/ownership/queries.php';
require_once dirname(__DIR__) . '/short/queries.php';
require_once dirname(__DIR__) . '/reconciliation/queries.php';
require_once dirname(__DIR__) . '/events/queries.php';
require_once dirname(__DIR__) . '/events/present.php';
require_once dirname(__DIR__) . '/signals/present.php';
require_once dirname(__DIR__) . '/issuers/queries.php';
require_once dirname(__DIR__) . '/issuers/present.php';
require_once dirname(__DIR__, 2) . '/si/storage.php';
require_once __DIR__ . '/queries.php';

/** A reconciliation's state in words (uploads/write.php has the same rule; the pack does not need the upload pipeline). */
function pack_recon_state(array|false|null $r): string
{
    if (!$r || ($r['tied_registered'] === null && $r['tied_spr'] === null)) { return 'incomplete'; }
    return ($r['tied'] === true || $r['tied'] === 't') ? 'tied' : 'untied';
}

/** What every section needs: the pack, the issuer, its primary security (null when it has none) and the period. */
function pack_context(PDO $pdo, array $report): array
{
    $st = $pdo->prepare('SELECT security_id, name, ticker, cusip FROM mcp_securities WHERE issuer_id = :i ORDER BY is_primary DESC, security_id LIMIT 1');
    $st->execute(['i' => (int) $report['issuer_id']]);
    return ['report' => $report, 'issuer' => find_issuer($pdo, (int) $report['issuer_id']) ?? ['name' => (string) ($report['issuer_name'] ?? ''), 'issuer_id' => (int) $report['issuer_id']],
            'security' => $st->fetch() ?: null, 'from' => (string) $report['period_from'], 'to' => (string) $report['period_to']];
}

/** The rows a section draws, from the screens' own queries; the security-less pack gets an empty answer per section. */
function pack_section_data(PDO $pdo, string $key, array $ctx): array
{
    $sec = $ctx['security'] === null ? null : (int) $ctx['security']['security_id'];
    $iss = (int) $ctx['report']['issuer_id'];
    $from = $ctx['from'];
    $to = $ctx['to'];
    if ($sec === null && !in_array($key, ['insiders', 'signals', 'events'], true)) {
        return ['none' => 'The issuer has no security on record.'];
    }
    switch ($key) {
        case 'movement':
            $dates = position_dates($pdo, $sec, $from, $to);
            if (count($dates) < 2) { return ['dates' => $dates, 'rows' => null]; }
            $first = $dates[0]; $last = $dates[count($dates) - 1];
            $p = positions_on($pdo, $sec, $last, $dates[count($dates) - 2], $first, null, null);
            $rows = array_values(array_filter($p['rows'], static fn (array $r): bool => $r['net_window'] !== null));
            $up = $rows; usort($up, static fn (array $a, array $b): int => (float) $b['net_window'] <=> (float) $a['net_window']);
            $down = $rows; usort($down, static fn (array $a, array $b): int => (float) $a['net_window'] <=> (float) $b['net_window']);
            return ['dates' => $dates, 'first' => $first, 'last' => $last, 'total_first' => one_value($pdo, 'SELECT si_spr_total(:s, CAST(:d AS date))', ['s' => $sec, 'd' => $first]), 'total_last' => $p['total'],
                    'up' => array_slice(array_values(array_filter($up, static fn ($r): bool => (float) $r['net_window'] > 0)), 0, 5), 'down' => array_slice(array_values(array_filter($down, static fn ($r): bool => (float) $r['net_window'] < 0)), 0, 5),
                    'new' => count(array_filter($p['rows'], static fn ($r): bool => (bool) $r['is_new'])), 'gone' => count($p['gone'])];
        case 'flow':
            return ['rows' => flow_by_date($pdo, $sec, $from, $to)];
        case 'top_participants':
            $dates = position_dates($pdo, $sec, '1900-01-01', $to);
            if ($dates === []) { return ['rows' => null]; }
            $last = $dates[count($dates) - 1];
            $prev = count($dates) > 1 ? $dates[count($dates) - 2] : null;
            $p = positions_on($pdo, $sec, $last, $prev, null, null, null);
            return ['date' => $last, 'prev' => $prev, 'total' => $p['total'], 'rows' => array_slice($p['rows'], 0, 10)];
        case 'holders_ranges':
            $dates = holder_record_dates($pdo, $sec, 'nobo');
            $date = null;
            foreach ($dates as $d) { if ($d <= $to) { $date = $d; break; } }
            $date ??= $dates[0] ?? null;
            return ['date' => $date] + ($date === null ? ['list' => [], 'broadridge' => []] : holders_ranges($pdo, $sec, 'nobo', $date));
        case 'holders_geography':
            $dates = holder_record_dates($pdo, $sec, 'nobo');
            $date = null;
            foreach ($dates as $d) { if ($d <= $to) { $date = $d; break; } }
            $date ??= $dates[0] ?? null;
            $rows = $date === null ? [] : holders_geography($pdo, $sec, 'nobo', $date);
            usort($rows, static fn (array $a, array $b): int => (float) $b['shares'] <=> (float) $a['shares']);
            return ['date' => $date, 'rows' => array_slice($rows, 0, 10), 'total' => array_sum(array_map(static fn ($r): float => (float) $r['shares'], $rows))];
        case 'thirteen_f':
            $t = thirteen_f($pdo, $sec, null);
            return ['period' => $t['period'], 'summary' => $t['summary'], 'rows' => array_slice($t['holders'], 0, 10)];
        case 'beneficial':
            return ['rows' => beneficial($pdo, $sec)];
        case 'insiders':
            $x = insiders($pdo, $iss, ['from' => $from, 'to' => $to], 1);
            return ['rows' => array_slice($x, 0, 15), 'total' => count($x) > 0 ? (int) $x[0]['total'] : 0];
        case 'short_side':
            return ['now' => short_vs_float($pdo, $sec, $to), 'series' => array_slice(short_interest($pdo, $sec, (new DateTimeImmutable($from))->modify('-45 days')->format('Y-m-d'), $to), 0, 6)];
        case 'fails_threshold':
            $t = threshold_runs($pdo, $sec);
            return ['fails' => array_slice(fails($pdo, $sec, $from, $to), 0, 8), 'status' => $t['status'], 'runs' => array_slice($t['runs'], 0, 3)];
        case 'reconciliation':
            $r = reconciliation_page($pdo, $sec)['latest'];
            return ['latest' => $r, 'state' => pack_recon_state($r)];
        case 'signals':
            $st = $pdo->prepare("SELECT s.signal_id, s.rule_name, s.evidence_class, s.severity, s.status, s.raised_on, s.summary FROM mcp_signals s WHERE s.issuer_id = :i AND s.raised_on BETWEEN CAST(:a AS date) AND CAST(:b AS date)
                                  ORDER BY CASE s.severity WHEN 'high' THEN 1 WHEN 'watch' THEN 2 ELSE 3 END, s.raised_on DESC, s.signal_id DESC LIMIT 25");
            $st->execute(['i' => $iss, 'a' => $from, 'b' => $to]);
            return ['rows' => $st->fetchAll()];
        case 'events':
            $e = find_events($pdo, $iss, ['from' => $from, 'to' => $to], 1);
            return ['rows' => array_slice($e['rows'], 0, 30), 'total' => $e['total']];
    }
    return ['none' => 'Unknown section.'];
}

/** Each chosen section of a pack drawn in print order, from the data on record now. */
function pack_sections_html(PDO $pdo, array $report): string
{
    $ctx = pack_context($pdo, $report);
    $sections = '';
    foreach (report_sections_list($report['sections']) as $key) {
        $sections .= view('reports/partials/section-' . $key . '.php', ['d' => pack_section_data($pdo, $key, $ctx), 'ctx' => $ctx, 'title' => REPORT_SECTIONS[$key]]);
    }
    return $sections;
}

/** The pack's body as HTML: the title, the period, the narrative, then the sections. */
function pack_body_html(PDO $pdo, array $report): string
{
    return view('reports/pack.php', ['r' => $report, 'ctx' => pack_context($pdo, $report), 'narrative' => markdown_html((string) ($report['narrative'] ?? '')), 'sections' => pack_sections_html($pdo, $report)]);
}

/** The sections of a stored rendering (what the pack looked like when it was approved), or null when the file is gone or is not ours. */
function pack_stored_sections(?string $rel): ?string
{
    if ($rel === null) { return null; }
    try { $html = si_stored_named($rel); } catch (SiStoreError) { return null; }
    return $html !== null && preg_match('~<!--pack-sections-->(.*)<!--/pack-sections-->~s', $html, $m) ? $m[1] : null;
}

/** The stand-alone document: the body inside a page with the few styles it needs (it opens without the application's stylesheets). */
function pack_document(string $title, string $body): string
{
    $css = 'body{font:14px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;margin:24px auto;max-width:960px;padding:0 16px}h1{font-size:22px}h2{font-size:18px;margin-top:28px;border-bottom:1px solid #e5e7eb;padding-bottom:4px}h3{font-size:16px;margin-top:22px}'
        . 'table{border-collapse:collapse;width:100%;font-size:12.5px;margin:8px 0}th,td{border-bottom:1px solid #e5e7eb;padding:4px 6px;text-align:left}.text-end{text-align:right}.text-muted,.fs-11,.fs-12{color:#6b7280}.fs-11{font-size:11px}.fs-12{font-size:12px}'
        . '.badge{display:inline-block;padding:1px 6px;border-radius:4px;background:#eef2ff;color:#3454d1;font-size:11px}.feather-info{display:none}section{page-break-inside:avoid}.si-pack-meta{color:#6b7280}';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title><style>' . $css . '</style></head><body>' . $body . '</body></html>';
}

/**
 * Render a pack: the HTML document and, when SI_WKHTMLTOPDF names an executable, the PDF (stored; pdf_path relative to the store). Answers
 * ['html' => document, 'body' => the fragment, 'pdf_path' => ?string]. Nothing is installed: no binary, no PDF.
 */
function render_pack(PDO $pdo, int $report): array
{
    $r = find_report($pdo, $report) ?? throw new DomainException('Not found.');
    $body = pack_body_html($pdo, $r);
    $html = pack_document((string) $r['title'], $body);
    $pdf = pack_pdf_from_html($report, $html);
    return ['html' => $html, 'body' => $body, 'pdf_path' => $pdf];
}

/** Run $fn with the database's acting member set to $member (the worker renders as the pack's approver, so the walls read as that person), then put it back. */
function as_member(PDO $pdo, int $member, callable $fn): mixed
{
    $was = (string) one_value($pdo, "SELECT current_setting('app.member_id', true)");
    $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => (string) $member]);
    try {
        return $fn();
    } finally {
        $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => $was]);
    }
}

/**
 * A pack's PDF from its HTML document, when SI_WKHTMLTOPDF names an executable (stored; the path relative to the store), else null. The renderer reads
 * the HTML with JavaScript and local files off. Used at render time and, for a pack approved before a renderer existed, from its stored HTML (report_pdf_late).
 */
function pack_pdf_from_html(int $report, string $html): ?string
{
    $bin = (string) env('SI_WKHTMLTOPDF', '');
    if ($bin === '' || !is_executable($bin)) {
        return null;
    }
    $tmpIn = tempnam(sys_get_temp_dir(), 'sipack') ?: throw new RuntimeException('No temporary file.');
    $tmpOut = $tmpIn . '.pdf';
    try {
        file_put_contents($tmpIn, $html);
        $p = proc_open([$bin, '--quiet', '--disable-javascript', '--disable-local-file-access', $tmpIn, $tmpOut], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($p)) {
            stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            proc_close($p);
        }
        $bytes = is_file($tmpOut) ? (string) file_get_contents($tmpOut) : '';
        return str_starts_with($bytes, '%PDF') ? si_store_named('reports', $report, 'pdf', $bytes) : null;
    } finally {
        @unlink($tmpIn); @unlink($tmpOut);
    }
}

