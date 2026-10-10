<?php /** The concentration (screen `positions-concentration`): the top-5, top-10 and top-20 participants' share of the DTC total and the index over the window. Data: security, series, from, to */
$sid = (int) $security['security_id'];
$lines = [['label' => 'Top 5', 'colour' => '#3454d1', 'points' => array_column($series, 'top5_pct', 'position_date')],
          ['label' => 'Top 10', 'colour' => '#8b5cf6', 'points' => array_column($series, 'top10_pct', 'position_date')],
          ['label' => 'Top 20', 'colour' => '#41b2c4', 'points' => array_column($series, 'top20_pct', 'position_date')]];
?>
<?= view('shared/header.php', ['id' => 'positions-concentration', 'title' => 'Concentration', 'crumbs' => [['Home', '/'], ['Positions', '/positions?security=' . $sid], ['Concentration', null]]]) ?>
<div class="main-content" id="positions-concentration-content">
    <?= view('positions/partials/subnav.php', ['active' => 'concentration', 'security' => $security, 'from' => $from, 'to' => $to]) ?>
    <div class="card" id="concentration-card"><div class="card-body">
        <?php if ($series === []): ?><div class="text-muted" id="concentration-empty">No position date in this window.</div>
        <?php else: ?>
            <?= svg_lines($lines, 'concentration-chart', 'The share of the DTC total held by the top 5, 10 and 20 participants', 100.0) ?>
            <?= svg_legend(['Top 5' => '#3454d1', 'Top 10' => '#8b5cf6', 'Top 20' => '#41b2c4'], 'concentration-legend') ?>
            <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Reports; the index is the sum of the squared shares (10,000 = one participant holds it all)', 'date' => end($series)['position_date']]) ?>
            <details class="mt-2 fs-12" open><summary>As a table</summary><div class="table-responsive"><table class="table table-sm mb-0" id="concentration-table"><thead><tr><th>Date</th><th class="text-end">Participants</th><th class="text-end">Top 5</th><th class="text-end">Top 10</th><th class="text-end d-none d-sm-table-cell">Top 20</th><th class="text-end">Index</th></tr></thead><tbody>
                <?php foreach (array_reverse($series) as $r): ?><tr><td class="text-nowrap"><?= e(date('M j, Y', strtotime($r['position_date']))) ?></td><td class="text-end"><?= (int) $r['participants'] ?></td><td class="text-end"><?= e(number_format((float) $r['top5_pct'], 1)) ?>%</td><td class="text-end"><?= e(number_format((float) $r['top10_pct'], 1)) ?>%</td><td class="text-end d-none d-sm-table-cell"><?= e(number_format((float) $r['top20_pct'], 1)) ?>%</td><td class="text-end"><?= e(number_format((float) $r['hhi'], 0)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></details>
        <?php endif; ?>
    </div></div>
</div>
