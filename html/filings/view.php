<?php
declare(strict_types=1);
/** /filings/{id}?q=&at= — one filing (screen `filing-view`): the index row, the text in a reading column with the q hits highlighted and "next hit" links (a window of 100,000 characters from `at`), the structured rows when present, the event it made, the watch's hits; Fetch text, Make event. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$f = filing_from_request($pdo, 'id');
$fid = (int) $f['filing_id'];
$q = mb_substr(trim(request_string('q')), 0, 100);
$at = max(0, request_integer('at') ?? 0);
$win = filing_text_window($pdo, $fid, $at, FILING_TEXT_WINDOW, $q !== '' ? $q : null);
$ctx = filing_context($pdo, $fid);
log_screen_view($pdo, 'filing-view');
if (wants_json()) {
    respond_screen(['filing' => present_filing($f), 'event' => $ctx['event'] === null ? null : ['event_id' => (int) $ctx['event']['event_id'], 'title' => $ctx['event']['title'], 'severity' => $ctx['event']['severity']],
        'text' => ['char_count' => $win['char_count'], 'offset' => $win['offset'], 'length' => mb_strlen($win['text']), 'hits' => $win['hits']], 'structured' => array_map('count', $ctx['structured']),
        'watch_hits' => array_map(static fn ($w) => ['watch_hit_id' => (int) $w['watch_hit_id'], 'term' => $w['term'], 'status' => $w['status']], $ctx['hits'])]);
}
$notices = ['fetched' => ['success', 'The text is read.'], 'event_made' => ['success', 'The event is made.']];
render_screen($f['form'] . ' — ' . format_date($f['filed_on']), view('filings/view.php', ['f' => $f, 'q' => $q, 'win' => $win, 'ctx' => $ctx, 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'filings', 'screen' => 'filing-view', 'entity' => 'filing', 'recordId' => (string) $fid]);
