<?php /** FINRA's daily short volume (screen `short-volume`): the caveat first, the ratio's line with its means, the table. Data: security, from, to, market, rows */ $sid = (int) $security['security_id']; ?>
<?= view('shared/header.php', ['id' => 'short-volume', 'title' => 'Short volume', 'crumbs' => [['Home', '/'], ['Short side', '/short?security=' . $sid], ['Short volume', null]]]) ?>
<div class="main-content" id="short-volume-content">
    <?= short_tabs('volume', $sid) ?>
    <?= view('short/partials/caveat.php', []) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="volume-markets"><?php foreach (SHORT_VOLUME_MARKETS as $k => $l): ?><?= hx_link('/short/volume?security=' . $sid . '&market=' . $k . '&from=' . $from . '&to=' . $to, e($l), 'btn btn-sm btn-touch ' . ($market === $k ? 'btn-primary' : 'btn-light'), 'id="volume-market-' . strtolower($k) . '"') ?><?php endforeach; ?></div>
    <?= short_window_form('/short/volume', $sid, $from, $to, ['market' => $market]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="volume-table-empty">No daily short volume in this file for these dates.</div></div>
    <?php else: $asc = array_reverse($rows); ?>
    <div class="card"><div class="card-body">
        <?= svg_lines([['label' => 'The day\'s ratio', 'colour' => '#cbd5e1', 'points' => array_column(array_map(static fn ($r) => [$r['trade_date'], round(100 * (float) $r['ratio'], 1)], array_filter($asc, static fn ($r) => $r['ratio'] !== null)), 1, 0)],
                       ['label' => '5-day mean', 'colour' => SI_PALETTE[1], 'points' => array_column(array_map(static fn ($r) => [$r['trade_date'], round(100 * (float) $r['mean_5'], 1)], array_filter($asc, static fn ($r) => $r['mean_5'] !== null)), 1, 0)],
                       ['label' => '20-day mean', 'colour' => SI_PALETTE[0], 'points' => array_column(array_map(static fn ($r) => [$r['trade_date'], round(100 * (float) $r['mean_20'], 1)], array_filter($asc, static fn ($r) => $r['mean_20'] !== null)), 1, 0)]],
                      'volume-chart', 'The off-exchange short-volume ratio, % of the day\'s off-exchange volume', 100.0) ?>
        <?= svg_legend(['The day\'s ratio' => '#cbd5e1', '5-day mean' => SI_PALETTE[1], '20-day mean' => SI_PALETTE[0]], 'volume-chart-legend') ?>
    </div></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="volume-table">
        <thead class="thead-light"><tr><th>Day</th><th class="text-end">Short</th><th class="text-end d-none d-md-table-cell">Exempt</th><th class="text-end">Total</th><th class="text-end">Ratio</th><th class="text-end d-none d-sm-table-cell">5-day</th><th class="text-end d-none d-sm-table-cell">20-day</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr id="volume-row-<?= e((string) $r['trade_date']) ?>"><td class="text-nowrap"><?= e(format_date((string) $r['trade_date'])) ?></td><td class="text-end"><?= e(short_n($r['short_volume'])) ?></td><td class="text-end d-none d-md-table-cell"><?= e(short_n($r['short_exempt_volume'])) ?></td>
            <td class="text-end"><?= e(short_n($r['total_volume'])) ?></td><td class="text-end"><?= $r['ratio'] === null ? '—' : e(number_format(100 * (float) $r['ratio'], 1)) . '%' ?></td>
            <td class="text-end d-none d-sm-table-cell"><?= $r['mean_5'] === null ? '—' : e(number_format(100 * (float) $r['mean_5'], 1)) . '%' ?></td><td class="text-end d-none d-sm-table-cell"><?= $r['mean_20'] === null ? '—' : e(number_format(100 * (float) $r['mean_20'], 1)) . '%' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?>
    <div class="mt-2"><?= view('shared/source-line.php', ['source' => 'FINRA Reg SHO daily short-sale volume, ' . $market, 'date' => $rows[0]['trade_date'] ?? null, 'extra' => 'fractional volumes kept to the whole share']) ?></div>
</div>
