<?php
/**
 * The short side (screen `short`): the tiles (short interest as a share of the float, days to cover, the threshold status, the latest fails,
 * the 5-day off-exchange ratio, the latest borrow), each with its date and source, then the one chart. Data: security, from, to, svf, status,
 * fail, ratio, borrowLast, ov, sources, canBorrow, narrowDefault
 */
$sid = (int) $security['security_id'];
$tile = static function (string $id, string $label, string $value, string $source, ?string $date, string $tone = '', string $extra = ''): string {
    return '<div class="col-6 col-lg-4 col-xl-2"><div class="card h-100 mb-0" id="short-tile-' . e($id) . '"><div class="card-body">'
        . '<div class="fs-12 text-muted text-uppercase fw-semibold">' . e($label) . '</div>'
        . '<div class="si-tile-value fw-bold' . ($tone !== '' ? ' text-' . e($tone) : '') . '" id="short-tile-' . e($id) . '-value">' . $value . '</div>'
        . ($extra !== '' ? '<div class="fs-12">' . $extra . '</div>' : '')
        . view('shared/source-line.php', ['source' => $source, 'date' => $date]) . '</div></div></div>';
};
$pct = static fn ($v, int $d = 2): string => $v === null ? '—' : number_format((float) $v, $d) . '%';
?>
<?= view('shared/header.php', ['id' => 'short', 'title' => 'Short side', 'crumbs' => [['Home', '/'], ['Short side', null]],
    'action' => $canBorrow ? hx_link('/short/borrow/new?security=' . $sid, '<i class="feather-plus me-1"></i>Record borrow', 'btn btn-light btn-touch btn-sm', 'id="short-borrow-add-btn"') : '']) ?>
<div class="main-content" id="short-content">
    <?= short_tabs('short', $sid) ?>
    <?= short_window_form('/short', $sid, $from, $to) ?>
    <div class="row g-3 mb-3" id="short-tiles">
        <?= $tile('si', 'Short, % of float', e($pct($svf['pct_of_float'] ?? null)), 'FINRA short interest, settlement', $svf['settlement_date'] ?? null, ((float) ($svf['pct_of_float'] ?? 0)) >= 20 ? 'danger' : '',
            $svf !== null ? e(short_n($svf['short_shares'])) . ' shares · ' . e($pct($svf['pct_of_outstanding'] ?? null)) . ' of outstanding' : '') ?>
        <?= $tile('dtc', 'Days to cover', e($svf['days_to_cover'] ?? null ? number_format((float) $svf['days_to_cover'], 2) : '—'), 'FINRA, average daily volume', $svf['settlement_date'] ?? null, '',
            $svf !== null && $svf['avg_daily_volume'] !== null ? e(short_n($svf['avg_daily_volume'])) . ' a day' : '') ?>
        <?= $tile('threshold', 'Threshold list', $status !== null && $status['on_list'] ? '<span class="text-danger">' . (int) $status['consecutive_days'] . ' days</span>' : 'None',
            'Reg SHO threshold lists', $status['latest_list_date'] ?? null, '', $status !== null && $status['on_list'] ? 'On the ' . e(THRESHOLD_LISTS[$status['list']] ?? $status['list']) . ' list since ' . e(format_date($status['since'])) : 'on no list') ?>
        <?= $tile('fails', 'Fails to deliver', e($fail === null ? '—' : short_n($fail['quantity'])), 'the SEC, settlement', $fail['settlement_date'] ?? null, '',
            $fail !== null ? e($pct($fail['pct_of_outstanding'] ?? null, 3)) . ' of outstanding · 60-day median ' . e(short_n($fail['median_60'])) : '') ?>
        <?= $tile('ratio', '5-day short ratio', e($ratio === null || $ratio['mean_5'] === null ? '—' : number_format(100 * (float) $ratio['mean_5'], 1) . '%'), 'FINRA daily, off-exchange only', $ratio['trade_date'] ?? null, '',
            '<span class="text-muted">not short interest</span>') ?>
        <?= $tile('borrow', 'Borrow fee', e($borrowLast === null ? '—' : $pct($borrowLast['fee_rate_percent'])), 'typed by a person', $borrowLast['observed_on'] ?? null, '',
            $borrowLast !== null ? '<span class="badge text-bg-light">indicative</span>' . ($borrowLast['utilization_percent'] !== null ? ' ' . e($pct($borrowLast['utilization_percent'], 1)) . ' utilised' : '') : '') ?>
    </div>
    <?= view('short/partials/overlay-chart.php', ['security' => $security, 'ov' => $ov, 'from' => $from, 'to' => $to, 'sources' => $sources]) ?>
    <?php if ($narrowDefault): $u = '/short?security=' . $sid . '&from=' . $narrowDefault . '&to=' . $to; ?><script>(function () {
        var go = function () { if (!window.matchMedia('(max-width: 575.98px)').matches) { return; }
            if (window.htmx) { htmx.ajax('GET', <?= json_encode($u) ?>, {target: '#page-content', swap: 'innerHTML'}); history.replaceState(null, '', <?= json_encode($u) ?>); } else { location.replace(<?= json_encode($u) ?>); } };
        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', go) : go(); })();</script><?php endif; ?>
</div>
