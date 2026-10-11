<?php /** A pack's section: the ten largest DTC participants on the latest report. Data: d, ctx, title */ ?>
<section id="pack-section-top_participants"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['rows'] === null): ?><p class="text-muted">No Security Position Report is loaded at or before the end of the period.</p>
<?php else: ?>
    <table><thead><tr><th>Participant</th><th class="text-end">Shares</th><th class="text-end">% of DTC</th><th class="text-end">Change</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $r): ?><tr><td><?= e($r['number'] . ' ' . $r['name']) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td><td class="text-end"><?= $r['pct'] === null ? '—' : e(number_format((float) $r['pct'], 2)) . '%' ?></td>
        <td class="text-end"><?= $r['is_new'] ? 'new' : e(fmt_n($r['change_prev'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Report (total ' . fmt_n($d['total']) . ' shares)' . ($d['prev'] ? ', the change against ' . format_date($d['prev']) : ''), 'date' => $d['date']]) ?>
<?php endif; ?></section>
