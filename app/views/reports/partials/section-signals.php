<?php /** A pack's section: the signals raised in the period, with their evidence class. A signal advises; nothing here scores. Data: d, ctx, title */ ?>
<section id="pack-section-signals"><h3><?= e($title) ?></h3>
<?php if ($d['rows'] === []): ?><p class="text-muted">No rule raised a signal in the period.</p>
<?php else: ?>
    <table><thead><tr><th>Raised</th><th>Rule</th><th>Severity</th><th>Class</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $x): ?><tr><td><?= e(format_date($x['raised_on'])) ?></td><td><?= e($x['rule_name']) ?><div class="fs-11 text-muted"><?= e((string) $x['summary']) ?></div></td><td><?= e($x['severity']) ?></td><td><?= e(SIGNAL_CLASS_CHIPS[$x['evidence_class']][1] ?? (string) $x['evidence_class']) ?></td><td><?= e($x['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="fs-11 text-muted">Every signal is a rule's flag with its evidence class; a person decides what it means.</p>
<?php endif; ?></section>
