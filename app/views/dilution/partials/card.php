<?php /** An instrument as a card. Data: d, here */
$did = (int) $d['instrument_id'];
$fac = $d['facility_amount'] === null ? null : (float) $d['facility_amount'];
$rem = $d['remaining_amount'] === null ? null : (float) $d['remaining_amount'];
$money = static fn (?float $v, string $c): string => $v === null ? '—' : ($c === 'USD' ? '$' : $c . ' ') . number_format($v, 0);
?>
<div class="col-md-6 col-xl-4" id="dilution-card-<?= $did ?>"><div class="card h-100 mb-0"><div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-2"><div class="min-w-0"><div class="fw-semibold"><?= hx_link(with_back('/dilution/' . $did, $here), e(DILUTION_KINDS[$d['kind']] ?? $d['kind']), 'text-dark', 'id="dilution-card-' . $did . '-link"') ?></div>
        <div class="fs-12 text-muted text-break"><?= e((string) ($d['counterparty'] ?? 'no counterparty yet')) ?></div></div>
        <div class="d-flex flex-column align-items-end gap-1"><?= dilution_status_chip((string) $d['status']) ?><?= $d['floating_price'] ? '<span class="badge text-bg-danger" id="dilution-floating-' . $did . '">floating price</span>' : '' ?></div></div>
    <?php if ($fac !== null): ?><div class="fs-12 mt-2"><?= e($money($rem ?? $fac, (string) $d['currency'])) ?> of <?= e($money($fac, (string) $d['currency'])) ?> remaining</div>
        <div class="progress mt-1" style="height:.4rem" role="progressbar" aria-label="Remaining" aria-valuenow="<?= (int) round(100 * ($rem ?? $fac) / max(1, $fac)) ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= (int) round(100 * ($rem ?? $fac) / max(1, $fac)) ?>%"></div></div><?php endif; ?>
    <?php if ($d['price_terms']): ?><div class="fs-11 text-muted mt-2 text-break"><?= e(mb_strimwidth((string) $d['price_terms'], 0, 160, '…')) ?></div><?php endif; ?>
    <div class="fs-11 text-muted mt-2"><?= $d['filed_on'] ? 'Filed ' . e(format_date($d['filed_on'])) : '' ?><?= $d['form'] ? ' · ' . e((string) $d['form']) : '' ?><?= $d['proposed_by'] === 'watch' ? ' · found by the watch' : '' ?></div>
</div></div></div>
