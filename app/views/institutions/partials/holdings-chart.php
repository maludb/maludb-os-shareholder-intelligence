<?php /** An institution's shares by quarter as bars, and the table. Data: holdings */
require_once dirname(__DIR__, 3) . '/si/svg.php';
$long = array_values(array_filter($holdings, static fn ($r) => $r['put_call'] === null));
?>
<?php if ($long === []): ?><div class="fs-12 text-muted" id="holdings-empty">No 13F holding of this security.</div>
<?php else: ?>
    <?= svg_stacked_bars(array_map(static fn ($r) => ['label' => date('M Y', strtotime((string) $r['period_end'])), 'values' => ['shares' => (float) $r['shares']]], $long), ['shares' => '#3454d1'], 'holdings-chart', 'Shares held at each quarter\'s end (13F)') ?>
    <details class="mt-2 fs-12" open><summary>As a table</summary><div class="table-responsive"><table class="table table-sm mb-0" id="holdings-table"><thead><tr><th>Quarter</th><th class="text-end">Shares</th><th class="text-end">Value</th><th>Kind</th></tr></thead><tbody>
        <?php foreach (array_reverse($holdings) as $r): ?><tr><td><?= e(format_date($r['period_end'])) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td><td class="text-end"><?= $r['value'] === null ? '—' : '$' . e(number_format((float) $r['value'], 0)) ?></td><td><?= $r['put_call'] ? '<span class="badge text-bg-warning">' . e((string) $r['put_call']) . '</span>' : 'shares' ?><?= $r['amended'] ? ' <span class="text-muted">amended</span>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
    <?= view('shared/source-line.php', ['source' => '13F data set, period ' . format_date(end($long)['period_end']) . ', filing ' . end($long)['accession'], 'date' => end($long)['period_end']]) ?>
<?php endif; ?>
