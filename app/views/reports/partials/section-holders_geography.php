<?php /** A pack's section: holders by country and state, the ten largest places. Counts only. Data: d, ctx, title */ ?>
<section id="pack-section-holders_geography"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['rows'] === []): ?><p class="text-muted">No NOBO list is loaded.</p>
<?php else: ?>
    <table><thead><tr><th>Where</th><th class="text-end">Holders</th><th class="text-end">Shares</th><th class="text-end">%</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $x): ?><tr><td><?= e(($x['state'] ? $x['state'] . ', ' : '') . $x['country']) ?></td><td class="text-end"><?= number_format((int) $x['holders']) ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td>
        <td class="text-end"><?= $d['total'] > 0 ? e(number_format((float) $x['shares'] / $d['total'] * 100, 1)) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?= view('shared/source-line.php', ['source' => 'the NOBO list uploaded (the state as the list writes it)', 'date' => $d['date']]) ?>
<?php endif; ?></section>
