<?php /** The watch's hits (screen `watch-hits`). Data: issuer, status, rows, terms, page, here, notice */ $may = has_right('events.write') && (current_member()['member_kind'] ?? '') === 'human'; ?>
<?= view('shared/header.php', ['id' => 'watch-hits', 'title' => 'The watch', 'crumbs' => [['Home', '/'], ['Filings', '/filings'], ['The watch', null]]]) ?>
<div class="main-content" id="watch-hits-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= filings_tabs('watch') ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="watch-statuses"><?php foreach (['new' => 'New', 'confirmed' => 'Confirmed', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l): ?><?= hx_link('/filings/watch?status=' . $k, e($l), 'btn btn-sm btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="watch-status-' . $k . '"') ?><?php endforeach; ?></div>
    <p class="fs-12 text-muted" id="watch-terms">EDGAR's full-text search is asked, every market hour, for <?= e(implode(', ', array_map(static fn ($t) => '“' . $t . '”', $terms))) ?> in the company's filings, and for the company's name in anyone else's.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="watch-empty">No <?= $status === 'all' ? '' : e($status) . ' ' ?>hit.</div></div><?php endif; ?>
    <div class="row g-3" id="watch-cards">
    <?php foreach ($rows as $w): $wid = (int) $w['watch_hit_id']; ?>
        <div class="col-md-6 col-xl-4" id="watch-hit-<?= $wid ?>"><div class="card h-100 mb-0"><div class="card-body d-flex flex-column">
            <div class="d-flex flex-wrap align-items-center gap-1 mb-2"><span class="badge text-bg-warning"><?= e($w['term']) ?></span><?= $w['form'] ? form_chip((string) $w['form']) : '' ?>
                <?= $w['about_us'] ? '<span class="badge bg-soft-danger text-danger"><i class="bi bi-box-arrow-in-down-left me-1"></i>by another filer</span>' : '' ?><span class="fs-11 text-muted ms-auto"><?= e(format_date($w['filed_on'])) ?></span></div>
            <div class="fs-12 text-break flex-grow-1"><?= e((string) $w['snippet']) ?></div>
            <div class="fs-12 mt-2"><?= $w['filing_id'] ? hx_link(with_back('/filings/' . (int) $w['filing_id'] . '?q=' . rawurlencode(str_starts_with((string) $w['term'], 'about') ? '' : (string) $w['term']), $here), 'The filing', 'fw-semibold') : '' ?>
                <?= $w['instrument_id'] ? ' · proposed ' . hx_link('/dilution/' . (int) $w['instrument_id'], e(DILUTION_KINDS[$w['instrument_kind']] ?? (string) $w['instrument_kind']), '') : '' ?> · <span class="text-muted"><?= e($w['status']) ?></span></div>
            <?php if ($w['status'] === 'new' && $may): ?><div class="d-flex gap-2 mt-2">
                <?= $w['instrument_kind'] || in_array((string) $w['term'], ['at-the-market', 'equity line', 'equity purchase agreement', 'convertible', 'warrant', 'registered direct'], true) ? hx_link('/dilution/new?hit=' . $wid, '<i class="feather-check me-1"></i>Confirm instrument', 'btn btn-primary btn-touch flex-grow-1', 'id="watch-confirm-' . $wid . '"') : '' ?>
                <form method="post" action="/filings/watch/dismiss.php" hx-post="/filings/watch/dismiss.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="watch_hit" value="<?= $wid ?>">
                    <button type="submit" class="btn btn-light btn-touch w-100" id="watch-dismiss-<?= $wid ?>">Dismiss</button></form></div><?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
