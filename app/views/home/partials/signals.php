<?php /** The open signals by severity, as cards with the evidence class (signals.md §2). Data: signals */
$classChip = ['established' => ['primary', 'Established'], 'contested' => ['warning', 'Contested'], 'lore' => ['secondary', 'Lore — no published validation'], 'data_defect' => ['danger', 'Data defect']];
$sev = ['high' => 'danger', 'watch' => 'warning', 'info' => 'info'];
?>
<div class="card mb-3" id="home-signals"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Open signals</h5><span class="badge bg-soft-secondary text-secondary ms-2" id="home-signals-count"><?= count($signals) ?></span><?= hx_link('/signals', 'All signals', 'ms-auto fs-12 fw-semibold') ?></div><div class="card-body">
<?php if ($signals === []): ?>
    <div class="text-muted fs-12" id="home-signals-empty">No open signal. The rules run after every upload and every pull, and at 06:45 on market days.</div>
<?php else: foreach ($signals as $g): $cc = $classChip[$g['evidence_class']] ?? ['secondary', $g['evidence_class']]; ?>
    <div class="border rounded p-2 mb-2" id="home-signal-<?= (int) $g['signal_id'] ?>">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-<?= e($sev[$g['severity']] ?? 'secondary') ?>"><?= e($g['severity']) ?></span>
            <span class="badge bg-soft-<?= e($cc[0]) ?> text-<?= e($cc[0]) ?>"><?= e($cc[1]) ?></span>
            <?= hx_link(with_back('/signals/' . (int) $g['signal_id'], '/'), e($g['rule_name']), 'fw-semibold text-dark') ?>
            <span class="fs-11 text-muted ms-auto"><?= e(format_date($g['raised_on'])) ?></span>
        </div>
        <div class="fs-12 mt-1"><?= e($g['summary']) ?></div>
        <?php if ($g['proposal_reason'] !== null): ?><div class="fs-11 text-primary mt-1"><i class="feather-inbox me-1"></i>The Analyst proposes: <?= e(str_replace('_', ' ', $g['proposal_reason'])) ?></div><?php endif; ?>
    </div>
<?php endforeach; endif; ?>
</div></div>
