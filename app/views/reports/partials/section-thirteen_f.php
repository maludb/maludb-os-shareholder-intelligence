<?php /** A pack's section: the ten largest 13F holders of the latest quarter. Data: d, ctx, title */ ?>
<section id="pack-section-thirteen_f"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['period'] === null): ?><p class="text-muted">No 13F data set has been read for this security yet.</p>
<?php else: $s = $d['summary']; ?>
    <?php if ($s): ?><p><?= number_format((int) $s['holders']) ?> institutions held <?= e(fmt_n($s['shares'])) ?> shares<?= $s['pct_of_outstanding'] !== null ? ' (' . e(number_format((float) $s['pct_of_outstanding'], 2)) . '% of the outstanding count)' : '' ?>; the ten largest hold <?= $s['top10_pct'] === null ? '—' : e(number_format((float) $s['top10_pct'], 1)) . '%' ?> of what 13F filers hold.</p><?php endif; ?>
    <table><thead><tr><th>Institution</th><th class="text-end">Shares</th><th class="text-end">Change</th><th class="text-end">% of outstanding</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $x): ?><tr><td><?= e($x['name']) ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td><td class="text-end"><?= e(fmt_n($x['net'])) ?></td><td class="text-end"><?= $x['pct_of_outstanding'] === null ? '—' : e(number_format((float) $x['pct_of_outstanding'], 2)) . '%' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?= view('shared/source-line.php', ['source' => 'the SEC\'s Form 13F data sets (filed 45 days after the quarter), the quarter ending', 'date' => $d['period']]) ?>
<?php endif; ?></section>
