<?php /** A pack's section: the latest reconciliation of the share count against the register and DTC. Data: d, ctx, title */ ?>
<section id="pack-section-reconciliation"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['latest'] === null): ?><p class="text-muted">The reconciliation has not run for this security.</p>
<?php else: $r = $d['latest']; ?>
    <p>As of <?= e(format_date($r['as_of'])) ?> the reconciliation is <strong><?= e($d['state']) ?></strong>.</p>
    <table><tbody>
        <tr><th>Shares outstanding</th><td class="text-end"><?= e(fmt_n($r['outstanding'])) ?></td></tr>
        <tr><th>Cede &amp; Co on the register</th><td class="text-end"><?= e(fmt_n($r['cede_registered'])) ?></td></tr>
        <tr><th>DTC's Security Position Report total</th><td class="text-end"><?= e(fmt_n($r['spr_total'])) ?></td></tr>
        <tr><th>Difference, register against the count</th><td class="text-end"><?= e(fmt_n($r['difference_registered'])) ?></td></tr>
        <tr><th>Difference, DTC against Cede &amp; Co</th><td class="text-end"><?= e(fmt_n($r['difference_spr'])) ?></td></tr>
    </tbody></table>
    <?php if (!empty($r['note'])): ?><p class="fs-12"><?= e((string) $r['note']) ?></p><?php endif; ?>
    <?= view('shared/source-line.php', ['source' => 'the share count on record, the transfer agent\'s register and the DTC report', 'date' => $r['as_of']]) ?>
<?php endif; ?></section>
