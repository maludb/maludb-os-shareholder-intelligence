<?php
/**
 * The short side's one chart with its legend, its source lines, FINRA's caveat and a table twin (short-side.md "Screens"). Data: security, ov
 * (overlay(): points, events), from, to, sources (short_sources())
 */
$c = svg_short_overlay($ov['points'], $ov['events'], 'short-chart', $from, $to);
$s = $c['scales'];
$pulled = $sources['pulled'] ?? [];
?>
<div class="card" id="short-chart">
    <div class="card-body">
        <?php if ($c['svg'] === ''): ?>
            <div class="text-muted" id="short-chart-empty">No price is loaded for these days: the chart is drawn on the trading days of the price series. <?php $av = $pulled['prices_alphavantage'] ?? null; ?>
                <?= $av !== null && !$av['has_key'] ? 'Prices come from Alpha Vantage once an admin sets its key — or from a price file uploaded.' : '' ?>
                <?= hx_link('/uploads/new?kind=prices_csv', 'Upload prices', 'btn btn-light btn-touch btn-sm ms-1', 'id="short-chart-upload-btn"') ?>
                <?= $av !== null && !$av['has_key'] && has_right('credentials.manage') ? hx_link('/admin/sources/' . (int) $av['id'] . '/credential', 'Set the Alpha Vantage key', 'btn btn-primary btn-touch btn-sm ms-1', 'id="short-chart-key-btn"') : '' ?></div>
        <?php else: ?>
            <div class="fs-12 fw-semibold mb-1">The close and the volume</div>
            <?= $c['svg'] ?>
            <?= svg_legend(array_filter([
                'Close' => 'var(--bs-primary, #3454d1)', 'Volume (light bars)' => '#cbd5e1',
                'Short interest, % of float (points' . ($s['si'] !== null ? '; highest ' . number_format((float) $s['si'], 2) . '%' : '') . ')' => SI_PALETTE[3],
                'Fails to deliver (bars' . ($s['fails'] !== null ? '; tallest ' . number_format((float) $s['fails']) : '') . ')' => SI_PALETTE[5],
                '5-day off-exchange short ratio (line; 0–100%)' => SI_PALETTE[1],
                'Borrow fee (diamonds' . ($s['fee'] !== null ? '; highest ' . number_format((float) $s['fee'], 2) . '%' : '') . ')' => SI_PALETTE[2],
                'A threshold-list day (band)' => '#fde2e2', 'An event (dashed line)' => '#41b2c4']), 'short-chart-legend') ?>
        <?php endif; ?>
        <div class="mt-2 d-flex flex-column gap-1" id="short-chart-sources">
            <?= view('shared/source-line.php', ['source' => $sources['prices_source'] ?? 'prices: none loaded', 'date' => $sources['prices'] ?? null]) ?>
            <?= view('shared/source-line.php', ['source' => 'FINRA short interest, settlement', 'date' => $sources['short_interest'] ?? null]) ?>
            <?= view('shared/source-line.php', ['source' => 'FINRA Reg SHO daily short volume (off-exchange)', 'date' => $sources['short_volume'] ?? null]) ?>
            <?= view('shared/source-line.php', ['source' => 'the SEC\'s fails-to-deliver, settlement', 'date' => $sources['fails'] ?? null, 'extra' => 'published about two weeks after each half-month']) ?>
            <?= view('shared/source-line.php', ['source' => 'the Reg SHO threshold lists (Nasdaq, NYSE, FINRA OTC)', 'date' => $sources['threshold'] ?? null]) ?>
            <?= view('shared/source-line.php', ['source' => 'borrow, typed by a person (indicative)', 'date' => $sources['borrow'] ?? null]) ?>
        </div>
        <div class="mt-2"><?= view('short/partials/caveat.php', ['compact' => true]) ?></div>
        <?php if ($ov['points'] !== []): ?>
        <details class="mt-2" id="short-chart-table"><summary class="fs-12">The chart as a table</summary>
            <div class="table-responsive"><table class="table table-sm fs-12 mb-0"><thead><tr><th>Day</th><th class="text-end">Close</th><th class="text-end">Short % float</th><th class="text-end">Fails</th><th class="text-end">5-day ratio</th><th>Threshold</th><th class="text-end">Borrow fee</th></tr></thead><tbody>
            <?php foreach (array_reverse($ov['points']) as $p): ?><tr><td class="text-nowrap"><?= e(format_date((string) $p['d'])) ?></td><td class="text-end"><?= e(short_n($p['close'], 2)) ?></td><td class="text-end"><?= $p['short_pct_float'] === null ? '' : e(number_format((float) $p['short_pct_float'], 2)) . '%' ?></td>
                <td class="text-end"><?= $p['fails'] === null ? '' : e(number_format((float) $p['fails'])) ?></td><td class="text-end"><?= $p['short_volume_ratio_5'] === null ? '' : e(number_format(100 * (float) $p['short_volume_ratio_5'], 1)) . '%' ?></td>
                <td><?= $p['on_threshold'] ? 'on a list' : '' ?></td><td class="text-end"><?= $p['borrow_fee'] === null ? '' : e(number_format((float) $p['borrow_fee'], 2)) . '%' ?></td></tr><?php endforeach; ?>
            </tbody></table></div></details>
        <?php endif; ?>
    </div>
</div>
