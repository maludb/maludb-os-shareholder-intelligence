<?php /** An institution as a card. Data: i, security, here */ $iid = (int) $i['institution_id']; ?>
<div class="col-sm-6 col-xl-4" id="institution-card-<?= $iid ?>"><div class="card h-100 mb-0"><div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-2"><div class="min-w-0"><div class="fw-semibold text-break"><?= hx_link(with_back('/institutions/' . $iid . ($security ? '?security=' . (int) $security['security_id'] : ''), $here), e((string) $i['name']), 'text-dark', 'id="institution-card-' . $iid . '-link"') ?></div>
        <div class="fs-11 text-muted">CIK <?= e(ltrim((string) $i['cik'], '0')) ?></div></div><?= $i['is_activist'] ? '<span class="badge text-bg-danger">activist</span>' : '' ?></div>
    <div class="mt-2"><?= institution_kind_chip((string) $i['kind']) ?><?= $i['kind_source'] === 'proposed' ? ' <span class="fs-11 text-muted">from the name</span>' : '' ?></div>
    <?php if (isset($i['shares']) && $i['shares'] !== null): ?><div class="fs-12 mt-2"><span class="fw-semibold"><?= e(fmt_n($i['shares'])) ?></span> shares at <?= e(format_date($i['period_end'])) ?> (13F)</div><?php endif; ?>
    <?php if (isset($i['percent_of_class']) && $i['percent_of_class'] !== null): ?><div class="fs-12"><?= e(number_format((float) $i['percent_of_class'], 1)) ?>% on its latest 13D/G</div><?php endif; ?>
</div></div></div>
