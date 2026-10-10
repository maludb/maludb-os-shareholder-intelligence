<?php /** The flow by class (screen `positions-flow`): net shares per class between position dates as stacked bars, the retail side's cumulative net as a dashed line, the caveat under it ALWAYS. Data: security, flow, from, to, classes, colours */
$sid = (int) $security['security_id'];
$bars = array_map(static fn (array $f): array => ['label' => $f['date'], 'values' => $f['by_class']], $flow);
$line = array_column($flow, 'retail_cumulative', 'date');
$used = [];
foreach ($flow as $f) { foreach ($f['by_class'] as $k => $v) { if ($v != 0) { $used[$k] = true; } } }
$legend = [];
foreach ($classes as $k => [$name]) { if (isset($used[$k])) { $legend[$name] = $colours[$k]; } }
?>
<?= view('shared/header.php', ['id' => 'positions-flow', 'title' => 'Flow by class', 'crumbs' => [['Home', '/'], ['Positions', '/positions?security=' . $sid], ['Flow by class', null]]]) ?>
<div class="main-content" id="positions-flow-content">
    <?= view('positions/partials/subnav.php', ['active' => 'flow', 'security' => $security, 'from' => $from, 'to' => $to]) ?>
    <div class="card" id="flow-card"><div class="card-body">
        <?php if ($flow === []): ?><div class="text-muted" id="flow-empty">Two position dates at least are needed in the window.</div>
        <?php else: ?>
            <?= svg_stacked_bars($bars, $colours, 'flow-chart', 'Net shares by participant class between position dates', $line) ?>
            <?= svg_legend($legend + ['Retail side, cumulative (dashed)' => '#1f2937'], 'flow-legend') ?>
            <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Reports, net per class from one position date to the next', 'date' => end($flow)['date']]) ?>
            <details class="mt-2 fs-12"><summary>As a table</summary><div class="table-responsive"><table class="table table-sm mb-0" id="flow-table"><thead><tr><th>Date</th><?php foreach ($legend as $name => $c): if (str_starts_with($name, 'Retail side')) { continue; } ?><th class="text-end"><?= e($name) ?></th><?php endforeach; ?><th class="text-end">Retail, cumulative</th></tr></thead><tbody>
                <?php foreach ($flow as $f): ?><tr><td class="text-nowrap"><?= e(date('M j', strtotime($f['date']))) ?></td><?php foreach ($classes as $k => [$name]): if (!isset($used[$k])) { continue; } ?><td class="text-end"><?= e(number_format((int) ($f['by_class'][$k] ?? 0))) ?></td><?php endforeach; ?><td class="text-end"><?= e(number_format($f['retail_cumulative'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></details>
        <?php endif; ?>
        <div class="alert alert-secondary fs-12 mt-3 mb-0" id="flow-caveat"><i class="feather-info me-1"></i>Class buckets are a setting. Movement between them is practitioner lore with no published validation.</div>
    </div></div>
</div>
