<?php /** One issuer as a card (the cards rule). Data: i (find_issuers row), here */ $id = (int) $i['issuer_id']; ?>
<div class="col-sm-6 col-xl-4" id="issuer-card-<?= $id ?>"><div class="card h-100 mb-0"><div class="card-body">
    <div class="d-flex align-items-start gap-3">
        <span class="avatar-text avatar-lg rounded fw-bold fs-12"><?= e(mb_substr((string) ($i['ticker'] ?: $i['name']), 0, 4)) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold text-truncate"><?= hx_link(with_back('/issuers/' . $id, $here), e($i['name']), 'text-dark', 'id="issuer-card-' . $id . '-link"') ?></div>
            <div class="fs-12 text-muted"><?= e(implode(' · ', array_filter([$i['ticker'], ISSUER_EXCHANGES[$i['exchange']] ?? $i['exchange'], $i['cik'] !== null ? 'CIK ' . ltrim((string) $i['cik'], '0') : null]))) ?></div>
        </div>
        <?php if (!empty($i['restricted'])): ?><span class="badge bg-soft-warning text-warning" title="Restricted to named members"><i class="feather-lock me-1"></i>restricted</span><?php endif; ?>
    </div>
    <div class="fs-12 mt-3 d-flex flex-wrap gap-3">
        <span><span class="text-muted">CUSIP</span> <?= e($i['cusip'] ?? '—') ?></span>
        <span><span class="text-muted">Last SPR</span> <?= e($i['last_position_date'] !== null ? format_date($i['last_position_date']) : 'none yet') ?></span>
    </div>
    <?php $tags = pg_text_array((string) ($i['tags'] ?? '{}')); if ($tags !== []): ?><div class="mt-2"><?php foreach ($tags as $t): ?><span class="badge bg-soft-primary text-primary me-1"><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
</div></div></div>
