<?php
/** A signal as a card (signals.md §2): severity, the class chip, the rule, the security, the window, the summary — the basis on hover — the status and the waiting proposal. Data: s, may, here */
$sid = (int) $s['signal_id'];
?>
<div class="card mb-2 signal-card border-start border-4 border-<?= e(['high' => 'danger', 'watch' => 'warning', 'info' => 'info'][$s['severity']] ?? 'secondary') ?>" id="signal-card-<?= $sid ?>" title="<?= e('The basis: ' . (string) $s['basis']) ?>" data-severity="<?= e((string) $s['severity']) ?>" data-status="<?= e((string) $s['status']) ?>"><div class="card-body p-3">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?= signal_severity_chip((string) $s['severity']) ?>
        <span class="badge <?= e(SIGNAL_CLASS_CHIPS[$s['evidence_class']][0] ?? 'text-bg-secondary') ?> signal-class" data-class="<?= e((string) $s['evidence_class']) ?>"><?= e(SIGNAL_CLASS_CHIPS[$s['evidence_class']][1] ?? (string) $s['evidence_class']) ?></span>
        <?= hx_link(with_back('/signals/' . $sid, $here), e((string) $s['rule_name']), 'fw-semibold text-dark', 'id="signal-link-' . $sid . '"') ?>
        <?= $s['status'] !== 'open' ? signal_status_chip((string) $s['status']) : '' ?>
        <span class="fs-11 text-muted ms-auto text-nowrap"><?= e(format_date((string) $s['raised_on'])) ?></span>
    </div>
    <div class="fs-11 text-muted mt-1"><?= e((string) ($s['ticker'] ?? $s['security_name'])) ?> · the window <?= e(format_date((string) $s['window_from'])) ?><?= $s['window_to'] !== $s['window_from'] ? ' – ' . e(format_date((string) $s['window_to'])) : '' ?><?= (int) $s['repeat_count'] > 0 ? ' · fired again ' . (int) $s['repeat_count'] . '×' : '' ?></div>
    <div class="fs-12 mt-1 text-break"><?= e((string) $s['summary']) ?></div>
    <?php if ($s['proposal_reason'] !== null && in_array($s['status'], ['open', 'escalated'], true)): ?><?= view('signals/partials/proposal.php', ['s' => $s, 'may' => $may, 'here' => $here, 'idSuffix' => '-' . $sid]) ?><?php endif; ?>
</div></div>
