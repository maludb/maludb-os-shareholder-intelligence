<?php /** A pack's section: insider transactions of the period (Forms 3, 4 and 5; the public record). Data: d, ctx, title */ ?>
<section id="pack-section-insiders"><h3><?= e($title) ?></h3>
<?php if ($d['rows'] === []): ?><p class="text-muted">No insider transaction is filed in the period.</p>
<?php else: ?>
    <table><thead><tr><th>Date</th><th>Insider</th><th>Code</th><th class="text-end">Shares</th><th class="text-end">Price</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $x): ?><tr><td><?= e(format_date($x['transaction_date'])) ?></td><td><?= e($x['reporting_owner_name']) ?></td><td><?= e((string) $x['transaction_code']) ?><?= $x['acquired_disposed'] ? ' (' . e($x['acquired_disposed']) . ')' : '' ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td><td class="text-end"><?= $x['price'] === null ? '—' : e(number_format((float) $x['price'], 2)) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php if ($d['total'] > count($d['rows'])): ?><p class="fs-11 text-muted">The <?= count($d['rows']) ?> latest of <?= (int) $d['total'] ?> shown.</p><?php endif; ?>
    <?= view('shared/source-line.php', ['source' => 'Forms 3, 4 and 5 on EDGAR']) ?>
<?php endif; ?></section>
