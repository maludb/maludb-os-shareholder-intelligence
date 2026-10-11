<?php
declare(strict_types=1);

/**
 * The calendars of the connectors (edgar-and-finra-fetchers.md §2): a source due by its schedule still runs only when its calendar says so.
 * Market time is si_settings.market_timezone; a market day is si_is_market_day() (weekdays not in the holidays). A manual pull ignores the
 * calendar. Slices add their connectors' rules here (the 13F quarter, FINRA's publication days, the fails' half-months).
 */

/** The market's time zone (si_settings.market_timezone, default America/New_York). */
function si_market_tz(PDO $pdo): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $name = (string) (one_value($pdo, 'SELECT market_timezone FROM si_settings WHERE id = 1') ?: 'America/New_York');
        try { $tz = new DateTimeZone($name); } catch (Throwable) { $tz = new DateTimeZone('America/New_York'); }
    }
    return $tz;
}

function si_is_market_day_on(PDO $pdo, DateTimeImmutable $at): bool
{
    static $memo = [];                                              // per process: the admin's next-slot walk asks about the same days hundreds of times
    $d = $at->setTimezone(si_market_tz($pdo))->format('Y-m-d');
    return $memo[$d] ??= (bool) one_value($pdo, 'SELECT si_is_market_day(CAST(:d AS date))', ['d' => $d]);
}

/** Does the connector's calendar admit a scheduled run now? */
function si_source_on_calendar(PDO $pdo, array $source, DateTimeImmutable $now): bool
{
    $local = $now->setTimezone(si_market_tz($pdo));
    $hour = (int) $local->format('G');
    return match ((string) $source['connector']) {
        // the issuers' EDGAR entity and index: market days, from 06:00 to 20:59 market time (the schedule makes it twice a day)
        'edgar_submissions' => si_is_market_day_on($pdo, $now) && $hour >= 6 && $hour <= 20,
        // the cover share counts: market days, after the morning index (07:00–20:59)
        'edgar_companyfacts' => si_is_market_day_on($pdo, $now) && $hour >= 7 && $hour <= 20,
        // the full-text watch: hourly on market days, 07:00–20:59 market time
        'edgar_fulltext' => si_is_market_day_on($pdo, $now) && $hour >= 7 && $hour <= 20,
        // the ownership forms: with the index (market days, 06:00–20:59); the 13F page any day (the sets appear quarterly)
        'edgar_13dg', 'edgar_ownership', 'edgar_144' => si_is_market_day_on($pdo, $now) && $hour >= 6 && $hour <= 20,
        'edgar_13f_dataset' => true,
        // the directory: any day (DTCC refreshes it monthly; the schedule is 30 days)
        'dtcc_participants' => true,
        // FINRA's short interest: on market days from 17:00 around each publication (si_finra_publication_window) — and a source never read yet
        'finra_short_interest' => si_is_market_day_on($pdo, $now) && $hour >= 17 && ($source['last_ok_at'] === null || si_finra_publication_window($pdo, $local)),
        // FINRA's daily files: market days from 18:30 market time (FINRA posts them about 18:00)
        'finra_daily_short_volume' => si_is_market_day_on($pdo, $now) && ($hour > 18 || ($hour === 18 && (int) $local->format('i') >= 30)),
        // the SEC's fails: the page any day (a half-month's zip appears about two weeks after it ends)
        'sec_ftd' => true,
        // the threshold lists: market days from the hour of the source's settings (23:00 market time)
        'threshold_nasdaq', 'threshold_nyse', 'threshold_otc' => si_is_market_day_on($pdo, $now) && $hour >= (int) ((json_decode((string) $source['settings'], true) ?: [])['hour_et'] ?? 23),
        // prices: market days after the close
        'prices_stooq', 'prices_alphavantage', 'prices_polygon' => si_is_market_day_on($pdo, $now) && $hour >= 18,
        default => true,
    };
}

/**
 * Is $local within a FINRA short-interest publication? Settlement dates are the 15th (or the market day before) and the month's last market
 * day; FINRA publishes about seven market days after each — the window admits the sixth to the ninth market day after a settlement, so a
 * shifted schedule (a holiday) is still caught. A missed publication is not lost: every pull reads the whole series.
 */
function si_finra_publication_window(PDO $pdo, DateTimeImmutable $local): bool
{
    $today = $local->format('Y-m-d');
    $days = si_market_days($pdo, $local->modify('-75 days')->format('Y-m-d'), $local->modify('+5 days')->format('Y-m-d'));
    $settlements = [];
    foreach ([-2, -1, 0] as $m) {
        $month = $local->modify('first day of this month')->modify(($m >= 0 ? '+' : '') . $m . ' months');
        $mid = $month->format('Y-m-15');
        $end = $month->format('Y-m-t');
        $s1 = null; $s2 = null;
        foreach ($days as $d) {
            if ($d <= $mid && $d >= $month->format('Y-m-01')) { $s1 = $d; }
            if ($d <= $end && $d >= $month->format('Y-m-01')) { $s2 = $d; }
        }
        array_push($settlements, ...array_filter([$s1, $s2]));
    }
    foreach ($settlements as $s) {
        $i = array_search($s, $days, true);
        if ($i === false) { continue; }
        for ($k = 6; $k <= 9; $k++) {
            if (($days[$i + $k] ?? null) === $today) { return true; }
        }
    }
    return false;
}
