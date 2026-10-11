<?php /** The fails-to-deliver series (screen `short-fails`): bars with the 60-day median, the table. Data: security, from, to, rows */ $sid = (int) $security['security_id']; ?>
<?= view('shared/header.php', ['id' => 'short-fails', 'title' => 'Fails to deliver', 'crumbs' => [['Home', '/'], ['Short side', '/short?security=' . $sid], ['Fails', null]]]) ?>
<div class="main-content" id="short-fails-content">
    <?= short_tabs('fails', $sid) ?>
    <?= short_window_form('/short/fails', $sid, $from, $to) ?>
    <p class="fs-12 text-muted">The SEC's fails-to-deliver: the shares NSCC's members had failed to deliver at the end of each settlement date (a balance, not a day's new fails), published twice a month about two weeks after each half.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="fails-table-empty">The SEC lists no fails for this security in these dates.</div></div>
    <?php else: $asc = array_reverse($rows); ?>
    <div class="card"><div class="card-body">
        <?= svg_stacked_bars(array_map(static fn ($r) => ['label' => $r['settlement_date'], 'values' => ['Fails' => (float) $r['quantity']]], $asc), ['Fails' => SI_PALETTE[5]], 'fails-chart', 'Fails to deliver by settlement date') ?>
        <?php $med = array_filter(array_map(static fn ($r) => $r['median_60'] === null ? null : (float) $r['median_60'], $asc), static fn ($v) => $v !== null); ?>
        <?= $med === [] ? '' : svg_lines([['label' => '60-day median', 'colour' => SI_PALETTE[4], 'points' => array_combine(array_map(static fn ($k) => $asc[$k]['settlement_date'], array_keys($med)), array_values($med))]], 'fails-median', 'The 60-day median of the fails', max(array_map(static fn ($r) => (float) $r['quantity'], $asc)), 720, 80) ?>
        <?= svg_legend(['Fails (bars)' => SI_PALETTE[5], '60-day median (the line under, on the bars\' scale)' => SI_PALETTE[4]], 'fails-chart-legend') ?>
    </div></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="fails-table">
        <thead class="thead-light"><tr><th>Settlement</th><th class="text-end">Fails</th><th class="text-end d-none d-sm-table-cell">Price</th><th class="text-end d-none d-md-table-cell">Value</th><th class="text-end">% outstanding</th><th class="text-end d-none d-sm-table-cell">60-day median</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr id="fails-row-<?= e((string) $r['settlement_date']) ?>"><td class="text-nowrap"><?= e(format_date((string) $r['settlement_date'])) ?></td><td class="text-end<?= $r['median_60'] !== null && (float) $r['quantity'] > 3 * (float) $r['median_60'] ? ' fw-semibold text-danger' : '' ?>"><?= e(short_n($r['quantity'])) ?></td>
            <td class="text-end d-none d-sm-table-cell"><?= $r['price'] === null ? '—' : '$' . e(number_format((float) $r['price'], 2)) ?></td><td class="text-end d-none d-md-table-cell"><?= $r['price'] === null ? '—' : '$' . e(number_format((float) $r['quantity'] * (float) $r['price'], 0)) ?></td>
            <td class="text-end"><?= $r['pct_of_outstanding'] === null ? '—' : e(number_format((float) $r['pct_of_outstanding'], 3)) . '%' ?></td><td class="text-end d-none d-sm-table-cell"><?= e(short_n($r['median_60'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?>
    <div class="mt-2"><?= view('shared/source-line.php', ['source' => 'the SEC\'s fails-to-deliver data, each file checked against its own trailer', 'date' => $rows[0]['settlement_date'] ?? null]) ?></div>
</div>
