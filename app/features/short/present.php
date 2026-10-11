<?php
declare(strict_types=1);

/**
 * The short side's whitelist presenters (the JSON branch of every screen) and the pieces the screens share: the tabs, the window form,
 * FINRA's caveat. A number is a number or null; a date is Y-m-d; nothing raw.
 */

/** FINRA's caveat on its daily short-sale volume — on every screen and in every answer that shows the ratio. */
const SI_SHORT_VOLUME_CAVEAT = 'Off-exchange volume only; counts market-maker short sales and omits the offsetting buys (FINRA). Not comparable with short interest.';
const BORROW_SOURCES = ['broker_quote' => 'Broker quote', 'manual' => 'Typed', 'vendor' => 'Vendor feed'];
const SHORT_VOLUME_MARKETS = ['CNMS' => 'Consolidated NMS (exchange-listed)', 'FORF' => 'OTC (ORF)'];
const THRESHOLD_LISTS = ['nasdaq' => 'Nasdaq', 'nyse' => 'NYSE', 'nyse_american' => 'NYSE American', 'nyse_arca' => 'NYSE Arca', 'otc' => 'OTC (FINRA)'];

function si_num_or_null(mixed $v): ?float
{
    return $v === null || $v === '' ? null : (float) $v;
}

function present_short_interest(array $r): array
{
    return ['settlement_date' => $r['settlement_date'], 'published_on' => $r['published_on'] ?? null, 'first_read_on' => $r['observed_on'] ?? null, 'short_shares' => si_num_or_null($r['short_shares']),
            'previous_short_shares' => si_num_or_null($r['previous_short_shares'] ?? null), 'change_shares' => si_num_or_null($r['change_shares'] ?? null), 'change_percent' => si_num_or_null($r['change_percent']),
            'avg_daily_volume' => si_num_or_null($r['avg_daily_volume']), 'days_to_cover' => si_num_or_null($r['days_to_cover']), 'pct_of_float' => si_num_or_null($r['pct_of_float']),
            'pct_of_outstanding' => si_num_or_null($r['pct_of_outstanding']), 'revision_flag' => (bool) $r['revision_flag'], 'observations' => (int) ($r['observations'] ?? 1),
            'earlier' => ($r['earlier_short_shares'] ?? null) === null ? null : ['short_shares' => (float) $r['earlier_short_shares'], 'days_to_cover' => si_num_or_null($r['earlier_days_to_cover']), 'observed_at' => json_ts($r['earlier_observed_at'])]];
}

function present_short_volume(array $r): array
{
    return ['trade_date' => $r['trade_date'], 'short_volume' => (float) $r['short_volume'], 'short_exempt_volume' => (float) $r['short_exempt_volume'], 'total_volume' => (float) $r['total_volume'],
            'ratio' => si_num_or_null($r['ratio']), 'mean_5' => si_num_or_null($r['mean_5']), 'mean_20' => si_num_or_null($r['mean_20'])];
}

function present_fail(array $r): array
{
    return ['settlement_date' => $r['settlement_date'], 'quantity' => (float) $r['quantity'], 'price' => si_num_or_null($r['price']), 'value' => $r['price'] === null ? null : round((float) $r['quantity'] * (float) $r['price'], 2),
            'pct_of_outstanding' => si_num_or_null($r['pct_of_outstanding']), 'median_60' => si_num_or_null($r['median_60'])];
}

function present_threshold_run(array $r): array
{
    return ['list' => $r['list'], 'from' => $r['from_date'], 'to' => $r['to_date'], 'list_days' => (int) $r['list_days'], 'current' => (bool) $r['current']];
}

function present_borrow(array $r): array
{
    return ['observation_id' => (int) $r['observation_id'], 'observed_on' => $r['observed_on'], 'source' => $r['source'], 'indicative' => $r['source'] !== 'vendor',
            'fee_rate_percent' => si_num_or_null($r['fee_rate_percent']), 'utilization_percent' => si_num_or_null($r['utilization_percent']), 'shares_on_loan' => si_num_or_null($r['shares_on_loan']),
            'shares_available' => si_num_or_null($r['shares_available']), 'note' => $r['note'], 'recorded_by' => $r['recorded_by'] === null ? null : (int) $r['recorded_by'], 'recorded_at' => json_ts($r['recorded_at'])];
}

function present_overlay_point(array $r): array
{
    return ['d' => $r['d'], 'close' => si_num_or_null($r['close']), 'volume' => si_num_or_null($r['volume']), 'short_pct_float' => si_num_or_null($r['short_pct_float']), 'days_to_cover' => si_num_or_null($r['days_to_cover']),
            'fails' => si_num_or_null($r['fails']), 'short_volume_ratio_5' => si_num_or_null($r['short_volume_ratio_5']), 'on_threshold' => (bool) $r['on_threshold'], 'borrow_fee' => si_num_or_null($r['borrow_fee']),
            'utilization' => si_num_or_null($r['utilization'])];
}

/** The short-side screens' tabs. */
function short_tabs(string $active, int $security): string
{
    $o = '<div class="d-flex flex-wrap gap-1 mb-3" id="short-tabs" role="tablist">';
    foreach ([['short', '/short', 'Overview'], ['interest', '/short/interest', 'Short interest'], ['volume', '/short/volume', 'Short volume'], ['fails', '/short/fails', 'Fails'],
              ['threshold', '/short/threshold', 'Threshold lists'], ['borrow', '/short/borrow', 'Borrow']] as [$k, $u, $l]) {
        $o .= hx_link($u . '?security=' . $security, e($l), 'btn btn-touch btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-light'), 'id="short-tab-' . $k . '"');
    }
    return $o . '</div>';
}

/** The window chooser (from, to; extra hidden fields kept). */
function short_window_form(string $action, int $security, string $from, string $to, array $extra = []): string
{
    $o = '<form method="get" action="' . e($action) . '" class="row g-2 align-items-end mb-3" id="short-window" hx-get="' . e($action) . '" hx-target="#page-content" hx-push-url="true">'
       . '<input type="hidden" name="security" value="' . $security . '">';
    foreach ($extra as $k => $v) {
        $o .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $o . '<div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="short-window-from">From</label><input type="date" class="form-control btn-touch" name="from" id="short-window-from" value="' . e($from) . '"></div>'
       . '<div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="short-window-to">To</label><input type="date" class="form-control btn-touch" name="to" id="short-window-to" value="' . e($to) . '"></div>'
       . '<div class="col-12 col-md-2"><button class="btn btn-light btn-touch w-100" id="short-window-btn">Show</button></div></form>';
}

/** "1,234,567" or the dash. */
function short_n(mixed $v, int $dec = 0): string
{
    return $v === null || $v === '' ? '—' : number_format((float) $v, $dec);
}
