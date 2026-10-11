<?php /** A pack's section: holders by share range (the NOBO list's, Broadridge's beside it). Counts only. Data: d, ctx, title */ ?>
<section id="pack-section-holders_ranges"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['date'] === null): ?><p class="text-muted">No NOBO list is loaded.</p>
<?php else: ?>
    <?php if ($d['list'] !== [] && array_sum(array_column($d['list'], 'holders')) > 0): ?>
    <table><caption class="text-start fw-semibold">From the NOBO list</caption><thead><tr><th>Shares</th><th class="text-end">Holders</th><th class="text-end">Shares held</th><th class="text-end">%</th></tr></thead><tbody>
    <?php foreach ($d['list'] as $x): ?><tr><td><?= e($x['range_label']) ?></td><td class="text-end"><?= number_format((int) $x['holders']) ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td><td class="text-end"><?= $x['share_pct'] === null ? '—' : e(number_format((float) $x['share_pct'], 1)) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($d['broadridge'] !== []): ?>
    <table><caption class="text-start fw-semibold">Broadridge's Share Range Analysis</caption><thead><tr><th>Shares</th><th class="text-end">All</th><th class="text-end">OBO</th><th class="text-end">NOBO</th></tr></thead><tbody>
    <?php foreach ($d['broadridge'] as $x): ?><tr><td><?= e($x['range_label']) ?></td><td class="text-end"><?= number_format((int) $x['all_accounts']) ?></td><td class="text-end"><?= number_format((int) $x['obo_accounts']) ?></td><td class="text-end"><?= number_format((int) $x['nobo_accounts']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?= view('shared/source-line.php', ['source' => 'the NOBO list uploaded (a holder counted once over its accounts)', 'date' => $d['date']]) ?>
<?php endif; ?></section>
