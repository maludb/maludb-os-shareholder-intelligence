<?php /** A 5% filer as a card. Data: r, sid, here */ $pct = $r['percent_of_class'] === null ? null : (float) $r['percent_of_class']; ?>
<div class="col-md-6" id="beneficial-card-<?= (int) $r['institution_id'] ?>"><div class="card h-100 mb-0"><div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-2"><div class="min-w-0 fw-semibold text-break"><?= hx_link(with_back('/institutions/' . (int) $r['institution_id'] . '?security=' . $sid, $here), e((string) $r['name']), 'text-dark') ?></div>
        <span class="badge <?= $r['schedule'] === '13D' ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= e((string) $r['schedule']) ?></span></div>
    <?php if ($pct !== null): ?><div class="d-flex align-items-center gap-2 mt-2"><div class="progress flex-grow-1 position-relative" style="height:.6rem" role="progressbar" aria-label="Percent of the class" aria-valuenow="<?= e((string) $pct) ?>" aria-valuemin="0" aria-valuemax="20">
        <div class="progress-bar <?= $pct >= 10 ? 'bg-danger' : 'bg-warning' ?>" style="width:<?= e((string) min(100, $pct * 5)) ?>%"></div></div><span class="fw-bold"><?= e(number_format($pct, 1)) ?>%</span></div>
        <div class="fs-11 text-muted">The bar's 25% mark is 5%, its half 10%.</div><?php endif; ?>
    <div class="fs-12 mt-2"><?= e(fmt_n($r['shares'])) ?> shares · filed <?= e(format_date($r['filed_on'])) ?><?= $r['event_date'] ? ' · event ' . e(format_date($r['event_date'])) : '' ?></div>
    <?php if ($r['purpose_text']): ?><div class="fs-12 mt-2 text-break" id="beneficial-purpose-<?= (int) $r['institution_id'] ?>"><span class="text-muted">Purpose:</span> <?= e(mb_strimwidth((string) $r['purpose_text'], 0, 600, '…')) ?></div><?php endif; ?>
    <?php if ($r['filing_id']): ?><div class="fs-12 mt-2"><?= hx_link('/filings/' . (int) $r['filing_id'], 'The filing', 'fw-semibold') ?></div><?php endif; ?>
</div></div></div>
