<?php
/**
 * Proof: the calendar (events-reports.md) — the month grid of the current issuer: past events as chips by kind in their day, a day with something is a link to that day's events, the
 * stored coming events (record dates, earnings) and a dilution instrument's expiry are chips of the coming kind, and two COMPUTED days no row stores are muted: FINRA's expected short-interest
 * publication (the 7th market day after each settlement — the 15th and the month's last, moved back to a market day; Good Friday 2024-03-29 is the holiday run.sh sets) and the 13F deadline
 * (45 days after the quarter). A kind filter narrows to stored events; the month moves; a malformed month is this one; an issuer behind the wall has no day here.
 */
require __DIR__ . '/lib.php';
$ids = seed8();
$alpha = $ids['alpha'];
$jP = person(26);
$jV = person(29);
$json = ['headers' => ['Accept: application/json']];

$mk = static function (string $date, string $kind, string $title, string $sev = 'info') use ($jP, $alpha): int {
    return rid(post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => $date, 'kind' => $kind, 'title' => $title, 'severity' => $sev]));
};
$eA = $mk('2024-04-05', 'earnings', 'SMOKE: first-quarter results', 'watch');
$eB = $mk('2024-04-05', 'press', 'SMOKE: a second thing the same day');
$eC = $mk('2024-04-12', 'record_date', 'SMOKE: record date for the meeting');
$eD = $mk('2024-05-20', 'earnings', 'SMOKE: a later call');
q("INSERT INTO dilution_instruments (issuer_id, kind, counterparty, status, expires_on) VALUES (:i, 'atm', 'SMOKE Capital', 'active', '2024-05-03')", ['i' => $alpha]);
ok($eA > 0 && $eB > 0 && $eC > 0 && $eD > 0, 'four events recorded in April and May 2024 and an ATM that expires on May 3');

echo "1. April 2024 — the grid\n";
$h = page($jP, '/calendar?at=2024-04')['body'];
ok(str_contains($h, 'id="calendar-grid"') && str_contains($h, 'id="calendar-month-label">April 2024<') && substr_count($h, 'id="calendar-cell-') === 35, 'the grid is April 2024: five weeks of seven days, Monday to Sunday');
ok(str_contains($h, 'id="calendar-cell-2024-04-01"') && str_contains($h, 'id="calendar-cell-2024-05-05"') && str_contains($h, 'class="si-cal-day si-cal-out" id="calendar-cell-2024-05-03"'), 'it starts on Monday April 1 and ends on Sunday May 5, the days beyond the month marked');
ok(str_contains($h, 'id="calendar-day-2024-04-05"') && preg_match('/<a [^>]*id="calendar-day-2024-04-05"/', $h) === 1 && preg_match('/<a [^>]*id="calendar-day-2024-04-06"/', $h) !== 1 && str_contains($h, 'id="calendar-day-2024-04-06"'),
    'a day with events is a link; a day without is just its number');
ok(str_contains($h, 'href="/events?from=2024-04-05&amp;to=2024-04-05"') || str_contains($h, 'href="/events?from=2024-04-05&to=2024-04-05"'), 'the link opens that day\'s events');
ok(str_contains($h, 'SMOKE: first-quarter results') && str_contains($h, 'data-kind="earnings"') && str_contains($h, 'data-when="past"') && str_contains($h, 'data-kind="record_date"'), 'past events are chips by kind, marked past');
$d = json_decode(page($jP, '/calendar?at=2024-04', $json)['body'], true)['data'] ?? [];
$days = $d['days'] ?? [];
$titles = static function (string $date) use (&$days): array { return array_column($days[$date] ?? [], 'title'); };
ok(($d['month'] ?? '') === '2024-04' && ($d['prev'] ?? '') === '2024-03' && ($d['next'] ?? '') === '2024-05' && count($days['2024-04-05'] ?? []) === 2 && $titles('2024-04-12') === ['SMOKE: record date for the meeting'], 'JSON: the month, its neighbours, two events on April 5, the record date on April 12');
ok($titles('2024-04-09') === ['FINRA short interest for Mar 28 expected'] && ($days['2024-04-09'][0]['computed'] ?? false) === true && $titles('2024-04-24') === ['FINRA short interest for Apr 15 expected'],
    'FINRA\'s computed publication days: Apr 9 (the 7th market day after the Mar 28 settlement — Good Friday is a holiday) and Apr 24 (after Apr 15)');
ok(!isset($days['2024-03-26']) && count(array_filter($days, static fn ($items) => array_filter($items, static fn ($i) => $i['kind'] === 'thirteen_f_deadline'))) === 0, 'no 13F deadline falls in April, and Mar 26 (March\'s) is outside the grid');
ok(str_contains($h, 'si-cal-muted') && preg_match('/si-cal-muted[^>]*data-kind="short_interest_publication"|data-kind="short_interest_publication"[^>]*data-when="past"/', $h) === 1, 'the computed days are muted chips');
ok(str_contains($h, 'id="calendar-agenda-2024-04-09"') && str_contains($h, 'id="calendar-agenda-2024-04-05"') && !str_contains($h, 'id="calendar-agenda-2024-05-03"'), 'the agenda beneath lists the month\'s days, not the days beyond it');
ok(str_contains($h, 'id="calendar-prev"') && str_contains($h, 'href="/calendar?at=2024-03"') && str_contains($h, 'href="/calendar?at=2024-05"'), 'the previous and next month are links');

echo "2. May 2024 — an expiry, a stored coming event, the 13F deadline\n";
$d = json_decode(page($jP, '/calendar?at=2024-05', $json)['body'], true)['data'] ?? [];
$days = $d['days'] ?? [];
ok($titles('2024-05-03') === ['Atm with SMOKE Capital expires'] && ($days['2024-05-03'][0]['source'] ?? '') === 'instrument' && $titles('2024-05-09') === ['FINRA short interest for Apr 30 expected'] && $titles('2024-05-15') === ['13F filings due for the quarter ending Mar 31'] && $titles('2024-05-20') === ['SMOKE: a later call'],
    'May 3 the ATM expires; May 9 FINRA (after Apr 30); May 15 the 13F deadline for the quarter ending Mar 31; May 20 the recorded call');
$h = page($jP, '/calendar?at=2024-05')['body'];
ok(str_contains($h, 'data-kind="dilution_expiry"') && str_contains($h, 'data-kind="thirteen_f_deadline"'), 'the page draws the expiry and the deadline');

echo "3. The kind filter, a bad month, the real today\n";
$d = json_decode(page($jP, '/calendar?at=2024-04&kinds=earnings', $json)['body'], true)['data'] ?? [];
ok(array_keys($d['days'] ?? []) === ['2024-04-05'] && count($d['days']['2024-04-05']) === 1 && $d['kinds'] === ['earnings'], 'kinds=earnings: the earnings alone — no other kind and no computed day');
$d = json_decode(page($jP, '/calendar?at=nonsense', $json)['body'], true)['data'] ?? [];
ok(($d['month'] ?? 'x') === substr($d['today'] ?? 'y', 0, 7), 'a malformed month is the current one');
$soon = date('Y-m-d', strtotime('+10 days'));
$d = json_decode(page($jP, '/calendar?at=' . substr($soon, 0, 7), $json)['body'], true)['data'] ?? [];
$h = page($jP, '/calendar?at=' . substr($soon, 0, 7))['body'];
$days = $d['days'] ?? [];
ok(in_array('SMOKE: the record date for the annual meeting', $titles($soon), true) && str_contains($h, 'data-when="coming"'), 'the record date ten days out is a coming chip');
ok(str_contains($h, 'id="calendar-cell-' . date('Y-m-d') . '"') && str_contains($h, 'si-cal-today'), 'today is marked');

echo "4. Who sees it, and the wall\n";
$bsec = (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $ids['beta']]);
q("INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity, source, created_by) VALUES (:i, :s, '2024-04-17', 'press', 'SMOKE: Beta only', 'info', 'person', 30)", ['i' => $ids['beta'], 's' => $bsec]);
$h = page($jV, '/calendar?at=2024-04')['body'];
ok(str_contains($h, 'SMOKE: first-quarter results') && !str_contains($h, 'Beta only') && !str_contains($h, 'id="calendar-add-link"'), 'a Viewer reads Alpha\'s calendar, with no "Record an event" — and Beta\'s day is not in it');
$d = json_decode(page($jP, '/calendar?at=2024-04&issuer=' . $ids['beta'], $json)['body'], true)['data'] ?? [];
ok(!isset($d['days']['2024-04-17']), 'asking for Beta by id is not honoured: its 17th is not in Priya\'s calendar');
ok(str_contains(page($jP, '/calendar?at=2024-04')['body'], 'id="calendar-add-link"'), 'an Analyst sees "Record an event" on the calendar');
ok(page(jar(), '/calendar')['code'] === 302, 'no session: the launcher');
finish();
