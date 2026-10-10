<?php /** Holders by share range (screen `holders-ranges`). Data: security, channel, dates, date, r */
require_once dirname(__DIR__, 2) . '/si/svg.php';
$br = $r['broadridge'];
$brDate = $br[0]['record_date'] ?? null;
$tot = ['all' => array_sum(array_column($br, 'all_accounts')), 'obo' => array_sum(array_column($br, 'obo_accounts'))];
?>
<?= view('shared/header.php', ['id' => 'holders-ranges', 'title' => 'Share ranges', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Ranges', null]]]) ?>
<div class="main-content" id="holders-ranges-content">
    <?= view('holders/partials/subnav.php', ['active' => 'ranges', 'security' => $security, 'channel' => $channel, 'dates' => $dates, 'date' => $date]) ?>
    <div class="row g-3">
        <div class="col-xl-6"><div class="card mb-0 h-100" id="ranges-list"><div class="card-header"><h5 class="card-title mb-0">From the <?= e(strtolower(HOLDER_CHANNELS[$channel])) ?></h5></div><div class="card-body p-0">
            <?php if ($r['list'] === [] || array_sum(array_column($r['list'], 'holders')) === 0): ?><div class="p-3 fs-12 text-muted" id="ranges-list-empty">No <?= e(strtolower(HOLDER_CHANNELS[$channel])) ?> on <?= e(format_date($date)) ?>.</div>
            <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="ranges-list-table"><thead class="thead-light"><tr><th>Shares</th><th class="text-end">Holders</th><th class="text-end">Shares held</th><th class="text-end">%</th></tr></thead><tbody>
                <?php foreach ($r['list'] as $x): ?><tr><td class="text-nowrap"><?= e($x['range_label']) ?></td><td class="text-end"><?= number_format((int) $x['holders']) ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td><td class="text-end"><?= $x['share_pct'] === null ? '—' : e(number_format((float) $x['share_pct'], 1)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="p-3"><?= view('shared/source-line.php', ['source' => 'the ' . strtolower(HOLDER_CHANNELS[$channel]) . ' uploaded, a holder counted once over its accounts', 'date' => $date]) ?></div><?php endif; ?>
        </div></div></div>
        <div class="col-xl-6"><div class="card mb-0 h-100" id="ranges-broadridge"><div class="card-header"><h5 class="card-title mb-0">Broadridge's Share Range Analysis</h5></div><div class="card-body p-0">
            <?php if ($br === []): ?><div class="p-3 fs-12 text-muted" id="ranges-broadridge-empty">No Share Range Analysis is loaded at or before <?= e(format_date($date)) ?>: it is the one place the objecting owners (OBOs) are counted — <?= hx_link('/uploads/new?kind=share_range', 'upload one', 'fw-semibold') ?>.</div>
            <?php else: ?>
                <div class="p-3 fs-12" id="ranges-obo-share">On <?= e(format_date($brDate)) ?>: <span class="fw-semibold"><?= number_format($tot['obo']) ?></span> of <?= number_format($tot['all']) ?> accounts are OBOs (<?= $tot['all'] > 0 ? e(number_format($tot['obo'] / $tot['all'] * 100, 1)) : '0' ?>%) — owners who refuse to be known.</div>
                <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="ranges-broadridge-table"><thead class="thead-light"><tr><th>Shares</th><th class="text-end">All</th><th class="text-end">OBO</th><th class="text-end">NOBO</th><th class="text-end d-none d-sm-table-cell">OBO shares</th></tr></thead><tbody>
                <?php foreach ($br as $x): ?><tr><td class="text-nowrap"><?= e($x['range_label']) ?></td><td class="text-end"><?= number_format((int) $x['all_accounts']) ?></td><td class="text-end"><?= number_format((int) $x['obo_accounts']) ?></td><td class="text-end"><?= number_format((int) $x['nobo_accounts']) ?></td><td class="text-end d-none d-sm-table-cell"><?= e(fmt_n($x['obo_shares'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <div class="p-3"><?= view('shared/source-line.php', ['source' => 'Broadridge\'s Share Range Analysis (accounts before suppression; NOBO = all − OBO)', 'date' => $brDate]) ?></div>
            <?php endif; ?>
        </div></div></div>
    </div>
    <?php if (count($r['obo_totals']) > 1): ?>
    <div class="card mt-3" id="ranges-obo-history"><div class="card-header"><h5 class="card-title mb-0">The OBO share of accounts over time</h5></div><div class="card-body">
        <?= svg_series(array_map(static fn (array $x): array => ['d' => $x['record_date'], 'v' => (float) $x['obo_pct_of_accounts']], $r['obo_totals']), 'ranges-obo-chart', 'OBO accounts as a percentage of all accounts by record date') ?>
        <?= view('shared/source-line.php', ['source' => 'every Share Range Analysis uploaded', 'date' => end($r['obo_totals'])['record_date']]) ?>
    </div></div>
    <?php endif; ?>
</div>
