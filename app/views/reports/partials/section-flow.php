<?php /** A pack's section: the flow by participant class between position dates. Data: d, ctx, title */
$classes = [];
foreach ($d['rows'] ?? [] as $r) { foreach (array_keys($r['by_class']) as $c) { $classes[$c] = true; } }
$classes = array_keys($classes);
sort($classes);
?>
<section id="pack-section-flow"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['rows'] === []): ?><p class="text-muted">Fewer than two Security Position Reports fall in the period; there is no flow to show.</p>
<?php else: ?>
    <table><thead><tr><th>Position date</th><?php foreach ($classes as $c): ?><th class="text-end"><?= e(str_replace('_', ' ', $c)) ?></th><?php endforeach; ?><th class="text-end">Retail side</th><th class="text-end">Retail, cumulative</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $r): ?><tr><td><?= e(format_date($r['date'])) ?></td><?php foreach ($classes as $c): ?><td class="text-end"><?= e(fmt_n($r['by_class'][$c] ?? null)) ?></td><?php endforeach; ?><td class="text-end"><?= e(fmt_n($r['retail_net'])) ?></td><td class="text-end"><?= e(fmt_n($r['retail_cumulative'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="fs-11 text-muted">The net change in shares held by each class of DTC participant between consecutive reports. A participant's box is a custodian's, not a holder's intent: the flow is a clue, not a count of buyers.</p>
    <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Reports uploaded', 'date' => $d['rows'][count($d['rows']) - 1]['date']]) ?>
<?php endif; ?></section>
