<?php /** A pack's section: the holders of more than 5% as the latest Schedules 13D and 13G show them. Data: d, ctx, title */ ?>
<section id="pack-section-beneficial"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['rows'] === []): ?><p class="text-muted">No Schedule 13D or 13G has been read for this security.</p>
<?php else: ?>
    <table><thead><tr><th>Filer</th><th>Schedule</th><th class="text-end">% of class</th><th class="text-end">Shares</th><th>Filed</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $x): ?><tr><td><?= e($x['name']) ?></td><td><?= e($x['schedule']) ?></td><td class="text-end"><?= $x['percent_of_class'] === null ? '—' : e(number_format((float) $x['percent_of_class'], 2)) . '%' ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td><td><?= e(format_date($x['filed_on'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?= view('shared/source-line.php', ['source' => 'the latest Schedule 13D or 13G of each filer, on EDGAR']) ?>
<?php endif; ?></section>
