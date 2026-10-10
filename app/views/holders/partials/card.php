<?php /** A holder as a card. Data: h, here */
$hid = (int) $h['holder_id'];
?>
<div class="col-sm-6 col-xl-4" id="holder-card-<?= $hid ?>"><div class="card h-100 mb-0"><div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-2">
        <div class="min-w-0"><div class="fw-semibold text-break"><?= $h['pii_withheld'] ? hx_link(with_back('/holders/' . $hid, $here), view('holders/partials/pii-withheld.php'), '', 'id="holder-card-' . $hid . '-link"')
            : hx_link(with_back('/holders/' . $hid, $here), e($h['display_name']), 'text-dark', 'id="holder-card-' . $hid . '-link"') ?></div>
            <div class="fs-11 text-muted"><?= e(trim(implode(', ', array_filter([$h['city'], $h['state'], $h['country'] !== 'US' ? $h['country'] : null])))) ?: '&nbsp;' ?></div></div>
        <?= holder_kind_chip((string) $h['kind']) ?>
    </div>
    <div class="fs-12 mt-2"><span class="fw-semibold"><?= e(fmt_n($h['shares'] ?? $h['last_shares'])) ?></span> shares<?= $h['last_seen_on'] ? ' · last listed ' . e(format_date($h['last_seen_on'])) : '' ?></div>
    <div class="d-flex flex-wrap gap-1 mt-2"><?php foreach (array_filter(explode(',', (string) ($h['account_kinds'] ?? ''))) as $k): ?><?= account_kind_chip($k) ?><?php endforeach; ?>
        <?php foreach (array_filter(explode(',', (string) ($h['channels'] ?? ''))) as $c): ?><span class="badge text-bg-light"><?= e(HOLDER_CHANNELS[$c] ?? $c) ?></span><?php endforeach; ?></div>
    <?php if (!empty($h['custodians'])): ?><div class="fs-11 text-muted mt-1">At <?= e($h['custodians']) ?></div><?php endif; ?>
    <div class="fs-11 text-muted mt-1"><?= (int) $h['accounts_count'] ?> account<?= (int) $h['accounts_count'] === 1 ? '' : 's' ?><?= $h['is_insider'] ? ' · <span class="text-danger">an insider</span>' : '' ?></div>
</div></div></div>
