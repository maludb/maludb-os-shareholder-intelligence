<?php /** A pack's section: the events of the period. Data: d, ctx, title */ ?>
<section id="pack-section-events"><h3><?= e($title) ?></h3>
<?php if ($d['rows'] === []): ?><p class="text-muted">No event is recorded in the period.</p>
<?php else: ?>
    <table><thead><tr><th>Date</th><th>Kind</th><th>What happened</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $x): ?><tr><td><?= e(format_date($x['on_date'])) ?></td><td><?= e(event_kind_label((string) $x['kind'])) ?></td><td><?= e($x['title']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php if ($d['total'] > count($d['rows'])): ?><p class="fs-11 text-muted">The <?= count($d['rows']) ?> latest of <?= (int) $d['total'] ?> shown.</p><?php endif; ?>
<?php endif; ?></section>
