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
    return (bool) one_value($pdo, 'SELECT si_is_market_day(CAST(:d AS date))', ['d' => $at->setTimezone(si_market_tz($pdo))->format('Y-m-d')]);
}

/** Does the connector's calendar admit a scheduled run now? */
function si_source_on_calendar(PDO $pdo, array $source, DateTimeImmutable $now): bool
{
    $local = $now->setTimezone(si_market_tz($pdo));
    $hour = (int) $local->format('G');
    return match ((string) $source['connector']) {
        // the issuers' EDGAR entity and index: market days, from 06:00 to 20:59 market time (the schedule makes it twice a day)
        'edgar_submissions' => si_is_market_day_on($pdo, $now) && $hour >= 6 && $hour <= 20,
        // the directory: any day (DTCC refreshes it monthly; the schedule is 30 days)
        'dtcc_participants' => true,
        default => true,
    };
}
