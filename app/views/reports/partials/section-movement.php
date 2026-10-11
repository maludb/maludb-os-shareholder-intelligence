<?php /** A pack's section: the movement of the DTC positions over the period. Data: d, ctx, title */ ?>
<section id="pack-section-movement"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif (array_key_exists('rows', $d) && $d['rows'] === null): ?><p class="text-muted">Fewer than two Security Position Reports fall in the period (<?= count($d['dates']) ?>); there is no movement to show.</p>
<?php else: ?>
    <p>DTC's total went from <strong><?= e(fmt_n($d['total_first'])) ?></strong> on <?= e(format_date($d['first'])) ?> to <strong><?= e(fmt_n($d['total_last'])) ?></strong> on <?= e(format_date($d['last'])) ?>
        (<?= e(fmt_n((float) $d['total_last'] - (float) $d['total_first'])) ?> shares); <?= (int) $d['new'] ?> participant(s) new since the report before, <?= (int) $d['gone'] ?> gone.</p>
    <?php foreach (['up' => 'Largest increases', 'down' => 'Largest decreases'] as $k => $label): ?>
        <?php if ($d[$k] !== []): ?><table><caption class="text-start fw-semibold"><?= e($label) ?></caption><thead><tr><th>Participant</th><th class="text-end">Net shares</th><th class="text-end">Now</th></tr></thead><tbody>
            <?php foreach ($d[$k] as $r): ?><tr><td><?= e($r['number'] . ' ' . $r['name']) ?></td><td class="text-end"><?= e(fmt_n($r['net_window'])) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
    <?php endforeach; ?>
    <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Reports uploaded', 'date' => $d['last']]) ?>
<?php endif; ?></section>
