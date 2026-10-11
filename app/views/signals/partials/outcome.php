<?php /** What the price did after the signal (signals.md §6): the close at the signal and 5, 20 and 60 trading days after, as they arrive. Data: outcome, s */
$o = $outcome;
$cell = static function ($p, $r): string {
    if ($p === null) { return '<span class="text-muted">not yet</span>'; }
    return e(number_format((float) $p, 2)) . ($r === null ? '' : ' <span class="' . ((float) $r < 0 ? 'text-danger' : 'text-success') . '">' . e(sprintf('%+.1f%%', 100 * (float) $r)) . '</span>');
}; ?>
<div class="card mb-3" id="signal-outcome"><div class="card-header"><h5 class="card-title mb-0">After the signal</h5></div><div class="card-body fs-12">
<?php if ($o === null || $o['price_at'] === null): ?><div class="text-muted" id="signal-outcome-none">No close is recorded at the signal yet; the worker fills this in daily as prices arrive.</div>
<?php else: ?>
    <dl class="row mb-1">
        <dt class="col-6 text-muted fw-normal">The close at the signal</dt><dd class="col-6 text-end"><?= e(number_format((float) $o['price_at'], 2)) ?></dd>
        <dt class="col-6 text-muted fw-normal">5 trading days after</dt><dd class="col-6 text-end"><?= $cell($o['price_5d'], $o['return_5d']) ?></dd>
        <dt class="col-6 text-muted fw-normal">20 trading days after</dt><dd class="col-6 text-end"><?= $cell($o['price_20d'], $o['return_20d']) ?></dd>
        <dt class="col-6 text-muted fw-normal">60 trading days after</dt><dd class="col-6 text-end"><?= $cell($o['price_60d'], $o['return_60d']) ?></dd>
    </dl>
<?php endif; ?>
    <div class="text-muted fs-11">For the Auditor's test of the rules, not advice: one signal's price after says nothing alone.</div>
</div></div>
